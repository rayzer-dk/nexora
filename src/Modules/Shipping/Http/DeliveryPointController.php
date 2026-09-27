<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Http;

use Commerce\Modules\Shipping\Application\DeliveryLocationGateway;
use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Commerce\Modules\Shipping\Domain\DeliveryPointType;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DeliveryPointController
{
    public function __construct(private DeliveryLocationGateway $gateway)
    {
    }

    #[Route('/api/shipping/points', name: 'api_shipping_points', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $providerCode = trim((string) $request->query->get('provider', ''));
        $country = strtoupper(trim((string) $request->query->get('country', 'UA')));
        $query = trim((string) $request->query->get('q', ''));
        $cityId = trim((string) $request->query->get('city_id', ''));
        $cityName = trim((string) $request->query->get('city_name', ''));
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));

        if ($providerCode === '') {
            return new JsonResponse(['error' => \Commerce\Core\I18n\CanonicalUiText::get('shipping.error.provider_required')], 400);
        }

        try {
            $search = new DeliveryPointSearch(
                countryCode: $country,
                query: $query !== '' ? $query : null,
                cityId: $cityId !== '' ? $cityId : null,
                cityName: $cityName !== '' ? $cityName : null,
                types: $this->parseTypes((string) $request->query->get('types', '')),
                limit: $limit,
            );
            $result = $this->gateway->searchPoints($providerCode, $search);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'items' => array_map($this->normalizePoint(...), $result->points),
            'count' => count($result->points),
            'availability' => $result->availability->value,
            'manual_fallback_allowed' => $result->manualFallbackAllowed,
            'notice' => $result->notice,
        ]);
    }

    /** @return list<DeliveryPointType> */
    private function parseTypes(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $types = [];
        foreach (explode(',', $raw) as $value) {
            $type = DeliveryPointType::tryFrom(trim($value));
            if ($type !== null) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /** @return array<string,mixed> */
    private function normalizePoint(DeliveryPoint $point): array
    {
        return [
            'id' => $point->id,
            'provider' => $point->providerCode,
            'name' => $point->name,
            'city' => $point->city,
            'type' => $point->type->value,
            'address' => $point->address,
            'postal_code' => $point->postalCode,
            'country_code' => $point->countryCode,
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'metadata' => $point->metadata,
        ];
    }
}
