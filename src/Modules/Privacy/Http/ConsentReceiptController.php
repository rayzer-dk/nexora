<?php

declare(strict_types=1);

namespace Commerce\Modules\Privacy\Http;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ConsentReceiptController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $appSecret,
    ) {
    }

    #[Route('/privacy/consent', name: 'privacy_consent_receipt', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (strlen($request->getContent()) > 4096) {
            return new JsonResponse(['error' => 'payload_too_large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $fetchSite = strtolower((string) $request->headers->get('Sec-Fetch-Site', 'same-origin'));
        if (!in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
            return new JsonResponse(['error' => 'cross_site_request_rejected'], Response::HTTP_FORBIDDEN);
        }

        try {
            $payload = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new JsonResponse(['error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_BAD_REQUEST);
        }

        $clientId = (string) ($payload['client_id'] ?? '');
        if ($clientId === '' || strlen($clientId) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $clientId)) {
            return new JsonResponse(['error' => 'invalid_client_id'], Response::HTTP_BAD_REQUEST);
        }

        $storeId = $request->attributes->getInt('commerce_store_id');
        $store = $storeId > 0
            ? $this->connection->fetchAssociative('SELECT id, default_locale FROM mc_store WHERE id = ? AND status = ? LIMIT 1', [$storeId, 'active'])
            : $this->connection->fetchAssociative('SELECT id, default_locale FROM mc_store WHERE status = ? ORDER BY id ASC LIMIT 1', ['active']);

        if (!$store) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $policy = $this->connection->fetchAssociative(
            'SELECT id FROM mc_consent_policy WHERE store_id = ? AND status = ? AND (effective_from IS NULL OR effective_from <= ?) AND (effective_to IS NULL OR effective_to > ?) ORDER BY effective_from DESC, id DESC LIMIT 1',
            [(int) $store['id'], 'active', $now->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u')]
        );

        if (!$policy) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $preferences = (bool) ($payload['preferences'] ?? false);
        $analytics = (bool) ($payload['analytics'] ?? false);
        $marketing = (bool) ($payload['marketing'] ?? false);
        $locale = (string) ($payload['locale'] ?? $store['default_locale']);
        if ($locale === '' || strlen($locale) > 16) {
            $locale = (string) $store['default_locale'];
        }

        $knownLocale = $this->connection->fetchOne('SELECT code FROM mc_locale WHERE code = ? LIMIT 1', [$locale]);
        if (!$knownLocale) {
            $locale = (string) $store['default_locale'];
        }

        $countryCode = strtoupper((string) ($payload['country_code'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $countryCode = null;
        }

        $subjectHash = hash_hmac('sha256', $clientId, $this->appSecret, true);
        $canonical = json_encode([
            'store' => (int) $store['id'],
            'policy' => (int) $policy['id'],
            'client' => $clientId,
            'preferences' => $preferences,
            'analytics' => $analytics,
            'marketing' => $marketing,
            'granted_at' => $now->format(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $proofHash = hash_hmac('sha256', $canonical, $this->appSecret, true);
        $expires = $now->add(new DateInterval('P180D'));

        $this->connection->insert('mc_consent_receipt', [
            'public_id' => Uuid::v7()->toBinary(),
            'store_id' => (int) $store['id'],
            'consent_policy_id' => (int) $policy['id'],
            'subject_hash' => $subjectHash,
            'proof_hash' => $proofHash,
            'locale' => $locale,
            'country_code' => $countryCode,
            'necessary' => 1,
            'preferences' => $preferences ? 1 : 0,
            'analytics' => $analytics ? 1 : 0,
            'marketing' => $marketing ? 1 : 0,
            'analytics_storage' => $analytics ? 1 : 0,
            'ad_storage' => $marketing ? 1 : 0,
            'ad_user_data' => $marketing ? 1 : 0,
            'ad_personalization' => $marketing ? 1 : 0,
            'personalization_storage' => $preferences ? 1 : 0,
            'functionality_storage' => $preferences ? 1 : 0,
            'security_storage' => 1,
            'choice_source' => 'banner',
            'granted_at' => $now->format('Y-m-d H:i:s.u'),
            'expires_at' => $expires->format('Y-m-d H:i:s.u'),
        ]);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
