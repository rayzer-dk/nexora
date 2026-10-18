<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Core\Security\SecretVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final readonly class ExtensionSettingsManager
{
    /**
     * Parsed settings schemas by file (path, size, mtime): a module that reads its settings often parses the file once.
     *
     * @var \ArrayObject<string,array{schema_version:int,fields:list<array<string,mixed>>}>
     */
    private \ArrayObject $schemaCache;

    public function __construct(
        private Connection $connection,
        private ExtensionSettingsSchemaValidator $schemas,
        private SecretVault $vault,
        private ?LoggerInterface $logger = null,
    ) {
        /** @var \ArrayObject<string,array{schema_version:int,fields:list<array<string,mixed>>}> $cache */
        $cache = new \ArrayObject();
        $this->schemaCache = $cache;
    }

    /**
     * Form model of the settings page. `locales` are the languages a multilingual field gets one input for:
     * every language enabled in a store (default languages first) plus any language a saved value already has.
     *
     * @return array{extension:array<string,mixed>,fields:list<array<string,mixed>>,revisions:list<array<string,mixed>>,locales:list<array{code:string,name:string}>}
     */
    public function editor(int $installationId): array
    {
        $extension = $this->extension($installationId);
        $schema = $this->schema($extension);
        $stored = $this->stored($installationId);
        $locales = $this->enabledLocales();
        $fields = [];
        foreach ($schema['fields'] as $field) {
            $key = (string) $field['key'];
            $row = $stored[$key] ?? null;
            $field['has_value'] = is_array($row);
            if ((string) $field['type'] === 'secret') {
                $field['value'] = '';
                $field['masked'] = is_array($row) ? '••••••••••••' : '';
            } elseif (is_array($row)) {
                $field['value'] = $this->decodeValue((string) $row['value_payload']);
            } else {
                $field['value'] = $field['default'] ?? $this->emptyForType((string) $field['type']);
            }
            if ($this->isMultilingual((string) $field['type'])) {
                $field['value'] = $this->localizedMap($field['value'], $locales);
                foreach (array_keys($field['value']) as $code) {
                    if (!in_array($code, array_column($locales, 'code'), true)) {
                        $locales[] = ['code' => $code, 'name' => $code];
                    }
                }
            }
            $fields[] = $field;
        }
        $revisions = $this->connection->fetchAllAssociative(
            'SELECT id,actor,created_at FROM mc_extension_setting_revision WHERE installation_id=? ORDER BY id DESC LIMIT 20',
            [$installationId],
        );
        foreach ($revisions as &$revision) {
            $revision['id'] = (int) $revision['id'];
        }
        unset($revision);
        return ['extension' => $extension, 'fields' => $fields, 'revisions' => $revisions, 'locales' => $locales];
    }

    /** @param array<string,mixed> $input */
    public function save(int $installationId, array $input, string $actor): void
    {
        $extension = $this->extension($installationId);
        if ((string) $extension['status'] === 'quarantined') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.78fb12c0970d'));
        }
        $schema = $this->schema($extension);
        $stored = $this->stored($installationId);
        $next = [];
        foreach ($schema['fields'] as $field) {
            $key = (string) $field['key'];
            $type = (string) $field['type'];
            if ($type === 'secret') {
                $candidate = isset($input[$key]) ? trim((string) $input[$key]) : '';
                if ($candidate === '' && isset($stored[$key])) {
                    $next[$key] = $stored[$key];
                    continue;
                }
                if ($candidate === '' && (bool) ($field['required'] ?? false)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a2df50fd2104') . $field['label'] . '.');
                }
                if ($candidate === '') {
                    continue;
                }
                if (strlen($candidate) > 8192 || str_contains($candidate, "\0")) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1604907ac3a8') . $field['label'] . '.');
                }
                $next[$key] = [
                    'value_payload' => $this->vault->encrypt($candidate, $this->vaultContext((string) $extension['code'], $key)),
                    'is_secret' => 1,
                ];
                continue;
            }
            $raw = $type === 'boolean' ? array_key_exists($key, $input) : ($input[$key] ?? null);
            $value = $this->normalize($field, $raw);
            if ($value === null && (bool) ($field['required'] ?? false)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a2df50fd2104') . $field['label'] . '.');
            }
            if ($value === null) {
                continue;
            }
            $next[$key] = ['value_payload' => $this->encodeValue($value), 'is_secret' => 0];
        }
        // Keys the current schema no longer has (carried over from an older version) are kept for modules that still read them.
        // A secret is not: no form shows it any more, so the owner could never remove an old API key.
        $schemaKeys = array_column($schema['fields'], 'key');
        foreach ($stored as $key => $row) {
            if (!in_array($key, $schemaKeys, true) && (int) $row['is_secret'] !== 1) {
                $next[$key] = $row;
            }
        }

        $this->connection->transactional(function (Connection $db) use ($installationId, $actor, $stored, $next): void {
            $this->insertRevision($db, $installationId, $stored, $actor);
            $db->delete('mc_extension_setting', ['installation_id' => $installationId]);
            $now = $this->now();
            foreach ($next as $key => $row) {
                $db->insert('mc_extension_setting', [
                    'installation_id' => $installationId,
                    'setting_key' => $key,
                    'value_payload' => (string) $row['value_payload'],
                    'is_secret' => (int) $row['is_secret'],
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function rollback(int $installationId, int $revisionId, string $actor): void
    {
        $this->extension($installationId);
        $revision = $this->connection->fetchAssociative(
            'SELECT settings_json FROM mc_extension_setting_revision WHERE id=? AND installation_id=? LIMIT 1',
            [$revisionId, $installationId],
        );
        if (!is_array($revision)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.de9d3bdc8913'));
        }
        try {
            $snapshot = json_decode((string) $revision['settings_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.413a367a2029'), 0, $e);
        }
        if (!is_array($snapshot)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f62f1ddbc434'));
        }
        $current = $this->stored($installationId);
        $this->connection->transactional(function (Connection $db) use ($installationId, $actor, $current, $snapshot): void {
            $this->insertRevision($db, $installationId, $current, $actor . ':pre-rollback');
            $db->delete('mc_extension_setting', ['installation_id' => $installationId]);
            $now = $this->now();
            foreach ($snapshot as $key => $row) {
                if (!is_string($key) || !is_array($row) || !isset($row['value_payload'], $row['is_secret'])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1b890c4256e5'));
                }
                $db->insert('mc_extension_setting', [
                    'installation_id' => $installationId,
                    'setting_key' => $key,
                    'value_payload' => (string) $row['value_payload'],
                    'is_secret' => (int) ((bool) $row['is_secret']),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * Gives a freshly updated version the settings of the newest lower version of the same module. Runs only while the
     * target has never been configured (no values, no revisions), so it never overwrites edits or a version's own
     * settings and never copies "down" on a rollback. Values the new schema still accepts are copied, a value that no
     * longer validates is dropped (the new default applies), fields new in this version keep their defaults, and keys
     * the new schema removed are kept as they were for modules that still read them.
     *
     * @return int number of copied values
     */
    public function carryOver(int $installationId): int
    {
        $target = $this->extension($installationId);
        if ($this->stored($installationId) !== [] || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_extension_setting_revision WHERE installation_id=?', [$installationId]) > 0) {
            return 0;
        }
        // Of the lower versions, the one whose settings were saved last: after 1.0 -> 2.0 -> back to 1.0 and edits there,
        // an update to 2.1 starts from the edited 1.0 settings, not from the older 2.0 ones.
        $source = null;
        foreach ($this->connection->fetchAllAssociative('SELECT e.id,e.version,MAX(s.updated_at) AS last_saved FROM mc_extension_installation e JOIN mc_extension_setting s ON s.installation_id=e.id WHERE e.code=? AND e.id<>? GROUP BY e.id,e.version ORDER BY e.id DESC', [(string) $target['code'], $installationId]) as $candidate) {
            if (!version_compare((string) $candidate['version'], (string) $target['version'], '<')) {
                continue;
            }
            $newer = $source === null
                || strcmp((string) $candidate['last_saved'], (string) $source['last_saved']) > 0
                || ((string) $candidate['last_saved'] === (string) $source['last_saved'] && version_compare((string) $candidate['version'], (string) $source['version'], '>'));
            if ($newer) {
                $source = $candidate;
            }
        }
        if ($source === null) {
            return 0;
        }
        $fields = array_column($this->schema($target)['fields'], null, 'key');
        $next = [];
        foreach ($this->stored((int) $source['id']) as $key => $row) {
            $field = $fields[$key] ?? null;
            if ($field === null) {
                if ((int) $row['is_secret'] !== 1) {
                    $next[$key] = $row; // a secret the new version no longer asks for is not carried along
                }
                continue;
            }
            $isSecret = (string) $field['type'] === 'secret';
            if ($isSecret !== ((int) $row['is_secret'] === 1)) {
                continue;
            }
            if ($isSecret) {
                $next[$key] = $row;
                continue;
            }
            try {
                $value = $this->compatibleValue($field, $this->decodeValue((string) $row['value_payload']));
            } catch (Throwable) {
                continue;
            }
            if ($value !== null) {
                $next[$key] = ['value_payload' => $this->encodeValue($value), 'is_secret' => 0];
            }
        }
        if ($next === []) {
            return 0;
        }
        $this->connection->transactional(function (Connection $db) use ($installationId, $next, $source): void {
            $this->insertRevision($db, $installationId, [], 'system:carry-over from ' . (string) $source['version']);
            $now = $this->now();
            foreach ($next as $key => $row) {
                $db->insert('mc_extension_setting', [
                    'installation_id' => $installationId,
                    'setting_key' => $key,
                    'value_payload' => (string) $row['value_payload'],
                    'is_secret' => (int) $row['is_secret'],
                    'updated_at' => $now,
                ]);
            }
        });
        return count($next);
    }

    /**
     * All settings of the active version of a module as the module sees them: saved values, schema defaults for
     * fields never saved, secrets decrypted. Unknown or inactive modules get an empty array.
     *
     * @return array<string,mixed>
     */
    public function activeSettings(string $extensionCode): array
    {
        try {
            $id = $this->connection->fetchOne("SELECT id FROM mc_extension_installation WHERE code=? AND status='active' ORDER BY id DESC LIMIT 1", [$extensionCode]);
            if ($id === false || $id === null) {
                return [];
            }
            $extension = $this->extension((int) $id);
            $stored = $this->stored((int) $id);
            $out = [];
            foreach ($this->schema($extension)['fields'] as $field) {
                $key = (string) $field['key'];
                $value = isset($stored[$key]) ? $this->readSafely($extensionCode, $key, $stored[$key]) : null;
                if ($value !== null) {
                    $out[$key] = $value;
                } elseif (array_key_exists('default', $field)) {
                    $out[$key] = $field['default'];
                } elseif ((string) $field['type'] === 'boolean') {
                    $out[$key] = false;
                }
                unset($stored[$key]);
            }
            foreach ($stored as $key => $row) {
                if ((int) $row['is_secret'] === 1) {
                    continue; // a secret of a dropped field is never handed out
                }
                $value = $this->readSafely($extensionCode, $key, $row);
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }
            return $out;
        } catch (Throwable) {
            // Optional extension configuration is never allowed to crash Core/storefront.
            return [];
        }
    }

    /** @return mixed */
    public function value(string $extensionCode, string $key, mixed $default = null): mixed
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $extensionCode) !== 1 || preg_match('/^[a-z][a-z0-9_.-]{0,95}$/D', $key) !== 1) {
            return $default;
        }
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT s.value_payload,s.is_secret FROM mc_extension_setting s JOIN mc_extension_installation e ON e.id=s.installation_id WHERE e.code=? AND e.status='active' AND s.setting_key=? ORDER BY e.id DESC LIMIT 1",
                [$extensionCode, $key],
            );
            if (!is_array($row)) {
                return $default;
            }
            if ((int) $row['is_secret'] === 1) {
                return $this->vault->decrypt((string) $row['value_payload'], $this->vaultContext($extensionCode, $key));
            }
            return $this->decodeValue((string) $row['value_payload']);
        } catch (Throwable) {
            // Optional extension configuration is never allowed to crash Core/storefront.
            return $default;
        }
    }

    /** @return array<string,mixed> */
    private function extension(int $installationId): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM mc_extension_installation WHERE id=? LIMIT 1', [$installationId]);
        if (!is_array($row)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8a63c4bcb514'));
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @param array<string,mixed> $extension @return array{schema_version:int,fields:list<array<string,mixed>>} */
    private function schema(array $extension): array
    {
        try {
            $manifest = json_decode((string) $extension['manifest_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.378c18736c46'), 0, $e);
        }
        if (!is_array($manifest) || !isset($manifest['settings_schema'])) {
            return ['schema_version' => 2, 'fields' => []];
        }
        $relative = str_replace('\\', '/', (string) $manifest['settings_schema']);
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.(?:/|$)#', $relative) === 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5ff53c978bd0'));
        }
        $path = rtrim((string) $extension['install_path'], '/\\') . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a16cf4d39720'));
        }
        $cacheKey = $path . '|' . (int) @filesize($path) . '|' . (int) @filemtime($path);
        if (!isset($this->schemaCache[$cacheKey])) {
            $this->schemaCache[$cacheKey] = $this->schemas->decodeAndValidate((string) file_get_contents($path));
        }
        return $this->schemaCache[$cacheKey];
    }

    /** @return array<string,array{value_payload:string,is_secret:int}> */
    private function stored(int $installationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT setting_key,value_payload,is_secret FROM mc_extension_setting WHERE installation_id=? ORDER BY setting_key',
            [$installationId],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['setting_key']] = [
                'value_payload' => (string) $row['value_payload'],
                'is_secret' => (int) $row['is_secret'],
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $field */
    private function normalize(array $field, mixed $raw): mixed
    {
        $type = (string) $field['type'];
        if ($type === 'boolean') {
            return (bool) $raw;
        }
        if ($raw === null) {
            return array_key_exists('default', $field) ? $field['default'] : null;
        }
        if (in_array($type, ['multilingual_text','multilingual_textarea'], true)) {
            return $this->multilingual($field, $raw, $type === 'multilingual_textarea' ? 20000 : 1000);
        }
        if (is_array($raw) || is_object($raw)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6823d2841764') . $field['label'] . '.');
        }
        $string = trim((string) $raw);
        if ($string === '') {
            return array_key_exists('default', $field) ? $field['default'] : null;
        }
        return match ($type) {
            'text', 'textarea' => $this->boundedString($string, $type === 'textarea' ? 20000 : 1000, (string) $field['label']),
            'url' => filter_var($string, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($string), 'https://')
                ? $this->boundedString($string, 2048, (string) $field['label'])
                : throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1d09f4615d49') . $field['label'] . '.'),
            'email' => filter_var($string, FILTER_VALIDATE_EMAIL) !== false
                ? $this->boundedString($string, 320, (string) $field['label'])
                : throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ea077f4b086d') . $field['label'] . '.'),
            'integer' => $this->numeric($field, $string, true),
            'number', 'range' => $this->numeric($field, $string, false),
            'color' => preg_match('/^#[0-9A-Fa-f]{6}$/D', $string) === 1 ? strtoupper($string) : throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.45838ec38acc') . $field['label'] . '.'),
            'select' => $this->select($field, $string),
            default => throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9211e80c9dd0')),
        };
    }


    /** @param array<string,mixed> $field */
    private function multilingual(array $field, mixed $raw, int $max): ?array
    {
        if ($raw === null || $raw === '') {
            return array_key_exists('default', $field) && is_array($field['default']) ? $field['default'] : null;
        }
        if (!is_array($raw) || count($raw) > 50) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.091df0eca279') . $field['label'] . '.');
        }
        $out = [];
        foreach ($raw as $locale => $value) {
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale) !== 1 || !is_scalar($value)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.19160c636146') . $field['label'] . '.');
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $out[$locale] = $this->boundedString($text, $max, (string) $field['label']);
        }
        return $out !== [] ? $out : null;
    }

    /** @param array<string,mixed> $field */
    private function numeric(array $field, string $raw, bool $integer): int|float
    {
        if ($integer) {
            if (preg_match('/^-?\d+$/D', $raw) !== 1) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.da73f10653c7') . $field['label'] . '.');
            }
            $value = (int) $raw;
        } else {
            if (!is_numeric($raw)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6f08a8a0051a') . $field['label'] . '.');
            }
            $value = (float) $raw;
            if (!is_finite($value)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6a141d2f9518') . $field['label'] . '.');
            }
        }
        if (isset($field['min']) && $value < (float) $field['min']) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d9b8d7a89009') . $field['label'] . '.');
        }
        if (isset($field['max']) && $value > (float) $field['max']) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.22943c72d08a') . $field['label'] . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $field */
    private function select(array $field, string $raw): string|int
    {
        foreach ((array) ($field['options'] ?? []) as $option) {
            if ((string) ($option['value'] ?? '') === $raw) {
                return $option['value'];
            }
        }
        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.33f053197868') . $field['label'] . '.');
    }

    private function boundedString(string $value, int $max, string $label): string
    {
        if (mb_strlen($value, 'UTF-8') > $max || str_contains($value, "\0")) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cb35de2329a7') . $label . '.');
        }
        return $value;
    }

    private function encodeValue(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeValue(string $value): mixed
    {
        return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,array{value_payload:string,is_secret:int}> $snapshot */
    private function insertRevision(Connection $db, int $installationId, array $snapshot, string $actor): void
    {
        $db->insert('mc_extension_setting_revision', [
            'installation_id' => $installationId,
            'settings_json' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor' => mb_substr($actor, 0, 190, 'UTF-8'),
            'created_at' => $this->now(),
        ]);
    }

    private function vaultContext(string $extensionCode, string $key): string
    {
        return 'extension:' . $extensionCode . ':setting:' . $key;
    }

    private function emptyForType(string $type): mixed
    {
        return match ($type) {
            'boolean' => false,
            'multilingual_text', 'multilingual_textarea' => [],
            default => '',
        };
    }

    /**
     * One setting of the active version as the module sees it (see activeSettings()), without decrypting the others.
     */
    public function activeSetting(string $extensionCode, string $key, mixed $default = null): mixed
    {
        try {
            $id = $this->connection->fetchOne("SELECT id FROM mc_extension_installation WHERE code=? AND status='active' ORDER BY id DESC LIMIT 1", [$extensionCode]);
            if ($id === false || $id === null) {
                return $default;
            }
            $extension = $this->extension((int) $id);
            $fields = array_column($this->schema($extension)['fields'], null, 'key');
            $row = $this->connection->fetchAssociative('SELECT value_payload,is_secret FROM mc_extension_setting WHERE installation_id=? AND setting_key=?', [(int) $id, $key]);
            if (is_array($row) && (isset($fields[$key]) || (int) $row['is_secret'] !== 1)) {
                $value = $this->readSafely($extensionCode, $key, ['value_payload' => (string) $row['value_payload'], 'is_secret' => (int) $row['is_secret']]);
                if ($value !== null) {
                    return $value;
                }
            }
            $field = $fields[$key] ?? null;
            if ($field !== null && array_key_exists('default', $field)) {
                return $field['default'];
            }
            return $field !== null && (string) $field['type'] === 'boolean' ? false : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * A value that cannot be read (a secret encrypted with a vault key that was replaced since) counts as not set and is
     * written to the log; the other settings of the module are not affected.
     *
     * @param array{value_payload:string,is_secret:int} $row
     */
    private function readSafely(string $extensionCode, string $key, array $row): mixed
    {
        try {
            return $this->readStored($extensionCode, $key, $row);
        } catch (Throwable $e) {
            $this->logger?->warning('Extension setting could not be read.', ['extension' => $extensionCode, 'key' => $key, 'secret' => (int) $row['is_secret'] === 1, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** @param array{value_payload:string,is_secret:int} $row */
    private function readStored(string $extensionCode, string $key, array $row): mixed
    {
        return (int) $row['is_secret'] === 1
            ? $this->vault->decrypt((string) $row['value_payload'], $this->vaultContext($extensionCode, $key))
            : $this->decodeValue((string) $row['value_payload']);
    }

    /**
     * Re-validates a value saved by an older version against the field of the new schema, exactly as a form save
     * would. A text value becomes the first-language value of a field that turned multilingual.
     *
     * @param array<string,mixed> $field
     */
    private function compatibleValue(array $field, mixed $value): mixed
    {
        $type = (string) $field['type'];
        if ($type === 'boolean') {
            return is_bool($value) ? $value : null;
        }
        if ($this->isMultilingual($type)) {
            $map = $this->localizedMap($value, $this->enabledLocales());
            return $map === [] ? null : $this->multilingual($field, $map, $type === 'multilingual_textarea' ? 20000 : 1000);
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        if (trim((string) $value) === '') {
            return null;
        }
        return $this->normalize($field, (string) $value);
    }

    private function isMultilingual(string $type): bool
    {
        return in_array($type, ['multilingual_text', 'multilingual_textarea'], true);
    }

    /**
     * A plain string (a default written as text, or a value saved before the field became multilingual) belongs to
     * the first language of the form.
     *
     * @param list<array{code:string,name:string}> $locales
     * @return array<string,string>
     */
    private function localizedMap(mixed $value, array $locales): array
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $text = trim((string) $value);
            return $text === '' ? [] : [(string) ($locales[0]['code'] ?? 'uk-UA') => $text];
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $code => $text) {
            if (is_string($code) && is_scalar($text)) {
                $out[$code] = (string) $text;
            }
        }
        return $out;
    }

    /** @return list<array{code:string,name:string}> */
    private function enabledLocales(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT l.code,COALESCE(NULLIF(l.native_name,''),l.name,l.code) AS name,MAX(sl.is_default) AS is_default,MIN(sl.sort_order) AS sort_order FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.enabled=1 GROUP BY l.code,l.native_name,l.name ORDER BY is_default DESC,sort_order,l.code",
            );
        } catch (Throwable) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['code' => (string) $row['code'], 'name' => (string) $row['name']];
        }
        return $out !== [] ? $out : [['code' => 'uk-UA', 'name' => 'uk-UA']];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
