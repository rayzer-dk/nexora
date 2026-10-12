<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Application;

/**
 * What the VAT number a business buyer typed at checkout means for the VAT of the order. It never blocks an order and never changes a price:
 * the answer is stored with the order, and the manager sees whether a zero rate (reverse charge) can be applied.
 *
 * Statuses: valid (an EU number VIES knows, of another country than the shop), domestic (the shop's own country: ordinary VAT),
 * invalid, unavailable (VIES did not answer: check later), not_eu (no EU country prefix).
 */
final readonly class BuyerVatCheck
{
    public function __construct(private ViesClient $vies)
    {
    }

    /** @return array{status:string,name:string} */
    public function check(string $taxId, string $shopCountry): array
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $taxId) ?? '');
        $prefix = substr($clean, 0, 2);
        if (strlen($clean) < 4 || !in_array($prefix, ViesClient::COUNTRIES, true)) {
            return ['status' => 'not_eu', 'name' => ''];
        }
        $shop = strtoupper($shopCountry) === 'GR' ? 'EL' : strtoupper($shopCountry);
        if ($prefix === $shop) {
            return ['status' => 'domestic', 'name' => ''];
        }
        try {
            $answer = $this->vies->check($prefix, substr($clean, 2));
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'name' => ''];
        }

        return match (true) {
            $answer['valid'] => ['status' => 'valid', 'name' => $answer['name']],
            $answer['state'] === 'SERVICE_UNAVAILABLE' => ['status' => 'unavailable', 'name' => ''],
            default => ['status' => 'invalid', 'name' => ''],
        };
    }
}
