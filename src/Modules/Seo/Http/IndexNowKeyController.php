<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Http;

use Commerce\Modules\Seo\Application\IndexNowService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Ownership proof required by IndexNow: the key served as plain text at /{key}.txt. */
final readonly class IndexNowKeyController
{
    public function __construct(private IndexNowService $indexNow)
    {
    }

    #[Route('/{key}.txt', name: 'public_indexnow_key', methods: ['GET'], requirements: ['key' => '[a-f0-9]{32}'], priority: 900)]
    public function key(string $key): Response
    {
        if (!hash_equals($this->indexNow->key(), $key)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
