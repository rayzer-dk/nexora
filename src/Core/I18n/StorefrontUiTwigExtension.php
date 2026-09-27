<?php

declare(strict_types=1);
namespace Commerce\Core\I18n;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class StorefrontUiTwigExtension extends AbstractExtension
{
    public function __construct(private readonly StorefrontUiTranslator $translator,private readonly RequestStack $requests){}
    public function getFunctions():array{return[new TwigFunction('ui_text',$this->text(...),['needs_context'=>true]),new TwigFunction('ui_catalog',$this->catalog(...),['needs_context'=>true]),new TwigFunction('ui_catalog_json',$this->catalogJson(...),['needs_context'=>true,'is_safe'=>['html']])];}


    /** @param array<string,mixed> $context */
    public function catalogJson(array $context, array $prefixes = []): string
    {
        $catalog = $this->catalog($context);
        if ($prefixes !== []) {
            $catalog = array_filter(
                $catalog,
                static function (string $key) use ($prefixes): bool {
                    foreach ($prefixes as $prefix) {
                        if (str_starts_with($key, (string) $prefix)) {
                            return true;
                        }
                    }
                    return false;
                },
                ARRAY_FILTER_USE_KEY,
            );
        }
        try {
            return json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        } catch (\JsonException) {
            return '{}';
        }
    }
    /** @param array<string,mixed> $context @return array<string,string> */
    public function catalog(array $context): array
    {
        $locale=trim((string)($context['locale']??''));
        if($locale===''){$locale=$this->requests->getCurrentRequest()?->getLocale()??'uk-UA';}
        return $this->translator->catalogFor($locale);
    }
    /** @param array<string,mixed> $context */
    public function text(array $context,string $key,array $replace=[]):string
    {
        $locale=trim((string)($context['locale']??''));
        if($locale===''){$locale=$this->requests->getCurrentRequest()?->getLocale()??'uk-UA';}
        return $this->translator->translate($key,$locale,$replace);
    }
}
