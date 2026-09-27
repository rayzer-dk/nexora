<?php

declare(strict_types=1);

namespace Commerce\Core\Region;

final readonly class RegionProfile
{
    /**
     * @param list<string> $countries
     * @param list<string> $currencies
     * @param list<string> $languages
     * @param list<RegionalFeature> $features
     */
    public function __construct(
        public CommerceRegion $region,
        public array $countries,
        public array $currencies,
        public array $languages,
        public array $features,
    ) {
    }

    public function supportsCountry(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->countries, true);
    }

    public function has(RegionalFeature $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
