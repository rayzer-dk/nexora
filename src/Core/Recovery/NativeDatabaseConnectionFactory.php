<?php

declare(strict_types=1);

namespace Commerce\Core\Recovery;

use PDO;
use RuntimeException;

final class NativeDatabaseConnectionFactory
{
    public static function fromProject(string $projectDir): PDO
    {
        $databaseUrl = getenv('DATABASE_URL');
        if (!is_string($databaseUrl) || trim($databaseUrl) === '') {
            $databaseUrl = self::readDatabaseUrl($projectDir);
        }
        if ($databaseUrl === null || trim($databaseUrl) === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.80c0cb687968'));
        }
        return self::fromUrl($databaseUrl);
    }

    public static function fromUrl(string $url): PDO
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ecb14bd3c22f'));
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a030b5d1600a'));
        }
        $database = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
        if ($database === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a8a8e6405852'));
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $charset = isset($query['charset']) && preg_match('/^[A-Za-z0-9_]+$/D', (string) $query['charset']) === 1
            ? (string) $query['charset']
            : 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) $parts['host'],
            (int) ($parts['port'] ?? 3306),
            rawurldecode($database),
            $charset,
        );
        $pdo = new PDO(
            $dsn,
            rawurldecode((string) ($parts['user'] ?? '')),
            rawurldecode((string) ($parts['pass'] ?? '')),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ],
        );
        $pdo->exec("SET NAMES {$charset}");
        return $pdo;
    }

    private static function readDatabaseUrl(string $projectDir): ?string
    {
        $candidates = ['.env', '.env.local', '.env.prod', '.env.prod.local'];
        $value = null;
        foreach ($candidates as $file) {
            $path = rtrim($projectDir, '/\\') . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                if (preg_match('/^\s*DATABASE_URL\s*=\s*(.+?)\s*$/', $line, $match) !== 1) {
                    continue;
                }
                $raw = trim((string) $match[1]);
                if (($raw[0] ?? '') === '"' && str_ends_with($raw, '"')) {
                    $raw = stripcslashes(substr($raw, 1, -1));
                } elseif (($raw[0] ?? '') === "'" && str_ends_with($raw, "'")) {
                    $raw = substr($raw, 1, -1);
                }
                if ($raw !== '') {
                    $value = $raw;
                }
            }
        }
        return $value;
    }
}
