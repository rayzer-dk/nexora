<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Console;

use Commerce\Modules\Localization\Domain\LocaleNormalizer;
use Commerce\Modules\Migration\Application\MigrationCatalogImporter;
use Commerce\Modules\Migration\Application\MigrationDryRunAnalyzer;
use Commerce\Modules\Migration\Application\MigrationImportPlan;
use Commerce\Modules\Migration\Application\MigrationTargetConflictAnalyzer;
use Commerce\Modules\Migration\Source\OpenCart\OpenCart3CatalogSource;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name:'commerce:migration:opencart', description:'Dry-run, import, resume or rollback an OpenCart/ocStore 3.x migration. Source database is read-only.')] 
final class OpenCartMigrationCommand extends Command
{
    public function __construct(
        private readonly MigrationDryRunAnalyzer $dryRun,
        private readonly MigrationCatalogImporter $importer,
        private readonly MigrationTargetConflictAnalyzer $targetConflicts,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'PDO DSN for the source database, for example mysql:host=127.0.0.1;dbname=shop;charset=utf8mb4')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'OpenCart table prefix', 'oc_')
            ->addOption('source-key', null, InputOption::VALUE_REQUIRED, 'Stable non-secret source instance identifier used for idempotency')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Target store ID')
            ->addOption('market', null, InputOption::VALUE_REQUIRED, 'Target market ID')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Target primary locale', 'uk-UA')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Target currency', 'UAH')
            ->addOption('locale-map', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Locale mapping source=target, repeatable')
            ->addOption('image-root', null, InputOption::VALUE_REQUIRED, 'Optional OpenCart image/catalog root for safe media copy')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch size 1-1000', '250')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply import. Without this flag only dry-run is performed.')
            ->addOption('publish-products', null, InputOption::VALUE_NONE, 'Publish enabled source products immediately; default keeps products as draft.')
            ->addOption('resume', null, InputOption::VALUE_REQUIRED, 'Resume a previous migration run UUID')
            ->addOption('rollback', null, InputOption::VALUE_REQUIRED, 'Rollback entities created by the specified migration run UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rollback = trim((string) $input->getOption('rollback'));
        if ($rollback !== '') {
            try {
                $result = $this->importer->rollback($rollback);
                $io->success(sprintf('Rollback completed. Deleted=%d, skipped=%d.', $result['deleted'], $result['skipped']));
                return Command::SUCCESS;
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        $dsn = trim((string) $input->getOption('dsn'));
        $sourceKey = trim((string) $input->getOption('source-key'));
        if ($dsn === '' || $sourceKey === '') {
            $io->error('--dsn and --source-key are required unless --rollback is used.');
            return Command::INVALID;
        }
        $user = (string) (getenv('MIGRATION_SOURCE_DB_USER') ?: '');
        $password = (string) (getenv('MIGRATION_SOURCE_DB_PASSWORD') ?: '');

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $source = new OpenCart3CatalogSource($pdo, (string) $input->getOption('prefix'), new LocaleNormalizer());
            $batch = max(1, min(1000, (int) $input->getOption('batch')));
            $report = $this->dryRun->analyze($source, $batch);

            $io->section('Dry-run');
            foreach ($report->counts as $type => $count) {
                $io->writeln(sprintf('%s: %d', $type, $count));
            }
            $errors = 0;
            $warnings = 0;
            foreach ($report->issues as $issue) {
                if ($issue->severity === 'error') $errors++; else $warnings++;
                $io->writeln(sprintf('[%s] %s %s %s', strtoupper($issue->severity), $issue->code, $issue->sourceKey ?? '-', $issue->message));
            }
            $io->writeln(sprintf('Issues: errors=%d warnings=%d', $errors, $warnings));

            if (!(bool) $input->getOption('apply')) {
                $io->note('Dry-run only. No target data was changed. Use --apply after reviewing the report.');
                return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
            }
            if ($errors > 0) {
                $io->error('Import refused because dry-run found blocking errors.');
                return Command::FAILURE;
            }

            $store = (int) $input->getOption('store');
            $market = (int) $input->getOption('market');
            if ($store < 1 || $market < 1) {
                $io->error('--store and --market are required for --apply.');
                return Command::INVALID;
            }
            $localeMap = $this->localeMap((array) $input->getOption('locale-map'));
            $plan = new MigrationImportPlan(
                $store,
                $market,
                (string) $input->getOption('locale'),
                strtoupper((string) $input->getOption('currency')),
                $sourceKey,
                $localeMap,
                (bool) $input->getOption('publish-products'),
                true,
                $batch,
                ($root = trim((string) $input->getOption('image-root'))) !== '' ? $root : null,
            );
            $targetIssues = $this->targetConflicts->analyze($source, $plan);
            if ($targetIssues !== []) {
                $io->section('Target conflict report');
                foreach ($targetIssues as $issue) {
                    $io->writeln(sprintf('[%s] %s %s %s', strtoupper($issue->severity), $issue->code, $issue->sourceKey ?? '-', $issue->message));
                }
            }

            $resume = trim((string) $input->getOption('resume'));
            $result = $this->importer->import($source, $plan, $resume !== '' ? $resume : null);
            $io->success(sprintf(
                'Migration %s completed. created=%d reused=%d skipped=%d failed=%d issues=%d',
                $result->runId,
                $result->counts['created'] ?? 0,
                $result->counts['reused'] ?? 0,
                $result->counts['skipped'] ?? 0,
                $result->counts['failed'] ?? 0,
                $result->issues,
            ));
            return ($result->counts['failed'] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    /** @param list<string> $values @return array<string,string> */
    private function localeMap(array $values): array
    {
        $map = [];
        foreach ($values as $value) {
            $parts = explode('=', (string) $value, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f00257501e05'));
            }
            $map[trim($parts[0])] = trim($parts[1]);
        }
        return $map;
    }
}
