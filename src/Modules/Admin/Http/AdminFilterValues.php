<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Symfony\Component\HttpFoundation\Request;

/** Reads a list filter of an admin list: "?status[]=a&status[]=b" and the old single "?status=a" both work. */
final class AdminFilterValues
{
    /**
     * @param list<string>|null $allowed when given, anything else is dropped
     * @return list<string>
     */
    public static function list(Request $request, string $key, ?array $allowed = null, int $max = 24, ?array $source = null): array
    {
        $raw = $source !== null ? ($source[$key] ?? null) : ($request->query->all()[$key] ?? null);
        $parts = is_array($raw) ? $raw : (is_scalar($raw) ? [$raw] : []);
        $values = [];
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }
            $value = trim((string) $part);
            if ($value === '' || mb_strlen($value) > 120 || ($allowed !== null && !in_array($value, $allowed, true))) {
                continue;
            }
            $values[$value] = $value;
        }

        return array_slice(array_values($values), 0, $max);
    }

    /** Same for a posted form (saved views). @return list<string> */
    public static function post(Request $request, string $key, ?array $allowed = null): array
    {
        return self::list($request, $key, $allowed, 24, $request->request->all());
    }

    /**
     * Adds "column IN (?,?)" for the values to $where/$params. Does nothing for an empty list.
     *
     * @param list<string> $where
     * @param list<mixed> $params
     * @param list<string|int> $values
     */
    public static function in(string $column, array $values, array &$where, array &$params): void
    {
        if ($values === []) {
            return;
        }
        $where[] = $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }
}
