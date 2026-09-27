<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class ApiAccessService
{
    public const SCOPES = ['catalog:read','customers:read','orders:read','carts:read','carts:write','checkout:write','webhooks:manage'];

    public function __construct(private readonly Connection $db) {}

    public function require(Request $request, string $scope, int $storeId): ApiTokenContext
    {
        $authorization = trim((string) $request->headers->get('Authorization', ''));
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,200})$/D', $authorization, $match)) {
            throw new ApiAccessException('api_auth_required', CanonicalUiText::get('api.error.auth_required'), 401);
        }
        $hashHex = hash('sha256', $match[1]);
        $row = $this->db->fetchAssociative(
            "SELECT id,store_id,scopes,status,rate_limit_per_minute,expires_at FROM mc_api_token WHERE token_hash=UNHEX(?) LIMIT 1",
            [$hashHex]
        );
        if (!is_array($row) || (string) $row['status'] !== 'active') {
            throw new ApiAccessException('api_token_invalid', CanonicalUiText::get('api.error.token_invalid'), 401);
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
            throw new ApiAccessException('api_token_expired', CanonicalUiText::get('api.error.token_expired'), 401);
        }
        $tokenStoreId = $row['store_id'] === null ? null : (int) $row['store_id'];
        if ($tokenStoreId !== null && $tokenStoreId !== $storeId) {
            throw new ApiAccessException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.51a390c79264'), CanonicalUiText::get('api.error.store_forbidden'), 403);
        }
        $decoded = json_decode((string) $row['scopes'], true);
        $scopes = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
        $context = new ApiTokenContext((int) $row['id'], $tokenStoreId, $scopes, max(1, (int) $row['rate_limit_per_minute']));
        if (!$context->hasScope($scope)) {
            throw new ApiAccessException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4a4e27ae63eb'), CanonicalUiText::get('api.error.scope_forbidden', ['scope' => $scope]), 403);
        }
        $this->consumeRateLimit($context);
        $this->db->executeStatement('UPDATE mc_api_token SET last_used_at=NOW(6) WHERE id=?', [$context->id]);
        return $context;
    }

    private function consumeRateLimit(ApiTokenContext $context): void
    {
        $window = date('Y-m-d H:i:00');
        $this->db->executeStatement(
            'INSERT INTO mc_api_rate_window (token_id,window_started_at,request_count) VALUES (?,?,1) ON DUPLICATE KEY UPDATE request_count=request_count+1',
            [$context->id, $window]
        );
        $count = (int) $this->db->fetchOne('SELECT request_count FROM mc_api_rate_window WHERE token_id=? AND window_started_at=?', [$context->id, $window]);
        if ($count > $context->rateLimitPerMinute) {
            throw new ApiAccessException('api_rate_limited', CanonicalUiText::get('api.error.rate_limited'), 429);
        }
        if (random_int(1, 100) === 1) {
            $this->db->executeStatement('DELETE FROM mc_api_rate_window WHERE window_started_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)');
        }
    }
}
