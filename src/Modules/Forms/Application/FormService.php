<?php

declare(strict_types=1);

namespace Commerce\Modules\Forms\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Form builder: the merchant defines fields, visitors submit, the merchant reads the answers in the admin.
 * A form is stored with its own language; answers keep the label they were given under, so later edits of the
 * form never rewrite history.
 */
final class FormService
{
    public const TYPES = ['text', 'email', 'tel', 'textarea', 'number', 'date', 'select', 'radio', 'checkbox'];
    public const MAX_FIELDS = 30;
    private const MAX_OPTIONS = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly NotificationOutbox $notifications,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
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
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => $now,
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
        $this->db->delete('mc_form', ['id' => $id, 'store_id' => $storeId]);
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $input
     * @return int submission id
     * @throws \DomainException  message is a translated error text for the visitor
     */
    public function submit(int $storeId, array $form, array $input, string $ip, string $locale): int
    {
        $answers = [];
        $missing = false;
        $size = 0;
        foreach ((array) $form['fields'] as $field) {
            $key = (string) $field['key'];
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
            $answers[] = ['key' => $key, 'label' => (string) $field['label'], 'type' => $type, 'value' => $type === 'checkbox' ? '✓' : $value];
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
            $fields[] = ['key' => $key, 'type' => $type, 'label' => $label, 'required' => !empty($row['required']), 'placeholder' => $this->text($row['placeholder'] ?? '', 120), 'options' => $options];
        }

        return $fields;
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
                $lines[] = $answer['label'] . ': ' . $answer['value'];
            }
            $subject = CanonicalUiText::get('forms.mail.subject', ['form' => (string) $form['name']]);
            $this->notifications->enqueue(NotificationChannel::Email, new NotificationMessage('form_submission', $subject, implode("\n", $lines), [], 'generic'), $to, null, 'form-submission:' . $submissionId);
        } catch (\Throwable) {
            // The answer is stored; a failed notification must never lose it or fail the visitor's request.
        }
    }
}
