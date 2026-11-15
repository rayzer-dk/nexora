<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Twig;

use Commerce\Modules\Admin\Application\ContentLanguageTabs;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Language tabs for the admin: store_lang_tabs() for any multilingual screen, brand_lang_tabs() for the brand list (names of languages: locale_name() in Core). */
final class ContentLanguageTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentLanguageTabs $tabs,
        private readonly AdminContextResolver $contexts,
        private readonly RequestStack $requests,
        private readonly Connection $db,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('brand_lang_tabs', $this->brandTabs(...)), new TwigFunction('store_lang_tabs', $this->storeTabs(...))];
    }

    /**
     * The language tabs of the current store for any multilingual screen: enabled languages, the default one first, the
     * admin content language current. Screens add `href` (a page per language) or switch in-page panels.
     *
     * @param array<string,bool> $done locale => that language already has its texts
     * @return list<array{code:string,name:string,is_default:bool,current:bool,done:bool}>
     */
    public function storeTabs(array $done = []): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return [];
        }
        try {
            $context = $this->contexts->resolve($request);
        } catch (\DomainException) {
            return []; // no store context yet (installation, broken store settings): no tabs
        }

        return $this->tabs->tabs($context->storeId, $context->locale, $done);
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
