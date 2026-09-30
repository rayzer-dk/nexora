<?php

declare(strict_types=1);

namespace Commerce\Core\Health\Console;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Keeps technical tables and runtime directories bounded.
 *
 * Only technical/transient data is removed: logs, expired tokens, abandoned guest carts, finished deliveries,
 * superseded exchange rates, automatic pre-update snapshots beyond the newest N and leftovers of updates.
 * Orders, customers, catalog, manual snapshots and anything still pending/failed are never touched.
 * Every delete walks the primary key in small batches so the store is not locked while it runs.
 */
#[AsCommand(name: 'commerce:maintenance:retention', description: 'Remove expired technical data, old logs and runtime leftovers in small batches.')]
final class DataRetentionCommand extends Command
{
    private const BATCH = 2000;

    private bool $dryRun = false;
    private int $maxBatches = 50;

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.cache_dir%')] private readonly string $cacheDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be removed')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Maximum batches of 2000 rows per rule and run', '50')
            ->addOption('guest-cart-days', null, InputOption::VALUE_REQUIRED, 'Guest carts untouched for this many days', '30')
            ->addOption('customer-cart-days', null, InputOption::VALUE_REQUIRED, 'Carts of registered customers untouched for this many days', '180')
            ->addOption('search-log-days', null, InputOption::VALUE_REQUIRED, 'Search analytics history', '180')
            ->addOption('audit-days', null, InputOption::VALUE_REQUIRED, 'Admin audit log history', '365')
            ->addOption('security-days', null, InputOption::VALUE_REQUIRED, 'Security event history', '180')
            ->addOption('incident-days', null, InputOption::VALUE_REQUIRED, 'Runtime incident history', '90')
            ->addOption('delivery-days', null, InputOption::VALUE_REQUIRED, 'Finished webhook/marketing deliveries', '30')
            ->addOption('form-days', null, InputOption::VALUE_REQUIRED, 'Handled form submissions (contain contact data)', '365')
            ->addOption('inquiry-days', null, InputOption::VALUE_REQUIRED, 'Closed customer inquiries (contain contact data)', '365')
            ->addOption('ai-log-days', null, InputOption::VALUE_REQUIRED, 'AI assistant usage log', '90')
            ->addOption('automation-log-days', null, InputOption::VALUE_REQUIRED, 'Automation rule run log', '90')
            ->addOption('price-history-days', null, InputOption::VALUE_REQUIRED, 'Closed price history rows', '730')
            ->addOption('keep-auto-snapshots', null, InputOption::VALUE_REQUIRED, 'Automatic pre-update snapshots to keep', '3')
            ->addOption('log-days', null, InputOption::VALUE_REQUIRED, 'Rotated log files and update leftovers', '14');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dryRun = (bool) $input->getOption('dry-run');
        $this->maxBatches = max(1, min(1000, (int) $input->getOption('max-batches')));
        $days = static fn (string $name, int $min, int $max): int => max($min, min($max, (int) $input->getOption($name)));

        $guestCart = $this->cutoff($days('guest-cart-days', 7, 3650));
        $customerCart = $this->cutoff($days('customer-cart-days', 30, 3650));
        $delivery = $this->cutoff($days('delivery-days', 7, 3650));
        $now = gmdate('Y-m-d H:i:s');

        $report = [];
        // Carts: converted carts are only a pointer once the order exists; items cascade, reservations are SET NULL.
        $report['carts_guest'] = $this->purge('mc_cart', "customer_id IS NULL AND updated_at < ?", [$guestCart]);
        $report['carts_customer'] = $this->purge('mc_cart', "customer_id IS NOT NULL AND updated_at < ?", [$customerCart]);
        $report['carts_converted'] = $this->purge('mc_cart', "status = 'converted' AND updated_at < ?", [$guestCart]);

        $report['search_log'] = $this->purge('mc_search_query_log', 'created_at < ?', [$this->cutoff($days('search-log-days', 30, 3650))]);
        $report['audit_log'] = $this->purge('mc_audit_log', 'created_at < ?', [$this->cutoff($days('audit-days', 90, 3650))]);
        $report['security_events'] = $this->purge('mc_security_event', 'created_at < ?', [$this->cutoff($days('security-days', 30, 3650))]);
        $report['runtime_incidents'] = $this->purge('mc_runtime_incident', 'created_at < ?', [$this->cutoff($days('incident-days', 14, 3650))]);

        $report['idempotency_keys'] = $this->purge('mc_idempotency_key', 'expires_at < ?', [$this->cutoff(1)]);
        $report['api_idempotency'] = $this->purge('mc_api_idempotency', 'expires_at < ?', [$this->cutoff(1)]);
        $report['account_tokens'] = $this->purge('mc_customer_account_token', '(expires_at < ? OR consumed_at < ?)', [$this->cutoff(7), $this->cutoff(7)]);
        $report['reservations'] = $this->purge('mc_inventory_reservation', "status IN ('released','committed','expired') AND COALESCE(released_at, committed_at, expires_at) < ?", [$this->cutoff(90)]);

        $report['webhook_deliveries'] = $this->purge('mc_webhook_delivery', "status = 'delivered' AND completed_at < ?", [$delivery]);
        $report['webhook_dead'] = $this->purge('mc_webhook_delivery', "status = 'dead' AND completed_at < ?", [$this->cutoff(90)]);
        $report['marketing_deliveries'] = $this->purge('mc_marketing_delivery', "status = 'sent' AND updated_at < ?", [$delivery]);
        $report['webhook_replay'] = $this->purge('mc_webhook_replay', 'received_at < ?', [$this->cutoff(7)]);
        $report['api_rate_window'] = $this->count('mc_api_rate_window', 'window_started_at < ?', [$this->cutoff(1)], static fn (Connection $db, array $p): int => $db->executeStatement('DELETE FROM mc_api_rate_window WHERE window_started_at < ?', $p));

        // Privacy and growth: closed inquiries hold names, phones and e-mails; the rest are plain logs.
        $report['form_submissions_handled'] = $this->purge('mc_form_submission', "status='handled' AND created_at < ?", [$this->cutoff($days('form-days', 30, 3650))]);
        if (!$this->dryRun && $this->tableExists('mc_form_submission')) {
            // Uploaded attachments live in var/forms/<submission id>/; drop those whose submission was purged.
            $report['form_files_orphaned'] = $this->sweepFormFiles();
        }
        $report['inquiries_closed'] = $this->purge('mc_customer_inquiry', "status IN ('resolved','closed') AND updated_at < ?", [$this->cutoff($days('inquiry-days', 30, 3650))]);
        $report['ai_usage'] = $this->purge('mc_ai_usage', 'created_at < ?', [$this->cutoff($days('ai-log-days', 30, 3650))]);
        $report['automation_runs'] = $this->purge('mc_automation_run', 'created_at < ?', [$this->cutoff($days('automation-log-days', 30, 3650))]);
        $report['push_stale'] = $this->purge('mc_push_subscription', 'COALESCE(last_success_at, created_at) < ?', [$this->cutoff(270)]);
        $report['marketing_automation_deliveries'] = $this->purge('mc_marketing_automation_delivery', "status <> 'pending' AND created_at < ?", [$this->cutoff(365)]);
        $report['extension_events'] = $this->purge('mc_extension_lifecycle_event', 'created_at < ?', [$this->cutoff(365)]);
        $report['price_history'] = $this->purge('mc_price_history', 'valid_to IS NOT NULL AND valid_to < ?', [$this->cutoff($days('price-history-days', 90, 3650))]);
        $report['analytics_sessions'] = $this->purgeAnalytics();

        // Exchange rates: keep 90 days of history and always the newest observation of every pair.
        $report['exchange_rates'] = $this->purge(
            'mc_exchange_rate',
            'observed_at < ? AND id NOT IN (SELECT keep_id FROM (SELECT MAX(id) AS keep_id FROM mc_exchange_rate GROUP BY base_currency, quote_currency) latest)',
            [$this->cutoff(90)],
        );

        $report['auto_snapshots'] = $this->purgeAutoSnapshots(max(1, min(50, (int) $input->getOption('keep-auto-snapshots'))));
        $report['failed_snapshots'] = $this->purgeSnapshotRows("status = 'failed' AND created_at < ?", [$this->cutoff(7)]);

        $fileDays = $days('log-days', 3, 3650);
        $report['old_release_caches'] = $this->purgeOldReleaseCaches();
        $report['update_quarantine'] = $this->purgeDirectoryChildren('var/update/quarantine', $fileDays);
        $report['update_stage'] = $this->purgeDirectoryChildren('var/update/stage', 1);
        $report['update_inbox'] = $this->purgeUpdateInbox($fileDays);
        $report['recovery_leftovers'] = $this->purgeDirectoryChildren('var/recovery/quarantine', $fileDays)
            + $this->purgeDirectoryChildren('var/recovery/restore-stage', 1);
        $report['log_files'] = $this->rotateLogs($fileDays);
        $report['cache_pool_prune'] = $this->pruneCachePools();

        foreach ($report as $rule => $count) {
            $output->writeln(sprintf('%-22s %d', $rule, $count));
        }
        $output->writeln(sprintf('retention: %s, removed=%d, at=%s', $this->dryRun ? 'dry-run' : 'done', array_sum($report), $now));

        return Command::SUCCESS;
    }

    /** @param list<string> $params */
    private function sweepFormFiles(): int
    {
        $root = $this->projectDir . '/var/forms';
        if (!is_dir($root)) {
            return 0;
        }
        $removed = 0;
        foreach (new \DirectoryIterator($root) as $item) {
            if ($item->isDot() || !$item->isDir() || preg_match('/^\d+$/', $item->getFilename()) !== 1) {
                continue;
            }
            if ($this->db->fetchOne('SELECT 1 FROM mc_form_submission WHERE id=?', [(int) $item->getFilename()]) !== false) {
                continue;
            }
            foreach (glob($item->getPathname() . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($item->getPathname());
            ++$removed;
        }

        return $removed;
    }

    private function purge(string $table, string $where, array $params): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        if ($this->dryRun) {
            return (int) $this->db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where), $params);
        }
        $total = 0;
        for ($batch = 0; $batch < $this->maxBatches; $batch++) {
            // Old rows are at the start of the primary key, so the scan stops as soon as the batch is full.
            $ids = array_map('intval', $this->db->fetchFirstColumn(
                sprintf('SELECT id FROM %s WHERE %s ORDER BY id LIMIT %d', $table, $where, self::BATCH),
                $params,
            ));
            if ($ids === []) {
                break;
            }
            $total += $this->db->executeStatement(sprintf('DELETE FROM %s WHERE id IN (?)', $table), [$ids], [ArrayParameterType::INTEGER]);
            if (count($ids) < self::BATCH) {
                break;
            }
        }

        return $total;
    }

    /**
     * @param list<string> $params
     * @param callable(Connection,list<string>):int $delete
     */
    private function count(string $table, string $where, array $params, callable $delete): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        return $this->dryRun
            ? (int) $this->db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where), $params)
            : $delete($this->db, $params);
    }

    private function purgeAutoSnapshots(int $keep): int
    {
        if (!$this->tableExists('mc_recovery_snapshot')) {
            return 0;
        }
        $keepIds = array_map('intval', $this->db->fetchFirstColumn(
            "SELECT id FROM mc_recovery_snapshot WHERE reason LIKE 'pre-%' AND status <> 'failed' ORDER BY id DESC LIMIT " . $keep,
        ));
        if ($keepIds === []) {
            return 0;
        }
        return $this->purgeSnapshotRows("reason LIKE 'pre-%' AND id < ?", [(string) min($keepIds)]);
    }

    /** @param list<string> $params */
    private function purgeSnapshotRows(string $where, array $params): int
    {
        if (!$this->tableExists('mc_recovery_snapshot')) {
            return 0;
        }
        $rows = $this->db->fetchAllAssociative('SELECT id, archive_path FROM mc_recovery_snapshot WHERE ' . $where . ' ORDER BY id LIMIT 200', $params);
        if ($this->dryRun) {
            return count($rows);
        }
        $removed = 0;
        foreach ($rows as $row) {
            $path = is_string($row['archive_path'] ?? null) && $row['archive_path'] !== '' ? $this->absolute((string) $row['archive_path']) : null;
            if ($path !== null && is_file($path) && $this->insideProject($path) && !@unlink($path)) {
                continue; // keep the row while the file cannot be removed, so it stays visible in the admin
            }
            $removed += $this->db->executeStatement('DELETE FROM mc_recovery_snapshot WHERE id = ?', [(int) $row['id']]);
        }

        return $removed;
    }

    /** Keeps the running release cache and the newest previous one (instant rollback), removes the rest. */
    private function purgeOldReleaseCaches(): int
    {
        $current = realpath($this->cacheDir) ?: $this->cacheDir;
        $root = dirname($current);
        $prefix = preg_replace('/-[^-]+$/', '', basename($current)) . '-';
        $others = [];
        foreach (glob($root . '/' . $prefix . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ((realpath($dir) ?: $dir) !== $current) {
                $others[$dir] = (int) @filemtime($dir);
            }
        }
        arsort($others);
        $removed = 0;
        foreach (array_slice(array_keys($others), 1) as $dir) {
            $removed++;
            if (!$this->dryRun) {
                $this->removeTree($dir);
            }
        }

        return $removed;
    }

    private function purgeDirectoryChildren(string $relative, int $days): int
    {
        $dir = $this->projectDir . '/' . $relative;
        if (!is_dir($dir)) {
            return 0;
        }
        $limit = time() - $days * 86400;
        $removed = 0;
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot() || $item->getMTime() >= $limit) {
                continue;
            }
            $removed++;
            if (!$this->dryRun) {
                $item->isDir() && !$item->isLink() ? $this->removeTree($item->getPathname()) : @unlink($item->getPathname());
            }
        }

        return $removed;
    }

    /** Uploaded update bundles are kept only while an attempt still needs them (staged/applying). */
    private function purgeUpdateInbox(int $days): int
    {
        $dir = $this->projectDir . '/var/update/inbox';
        if (!is_dir($dir)) {
            return 0;
        }
        $needed = [];
        if ($this->tableExists('mc_update_attempt')) {
            foreach ($this->db->fetchFirstColumn("SELECT staged_path FROM mc_update_attempt WHERE status IN ('staged','applying') AND staged_path IS NOT NULL") as $path) {
                $needed[$this->absolute((string) $path)] = true;
            }
        }
        $limit = time() - $days * 86400;
        $removed = 0;
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot() || !$item->isFile() || $item->getMTime() >= $limit || isset($needed[$item->getPathname()])) {
                continue;
            }
            $removed++;
            if (!$this->dryRun) {
                @unlink($item->getPathname());
            }
        }

        return $removed;
    }

    /** Rotates *.log files over 20 MB and deletes rotated copies older than the retention window. */
    private function rotateLogs(int $days): int
    {
        $dir = $this->projectDir . '/var/log';
        if (!is_dir($dir)) {
            return 0;
        }
        $limit = time() - $days * 86400;
        $changed = 0;
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot() || !$item->isFile()) {
                continue;
            }
            $name = $item->getFilename();
            if (str_ends_with($name, '.log') && $item->getSize() > 20 * 1024 * 1024) {
                $changed++;
                if (!$this->dryRun) {
                    @rename($item->getPathname(), $item->getPathname() . '.' . gmdate('Ymd-His'));
                }
            } elseif (preg_match('/\.log\.[0-9-]+(\.gz)?$/', $name) === 1 && $item->getMTime() < $limit) {
                $changed++;
                if (!$this->dryRun) {
                    @unlink($item->getPathname());
                }
            }
        }

        return $changed;
    }

    /** Filesystem cache pools never remove expired entries on their own; prune them. */
    private function pruneCachePools(): int
    {
        if ($this->dryRun) {
            return 0;
        }
        $command = $this->getApplication()?->has('cache:pool:prune') ? $this->getApplication()->find('cache:pool:prune') : null;
        if ($command === null) {
            return 0;
        }
        try {
            return $command->run(new ArrayInput([]), new BufferedOutput()) === Command::SUCCESS ? 1 : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /** Raw visit sessions and daily page counters are kept for the per-store retention setting (default 400 days). */
    private function purgeAnalytics(): int
    {
        if (!$this->tableExists('mc_analytics_session')) {
            return 0;
        }
        $settings = $this->tableExists('mc_analytics_settings') ? $this->db->fetchAllKeyValue('SELECT store_id,retention_days FROM mc_analytics_settings') : [];
        $total = 0;
        foreach ($this->db->fetchFirstColumn('SELECT id FROM mc_store') as $storeId) {
            $days = max(30, min(1095, (int) ($settings[$storeId] ?? 400)));
            $total += $this->purge('mc_analytics_session', 'store_id = ' . (int) $storeId . ' AND started_at < ?', [$this->cutoff($days)]);
            $cut = gmdate('Y-m-d', time() - $days * 86400);
            if ($this->tableExists('mc_analytics_page_daily')) {
                $total += $this->dryRun
                    ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_analytics_page_daily WHERE store_id=? AND day < ?', [$storeId, $cut])
                    : $this->db->executeStatement('DELETE FROM mc_analytics_page_daily WHERE store_id=? AND day < ? LIMIT 100000', [$storeId, $cut]);
            }
        }

        return $total;
    }

    private function cutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    }

    private function tableExists(string $table): bool
    {
        static $tables = null;
        $tables ??= array_flip(array_map('strtolower', $this->db->createSchemaManager()->listTableNames()));

        return isset($tables[strtolower($table)]);
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ? $path : $this->projectDir . '/' . ltrim($path, '/');
    }

    private function insideProject(string $path): bool
    {
        $real = realpath($path);
        $root = realpath($this->projectDir);

        return $real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            @unlink($directory);
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
