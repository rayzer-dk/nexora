<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Dates are stored in UTC with microseconds; people should see them in the store's time zone:
 * {{ order.created_at|local_datetime }} → "27.09.2026 19:38".
 */
final class LocalDateTimeTwigExtension extends AbstractExtension
{
    private ?DateTimeZone $zone = null;

    public function __construct(private readonly Connection $db, private readonly RequestStack $requests)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('local_datetime', fn (mixed $value, string $format = 'd.m.Y H:i'): string => $this->format($value, $format)),
            new TwigFilter('local_date', fn (mixed $value): string => $this->format($value, 'd.m.Y')),
        ];
    }

    public function format(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            $date = $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));

            return $date->setTimezone($this->zone())->format($format);
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function zone(): DateTimeZone
    {
        if ($this->zone !== null) {
            return $this->zone;
        }
        $name = 'UTC';
        try {
            $request = $this->requests->getCurrentRequest();
            $storeId = 0;
            if ($request !== null && $request->hasSession() && str_starts_with($request->getPathInfo(), '/admin')) {
                $storeId = (int) $request->getSession()->get('admin_context.store_id', 0);
            }
            $row = $storeId > 0
                ? $this->db->fetchOne('SELECT timezone FROM mc_store WHERE id=?', [$storeId])
                : $this->db->fetchOne("SELECT timezone FROM mc_store WHERE status='active' ORDER BY id LIMIT 1");
            $name = is_string($row) && in_array($row, DateTimeZone::listIdentifiers(), true) ? $row : 'UTC';
        } catch (Throwable) {
        }

        return $this->zone = new DateTimeZone($name);
    }
}
