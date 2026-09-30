<?php

declare(strict_types=1);

namespace Commerce\Modules\Push\Twig;

use Commerce\Modules\Push\Application\PushSettings;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PushExtension extends AbstractExtension
{
    public function __construct(
        private readonly PushSettings $settings,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('push_public_key', [$this, 'publicKey'])];
    }

    /** VAPID public key when the storefront may offer push subscriptions, otherwise an empty string. */
    public function publicKey(): string
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return '';
        }
        try {
            $s = $this->settings->get($this->contexts->resolve($request)->storeId);

            return $s['enabled'] ? $s['public'] : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
