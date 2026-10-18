<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Module blocks placed by the builder on pages whose layout services know only the built-in sections (category, cart),
 * and the storefront-language text of a block value that the builder saved per language.
 */
final class BuilderBlockTwigExtension extends AbstractExtension
{
    private ?StorefrontContext $context = null;

    private ?string $defaultLocale = null;

    public function __construct(
        private readonly LayoutRevisionStore $layouts,
        private readonly ExtensionContributionRegistry $extensions,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
        private readonly Connection $db,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('layout_extension_blocks', [$this, 'extensionBlocks'])];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('builder_text', [$this, 'text'])];
    }

    /** @return list<array<string,mixed>> enabled blocks of active modules in the layout of the page type, in layout order */
    public function extensionBlocks(string $layoutType): array
    {
        $context = $this->context();
        if ($context === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($this->layouts->active($context->storeId, $layoutType)['blocks'] ?? []) as $block) {
            if (is_array($block) && ($block['enabled'] ?? true) !== false && $this->extensions->hasBuilderComponent((string) ($block['component'] ?? ''), $layoutType)) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /** A per-language value ({locale: text}) in the storefront language, else the store default, else any text. */
    public function text(mixed $value): string
    {
        if (!is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }
        $context = $this->context();
        foreach ([$context?->locale, $context === null ? null : $this->defaultLocale($context->storeId)] as $locale) {
            if ($locale !== null && is_scalar($value[$locale] ?? null) && (string) $value[$locale] !== '') {
                return (string) $value[$locale];
            }
        }
        foreach ($value as $text) {
            if (is_scalar($text) && (string) $text !== '') {
                return (string) $text;
            }
        }

        return '';
    }

    private function context(): ?StorefrontContext
    {
        $request = $this->requests->getMainRequest();

        return $request === null ? null : $this->context ??= $this->contexts->resolve($request);
    }

    private function defaultLocale(int $storeId): string
    {
        return $this->defaultLocale ??= (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);
    }
}
