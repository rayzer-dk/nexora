<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Application;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;

final readonly class CheckoutLayoutService
{
    private const ALLOWED = [
        'checkout_contact','checkout_shipping','checkout_company','checkout_comment',
        'checkout_payment','checkout_coupon','checkout_summary','checkout_consent',
    ];
    private const REQUIRED = ['checkout_contact','checkout_shipping','checkout_payment','checkout_summary','checkout_consent'];

    public function __construct(private LayoutRevisionStore $layouts, private ExtensionContributionRegistry $extensions) {}

    /** @return array{blocks:list<array<string,mixed>>,requirements:array<string,mixed>} */
    public function active(int $storeId): array
    {
        $layout = $this->layouts->publishedOrNull($storeId, 'checkout') ?? $this->layouts->active($storeId, 'checkout');
        $blocks = [];
        $seen = [];
        foreach ((array) ($layout['blocks'] ?? []) as $index => $block) {
            if (!is_array($block)) continue;
            $component = (string) ($block['component'] ?? '');
            $isExtension = $this->extensions->hasBuilderComponent($component, 'checkout');
            if ((!in_array($component, self::ALLOWED, true) && !$isExtension) || isset($seen[$component])) continue;
            if (($block['enabled'] ?? true) === false && !in_array($component, self::REQUIRED, true)) continue;
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $visibility = is_array($block['visibility'] ?? null) ? $block['visibility'] : [];
            $requiredComponent = in_array($component, self::REQUIRED, true);
            $blocks[] = [
                'id' => (string) ($block['id'] ?? $component),
                'component' => $component,
                'order' => $index * 10,
                'props' => $isExtension ? $this->normalizeExtensionProps($props) : $this->normalizeProps($component, $props),
                'visibility' => [
                    'desktop' => $requiredComponent ? true : (($visibility['desktop'] ?? true) !== false),
                    'tablet' => $requiredComponent ? true : (($visibility['tablet'] ?? true) !== false),
                    'mobile' => $requiredComponent ? true : (($visibility['mobile'] ?? true) !== false),
                ],
            ];
            $seen[$component] = true;
        }
        foreach (self::REQUIRED as $required) {
            if (isset($seen[$required])) continue;
            $blocks[] = ['id'=>$required,'component'=>$required,'order'=>count($blocks)*10,'props'=>$this->normalizeProps($required, []),'visibility'=>['desktop'=>true,'tablet'=>true,'mobile'=>true]];
        }
        usort($blocks, static fn(array $a, array $b): int => ((int)$a['order']) <=> ((int)$b['order']));

        $contact = $this->propsFor($blocks, 'checkout_contact');
        return ['blocks'=>$blocks,'requirements'=>[
            'show_name'=>(bool)($contact['show_name'] ?? true),
            'require_name'=>(bool)($contact['require_name'] ?? true),
            'show_phone'=>(bool)($contact['show_phone'] ?? true),
            'require_phone'=>(bool)($contact['require_phone'] ?? true),
            'show_email'=>(bool)($contact['show_email'] ?? true),
            'require_email'=>(bool)($contact['require_email'] ?? false),
            'show_company'=>$this->has($blocks,'checkout_company'),
            'show_comment'=>$this->has($blocks,'checkout_comment'),
            'show_coupon'=>$this->has($blocks,'checkout_coupon'),
        ]];
    }

    /** @param list<array<string,mixed>> $blocks @return array<string,mixed> */
    private function propsFor(array $blocks, string $component): array
    {
        foreach ($blocks as $block) if (($block['component'] ?? '') === $component) return (array)($block['props'] ?? []);
        return [];
    }

    /** @param list<array<string,mixed>> $blocks */
    private function has(array $blocks, string $component): bool
    {
        foreach ($blocks as $block) if (($block['component'] ?? '') === $component) return true;
        return false;
    }


    /** @param array<string,mixed> $props @return array<string,mixed> */
    private function normalizeExtensionProps(array $props): array
    {
        if (!isset($props['placement']) || !in_array((string) $props['placement'], ['left','right','full'], true)) {
            $props['placement'] = 'left';
        }
        return $props;
    }

    /** @param array<string,mixed> $props @return array<string,mixed> */
    private function normalizeProps(string $component, array $props): array
    {
        $out = [];
        if (isset($props['placement']) && in_array((string)$props['placement'], ['left','right','full'], true)) $out['placement'] = (string)$props['placement'];
        foreach (['title','subtitle','placeholder','button_label'] as $key) {
            if (isset($props[$key]) && is_scalar($props[$key])) $out[$key] = mb_substr(trim((string)$props[$key]), 0, 500, 'UTF-8');
        }
        foreach (['show_name','require_name','show_phone','require_phone','show_email','require_email','show_tax_id','require_tax_id','required'] as $key) {
            if (array_key_exists($key, $props)) $out[$key] = filter_var($props[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool)$props[$key];
        }
        if (!isset($out['placement'])) $out['placement'] = $component === 'checkout_summary' ? 'right' : 'left';
        if ($component === 'checkout_contact') {
            $out += ['show_name'=>true,'require_name'=>true,'show_phone'=>true,'require_phone'=>true,'show_email'=>true,'require_email'=>false];
            if (!($out['show_phone'] ?? false) && !($out['show_email'] ?? false)) $out['show_phone'] = true;
            if (($out['require_phone'] ?? false) && !($out['show_phone'] ?? false)) $out['require_phone'] = false;
            if (($out['require_email'] ?? false) && !($out['show_email'] ?? false)) $out['require_email'] = false;
        }
        return $out;
    }
}
