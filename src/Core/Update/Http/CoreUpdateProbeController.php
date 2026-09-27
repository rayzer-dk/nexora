<?php

declare(strict_types=1);

namespace Commerce\Core\Update\Http;

use Commerce\Core\Install\InstallationHealthVerifier;
use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Runtime\MaintenanceMode;
use Commerce\Core\Update\CoreUpdateProbeTokenStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class CoreUpdateProbeController extends AbstractController
{
    public function __construct(
        private readonly CoreUpdateProbeTokenStore $tokens,
        private readonly MaintenanceMode $maintenance,
        private readonly Connection $db,
        private readonly InstallationHealthVerifier $installation,
    ) {
    }

    #[Route('/__health/core-update', name: 'core_update_smoke_probe', methods: ['GET'], priority: 1000)]
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) $request->headers->get('X-Commerce-Update-Probe', '');
        if (!$this->maintenance->isEnabled() || !$this->tokens->verify($token)) {
            throw $this->createNotFoundException();
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        try {
            $database = (int) $this->db->fetchOne('SELECT 1') === 1;
            $health = $this->installation->verify();
            $installation = (bool) ($health['healthy'] ?? false);
        } catch (Throwable) {
            $database = false;
            $installation = false;
        }

        $ok = $database && $installation;
        $response = $this->json([
            'ok' => $ok,
            'version' => PlatformVersion::VERSION,
            'database' => $database,
            'installation' => $installation,
            'checked_at' => gmdate('c'),
        ], $ok ? 200 : 503);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
