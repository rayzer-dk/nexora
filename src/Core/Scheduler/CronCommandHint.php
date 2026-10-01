<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Builds the copy-paste crontab lines shown in the admin from the real PHP binary and project path. */
final class CronCommandHint
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /** Absolute path of the PHP CLI binary that most likely matches this PHP version. The web SAPI binary (php-fpm, apache) cannot run cron jobs. */
    public function phpBinary(): string
    {
        $candidates = [];
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            $candidates[] = PHP_BINARY;
        }
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach (array_unique([PHP_BINDIR, '/usr/local/bin', '/usr/bin', '/opt/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/bin']) as $dir) {
            $candidates[] = $dir . '/php' . $version;
            $candidates[] = $dir . '/php';
        }
        foreach ($candidates as $path) {
            if ($path !== '' && @is_file($path) && @is_executable($path) && !str_contains(basename($path), 'fpm') && !str_contains(basename($path), 'cgi')) {
                return $path;
            }
        }

        return '/usr/bin/php';
    }

    public function consolePath(): string
    {
        $root = realpath($this->projectDir) ?: $this->projectDir;

        return rtrim(str_replace('\\', '/', $root), '/') . '/bin/console';
    }

    public function schedule(): string
    {
        return '*/5 * * * *';
    }

    /** Command without the schedule (what shared-hosting panels ask for in a separate field). */
    public function command(): string
    {
        return self::quote($this->phpBinary()) . ' ' . self::quote($this->consolePath()) . ' commerce:cron:run';
    }

    public function line(): string
    {
        return $this->schedule() . ' ' . $this->command() . ' >/dev/null 2>&1';
    }

    public function webLine(string $url): string
    {
        return $this->schedule() . ' curl -fsS --max-time 60 ' . self::quote($url) . ' >/dev/null 2>&1';
    }

    /** Quote a value only when it needs quoting, so the common case stays readable. */
    private static function quote(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_./:@%+=,-]+$#', $value) === 1 ? $value : "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
