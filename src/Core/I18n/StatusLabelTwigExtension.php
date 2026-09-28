<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Human labels for machine codes (order/payment/fulfillment statuses, payment methods …):
 * {{ order.status|status_label('order') }} looks up "status.order.<code>" and falls back to a readable
 * form of the code, so a new status introduced by a module never shows an empty cell.
 */
final class StatusLabelTwigExtension extends AbstractExtension
{
    public function __construct(private readonly StorefrontUiTwigExtension $ui)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('status_label', $this->label(...), ['needs_context' => true])];
    }

    /** @param array<string,mixed> $context */
    public function label(array $context, mixed $code, string $group): string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return '—';
        }
        $key = 'status.' . $group . '.' . $code;
        $text = $this->ui->text($context, $key);

        return $text !== $key ? $text : ucfirst(str_replace(['_', '-'], ' ', $code));
    }
}
