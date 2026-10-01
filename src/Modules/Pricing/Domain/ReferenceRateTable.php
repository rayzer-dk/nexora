<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Domain;

/** Published rates of one source: how many pivot-currency units one unit of each listed currency costs. */
final readonly class ReferenceRateTable
{
    /** @param array<string,float> $pivotPerUnit currency => pivot units per ONE unit of it; the pivot itself is 1.0 */
    public function __construct(
        public string $source,
        public string $pivot,
        public string $date,
        public array $pivotPerUnit,
    ) {
    }

    public function has(string $currency): bool
    {
        $rate = $this->pivotPerUnit[strtoupper($currency)] ?? null;

        return $rate !== null && $rate > 0;
    }

    /** @return float|null how many $quote one $base buys, or null when either currency is not published */
    public function cross(string $base, string $quote): ?float
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        if ($base === $quote || !$this->has($base) || !$this->has($quote)) {
            return null;
        }

        return $this->pivotPerUnit[$base] / $this->pivotPerUnit[$quote];
    }

    /** @return list<string> */
    public function currencies(): array
    {
        return array_keys($this->pivotPerUnit);
    }
}
