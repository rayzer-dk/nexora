<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Twig;

use Commerce\Modules\Media\Application\MediaVariantService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * {{ product.image|media_variant('card') }}                 URL of the named size
 * {{ product.image|media_srcset(['thumb','card','product']) }}   srcset with the width of each size
 * <img {{ media_attrs(product.image, 'card', ['card','product'], '(min-width:1024px) 25vw, 50vw') }} …>   src, srcset and sizes in one go
 * Values that are not stored raster pictures (placeholders, SVG, external URLs) pass through unchanged.
 */
final class MediaTwigExtension extends AbstractExtension
{
    public function __construct(private readonly MediaVariantService $variants)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('media_attrs', $this->attrs(...), ['is_safe' => ['html']])];
    }

    /**
     * The src (and, with several sizes, srcset and sizes) attributes of a stored picture.
     *
     * @param list<string> $set
     */
    public function attrs(mixed $url, string $preset, array $set = [], string $sizes = ''): string
    {
        $url = (string) $url;
        $out = 'src="' . htmlspecialchars($this->variants->url($url, $preset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        $srcset = $set === [] ? '' : $this->variants->srcset($url, array_values(array_map('strval', $set)));
        if ($srcset !== '') {
            $out .= ' srcset="' . htmlspecialchars($srcset, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            if ($sizes !== '') {
                $out .= ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }

        return $out;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('media_variant', fn (mixed $url, string $preset): string => $this->variants->url((string) $url, $preset)),
            new TwigFilter('media_srcset', fn (mixed $url, array $presets): string => $this->variants->srcset((string) $url, array_values(array_map('strval', $presets)))),
        ];
    }
}
