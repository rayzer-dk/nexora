<?php

declare(strict_types=1);

namespace Nexora\StoreHealth;

use Commerce\Core\Extension\TrustedExtensionContext;
use Commerce\Core\Extension\TrustedExtensionEntrypointInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class Entrypoint implements TrustedExtensionEntrypointInterface
{
    public function boot(TrustedExtensionContext $context): void
    {
        $context->route('extension.nexora_store_health.status', static function (Request $request, array $route): JsonResponse {
            return new JsonResponse(['ok' => true, 'extension' => 'nexora.store_health', 'version' => '1.0.0']);
        });
    }
}
