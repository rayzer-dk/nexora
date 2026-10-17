<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

/**
 * The manifest "core" constraint, one reading for package validation and the core update preflight.
 * Composer-style subset: "^3.4.8", "~3.4.8", ">=3.0.0", "3.4.8", major-minor forms with an operator ("^4.0"),
 * and branches joined by "||" ("^3.4.8 || ^4.0"). Any malformed branch rejects the whole constraint;
 * one branch that contains the version accepts it.
 */
final class CoreVersionConstraint
{
    private function __construct()
    {
    }

    public static function allows(string $constraint, string $version): bool
    {
        $supported = false;
        foreach (explode('||', trim($constraint)) as $branch) {
            if (preg_match('/^(\^|~|>=)?\s*(\d+)\.(\d+)(?:\.(\d+))?$/D', trim($branch), $match) !== 1) {
                return false;
            }
            $operator = $match[1] ?? '';
            $hasPatch = isset($match[4]) && $match[4] !== '';
            if ($operator === '' && !$hasPatch) {
                return false;
            }
            $supported = $supported || self::branchAllows($operator, (int) $match[2], (int) $match[3], $hasPatch ? (int) $match[4] : null, $version);
        }

        return $supported;
    }

    private static function branchAllows(string $operator, int $major, int $minor, ?int $patch, string $version): bool
    {
        $base = $major . '.' . $minor . '.' . ($patch ?? 0);
        if ($operator === '') {
            return version_compare($version, $base, '==');
        }
        if ($operator === '>=') {
            return version_compare($version, $base, '>=');
        }
        if (version_compare($version, $base, '<')) {
            return false;
        }
        if ($operator === '~' && $patch === null) {
            $upper = ($major + 1) . '.0.0';
        } elseif ($operator === '~') {
            $upper = $major . '.' . ($minor + 1) . '.0';
        } elseif ($major > 0) {
            $upper = ($major + 1) . '.0.0';
        } elseif ($minor > 0) {
            $upper = '0.' . ($minor + 1) . '.0';
        } else {
            $upper = '0.0.' . (($patch ?? 0) + 1);
        }

        return version_compare($version, $upper, '<');
    }
}
