<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use RuntimeException;

final class ExtensionSettingsSchemaValidator
{
    /** @return array{schema_version:int,fields:list<array<string,mixed>>} */
    public function decodeAndValidate(string $raw): array
    {
        if (trim($raw) === '' || strlen($raw) > 262144) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.dd5ab468cac2'));
        }
        try {
            $schema = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.285aa5e2b731') . $e->getMessage(), 0, $e);
        }
        if (!is_array($schema) || (int) ($schema['schema_version'] ?? 0) !== 2) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a4b028496a1c'));
        }
        $schemaVersion = (int) $schema['schema_version'];
        $fields = $schema['fields'] ?? [];
        if (!is_array($fields) || count($fields) > 64) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.45c0d7777500'));
        }

        $seen = [];
        $allowedTypes = ['text','textarea','number','integer','boolean','select','url','email','secret','color','range','multilingual_text','multilingual_textarea'];
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f0f0bcb5e8cf'));
            }
            $allowedKeys = ['key','type','label','help','required','default','min','max','step','options','group','placeholder','advanced','help_url','example'];
            foreach (array_keys($field) as $fieldProperty) {
                if (!in_array((string) $fieldProperty, $allowedKeys, true)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.07b0b1fa39e5') . (string) $fieldProperty . '.');
                }
            }
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? '');
            $label = trim((string) ($field['label'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_.-]{0,95}$/D', $key) !== 1 || isset($seen[$key])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2c8ac54616d5'));
            }
            if (!in_array($type, $allowedTypes, true)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.19401c6c62b4') . $type);
            }
            if ($label === '' || mb_strlen($label, 'UTF-8') > 190) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7d7b7b3729cf'));
            }
            $help = trim((string) ($field['help'] ?? ''));
            if (mb_strlen($help, 'UTF-8') > 1000) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8dcad062b7df'));
            }
            $normalizedField = [
                'key' => $key,
                'type' => $type,
                'label' => $label,
                'help' => $help,
                'required' => (bool) ($field['required'] ?? false),
            ];
            if (array_key_exists('default', $field)) {
                $normalizedField['default'] = $field['default'];
            }
            if (array_key_exists('min', $field)) {
                if (!is_int($field['min']) && !is_float($field['min'])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d3c74ec2b1ff'));
                }
                $normalizedField['min'] = $field['min'];
            }
            if (array_key_exists('step', $field)) {
                if (!is_int($field['step']) && !is_float($field['step'])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6be5200a7339'));
                }
                $normalizedField['step'] = $field['step'];
            }
            $group = trim((string) ($field['group'] ?? 'general'));
            $normalizedField['group'] = preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $group) === 1 ? $group : 'general';
            $normalizedField['placeholder'] = mb_substr(trim((string) ($field['placeholder'] ?? '')), 0, 190, 'UTF-8');
            $normalizedField['advanced'] = (bool) ($field['advanced'] ?? false);
            // Optional "where to get it" link (https only) and a recommended value/example shown under the field.
            $helpUrl = trim((string) ($field['help_url'] ?? ''));
            $normalizedField['help_url'] = $helpUrl !== '' && mb_strlen($helpUrl, 'UTF-8') <= 500 && preg_match('#^https://[^\s"<>]+$#', $helpUrl) === 1 ? $helpUrl : '';
            $normalizedField['example'] = mb_substr(trim((string) ($field['example'] ?? '')), 0, 190, 'UTF-8');
            if (array_key_exists('max', $field)) {
                if (!is_int($field['max']) && !is_float($field['max'])) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9a297b3a7e4f'));
                }
                $normalizedField['max'] = $field['max'];
            }
            if (isset($normalizedField['min'], $normalizedField['max']) && (float) $normalizedField['min'] > (float) $normalizedField['max']) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.83bd28ad9c61'));
            }

            if ($type === 'select') {
                $options = $field['options'] ?? null;
                if (!is_array($options) || $options === [] || count($options) > 100) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8e006ee559f6'));
                }
                $optionValues = [];
                $normalizedOptions = [];
                foreach ($options as $option) {
                    if (!is_array($option) || array_diff(array_keys($option), ['value','label']) !== []) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.90dd4d3e0007'));
                    }
                    $value = $option['value'] ?? null;
                    $optionLabel = trim((string) ($option['label'] ?? ''));
                    if ((!is_string($value) && !is_int($value)) || strlen((string) $value) > 190 || $optionLabel === '' || mb_strlen($optionLabel, 'UTF-8') > 190) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b042c13096e6'));
                    }
                    $valueKey = get_debug_type($value) . ':' . (string) $value;
                    if (isset($optionValues[$valueKey])) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cedb9e582427'));
                    }
                    $optionValues[$valueKey] = true;
                    $normalizedOptions[] = ['value' => $value, 'label' => $optionLabel];
                }
                $normalizedField['options'] = $normalizedOptions;
            } elseif (isset($field['options'])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fb11870d03ae'));
            }

            $this->assertDefault($normalizedField);
            $seen[$key] = true;
            $normalized[] = $normalizedField;
        }

        return ['schema_version' => $schemaVersion, 'fields' => $normalized];
    }

    /** @param array<string,mixed> $field */
    private function assertDefault(array $field): void
    {
        if (!array_key_exists('default', $field)) {
            return;
        }
        $default = $field['default'];
        $type = (string) $field['type'];
        if ($type === 'secret' && $default !== '' && $default !== null) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.68df681c0ff3'));
        }
        if ($type === 'boolean' && !is_bool($default)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ea32c26fd3f1'));
        }
        if ($type === 'integer' && !is_int($default)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4fd25fc20225'));
        }
        if ($type === 'number' && !is_int($default) && !is_float($default)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2131a61dea7a'));
        }
        if (in_array($type, ['text','textarea','url','email','select','color','range','multilingual_text','multilingual_textarea'], true) && !is_string($default) && !is_int($default) && !is_float($default) && !is_array($default)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.870a282dd16a'));
        }
        if ($type === 'select') {
            $valid = false;
            foreach ((array) ($field['options'] ?? []) as $option) {
                if (($option['value'] ?? null) === $default) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.719e8516af04'));
            }
        }
    }
}
