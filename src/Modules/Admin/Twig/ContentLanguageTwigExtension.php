<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Twig;

use Commerce\Modules\Admin\Application\ContentLanguageTabs;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Catalog\Application\CatalogTranslationService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** content_lang_tabs('product'|'category', publicId) for the catalog edit forms: language tabs that open the translation editor. */
final class ContentLanguageTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentLanguageTabs $tabs,
        private readonly CatalogTranslationService $translations,
        private readonly AdminContextResolver $contexts,
        private readonly RequestStack $requests,
        private readonly Connection $db,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('content_lang_tabs', $this->catalogTabs(...)), new TwigFunction('brand_lang_tabs', $this->brandTabs(...))];
    }

    /** @return list<array{code:string,name:string,short:string,is_default:bool,current:bool,done:bool}> */
    public function catalogTabs(string $kind, string $publicId): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return [];
        }
        try {
            $context = $this->contexts->resolve($request);
            $storeId = $context->storeId;
            $entity = $kind === 'product' ? $this->translations->product($storeId, $publicId) : $this->translations->category($storeId, $publicId);
            if ($entity === null) {
                return [];
            }
            $texts = $kind === 'product' ? $this->translations->productTexts($storeId, $entity['id']) : $this->translations->categoryTexts($storeId, $entity['id']);
            $done = [];
            foreach ($texts as $locale => $row) {
                $done[$locale] = ($row['name'] ?? '') !== '' && ($kind === 'category' || ($row['description'] ?? '') !== '');
            }

            return $this->tabs->tabs($storeId, $context->locale, $done);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Language tabs of the brand list: a tab is complete when every brand of the store has a translation in that language. */
    public function brandTabs(): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return [];
        }
        try {
            $context = $this->contexts->resolve($request);
            $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_store_brand WHERE store_id=?', [$context->storeId]);
            $counts = $this->db->fetchAllKeyValue('SELECT locale, COUNT(DISTINCT brand_id) FROM mc_brand_translation WHERE store_id=? GROUP BY locale', [$context->storeId]);
            $done = [];
            foreach ($counts as $locale => $count) {
                $done[(string) $locale] = $total > 0 && (int) $count >= $total;
            }
            $tabs = [];
            foreach ($this->tabs->tabs($context->storeId, $context->locale, $done) as $tab) {
                $tabs[] = $tab + ['href' => $tab['current'] ? '' : $request->getPathInfo() . '?' . http_build_query(['locale' => $tab['code']])];
            }

            return $tabs;
        } catch (\Throwable) {
            return [];
        }
    }
}
