<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final readonly class ApiIdempotencyService
{
    public function __construct(private Connection $db) {}

    public function key(Request $request): string
    {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > 190 || preg_match('/^[A-Za-z0-9._:-]{16,190}$/D', $key) !== 1) {
            throw new ApiAccessException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.95d9b4e9d222'), CanonicalUiText::get('api.error.idempotency_key_required'), 400);
        }
        return $key;
    }

    /** @return array{status:int,body:array<string,mixed>}|null */
    public function replay(ApiTokenContext $token, string $key, string $requestHash): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT request_hash,response_status,response_body FROM mc_api_idempotency WHERE token_id=? AND idempotency_key=? AND expires_at>UTC_TIMESTAMP(6) LIMIT 1',
            [$token->id, $key]
        );
        if (!is_array($row)) return null;
        if (!hash_equals(bin2hex((string) $row['request_hash']), $requestHash)) {
            throw new ApiAccessException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.60192ae00eaa'), CanonicalUiText::get('api.error.idempotency_conflict'), 409);
        }
        $body = json_decode((string) $row['response_body'], true);
        return ['status'=>(int)$row['response_status'], 'body'=>is_array($body)?$body:[]];
    }

    /** @param array<string,mixed> $body */
    public function remember(ApiTokenContext $token, string $key, string $requestHash, int $status, array $body): void
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        try {
            $this->db->insert('mc_api_idempotency', [
                'token_id'=>$token->id,
                'idempotency_key'=>$key,
                'request_hash'=>hex2bin($requestHash),
                'response_status'=>$status,
                'response_body'=>$payload,
                'created_at'=>gmdate('Y-m-d H:i:s'),
                'expires_at'=>gmdate('Y-m-d H:i:s', time()+86400),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // A concurrent retry won the insert. Natural PUT semantics and checkout-level
            // idempotency keep the underlying write safe; the next retry will replay it.
        }
    }

    public function requestHash(Request $request, int $storeId): string
    {
        return hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            (string)$storeId,
            (string)$request->getContent(),
        ]));
    }
}
