<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Domain;

final readonly class OnlinePaymentRequest
{
    /** @param list<array{name:string,quantity:string,unit_price_minor:int}> $items the order lines, for providers that must list the goods (instalments) */
    public function __construct(
        public string $orderPublicId,
        public string $orderNumber,
        public int $amountMinor,
        public string $currency,
        public string $returnUrl,
        public string $webhookUrl,
        public string $customerPhone = '',
        public string $customerName = '',
        public array $items = [],
    ) {}

    /**
     * The goods as {name, count, price_minor} with whole counts whose lines add up to the amount to pay. When the order has a discount, shipping
     * or fractional quantities the lines would not match the amount, so the order is sent as one line.
     *
     * @return list<array{name:string,count:int,price_minor:int}>
     */
    public function goodsForInstalments(): array
    {
        $lines = [];
        $sum = 0;
        foreach ($this->items as $item) {
            $quantity = (float) $item['quantity'];
            if ($quantity < 1 || $quantity !== floor($quantity)) {
                $lines = [];
                break;
            }
            $lines[] = ['name' => mb_substr(trim((string) $item['name']), 0, 100), 'count' => (int) $quantity, 'price_minor' => (int) $item['unit_price_minor']];
            $sum += (int) $quantity * (int) $item['unit_price_minor'];
        }
        if ($lines === [] || $sum !== $this->amountMinor) {
            return [['name' => mb_substr('№ ' . $this->orderNumber, 0, 100), 'count' => 1, 'price_minor' => $this->amountMinor]];
        }

        return $lines;
    }
}
