<?php

declare(strict_types=1);

namespace Commerce\Modules\Forms\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Form builder: the merchant defines fields, visitors submit, the merchant reads the answers in the admin.
 * A form is stored with its own language; answers keep the label they were given under, so later edits of the
 * form never rewrite history.
 */
final class FormService
{
    public const TYPES = ['text', 'email', 'tel', 'textarea', 'number', 'date', 'select', 'radio', 'checkbox', 'file'];
    /** extension => accepted detected MIME types; files are stored outside the web root and served only to the admin */
    public const FILE_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
    public const MAX_FILE_BYTES = 5242880;
    public const MAX_FILE_FIELDS = 3;
    public const MAX_FIELDS = 30;
    private const MAX_OPTIONS = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly NotificationOutbox $notifications,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir = '',
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $storeId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT f.id,f.slug,f.name,f.locale,f.status,f.created_at,
                    (SELECT COUNT(*) FROM mc_form_submission s WHERE s.form_id=f.id) submissions,
                    (SELECT COUNT(*) FROM mc_form_submission s WHERE s.form_id=f.id AND s.status='new') unread
             FROM mc_form f WHERE f.store_id=? ORDER BY f.name",
            [$storeId],
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $storeId, int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM mc_form WHERE store_id=? AND id=?', [$storeId, $id]);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function findPublic(int $storeId, string $slug): ?array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM mc_form WHERE store_id=? AND slug=? AND status='active'", [$storeId, $slug]);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string,mixed> $input
     * @throws \InvalidArgumentException with a space-less error code
     */
    public function save(int $storeId, ?int $id, array $input): int
    {
        $name = trim(strip_tags((string) ($input['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('form_name_invalid');
        }
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        if ($slug === '') {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(\Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($name) ?? $name)), '-');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,118}$/', $slug) !== 1) {
            throw new \InvalidArgumentException('form_slug_invalid');
        }
        $taken = $this->db->fetchOne('SELECT id FROM mc_form WHERE store_id=? AND slug=?', [$storeId, $slug]);
        if ($taken !== false && (int) $taken !== (int) $id) {
            throw new \InvalidArgumentException('form_slug_taken');
        }
        $email = trim((string) ($input['notify_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('form_email_invalid');
        }
        $locale = trim((string) ($input['locale'] ?? ''));
        if ($locale !== '' && preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $locale) !== 1) {
            $locale = '';
        }
        $status = ($input['status'] ?? 'draft') === 'active' ? 'active' : 'draft';
        $fields = $this->normalizeFields($input['fields'] ?? []);
        if ($status === 'active' && $fields === []) {
            throw new \InvalidArgumentException('form_fields_required');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $row = [
            'slug' => $slug, 'name' => $name, 'locale' => $locale !== '' ? $locale : null, 'status' => $status,
            'intro' => $this->text($input['intro'] ?? '', 2000) ?: null,
            'submit_label' => $this->text($input['submit_label'] ?? '', 80) ?: null,
            'success_message' => $this->text($input['success_message'] ?? '', 500) ?: null,
            'notify_email' => $email !== '' ? $email : null,
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'translations' => ($translations = $this->normalizeTranslations($input['translations'] ?? [], $fields, $locale)) === [] ? null : json_encode($translations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];
        if ($id !== null && $this->find($storeId, $id) !== null) {
            $this->db->update('mc_form', $row, ['id' => $id, 'store_id' => $storeId]);

            return $id;
        }
        $this->db->insert('mc_form', $row + ['store_id' => $storeId, 'created_at' => $now]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $storeId, int $id): void
    {
        $ids = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM mc_form_submission WHERE form_id=? AND store_id=?', [$id, $storeId]));
        $this->db->delete('mc_form', ['id' => $id, 'store_id' => $storeId]);
        foreach ($ids as $submissionId) {
            $this->removeFiles($submissionId);
        }
    }

    /**
     * The form as a visitor of $locale sees it: the base texts, replaced by the translation when one exists.
     * Option values stay in the base language (that is what is validated and stored); `option_labels` carries what is shown.
     *
     * @param array<string,mixed> $form @return array<string,mixed>
     */
    public function localize(array $form, string $locale): array
    {
        $translations = is_array($form['translations'] ?? null) ? $form['translations'] : [];
        $t = $translations[$locale] ?? null;
        if (!is_array($t) || $locale === (string) ($form['locale'] ?? '')) {
            foreach ($form['fields'] as &$field) {
                $field['option_labels'] = [];
            }
            unset($field);

            return $form;
        }
        foreach (['intro', 'submit_label', 'success_message'] as $key) {
            if ((string) ($t[$key] ?? '') !== '') {
                $form[$key] = (string) $t[$key];
            }
        }
        foreach ($form['fields'] as &$field) {
            $ft = $t['fields'][$field['key']] ?? [];
            $field['base_label'] = (string) $field['label'];
            if ((string) ($ft['label'] ?? '') !== '') {
                $field['label'] = (string) $ft['label'];
            }
            if ((string) ($ft['placeholder'] ?? '') !== '') {
                $field['placeholder'] = (string) $ft['placeholder'];
            }
            $labels = [];
            foreach (array_values((array) $field['options']) as $i => $option) {
                $labels[$option] = (string) (($ft['options'][$i] ?? '') !== '' ? $ft['options'][$i] : $option);
            }
            $field['option_labels'] = $labels;
        }
        unset($field);

        return $form;
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $input
     * @return int submission id
     * @throws \DomainException  message is a translated error text for the visitor
     */
    public function submit(int $storeId, array $form, array $input, string $ip, string $locale, array $files = []): int
    {
        $answers = [];
        $uploads = [];
        $missing = false;
        $size = 0;
        $base = $form['fields'];
        $form = $this->localize($form, $locale);
        foreach ((array) $form['fields'] as $index => $field) {
            $key = (string) $field['key'];
            $field['label'] = (string) ($field['label'] ?? '');
            if ($field['type'] === 'file') {
                $upload = $files[$key] ?? null;
                if (!$upload instanceof UploadedFile || $upload->getError() === UPLOAD_ERR_NO_FILE) {
                    if (!empty($field['required'])) {
                        $missing = true;
                    }
                    continue;
                }
                $checked = $this->checkUpload($upload, (string) $field['label']);
                $uploads[$key] = [$upload, $checked];
                $answers[] = ['key' => $key, 'label' => (string) ($base[$index]['label'] ?? $field['label']), 'type' => 'file', 'value' => $checked['name'], 'file' => '', 'size' => $checked['size']];
                continue;
            }
            $raw = $input[$key] ?? '';
            $value = is_array($raw) ? '' : trim((string) $raw);
            $size += strlen($value);
            $type = (string) $field['type'];
            if ($type === 'checkbox') {
                $value = $value !== '' ? '1' : '';
            }
            if ($value === '') {
                if (!empty($field['required'])) {
                    $missing = true;
                }
                continue;
            }
            $valid = match ($type) {
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 320,
                'tel' => preg_match('/^[0-9+()\-\s.]{5,32}$/', $value) === 1,
                'number' => is_numeric($value) && strlen($value) <= 24,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false,
                'select', 'radio' => in_array($value, (array) $field['options'], true),
                'checkbox' => true,
                'textarea' => mb_strlen($value) <= 4000,
                default => mb_strlen($value) <= 500,
            };
            if (!$valid) {
                throw new \DomainException(CanonicalUiText::get('forms.error.invalid_value', ['field' => (string) $field['label']]));
            }
            $answers[] = ['key' => $key, 'label' => (string) ($base[$index]['label'] ?? $field['label']), 'type' => $type, 'value' => $type === 'checkbox' ? '✓' : $value];
        }
        if ($missing) {
            throw new \DomainException(CanonicalUiText::get('forms.error.required'));
        }
        if ($answers === [] || $size > 20000) {
            throw new \DomainException(CanonicalUiText::get('forms.error.empty'));
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_form_submission', [
            'form_id' => (int) $form['id'], 'store_id' => $storeId,
            'answers' => json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'locale' => $locale !== '' ? substr($locale, 0, 12) : null,
            'ip_hash' => hash_hmac('sha256', $ip, $this->secret . '|form-ip', true), 'status' => 'new', 'created_at' => $now,
        ]);
        $id = (int) $this->db->lastInsertId();
        if ($uploads !== []) {
            try {
                foreach ($answers as &$answer) {
                    if ($answer['type'] === 'file' && isset($uploads[$answer['key']])) {
                        $answer['file'] = $this->storeUpload($id, (string) $answer['key'], $uploads[$answer['key']][0], $uploads[$answer['key']][1]['extension']);
                    }
                }
                unset($answer);
                $this->db->update('mc_form_submission', ['answers' => json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => $id]);
            } catch (\Throwable) {
                $this->db->delete('mc_form_submission', ['id' => $id]);
                $this->removeFiles($id);
                throw new \DomainException(CanonicalUiText::get('forms.error.upload_failed'));
            }
        }
        $this->notify($storeId, $form, $answers, $id);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function submissions(int $storeId, int $formId, int $limit = 200): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id,answers,locale,status,created_at FROM mc_form_submission WHERE store_id=? AND form_id=? ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit)),
            [$storeId, $formId],
        );
        foreach ($rows as &$row) {
            $row['answers'] = json_decode((string) $row['answers'], true) ?: [];
        }

        return $rows;
    }

    public function setSubmissionStatus(int $storeId, int $submissionId, string $status): void
    {
        $status = $status === 'handled' ? 'handled' : ($status === 'read' ? 'read' : 'new');
        $this->db->executeStatement('UPDATE mc_form_submission SET status=? WHERE id=? AND store_id=?', [$status, $submissionId, $storeId]);
    }

    public function deleteSubmission(int $storeId, int $submissionId): void
    {
        $this->db->delete('mc_form_submission', ['id' => $submissionId, 'store_id' => $storeId]);
        $this->removeFiles($submissionId);
    }

    /**
     * @return array{path:string,name:string,mime:string}|null the stored file of a submission's answer, or null
     */
    public function fileFor(int $storeId, int $submissionId, string $key): ?array
    {
        $row = $this->db->fetchOne('SELECT answers FROM mc_form_submission WHERE id=? AND store_id=?', [$submissionId, $storeId]);
        if (!is_string($row)) {
            return null;
        }
        foreach (json_decode($row, true) ?: [] as $answer) {
            if (($answer['key'] ?? '') !== $key || ($answer['type'] ?? '') !== 'file' || (string) ($answer['file'] ?? '') === '') {
                continue;
            }
            $stored = basename((string) $answer['file']);
            $path = $this->filesDir($submissionId) . '/' . $stored;
            $extension = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
            if (!is_file($path) || !isset(self::FILE_TYPES[$extension])) {
                return null;
            }

            return ['path' => $path, 'name' => (string) $answer['value'], 'mime' => self::FILE_TYPES[$extension][0]];
        }

        return null;
    }

    /** @return array{name:string,size:int,extension:string} */
    private function checkUpload(UploadedFile $file, string $label): array
    {
        $fail = static fn (): \DomainException => new \DomainException(CanonicalUiText::get('forms.error.file_invalid', ['field' => $label]));
        if (!$file->isValid() || $file->getSize() < 1 || $file->getSize() > self::MAX_FILE_BYTES) {
            throw $fail();
        }
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !isset(self::FILE_TYPES[$extension])) {
            throw $fail();
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (!in_array($mime, self::FILE_TYPES[$extension], true)) {
            throw $fail();
        }
        if (in_array($extension, ['docx', 'xlsx'], true) && (string) file_get_contents($file->getPathname(), false, null, 0, 2) !== 'PK') {
            throw $fail();
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($file->getPathname()) === false) {
            throw $fail();
        }

        return ['name' => mb_substr($name, 0, 120), 'size' => (int) $file->getSize(), 'extension' => $extension];
    }

    private function storeUpload(int $submissionId, string $key, UploadedFile $file, string $extension): string
    {
        $dir = $this->filesDir($submissionId);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('form_upload_dir');
        }
        $stored = $key . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $file->move($dir, $stored);
        @chmod($dir . '/' . $stored, 0640);

        return $stored;
    }

    private function filesDir(int $submissionId): string
    {
        return $this->projectDir . '/var/forms/' . $submissionId;
    }

    private function removeFiles(int $submissionId): void
    {
        $dir = $this->filesDir($submissionId);
        if ($this->projectDir === '' || !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** @return list<list<string>> header row plus one row per submission, spreadsheet-safe */
    public function csvRows(int $storeId, array $form): array
    {
        $labels = [];
        foreach ((array) $form['fields'] as $field) {
            $labels[(string) $field['key']] = (string) $field['label'];
        }
        $rows = [array_merge(['#', 'date', 'status'], array_values($labels))];
        foreach (array_reverse($this->submissions($storeId, (int) $form['id'], 1000)) as $submission) {
            $byKey = [];
            foreach ($submission['answers'] as $answer) {
                $byKey[(string) $answer['key']] = (string) $answer['value'];
            }
            $line = [(string) $submission['id'], substr((string) $submission['created_at'], 0, 19), (string) $submission['status']];
            foreach (array_keys($labels) as $key) {
                $line[] = self::csvSafe($byKey[$key] ?? '');
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /** Prevents spreadsheet formula injection from visitor-supplied cells. */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $row['fields'] = json_decode((string) $row['fields'], true) ?: [];
        $row['translations'] = is_string($row['translations'] ?? null) ? (json_decode((string) $row['translations'], true) ?: []) : [];

        return $row;
    }

    /** @param mixed $rows @return list<array{key:string,type:string,label:string,required:bool,placeholder:string,options:list<string>}> */
    private function normalizeFields(mixed $rows): array
    {
        $fields = [];
        $used = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || count($fields) >= self::MAX_FIELDS) {
                continue;
            }
            $label = $this->text($row['label'] ?? '', 190);
            if ($label === '') {
                continue;
            }
            $type = in_array($row['type'] ?? '', self::TYPES, true) ? (string) $row['type'] : 'text';
            $key = preg_match('/^f[0-9]{1,3}$/', (string) ($row['key'] ?? '')) === 1 && !isset($used[(string) $row['key']]) ? (string) $row['key'] : '';
            if ($key === '') {
                for ($n = 1; $n <= 999; ++$n) {
                    if (!isset($used['f' . $n])) {
                        $key = 'f' . $n;
                        break;
                    }
                }
            }
            $used[$key] = true;
            $options = [];
            if (in_array($type, ['select', 'radio'], true)) {
                foreach (preg_split('/\R/', (string) ($row['options'] ?? '')) ?: [] as $option) {
                    $option = $this->text($option, 120);
                    if ($option !== '' && !in_array($option, $options, true) && count($options) < self::MAX_OPTIONS) {
                        $options[] = $option;
                    }
                }
                if ($options === []) {
                    throw new \InvalidArgumentException('form_options_required');
                }
            }
            if ($type === 'file' && count(array_filter($fields, static fn (array $f): bool => $f['type'] === 'file')) >= self::MAX_FILE_FIELDS) {
                throw new \InvalidArgumentException('form_too_many_files');
            }
            $fields[] = ['key' => $key, 'type' => $type, 'label' => $label, 'required' => !empty($row['required']), 'placeholder' => $this->text($row['placeholder'] ?? '', 120), 'options' => $options];
        }

        return $fields;
    }

    /**
     * @param mixed $input {locale: {intro, submit_label, success_message, fields: {key: {label, placeholder, options}}}}
     * @param list<array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    private function normalizeTranslations(mixed $input, array $fields, string $baseLocale): array
    {
        $out = [];
        $keys = array_column($fields, 'key');
        foreach (is_array($input) ? $input : [] as $locale => $row) {
            if (!is_string($locale) || $locale === $baseLocale || preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $locale) !== 1 || !is_array($row) || count($out) >= 12) {
                continue;
            }
            $entry = [];
            foreach (['intro' => 2000, 'submit_label' => 80, 'success_message' => 500] as $key => $max) {
                $value = $this->text($row[$key] ?? '', $max);
                if ($value !== '') {
                    $entry[$key] = $value;
                }
            }
            foreach (is_array($row['fields'] ?? null) ? $row['fields'] : [] as $key => $ft) {
                if (!in_array($key, $keys, true) || !is_array($ft)) {
                    continue;
                }
                $item = [];
                foreach (['label' => 190, 'placeholder' => 120] as $k => $max) {
                    $value = $this->text($ft[$k] ?? '', $max);
                    if ($value !== '') {
                        $item[$k] = $value;
                    }
                }
                $options = [];
                foreach (preg_split('/\R/', (string) ($ft['options'] ?? '')) ?: [] as $option) {
                    $options[] = $this->text($option, 120);
                }
                if (array_filter($options) !== []) {
                    $item['options'] = $options;
                }
                if ($item !== []) {
                    $entry['fields'][$key] = $item;
                }
            }
            if ($entry !== []) {
                $out[$locale] = $entry;
            }
        }

        return $out;
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags(is_scalar($value) ? (string) $value : '')), 0, $max);
    }

    /** @param array<string,mixed> $form @param list<array<string,string>> $answers */
    private function notify(int $storeId, array $form, array $answers, int $submissionId): void
    {
        try {
            $to = (string) ($form['notify_email'] ?? '');
            if ($to === '') {
                $to = (string) $this->db->fetchOne("SELECT COALESCE(NULLIF(email,''),NULLIF(privacy_contact,'')) FROM mc_store_profile WHERE store_id=? LIMIT 1", [$storeId]);
            }
            if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                return;
            }
            $lines = [];
            foreach ($answers as $answer) {
                $lines[] = $answer['label'] . ': ' . $answer['value'] . ($answer['type'] === 'file' ? ' ' . CanonicalUiText::get('forms.mail.attachment') : '');
            }
            $subject = CanonicalUiText::get('forms.mail.subject', ['form' => (string) $form['name']]);
            $this->notifications->enqueue(NotificationChannel::Email, new NotificationMessage('form_submission', $subject, implode("\n", $lines), [], 'generic'), $to, null, 'form-submission:' . $submissionId);
        } catch (\Throwable) {
            // The answer is stored; a failed notification must never lose it or fail the visitor's request.
        }
    }
}
