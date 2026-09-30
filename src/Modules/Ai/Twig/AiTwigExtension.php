<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Twig;

use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Ai\Application\AiSettings;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AiTwigExtension extends AbstractExtension
{
    /** @var list<array{code:string,label:string}>|null */
    private ?array $cache = null;

    public function __construct(private readonly AiSettings $settings, private readonly AdminContextResolver $contexts, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('ai_providers', $this->providers(...))];
    }

    /** @return list<array{code:string,label:string}> providers that can run in the current admin store */
    public function providers(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return $this->cache = [];
        }
        try {
            return $this->cache = $this->settings->enabledProviders($this->contexts->resolve($request)->storeId);
        } catch (\Throwable) {
            return $this->cache = [];
        }
    }
}
