<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Http;

use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Seo\Application\LlmsSettings;
use Commerce\Modules\Seo\Application\LlmsTxtBuilder;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LlmsController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly LlmsTxtBuilder $builder,
        private readonly LlmsSettings $settings,
        private readonly SiteCapabilitySettings $capabilities,
    ) {
    }

    #[Route('/llms.txt', name: 'public_llms_txt', methods: ['GET'], priority: 960)]
    public function short(Request $request): Response
    {
        return $this->respond($request, false);
    }

    #[Route('/llms-full.txt', name: 'public_llms_full_txt', methods: ['GET'], priority: 960)]
    public function full(Request $request): Response
    {
        return $this->respond($request, true);
    }

    private function respond(Request $request, bool $full): Response
    {
        if (!$this->settings->all()['enabled']) {
            throw $this->createNotFoundException();
        }
        $context = $this->contexts->resolve($request);
        $features = (array) ($this->capabilities->get($context->storeId)['features'] ?? []);

        return new Response($this->builder->build($context, $full, $features), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
