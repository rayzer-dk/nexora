<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Vitals;

use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Receives the browser's Core Web Vitals sample (sendBeacon). Anonymous, tiny, best effort. */
final readonly class WebVitalsController
{
    private const MAX_BODY = 512;

    public function __construct(private WebVitals $vitals, private StorefrontContextResolver $contexts)
    {
    }

    #[Route('/rum/vitals', name: 'storefront_rum_vitals', methods: ['POST'], priority: 100)]
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY) {
            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            if (is_array($data) && is_array($data['m'] ?? null)) {
                $this->vitals->record($this->contexts->resolve($request)->storeId, (string) ($data['p'] ?? ''), (string) ($data['d'] ?? ''), $data['m']);
            }
        } catch (\Throwable) {
            // a malformed or failed sample is simply dropped
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
