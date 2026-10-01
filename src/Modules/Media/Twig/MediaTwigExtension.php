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
 * {{ media_picture(product.image, 'card', ['card','product'], '48vw', {alt: product.name, width: 640, height: 640, loading: 'lazy'}) }}   the whole <img>, wrapped in <picture> with an AVIF source when the store delivers AVIF
 * Values that are not stored raster pictures (placeholders, SVG, external URLs) pass through unchanged.
 */
final class MediaTwigExtension extends AbstractExtension
{
    public function __construct(private readonly MediaVariantService $variants)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('media_attrs', $this->attrs(...), ['is_safe' => ['html']]),
            new TwigFunction('media_picture', $this->picture(...), ['is_safe' => ['html']]),
        ];
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

    /**
     * A complete <img> for a stored picture. When the store delivers AVIF (with a WebP fallback) the image is wrapped in a
     * <picture> whose first source is the AVIF file; a browser without AVIF support simply uses the <img>.
     *
     * @param list<string> $set
     * @param array<string,scalar|null> $attributes extra attributes (alt, width, height, loading, class …); true prints the bare name
     */
    public function picture(mixed $url, string $preset, array $set = [], string $sizes = '', array $attributes = []): string
    {
        $url = (string) $url;
        $img = '<img ' . $this->attrs($url, $preset, $set, $sizes);
        foreach ($attributes as $name => $value) {
            $name = (string) $name;
            if ($value === null || $value === false || preg_match('~^[a-z][a-z0-9\-]*$~', $name) !== 1 || str_starts_with($name, 'on')) {
                continue;
            }
            $img .= $value === true ? ' ' . $name : ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        $img .= '>';
        if (!$this->variants->avifEnabled() || $this->variants->url($url, $preset) === $url) {
            return $img;
        }
        $avif = $set === [] ? $this->variants->url($url, $preset, 'avif') : $this->variants->srcset($url, array_values(array_map('strval', $set)), 'avif');
        if ($avif === '') {
            return $img;
        }
        $source = '<source type="image/avif" srcset="' . htmlspecialchars($avif, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if ($set !== [] && $sizes !== '') {
            $source .= ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return '<picture>' . $source . '>' . $img . '</picture>';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('media_variant', fn (mixed $url, string $preset): string => $this->variants->url((string) $url, $preset)),
            new TwigFilter('media_srcset', fn (mixed $url, array $presets): string => $this->variants->srcset((string) $url, array_values(array_map('strval', $presets)))),
        ];
    }
}
