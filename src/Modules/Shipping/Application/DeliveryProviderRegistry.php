<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Commerce\Modules\Shipping\Contract\DeliveryProviderInterface;
use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use InvalidArgumentException;
use Throwable;

final class DeliveryProviderRegistry
{
    /** @var array<string,DeliveryProviderInterface> */
    private array $providers = [];

    /** @param iterable<DeliveryProviderInterface> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(DeliveryProviderInterface $provider): void
    {
        $code = trim($provider->code());
        if ($code === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1b1bfeb75bc5'));
        }
        if (isset($this->providers[$code])) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cb8a8b26ac64') . $code);
        }
        $this->providers[$code] = $provider;
    }

    public function get(string $code): DeliveryProviderInterface
    {
        if (!isset($this->providers[$code])) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.968275984bd6') . $code);
        }
        return $this->providers[$code];
    }

    /** @return list<DeliveryProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * A failing third-party carrier cannot break healthy carriers.
     * @return list<DeliveryPoint>
     */
    public function searchAllPoints(DeliveryPointSearch $search): array
    {
        $points = [];
        foreach ($this->providers as $provider) {
            try {
                foreach ($provider->searchPoints($search) as $point) {
                    $points[] = $point;
                }
            } catch (Throwable) {
                continue;
            }
        }
        return $points;
    }
}
