<?php

declare(strict_types=1);

namespace Commerce\Modules\Order\Application;

use Commerce\Core\I18n\StorefrontUiTranslator;

/** Human readable names of the delivery / payment method stored on an order (success page, e-mails, admin order view). */
final readonly class OrderMethodPresenter
{
    public function __construct(private StorefrontUiTranslator $translator, private ?\Commerce\Modules\Checkout\Application\CheckoutMethodSettings $custom = null)
    {
    }

    public function delivery(string $code, string $locale): string
    {
        if (str_starts_with($code, 'custom_') && ($name = $this->custom?->customName($code, $locale)) !== null && $name !== '') {
            return $name;
        }
        $key = 'method.delivery.' . $code;
        $label = $this->translator->translate($key, $locale);

        return $label === $key ? $code : $label;
    }

    public function payment(string $code, string $locale): string
    {
        if (str_starts_with($code, 'custom_') && ($name = $this->custom?->customName($code, $locale)) !== null && $name !== '') {
            return $name;
        }
        $key = 'method.payment.' . $code;
        $label = $this->translator->translate($key, $locale);

        return $label === $key ? $code : $label;
    }

    /** Short line for the delivery of an order, e.g. "Self pickup — Main store, 1 Main St (Mon-Fri 9-18)". */
    public function deliverySummary(string $code, array $destination, string $locale): string
    {
        $parts = [$this->delivery($code, $locale)];
        $place = trim((string) ($destination['point'] ?? '')) ?: trim((string) ($destination['manual'] ?? ''));
        $city = trim((string) ($destination['city'] ?? ''));
        $detail = implode(', ', array_filter([$city, $place, trim((string) ($destination['address'] ?? ''))]));
        $line = $parts[0] . ($detail !== '' ? ' — ' . $detail : '');
        $hours = trim((string) ($destination['working_hours'] ?? ''));

        return $hours !== '' ? $line . ' (' . $hours . ')' : $line;
    }
}
