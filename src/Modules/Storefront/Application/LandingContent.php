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

    public function __construct(private readonly SystemSettingStore $store, private readonly StorefrontUiTranslator $translator)
    {
    }

    /** @return array<string,mixed> */
    public function forLocale(string $locale): array
    {
        $defaults = $this->defaults($locale);
        $saved = ($this->store->getArray(self::KEY) ?? [])[$locale] ?? [];

        return is_array($saved) ? $this->merge($defaults, $saved) : $defaults;
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
        ];
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
