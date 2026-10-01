<?php

declare(strict_types=1);

namespace Commerce\Modules\Cart\Twig;

use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * cart_count(): number of lines in the visitor's active cart, so the header badge is correct on every page load
 * (not only right after an AJAX add). Never creates a cart and never fails a page.
 */
final class CartCountExtension extends AbstractExtension
{
    private ?int $count = null;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly StorefrontContextResolver $contexts,
        private readonly Connection $db,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('cart_count', $this->count(...))];
    }

    public function count(): int
    {
        if ($this->count !== null) {
            return $this->count;
        }
        $this->count = 0;
        $request = $this->requests->getCurrentRequest();
        $token = $request?->cookies->get('mc_cart');
        if ($request === null || !is_string($token) || preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
            return 0;
        }
        try {
            $context = $this->contexts->resolve($request);
            $this->count = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM mc_cart_item ci JOIN mc_cart c ON c.id=ci.cart_id WHERE c.token_hash=? AND c.store_id=? AND c.status='active' AND c.expires_at>UTC_TIMESTAMP(6)",
                [hash('sha256', $token, true), $context->storeId],
            );
        } catch (\Throwable) {
            $this->count = 0;
        }

        return $this->count;
    }
}
