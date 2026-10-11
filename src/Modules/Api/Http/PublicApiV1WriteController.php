<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Api\Application\ApiAccessException;
use Commerce\Modules\Api\Application\ApiAccessService;
use Commerce\Modules\Api\Application\ApiIdempotencyService;
use Commerce\Modules\Api\Application\CatalogSyncService;
use Commerce\Modules\Api\Infrastructure\DbalPublicApiQuery;
use Commerce\Modules\Order\Application\OrderManagementService;
use Commerce\Modules\Order\Application\OrderNotificationService;
use Commerce\Modules\Payment\Application\PaymentCancellationService;
use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontCacheVersion;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Commerce\Modules\Storefront\Projection\StorefrontFacetProjectionStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public API v1, write side: prices and stock of variants (one or a batch) and the life of an order (paid, shipped, tracking, completed, cancelled, note).
 * Scopes: catalog:write, inventory:write, orders:write. A batch needs an Idempotency-Key, so a retried request never counts twice.
 */
#[Route('/api/v1', name: 'api_v1_write_')]
final class PublicApiV1WriteController extends AbstractController
{
    private const ERROR_STATUS = ['variant_not_found' => 404, 'stock_row_missing' => 422, 'price_row_missing' => 422];

    public function __construct(
        private readonly ApiAccessService $access,
        private readonly ApiIdempotencyService $idempotency,
        private readonly CatalogSyncService $sync,
        private readonly DbalPublicApiQuery $query,
        private readonly Connection $db,
        private readonly OrderManagementService $orders,
        private readonly OrderNotificationService $notifications,
        private readonly PaymentLifecycleService $lifecycle,
        private readonly PaymentCancellationService $cancellations,
        private readonly StorefrontCacheVersion $cacheVersion,
        private readonly StorefrontFacetProjectionStore $facets,
    ) {
    }

    /** Price and/or stock of one variant, found by its public id. Send only the fields that change. */
    #[Route('/catalog/variants/{publicId}', name: 'variant_patch', methods: ['PATCH'], requirements: ['publicId' => '[0-9a-fA-F-]{36}'])]
    public function patchVariant(string $publicId, Request $request, StorefrontContextResolver $contexts): JsonResponse
    {
        return $this->patchOne($request, $contexts, ['variant_public_id' => $publicId]);
    }

    /** The same by SKU: PATCH /api/v1/catalog/variants/by-sku/{sku}. */
    #[Route('/catalog/variants/by-sku/{sku}', name: 'variant_patch_sku', methods: ['PATCH'], requirements: ['sku' => '[^/]{1,190}'], priority: 10)]
    public function patchVariantBySku(string $sku, Request $request, StorefrontContextResolver $contexts): JsonResponse
    {
        return $this->patchOne($request, $contexts, ['sku' => $sku]);
    }

    /**
     * A batch of up to 500 items: [{sku|variant_public_id, price_minor?, compare_at_minor?, stock_quantity?|stock_delta?}].
     * Every item is applied on its own; the answer lists what happened to each (HTTP 200 even when some items failed).
     */
    #[Route('/catalog/sync', name: 'sync', methods: ['POST'])]
    public function batch(Request $request, StorefrontContextResolver $contexts): JsonResponse
    {
        try {
            $context = $contexts->resolve($request);
            $token = $this->access->require($request, 'catalog:write', $context->storeId);
            $key = $this->idempotency->key($request);
            $hash = $this->idempotency->requestHash($request, $context->storeId);
            if (($replay = $this->idempotency->replay($token, $key, $hash)) !== null) {
                return $this->json($replay['body'], $replay['status']);
            }
            $payload = $this->jsonBody($request);
            $items = $payload['items'] ?? null;
            if (!is_array($items) || $items === [] || !array_is_list($items)) {
                return $this->problem('validation_failed', CanonicalUiText::get('api.error.write.items_required'), 422);
            }
            if (count($items) > CatalogSyncService::MAX_BATCH) {
                return $this->problem('validation_failed', CanonicalUiText::get('api.error.write.too_many_items', ['max' => (string) CatalogSyncService::MAX_BATCH]), 422);
            }
            $results = [];
            $ok = 0;
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $results[] = ['index' => $index, 'ok' => false, 'error' => 'validation_failed'];
                    continue;
                }
                try {
                    $applied = $this->sync->apply($context->storeId, $context->marketId, $context->currency, $item);
                    $results[] = ['index' => $index, 'ok' => true] + $applied;
                    ++$ok;
                } catch (\DomainException $e) {
                    $results[] = ['index' => $index, 'ok' => false, 'error' => $e->getMessage(), 'detail' => $this->detail($e->getMessage())];
                }
            }
            if ($ok > 0) {
                $this->dropCaches();
            }
            $body = ['data' => ['applied' => $ok, 'failed' => count($results) - $ok, 'results' => $results]];
            $this->idempotency->remember($token, $key, $hash, 200, $body);

            return $this->json($body);
        } catch (ApiAccessException $e) {
            return $this->problem($e->apiCode, $e->getMessage(), $e->status);
        } catch (\InvalidArgumentException $e) {
            return $this->problem('validation_failed', $e->getMessage(), 422);
        } catch (\Throwable) {
            return $this->problem('operation_failed', CanonicalUiText::get('common.error.operation_failed'), 500);
        }
    }

    /**
     * An order moves on: {"mark_paid":true} (bank transfer, cash on delivery), {"fulfillment_status":"shipped","tracking_number":"..."},
     * {"status":"completed"|"cancelled"}, {"note":"internal text"}. Several fields are applied in that order.
     */
    #[Route('/orders/{publicId}', name: 'order_patch', methods: ['PATCH'], requirements: ['publicId' => '[0-9a-fA-F-]{36}'])]
    public function patchOrder(string $publicId, Request $request, StorefrontContextResolver $contexts): JsonResponse
    {
        try {
            $context = $contexts->resolve($request);
            $this->access->require($request, 'orders:write', $context->storeId);
            $order = $this->query->order($context->storeId, $publicId);
            if ($order === null) {
                return $this->problem('not_found', CanonicalUiText::get('api.error.order_not_found'), 404);
            }
            $payload = $this->jsonBody($request);
            $known = ['mark_paid', 'fulfillment_status', 'tracking_number', 'status', 'note'];
            if (array_diff(array_keys($payload), $known) !== [] || array_intersect($known, array_keys($payload)) === []) {
                return $this->problem('validation_failed', CanonicalUiText::get('api.error.write.order_fields', ['fields' => implode(', ', $known)]), 422);
            }
            $status = array_key_exists('status', $payload) ? (string) $payload['status'] : '';
            if ($status !== '' && !in_array($status, ['completed', 'cancelled'], true)) {
                return $this->problem('validation_failed', CanonicalUiText::get('api.error.write.order_status'), 422);
            }
            $actor = 'API';
            $changed = [];
            if (!empty($payload['note'])) {
                $this->orders->addInternalNote($publicId, (string) $payload['note'], $actor);
                $changed[] = 'note';
            }
            if (!empty($payload['mark_paid'])) {
                $provider = (string) $this->db->fetchOne('SELECT p.provider_code FROM mc_payment p JOIN mc_sales_order o ON o.id=p.order_id WHERE o.public_id=? ORDER BY p.id DESC LIMIT 1', [\Symfony\Component\Uid\Uuid::fromString($publicId)->toBinary()]);
                if (!in_array($provider, ['bank_transfer', 'cash_on_delivery'], true) && !str_starts_with($provider, 'custom_pay_')) {
                    return $this->problem('validation_failed', CanonicalUiText::get('api.error.write.mark_paid_provider'), 422);
                }
                $this->lifecycle->markManualPaid($publicId, $actor);
                $changed[] = 'payment';
            }
            if (array_key_exists('fulfillment_status', $payload) || array_key_exists('tracking_number', $payload)) {
                $fulfillment = array_key_exists('fulfillment_status', $payload) ? (string) $payload['fulfillment_status'] : (string) ($order['fulfillment_status'] ?? '');
                $this->orders->updateFulfillment($publicId, $fulfillment, array_key_exists('tracking_number', $payload) ? (string) $payload['tracking_number'] : null, $actor);
                $changed[] = 'fulfillment';
            }
            if ($status === 'completed') {
                $this->orders->markCompleted($publicId, $actor);
                $changed[] = 'status';
            } elseif ($status === 'cancelled') {
                $this->cancellations->cancelProviderPayment($publicId);
                $this->lifecycle->cancelOrder($publicId, $actor);
                $changed[] = 'status';
            }
            if ($changed !== []) {
                try {
                    $this->notifications->enqueueCurrentStatus($publicId);
                } catch (\Throwable) {
                    // A failed customer message never undoes a successful order change.
                }
            }

            return $this->json(['data' => $this->query->order($context->storeId, $publicId), 'meta' => ['changed' => $changed]]);
        } catch (ApiAccessException $e) {
            return $this->problem($e->apiCode, $e->getMessage(), $e->status);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $this->problem('validation_failed', $e->getMessage(), 422);
        } catch (\Throwable) {
            return $this->problem('operation_failed', CanonicalUiText::get('common.error.operation_failed'), 500);
        }
    }

    /** @param array<string,string> $ref */
    private function patchOne(Request $request, StorefrontContextResolver $contexts, array $ref): JsonResponse
    {
        try {
            $context = $contexts->resolve($request);
            $payload = $this->jsonBody($request);
            $scope = array_key_exists('stock_quantity', $payload) || array_key_exists('stock_delta', $payload) ? 'inventory:write' : 'catalog:write';
            $this->access->require($request, $scope, $context->storeId);
            if (array_key_exists('price_minor', $payload) || array_key_exists('compare_at_minor', $payload)) {
                // Prices need the catalog scope even when the same request also changes the stock.
                if ($scope !== 'catalog:write') {
                    $this->access->require($request, 'catalog:write', $context->storeId);
                }
            }
            $result = $this->sync->apply($context->storeId, $context->marketId, $context->currency, array_intersect_key($payload, array_flip(['price_minor', 'compare_at_minor', 'stock_quantity', 'stock_delta'])) + $ref);
            $this->dropCaches();

            return $this->json(['data' => $result]);
        } catch (ApiAccessException $e) {
            return $this->problem($e->apiCode, $e->getMessage(), $e->status);
        } catch (\DomainException $e) {
            return $this->problem($e->getMessage(), $this->detail($e->getMessage()), self::ERROR_STATUS[$e->getMessage()] ?? 422);
        } catch (\InvalidArgumentException $e) {
            return $this->problem('validation_failed', $e->getMessage(), 422);
        } catch (\Throwable) {
            return $this->problem('operation_failed', CanonicalUiText::get('common.error.operation_failed'), 500);
        }
    }

    private function dropCaches(): void
    {
        $this->cacheVersion->bump();
        $this->facets->clear();
    }

    private function detail(string $code): string
    {
        return CanonicalUiText::get('api.error.write.' . $code);
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        if (strlen((string) $request->getContent()) > 1024 * 1024) {
            throw new \InvalidArgumentException(CanonicalUiText::get('api.error.payload_too_large'));
        }
        try {
            $data = json_decode((string) $request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException(CanonicalUiText::get('api.error.invalid_json'));
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException(CanonicalUiText::get('api.error.invalid_json'));
        }

        return $data;
    }

    private function problem(string $code, string $detail, int $status): JsonResponse
    {
        return $this->json(['type' => 'about:blank', 'title' => $code, 'status' => $status, 'detail' => $detail], $status, ['Content-Type' => 'application/problem+json']);
    }
}
