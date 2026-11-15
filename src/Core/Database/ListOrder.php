<?php

declare(strict_types=1);

namespace Commerce\Core\Database;

/**
 * ORDER BY of an admin list sorted by a column header (?sort=key&dir=asc|desc). Only a key of the list's whitelist reaches
 * the SQL, the direction is ASC or DESC, a tiebreaker keeps pages stable; an absent or unknown key keeps the list's own order.
 */
final class ListOrder
{
    /** @param array<string,string> $columns sort key => SQL expression written by the caller */
    public static function sql(array $columns, string $key, string $dir, string $tiebreaker, string $default): string
    {
        if (!isset($columns[$key])) {
            return $default;
        }

        return $columns[$key] . (strtolower($dir) === 'asc' ? ' ASC' : ' DESC') . ',' . $tiebreaker;
    }
}
