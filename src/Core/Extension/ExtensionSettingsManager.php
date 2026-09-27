<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Core\Security\SecretVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Throwable;

final readonly class ExtensionSettingsManager
{
    public function __construct(
        private Connection $connection,
        private ExtensionSettingsSchemaValidator $schemas,
        private SecretVault $vault,
    ) {
    }

    /** @return array{extension:array<string,mixed>,fields:list<array<string,mixed>>,revisions:list<array<string,mixed>>} */
    public function editor(int $installationId): array
    {
        $extension = $this->extension($installationId);
        $schema = $this->schema($extension);
        $stored = $this->stored($installationId);
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
        return ['extension' => $extension, 'fields' => $fields, 'revisions' => $revisions];
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
        return $this->schemas->decodeAndValidate((string) file_get_contents($path));
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
            default => '',
        };
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
