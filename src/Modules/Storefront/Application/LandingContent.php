<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Core\I18n\StorefrontUiTranslator;

/**
 * Text and links of the landing page. Every locale starts from the translated defaults and the owner overrides
 * any field in the admin; an untouched field keeps following the default of its language.
 */
final class LandingContent
{
    public const LOCALES = ['uk-UA', 'en-US', 'ru-RU', 'de-DE', 'pl-PL', 'da-DK'];
    public const FEATURE_ICONS = ['zap', 'shield-check', 'smartphone', 'palette', 'trending-up', 'headset', 'sparkles', 'target', 'layers', 'globe', 'clock', 'heart', 'users', 'lock', 'credit-card', 'star'];
    private const KEY = 'landing.content';
    private const COUNTS = ['features' => 6, 'stats' => 4, 'steps' => 3, 'reviews' => 3, 'faq' => 5];
    /** The request forms of the page: "main" sits in the contact section, the others open as a window from any link to #form-callback, #form-quote or #form-consult. */
    public const FORM_KEYS = ['main', 'callback', 'quote', 'consult'];
    public const FIELD_TYPES = ['name', 'phone', 'email', 'message', 'text', 'select', 'checkbox'];
    public const FIELD_SLOTS = 6;

    public function __construct(private readonly SystemSettingStore $store, private readonly StorefrontUiTranslator $translator)
    {
    }

    /** @return array<string,mixed> */
    public function forLocale(string $locale): array
    {
        $defaults = $this->defaults($locale);
        $saved = ($this->store->getArray(self::KEY) ?? [])[$locale] ?? [];

        if (!is_array($saved)) {
            return $defaults;
        }
        $savedForms = is_array($saved['forms'] ?? null) ? $saved['forms'] : [];
        unset($saved['forms']);
        $content = $this->merge($defaults, $saved);
        // A form keeps exactly the fields the owner left (an empty slot is a removed field); only empty texts follow the language default.
        foreach ($content['forms'] as $i => $form) {
            $row = $savedForms[$i] ?? null;
            if (!is_array($row) || !is_array($row['fields'] ?? null)) {
                continue;
            }
            foreach (['title', 'text', 'button'] as $field) {
                if (is_string($row[$field] ?? null) && $row[$field] !== '') {
                    $content['forms'][$i][$field] = $row[$field];
                }
            }
            $content['forms'][$i]['enabled'] = (bool) ($row['enabled'] ?? true);
            $content['forms'][$i]['fields'] = array_pad(array_slice(array_values($row['fields']), 0, self::FIELD_SLOTS), self::FIELD_SLOTS, ['type' => '', 'label' => '', 'required' => false, 'options' => '']);
        }

        return $content;
    }

    /** @param array<string,mixed> $input */
    public function save(string $locale, array $input): void
    {
        $all = $this->store->getArray(self::KEY) ?? [];
        $defaults = $this->defaults($locale);
        $clean = [];
        foreach (['hero', 'headings', 'cta', 'form'] as $group) {
            foreach (array_keys((array) $defaults[$group]) as $field) {
                $clean[$group][$field] = $this->text($input[$group][$field] ?? '', $field === 'subtitle' || $field === 'text' ? 400 : 200);
            }
        }
        $clean['form']['enabled'] = !empty($input['form']['enabled']);
        foreach (self::COUNTS as $group => $count) {
            for ($i = 0; $i < $count; ++$i) {
                foreach (array_keys((array) $defaults[$group][$i]) as $field) {
                    $value = $this->text($input[$group][$i][$field] ?? '', in_array($field, ['text', 'quote', 'a'], true) ? 500 : 200);
                    $clean[$group][$i][$field] = $field === 'icon' && !in_array($value, self::FEATURE_ICONS, true) ? $defaults[$group][$i]['icon'] : $value;
                }
            }
        }
        foreach (self::FORM_KEYS as $i => $key) {
            $row = $input['forms'][$i] ?? [];
            $entry = ['key' => $key, 'enabled' => !empty($row['enabled']), 'title' => $this->text($row['title'] ?? '', 200), 'text' => $this->text($row['text'] ?? '', 400), 'button' => $this->text($row['button'] ?? '', 120), 'fields' => []];
            $hasContact = false;
            for ($f = 0; $f < self::FIELD_SLOTS; ++$f) {
                $type = (string) ($row['fields'][$f]['type'] ?? '');
                $type = in_array($type, self::FIELD_TYPES, true) ? $type : '';
                $hasContact = $hasContact || in_array($type, ['phone', 'email'], true);
                $entry['fields'][$f] = ['type' => $type, 'label' => $this->text($row['fields'][$f]['label'] ?? '', 120), 'required' => !empty($row['fields'][$f]['required']), 'options' => $this->text($row['fields'][$f]['options'] ?? '', 400)];
            }
            if (!$hasContact) {
                // A request without a way to answer is useless: the first free slot becomes a phone field.
                foreach ($entry['fields'] as $f => $field) {
                    if ($field['type'] === '') {
                        $entry['fields'][$f] = ['type' => 'phone', 'label' => $this->translator->translate('landing.field.phone', $locale), 'required' => true, 'options' => ''];
                        break;
                    }
                }
            }
            $clean['forms'][$i] = $entry;
        }
        $all[$locale] = $clean;
        $this->store->setArray(self::KEY, $all);
    }

    public function reset(string $locale): void
    {
        $all = $this->store->getArray(self::KEY) ?? [];
        unset($all[$locale]);
        $this->store->setArray(self::KEY, $all);
    }

    /** @return array<string,mixed> */
    public function defaults(string $locale): array
    {
        $line = fn (string $key): string => $this->translator->translate('landing.' . $key, $locale);
        $list = static function (int $count, callable $row): array {
            $out = [];
            for ($i = 1; $i <= $count; ++$i) {
                $out[] = $row($i);
            }

            return $out;
        };
        $icons = ['zap', 'shield-check', 'smartphone', 'palette', 'trending-up', 'headset'];

        return [
            'hero' => ['eyebrow' => $line('hero.eyebrow'), 'title' => $line('hero.title'), 'subtitle' => $line('hero.subtitle'), 'primary_label' => $line('hero.primary'), 'primary_url' => '#contact', 'secondary_label' => $line('hero.secondary'), 'secondary_url' => '#how', 'note' => $line('hero.note'), 'image' => ''],
            'headings' => ['features' => $line('h.features'), 'features_lead' => $line('h.features_lead'), 'steps' => $line('h.steps'), 'reviews' => $line('h.reviews'), 'faq' => $line('h.faq')],
            'features' => $list(6, fn (int $i): array => ['icon' => $icons[$i - 1], 'title' => $line("feature.{$i}.title"), 'text' => $line("feature.$i.text")]),
            'stats' => $list(4, fn (int $i): array => ['value' => $line("stat.$i.value"), 'label' => $line("stat.$i.label")]),
            'steps' => $list(3, fn (int $i): array => ['title' => $line("step.$i.title"), 'text' => $line("step.$i.text")]),
            'reviews' => $list(3, fn (int $i): array => ['quote' => $line("review.$i.quote"), 'name' => $line("review.$i.name"), 'role' => $line("review.$i.role")]),
            'faq' => $list(5, fn (int $i): array => ['q' => $line("faq.$i.q"), 'a' => $line("faq.$i.a")]),
            'cta' => ['title' => $line('cta.title'), 'text' => $line('cta.text'), 'button' => $line('cta.button')],
            'form' => ['title' => $line('form.title'), 'text' => $line('form.text'), 'button' => $line('form.button'), 'enabled' => true],
            'forms' => $this->defaultForms($line),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function defaultForms(callable $line): array
    {
        $field = static fn (string $type, string $label, bool $required, string $options = ''): array => ['type' => $type, 'label' => $label, 'required' => $required, 'options' => $options];
        $none = $field('', '', false);
        $forms = [
            'main' => [[$field('name', $line('field.name'), true), $field('phone', $line('field.phone'), true), $field('message', $line('field.message'), false)], $line('form.title'), $line('form.text'), $line('form.button')],
            'callback' => [[$field('name', $line('field.name'), true), $field('phone', $line('field.phone'), true)], $line('forms.callback.title'), $line('forms.callback.text'), $line('forms.callback.button')],
            'quote' => [[$field('name', $line('field.name'), true), $field('phone', $line('field.phone'), true), $field('email', $line('field.email'), false), $field('message', $line('field.quote_details'), false)], $line('forms.quote.title'), $line('forms.quote.text'), $line('forms.quote.button')],
            'consult' => [[$field('name', $line('field.name'), true), $field('phone', $line('field.phone'), true), $field('select', $line('field.topic'), false, $line('field.topic_options')), $field('message', $line('field.message'), false)], $line('forms.consult.title'), $line('forms.consult.text'), $line('forms.consult.button')],
        ];
        $out = [];
        foreach (self::FORM_KEYS as $key) {
            [$fields, $title, $text, $button] = $forms[$key];
            $fields = array_pad($fields, self::FIELD_SLOTS, $none);
            $out[] = ['key' => $key, 'enabled' => true, 'title' => $title, 'text' => $text, 'button' => $button, 'fields' => $fields];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $saved
     * @return array<string,mixed>
     */
    private function merge(array $defaults, array $saved): array
    {
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $saved)) {
                continue;
            }
            if (is_array($value) && is_array($saved[$key])) {
                $defaults[$key] = $this->merge($value, $saved[$key]);
            } elseif (is_bool($value)) {
                $defaults[$key] = (bool) $saved[$key];
            } elseif (is_string($saved[$key]) && $saved[$key] !== '') {
                $defaults[$key] = $saved[$key];
            }
        }

        return $defaults;
    }

    private function text(mixed $value, int $max): string
    {
        return is_string($value) ? trim(mb_substr(strip_tags($value), 0, $max, 'UTF-8')) : '';
    }
}
