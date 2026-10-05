<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** A short-lived signed link that shows the saved draft of a layout on the real storefront page (nothing is published). */
final readonly class LayoutPreviewToken
{
    private const TTL = 1800;

    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    public function issue(int $storeId, string $type): string
    {
        $exp = time() + self::TTL;

        return $exp . '.' . substr(hash_hmac('sha256', 'layout-preview|' . $storeId . '|' . $type . '|' . $exp, $this->secret), 0, 32);
    }

    public function valid(string $token, int $storeId, string $type): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int) $parts[0] < time()) {
            return false;
        }

        return hash_equals(substr(hash_hmac('sha256', 'layout-preview|' . $storeId . '|' . $type . '|' . $parts[0], $this->secret), 0, 32), $parts[1]);
    }
}
