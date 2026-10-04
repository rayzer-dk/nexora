<?php

declare(strict_types=1);

/**
 * In-place upgrade after the release archive was unpacked over an existing installation.
 *
 * Dependency-free on purpose: it must run even when the unpacked code no longer compiles into a container
 * (stale files from the previous release are the usual cause of "cannot open the admin or the storefront"
 * after an overlay). Steps: remove files that are not part of the new release, clear compiled caches,
 * reset OPcache, boot the application and apply pending database migrations.
 *
 * Never touched: .env, .env.local, var/ (except var/cache), public/media, extensions, bonus, deploy, docs.
 */

/** Directories whose content is fully described by SHA256SUMS.txt and may be cleaned of stale files. */
const NEXORA_UPGRADE_MANAGED = ['src', 'migrations', 'bin', 'bootstrap', 'tools', 'config', 'resources', 'assets', 'themes/default', 'public/build', 'public/assets'];

/**
 * @param callable(string):void $log
 * @return array{ok:bool,removed:int,version:string,schema:int,output:string,error:string}
 */
function nexora_upgrade_run(string $projectDir, callable $log): array
{
    $result = ['ok' => false, 'removed' => 0, 'version' => '', 'schema' => 0, 'output' => '', 'error' => ''];
    @set_time_limit(0);
    @ignore_user_abort(true);

    $lock = @fopen($projectDir . '/var/upgrade.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        $result['error'] = 'upgrade_running';
        return $result;
    }

    try {
        $log('manifest');
        $manifest = nexora_upgrade_manifest($projectDir);
        if ($manifest === null) {
            $log('manifest_missing');
        } else {
            $result['removed'] = nexora_upgrade_remove_stale($projectDir, $manifest, $log);
        }

        $log('cache');
        nexora_upgrade_clear_dir($projectDir . '/var/cache');
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);

        $log('migrate');
        $autoload = $projectDir . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('vendor/autoload.php is missing: the archive was not unpacked completely.');
        }
        require_once $projectDir . '/bootstrap/tmpdir.php';
        require_once $autoload;
        if (class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
            // Same rule as public/index.php: .env when present, otherwise .env.local, otherwise real environment variables.
            $dotenv = (new \Symfony\Component\Dotenv\Dotenv())->usePutenv();
            if (is_file($projectDir . '/.env')) {
                $dotenv->bootEnv($projectDir . '/.env');
            } elseif (is_file($projectDir . '/.env.local')) {
                $dotenv->load($projectDir . '/.env.local');
            }
        }
        $environment = (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod');
        $kernel = new \Commerce\Kernel($environment, false);
        try {
            $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            // A database backup before the schema changes. Best effort: an upgrade must never be blocked by it.
            $log('snapshot');
            try {
                $snapshotInput = new \Symfony\Component\Console\Input\ArrayInput(['command' => 'commerce:recovery:snapshot', '--backup-profile' => 'database', '--reason' => 'before-upgrade', '--no-interaction' => true]);
                $snapshotInput->setInteractive(false);
                $snapshotOutput = new \Symfony\Component\Console\Output\BufferedOutput();
                $snapshotCode = $application->run($snapshotInput, $snapshotOutput);
                nexora_upgrade_log($projectDir, 'snapshot exit ' . $snapshotCode . ' ' . trim((string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $snapshotOutput->fetch())));
            } catch (Throwable $snapshotError) {
                nexora_upgrade_log($projectDir, 'snapshot skipped: ' . $snapshotError::class . ': ' . $snapshotError->getMessage());
            }
            $input = new \Symfony\Component\Console\Input\ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
                '--allow-no-migration' => true,
            ]);
            $input->setInteractive(false);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            $code = $application->run($input, $output);
            $result['output'] = trim((string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $output->fetch()));
            if ($code !== 0) {
                throw new RuntimeException('Migrations failed (exit ' . $code . ').');
            }
        } finally {
            $kernel->shutdown();
        }
        if (class_exists(\Commerce\Core\Platform\PlatformVersion::class)) {
            $result['version'] = (string) \Commerce\Core\Platform\PlatformVersion::VERSION;
            $result['schema'] = (int) \Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA;
        }
        $log('done');
        $result['ok'] = true;
    } catch (Throwable $error) {
        $result['error'] = $error::class . ': ' . $error->getMessage();
        nexora_upgrade_log($projectDir, 'FAILED ' . $result['error'] . "\n" . $error->getTraceAsString());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    nexora_upgrade_log($projectDir, ($result['ok'] ? 'OK ' : 'FAILED ') . json_encode(['removed' => $result['removed'], 'version' => $result['version']]));

    return $result;
}

function nexora_upgrade_log(string $projectDir, string $message): void
{
    $dir = $projectDir . '/var/log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/upgrade.log', '[' . gmdate('c') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

/** @return array<string,true>|null relative path => true, or null when the manifest is unusable */
function nexora_upgrade_manifest(string $projectDir): ?array
{
    $raw = @file_get_contents($projectDir . '/SHA256SUMS.txt');
    if (!is_string($raw)) {
        return null;
    }
    $files = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        if (preg_match('/^[0-9a-f]{64}\s+\*?(?:\.\/)?(.+)$/', trim($line), $m) === 1) {
            $files[$m[1]] = true;
        }
    }

    // Safety net: a truncated manifest must never lead to mass deletion.
    return count($files) > 500 && isset($files['src/Kernel.php'], $files['bootstrap/upgrade.php']) ? $files : null;
}

/**
 * Files the store owner added on purpose are not "stale": a whole language directory under resources/translations that the
 * release does not ship (a language added by hand). Everything else not listed in SHA256SUMS.txt is removed.
 *
 * @param array<string,true> $manifest
 */
function nexora_upgrade_is_user_file(string $rel, array $manifest): bool
{
    if (preg_match('~^resources/translations/([^/]+)/~', $rel, $m) !== 1) {
        return false;
    }
    foreach ($manifest as $path => $_) {
        if (str_starts_with((string) $path, 'resources/translations/' . $m[1] . '/')) {
            return false;
        }
    }

    return true;
}

/** @param array<string,true> $manifest */
function nexora_upgrade_remove_stale(string $projectDir, array $manifest, callable $log): int
{
    $removed = 0;
    foreach (NEXORA_UPGRADE_MANAGED as $relative) {
        $base = $projectDir . '/' . $relative;
        if (!is_dir($base) || is_link($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = str_replace('\\', '/', $item->getPathname());
            $rel = ltrim(substr($path, strlen(str_replace('\\', '/', $projectDir))), '/');
            if ($item->isDir() && !$item->isLink()) {
                // Empty directories left behind by removed files.
                if (count(scandir($item->getPathname()) ?: []) <= 2) {
                    @rmdir($item->getPathname());
                }
                continue;
            }
            if (!isset($manifest[$rel]) && !nexora_upgrade_is_user_file($rel, $manifest) && @unlink($item->getPathname())) {
                ++$removed;
                $log('stale:' . $rel);
                nexora_upgrade_log($projectDir, 'removed stale file ' . $rel);
            }
        }
    }

    return $removed;
}

function nexora_upgrade_clear_dir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) {
            continue;
        }
        if ($item->isDir() && !$item->isLink()) {
            nexora_upgrade_clear_dir($item->getPathname());
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
}

/** APP_SECRET from the environment or the dotenv files; empty string when unknown. */
function nexora_upgrade_secret(string $projectDir): string
{
    $value = (string) (getenv('APP_SECRET') ?: '');
    foreach (['/.env.local', '/.env'] as $file) {
        if ($value !== '') {
            break;
        }
        $raw = @file_get_contents($projectDir . $file);
        if (is_string($raw) && preg_match('/^\s*(?:export\s+)?APP_SECRET\s*=\s*(.*)$/m', $raw, $m) === 1) {
            $value = trim(trim($m[1]), "\"' \t\r");
        }
    }

    return $value;
}
