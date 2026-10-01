<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Twig;

use Commerce\Modules\Navigation\Application\NavigationManager;
use Commerce\Modules\Security\Http\CspExtra;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContactSettings;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Data for the shared storefront chrome: mega menu, configured menus, contact details and the cart badge. */
final class StorefrontLayoutExtension extends AbstractExtension
{
    /** @var array<string,mixed> */
    private array $memo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
        private readonly NavigationManager $navigation,
        private readonly StorefrontContactSettings $contact,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('storefront_category_tree', [$this, 'categoryTree']),
            new TwigFunction('storefront_menu', [$this, 'menu']),
            new TwigFunction('storefront_contact', [$this, 'contactView']),
            new TwigFunction('storefront_cart_units', [$this, 'cartUnits']),
        ];
    }

    /**
     * Active categories of the current market as a tree (top level + sub-categories, three levels at most) with their own image.
     * Categories without an image get a null "image": the template draws an icon placeholder.
     *
     * @return list<array<string,mixed>>
     */
    public function categoryTree(): array
    {
        return $this->memo['tree'] ??= $this->buildTree();
    }

    /** @return list<array<string,mixed>> */
    private function buildTree(): array
    {
        $ctx = $this->context();
        if ($ctx === null) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT c.id,c.parent_id,COALESCE(ct.name,dt.name) AS name,COALESCE(sr.path,dr.path) AS path,(SELECT cma.storage_key FROM mc_category_image cix JOIN mc_media_asset cma ON cma.id=cix.asset_id WHERE cix.category_id=c.id LIMIT 1) AS image_key
                 FROM mc_category c
                 JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active'
                 JOIN mc_market_category mk ON mk.category_id=c.id AND mk.market_id=? AND mk.status='active'
                 LEFT JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=?
                 LEFT JOIN mc_category_translation dt ON dt.category_id=c.id AND dt.store_id=? AND dt.locale=(SELECT default_locale FROM mc_store WHERE id=?)
                 LEFT JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='category' AND sr.entity_public_id=c.public_id
                 LEFT JOIN mc_seo_route dr ON dr.store_id=? AND dr.locale=(SELECT default_locale FROM mc_store WHERE id=?) AND dr.entity_type='category' AND dr.entity_public_id=c.public_id
                 WHERE c.status='active' AND COALESCE(ct.name,dt.name) IS NOT NULL AND COALESCE(sr.path,dr.path) IS NOT NULL
                 ORDER BY sc.sort_order ASC,c.sort_order ASC,c.id ASC LIMIT 600",
                [$ctx->storeId, $ctx->marketId, $ctx->storeId, $ctx->locale, $ctx->storeId, $ctx->storeId, $ctx->storeId, $ctx->locale, $ctx->storeId, $ctx->storeId],
            );
        } catch (\Throwable) {
            return [];
        }
        $byParent = [];
        foreach ($rows as $row) {
            $key = $row['image_key'] ?? null;
            $image = is_string($key) && trim($key) !== '' && !str_contains($key, '..') ? '/media/' . ltrim(str_replace('\\', '/', trim($key)), '/') : null;
            $byParent[$row['parent_id'] !== null ? (int) $row['parent_id'] : 0][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'url' => '/' . ltrim((string) $row['path'], '/'),
                'image' => $image,
                'children' => [],
            ];
        }
        $build = function (int $parent, int $depth) use (&$build, &$byParent): array {
            if ($depth > 2) {
                return [];
            }
            $out = [];
            foreach ($byParent[$parent] ?? [] as $node) {
                $node['children'] = $build($node['id'], $depth + 1);
                $out[] = $node;
            }

            return $out;
        };

        return $build(0, 0);
    }

    /**
     * A menu configured in Appearance → Navigation (header | footer | utility); [] when the owner has not configured it.
     *
     * @return list<array{name:string,url:string,badge:?string,open_new_tab:bool,children:list<array<string,mixed>>}>
     */
    public function menu(string $code): array
    {
        if (!in_array($code, ['header', 'footer', 'utility'], true)) {
            return [];
        }

        return $this->memo['menu.' . $code] ??= $this->buildMenu($code);
    }

    /** @return list<array<string,mixed>> */
    private function buildMenu(string $code): array
    {
        $ctx = $this->context();
        if ($ctx === null) {
            return [];
        }
        try {
            return $this->mapMenu($this->navigation->items($ctx->storeId, $code, $ctx->locale));
        } catch (\Throwable) {
            return []; // older schema during a rolling upgrade
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function mapMenu(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['label'] ?? ''));
            $url = (string) ($row['url'] ?? '');
            if ($name === '' || $url === '' || $url === '#') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'url' => $url,
                'badge' => ($row['badge'] ?? null) !== null && trim((string) $row['badge']) !== '' ? trim((string) $row['badge']) : null,
                'open_new_tab' => !empty($row['open_new_tab']),
                'children' => $this->mapMenu(is_array($row['children'] ?? null) ? $row['children'] : []),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function contactView(bool $withMap = false): array
    {
        $ctx = $this->context();
        if ($ctx === null) {
            return ['has_any' => false, 'address' => '', 'email' => '', 'phones' => [], 'hours' => [], 'social' => [], 'map' => null, 'map_search_url' => ''];
        }
        $view = $this->memo['contact'] ??= $this->contact->publicView($ctx->storeId);
        if ($withMap && $view['map'] !== null) {
            // The OpenStreetMap embed is the only third-party frame the storefront ever needs; allow it on this response only.
            CspExtra::merge($this->requests->getCurrentRequest(), ['frame' => ['https://www.openstreetmap.org']]);
        }

        return $view;
    }

    /** Units in the active cart of this visitor (sum of quantities; number of lines when any quantity is fractional). */
    public function cartUnits(): int
    {
        $request = $this->requests->getCurrentRequest();
        $ctx = $this->context();
        $token = $request?->cookies->get('mc_cart');
        if ($ctx === null || !is_string($token) || preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
            return 0;
        }

        return $this->memo['cart'] ??= $this->countUnits($ctx, $token);
    }

    private function countUnits(StorefrontContext $ctx, string $token): int
    {
        try {
            $row = $this->db->fetchAssociative(
                "SELECT COUNT(*) AS lines,COALESCE(SUM(ci.quantity),0) AS units,COALESCE(SUM(ci.quantity<>FLOOR(ci.quantity)),0) AS fractional
                 FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id
                 WHERE c.token_hash=? AND c.store_id=? AND c.status='active' AND c.expires_at>UTC_TIMESTAMP(6)",
                [hash('sha256', $token, true), $ctx->storeId],
            );
        } catch (\Throwable) {
            return 0;
        }
        if (!is_array($row)) {
            return 0;
        }

        return (int) $row['fractional'] > 0 ? (int) $row['lines'] : (int) round((float) $row['units']);
    }

    private function context(): ?StorefrontContext
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return null;
        }
        try {
            return $this->contexts->resolve($request);
        } catch (\Throwable) {
            return null;
        }
    }
}
