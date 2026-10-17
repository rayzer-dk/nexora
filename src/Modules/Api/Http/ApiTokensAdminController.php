<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Api\Application\ApiAccessService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Admin → System → Integrations → API keys: create a key for a CRM, an accounting program or a marketplace sync, see its use, switch it off. */
final class ApiTokensAdminController extends AbstractController
{
    /** Scope groups shown as presets: what a typical integration needs. */
    private const PRESETS = [
        'read' => ['catalog:read', 'customers:read', 'orders:read'],
        'sync' => ['catalog:read', 'catalog:write', 'inventory:write', 'orders:read', 'orders:write'],
        'store' => ['catalog:read', 'carts:read', 'carts:write', 'checkout:write'],
    ];

    public function __construct(private readonly Connection $db, private readonly AdminContextResolver $contexts)
    {
    }

    #[Route('/admin/system/integrations/api', name: 'admin_system_integrations_api', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id,name,token_prefix,scopes,status,rate_limit_per_minute,expires_at,last_used_at,created_at FROM mc_api_token WHERE store_id IS NULL OR store_id=? ORDER BY id DESC LIMIT 200',
            [$ctx->storeId],
        );
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['scopes'], true);
            $row['scope_list'] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
            $row['expired'] = $row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') <= time();
        }
        unset($row);
        $secret = $request->getSession()->get('api_token_secret');
        $request->getSession()->remove('api_token_secret');

        return $this->render('@storefront/admin/system/api_tokens.html.twig', [
            'tokens' => $rows,
            'scopes' => ApiAccessService::SCOPES,
            'presets' => self::PRESETS,
            'secret' => is_string($secret) ? $secret : null,
            'base_url' => rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/') . '/api/v1',
        ]);
    }

    #[Route('/admin/system/integrations/api/create', name: 'admin_system_integrations_api_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        $this->guard($request);
        $ctx = $this->contexts->resolve($request);
        $name = trim(mb_substr((string) $request->request->get('name', ''), 0, 190));
        $scopes = array_values(array_intersect(ApiAccessService::SCOPES, array_map('strval', $request->request->all('scopes'))));
        if ($name === '' || $scopes === []) {
            $this->addFlash('error', CanonicalUiText::get('admin.api.error_required'));

            return $this->redirectToRoute('admin_system_integrations_api');
        }
        $expires = null;
        $expiresRaw = trim((string) $request->request->get('expires', ''));
        if ($expiresRaw !== '') {
            $timestamp = strtotime($expiresRaw . ' 23:59:59');
            if ($timestamp === false || $timestamp <= time()) {
                $this->addFlash('error', CanonicalUiText::get('api.cli.issue.invalid_expiry'));

                return $this->redirectToRoute('admin_system_integrations_api');
            }
            $expires = gmdate('Y-m-d H:i:s', $timestamp);
        }
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->insert('mc_api_token', [
            'public_id' => Uuid::v7()->toBinary(), 'store_id' => $ctx->storeId, 'name' => $name,
            'token_prefix' => substr($raw, 0, 12), 'token_hash' => hash('sha256', $raw, true), 'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'status' => 'active', 'rate_limit_per_minute' => max(10, min(5000, $request->request->getInt('rate_limit', 120))), 'expires_at' => $expires, 'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
        $request->getSession()->set('api_token_secret', $raw);

        return $this->redirectToRoute('admin_system_integrations_api');
    }

    #[Route('/admin/system/integrations/api/{id}/revoke', name: 'admin_system_integrations_api_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, Request $request): RedirectResponse
    {
        $this->guard($request);
        $ctx = $this->contexts->resolve($request);
        $this->db->executeStatement("UPDATE mc_api_token SET status='revoked',revoked_at=UTC_TIMESTAMP(6) WHERE id=? AND (store_id IS NULL OR store_id=?)", [$id, $ctx->storeId]);
        $this->addFlash('success', CanonicalUiText::get('admin.api.revoked'));

        return $this->redirectToRoute('admin_system_integrations_api');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('api_tokens', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
