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
    /** Lists the owner can grow and shrink: the most items each may hold. */
    private const LISTS = ['features' => 12, 'stats' => 8, 'steps' => 8, 'reviews' => 12, 'faq' => 20];
    /** Sections of the page in their default order; the owner reorders and hides them. */
    public const SECTION_KEYS = ['hero', 'stats', 'features', 'steps', 'reviews', 'faq', 'custom1', 'custom2', 'custom3', 'cta', 'form_callback', 'form_quote', 'form_consult'];
    private const HIDDEN_BY_DEFAULT = ['custom1', 'custom2', 'custom3', 'form_callback', 'form_quote', 'form_consult'];
    /** Sections that take the alternating tinted background. */
    private const TINTABLE = ['features', 'steps', 'reviews', 'faq', 'custom1', 'custom2', 'custom3', 'form_callback', 'form_quote', 'form_consult'];
    public const STYLE_KEYS = ['accent', 'bg', 'text', 'tint', 'hero_bg'];
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
            return $this->finish($defaults);
        }
        $savedForms = is_array($saved['forms'] ?? null) ? $saved['forms'] : [];
        unset($saved['forms']);
        // Lists, sections, own blocks and colours are taken as saved (a list may be shorter or longer than the default one).
        $lists = [];
        foreach (array_keys(self::LISTS) as $group) {
            if (is_array($saved[$group] ?? null)) {
                $lists[$group] = array_values(array_filter($saved[$group], 'is_array'));
            }
            unset($saved[$group]);
        }
        $savedSections = is_array($saved['sections'] ?? null) ? $saved['sections'] : null;
        $savedCustom = is_array($saved['custom'] ?? null) ? array_values($saved['custom']) : null;
        $savedStyle = is_array($saved['style'] ?? null) ? $saved['style'] : [];
        unset($saved['sections'], $saved['custom'], $saved['style']);
        $content = $this->merge($defaults, $saved);
        foreach ($lists as $group => $rows) {
            $shape = array_fill_keys(array_keys((array) $defaults[$group][0]), '');
            $content[$group] = array_map(static fn (array $row): array => array_replace($shape, array_intersect_key($row, $shape)), $rows);
        }
        if ($savedCustom !== null) {
            foreach ($content['custom'] as $i => $slot) {
                if (is_array($savedCustom[$i] ?? null)) {
                    $content['custom'][$i] = array_replace($slot, array_intersect_key($savedCustom[$i], $slot));
                }
            }
        }
        foreach (self::STYLE_KEYS as $key) {
            $content['style'][$key] = is_string($savedStyle[$key] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $savedStyle[$key]) === 1 ? $savedStyle[$key] : '';
        }
        if ($savedSections !== null) {
            $content['sections'] = $this->orderSections($savedSections);
        }
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

        return $this->finish($content);
    }

    /**
     * Section order as saved: known keys only, each once; sections added by a later release join at the end with their default visibility.
     *
     * @param array<int,mixed> $saved
     * @return list<array{key:string,visible:bool}>
     */
    private function orderSections(array $saved): array
    {
        $out = [];
        $seen = [];
        foreach ($saved as $row) {
            $key = is_array($row) ? (string) ($row['key'] ?? '') : '';
            if (!in_array($key, self::SECTION_KEYS, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['key' => $key, 'visible' => (bool) ($row['visible'] ?? true)];
        }
        foreach (self::SECTION_KEYS as $key) {
            if (!isset($seen[$key])) {
                $out[] = ['key' => $key, 'visible' => !in_array($key, self::HIDDEN_BY_DEFAULT, true)];
            }
        }

        return $out;
    }

    /**
     * Adds what the page needs to draw itself: which sections are tinted (every second one of the tintable kind) and which are empty.
     *
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private function finish(array $content): array
    {
        $tint = false;
        foreach ($content['sections'] as $i => $section) {
            $key = (string) $section['key'];
            $empty = match ($key) {
                'stats' => $content['stats'] === [],
                'features' => $content['features'] === [],
                'steps' => $content['steps'] === [],
                'reviews' => $content['reviews'] === [],
                'faq' => $content['faq'] === [],
                'custom1' => trim((string) $content['custom'][0]['title'] . (string) $content['custom'][0]['text'] . (string) $content['custom'][0]['image']) === '',
                'custom2' => trim((string) $content['custom'][1]['title'] . (string) $content['custom'][1]['text'] . (string) $content['custom'][1]['image']) === '',
                'custom3' => trim((string) $content['custom'][2]['title'] . (string) $content['custom'][2]['text'] . (string) $content['custom'][2]['image']) === '',
                'form_callback' => !$content['forms'][1]['enabled'],
                'form_quote' => !$content['forms'][2]['enabled'],
                'form_consult' => !$content['forms'][3]['enabled'],
                default => false,
            };
            $show = (bool) $section['visible'] && !$empty;
            $content['sections'][$i]['show'] = $show;
            $content['sections'][$i]['tint'] = false;
            if ($show && in_array($key, self::TINTABLE, true)) {
                $tint = !$tint;
                $content['sections'][$i]['tint'] = $tint;
            }
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
        foreach (self::LISTS as $group => $max) {
            $rows = [];
            foreach (array_values(is_array($input[$group] ?? null) ? $input[$group] : []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $item = [];
                $any = false;
                foreach (array_keys((array) $defaults[$group][0]) as $field) {
                    $value = $this->text($row[$field] ?? '', in_array($field, ['text', 'quote', 'a'], true) ? 500 : 200);
                    if ($field === 'icon') {
                        $value = in_array($value, self::FEATURE_ICONS, true) ? $value : 'zap';
                    } else {
                        $any = $any || $value !== '';
                    }
                    $item[$field] = $value;
                }
                if ($any) {
                    $rows[] = $item;
                }
                if (count($rows) >= $max) {
                    break;
                }
            }
            $clean[$group] = $rows;
        }
        $order = [];
        foreach ((array) ($input['sections'] ?? []) as $key) {
            $order[] = ['key' => (string) $key, 'visible' => !empty($input['section_visible'][(string) $key])];
        }
        $clean['sections'] = $this->orderSections($order);
        for ($i = 0; $i < 3; ++$i) {
            $row = is_array($input['custom'][$i] ?? null) ? $input['custom'][$i] : [];
            $clean['custom'][$i] = [
                'title' => $this->text($row['title'] ?? '', 200),
                'text' => $this->text($row['text'] ?? '', 1500),
                'image' => $this->safeUrl($row['image'] ?? '', false),
                'button' => $this->text($row['button'] ?? '', 120),
                'url' => $this->safeUrl($row['url'] ?? '', true),
                'side' => ($row['side'] ?? '') === 'left' ? 'left' : 'right',
            ];
        }
        foreach (self::STYLE_KEYS as $key) {
            $value = trim((string) ($input['style'][$key] ?? ''));
            $clean['style'][$key] = !empty($input['style_on'][$key]) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1 ? $value : '';
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
            'custom' => array_fill(0, 3, ['title' => '', 'text' => '', 'image' => '', 'button' => '', 'url' => '', 'side' => 'right']),
            'sections' => array_map(static fn (string $key): array => ['key' => $key, 'visible' => !in_array($key, self::HIDDEN_BY_DEFAULT, true)], self::SECTION_KEYS),
            'style' => array_fill_keys(self::STYLE_KEYS, ''),
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

    /** A link or picture address that cannot run code: a local path, an anchor, https, tel: or mailto: (pictures: a path or https only). */
    private function safeUrl(mixed $value, bool $link): string
    {
        $url = $this->text($value, 500);
        if ($url === '' || preg_match('/[\s<>"\'\\\\]/', $url) === 1) {
            return '';
        }
        $ok = (str_starts_with($url, '/') && !str_starts_with($url, '//')) || str_starts_with($url, 'https://');
        if ($link) {
            $ok = $ok || str_starts_with($url, '#') || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:');
        }

        return $ok ? $url : '';
    }

    private function text(mixed $value, int $max): string
    {
        return is_string($value) ? trim(mb_substr(strip_tags($value), 0, $max, 'UTF-8')) : '';
    }
}
