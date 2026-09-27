<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Http;

use Commerce\Modules\Shipping\Application\DeliveryLocationGateway;
use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DeliveryCityController
{
    public function __construct(private DeliveryLocationGateway $gateway)
    {
    }

    #[Route('/api/shipping/cities', name: 'api_shipping_cities', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $provider = trim((string) $request->query->get('provider', ''));
        $country = strtoupper(trim((string) $request->query->get('country', 'UA')));
        $query = trim((string) $request->query->get('q', ''));
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));

        if ($provider === '') {
            return new JsonResponse(['error' => \Commerce\Core\I18n\CanonicalUiText::get('shipping.error.provider_required')], 400);
        }

        try {
            $cities = $this->gateway->searchCities($provider, new DeliveryCitySearch($country, $query, $limit));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'items' => array_map($this->normalizeCity(...), $cities),
            'count' => count($cities),
        ]);
    }

    /** @return array<string,mixed> */
    private function normalizeCity(DeliveryCity $city): array
    {
        return [
            'id' => $city->id,
            'provider' => $city->providerCode,
            'name' => $city->name,
            'country_code' => $city->countryCode,
            'region' => $city->region,
            'district' => $city->district,
            'latitude' => $city->latitude,
            'longitude' => $city->longitude,
            'metadata' => $city->metadata,
        ];
    }
}
