<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Ucp;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class UcpProfileController
{
    public function __construct(
        private UcpProfileBuilder $builder,
        private bool $enabled,
        private string $version,
        private string $publicBaseUrl,
    ) {
    }

    #[Route('/.well-known/ucp', name: 'google_ucp_profile', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse(
            $this->builder->build($this->publicBaseUrl, $this->version),
            headers: [
                'Cache-Control' => 'public, max-age=300',
                'Content-Type' => 'application/json; charset=utf-8',
            ],
        );
    }
}
