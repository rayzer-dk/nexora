<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Doctrine\DBAL\Connection;

/** The warehouse a market's stock is written to and read from. */
final class MarketStockLocation
{
    /** The market's first active warehouse by priority; 0 when there is none. */
    public static function locationId(Connection $db, int $marketId): int
    {
        return (int) ($db->fetchOne(
            'SELECT mil.location_id FROM mc_market_inventory_location mil JOIN mc_inventory_location l ON l.id=mil.location_id AND l.status=? WHERE mil.market_id=? ORDER BY mil.priority ASC,mil.location_id ASC LIMIT 1',
            ['active', $marketId],
        ) ?: 0);
    }
}
