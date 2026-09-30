<?php

declare(strict_types=1);

namespace Commerce\Core\Install;

use Commerce\Core\Health\RequirementLevel;
use Commerce\Core\Health\SystemPreflightInspector;
use Commerce\Modules\Demo\Application\DemoSeeder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:install')]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly SystemPreflightInspector $preflight,
        private readonly Connection $connection,
        private readonly InstallationState $state,
        private readonly InstallationSeeder $seeder,
        private readonly DemoSeeder $demoSeeder,
        private readonly InstallationHealthVerifier $healthVerifier,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(\Commerce\Core\I18n\CanonicalUiText::get('php.command.commerce_install.description'));
        $this
            ->addOption('store-name', null, InputOption::VALUE_REQUIRED, \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.nazva_mahazynu'))
            ->addOption('admin-name', null, InputOption::VALUE_REQUIRED, \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.imia_administratora'))
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.email_administratora'))
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.parol_administratora_shchonaimenshe_12_symvoliv'))
            ->addOption('public-url', null, InputOption::VALUE_REQUIRED, \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.publichna_adresa_mahazynu'), 'https://shop.example.com')
            ->addOption('site-mode', null, InputOption::VALUE_REQUIRED, \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.profil_saitu_shop_catalog_content_landing_hybrid'), 'shop')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'Store country (ISO 3166-1 alpha-2); proposes currency, language, time zone and tax rate', 'UA')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Default currency (ISO 4217); defaults to the country preset', '')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Default language (uk-UA, en-US, ru-RU, pl-PL, de-DE, da-DK); defaults to the country preset', '')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Time zone; defaults to the country preset', '')
            ->addOption('demo', null, InputOption::VALUE_NONE, \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.vstanovyty_prezentatsiini_demo_dani'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->state->isInstalled()) {
            $io->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.modern_commerce_uzhe_vstanovleno_instaliator_ne_vyko'));
            return Command::FAILURE;
        }

        $checks = [...$this->preflight->runtime(), ...$this->preflight->database($this->connection)];
        $failed = array_filter($checks, static fn ($r): bool => !$r->passed && $r->level === RequirementLevel::Required);
        if ($failed !== []) {
            $io->table(
                [\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.installationhealthcommand.perevirka'), \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.installationhealthcommand.potochne'), \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.potribno'), \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.diia')],
                array_map(static fn ($r): array => [$r->label, $r->current, $r->required, $r->action ?? ''], $failed),
            );
            $io->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.oboviazkovi_perevirky_servera_abo_bazy_danykh_ne_pro'));
            return Command::FAILURE;
        }

        $storeName = (string) ($input->getOption('store-name') ?: ($input->isInteractive() ? $io->ask(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.nazva_mahazynu'), \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.mii_mahazyn')) : \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.mii_mahazyn')));
        $adminName = (string) ($input->getOption('admin-name') ?: ($input->isInteractive() ? $io->ask(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.imia_administratora'), \Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.administrator')) : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.administrator')));
        $adminEmail = (string) ($input->getOption('admin-email') ?: ($input->isInteractive() ? $io->ask(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.email_administratora')) : ''));
        $adminPassword = (string) ($input->getOption('admin-password') ?: ($input->isInteractive() ? $io->askHidden(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.parol_administratora_shchonaimenshe_12_symvoliv')) : ''));
        $publicUrl = (string) $input->getOption('public-url');
        $siteMode = (string) $input->getOption('site-mode');

        try {
            $request = new InstallRequest($storeName, $adminName, $adminEmail, $adminPassword, $publicUrl, $siteMode, strtoupper((string) $input->getOption('country')), strtoupper((string) $input->getOption('currency')), (string) $input->getOption('locale'), (string) $input->getOption('timezone'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $application = $this->getApplication();
        if ($application === null) {
            $io->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.konsolnyi_zastosunok_nedostupnyi'));
            return Command::FAILURE;
        }

        $migration = $application->find('doctrine:migrations:migrate');
        $migrationInput = new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ]);
        $migrationInput->setInteractive(false);
        $migrationCode = $migration->run($migrationInput, $output);
        if ($migrationCode !== Command::SUCCESS) {
            $io->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.mihratsii_bazy_danykh_zavershylysia_pomylkoiu_pochat'));
            return Command::FAILURE;
        }

        // MySQL/MariaDB DDL performs implicit commits. Doctrine Migrations and the
        // installer share this DBAL connection, so reconnect before starting the
        // atomic seed transaction to guarantee a clean transaction state.
        $this->connection->close();

        try {
            $created = $this->seeder->seed($request);
        } catch (\Throwable $e) {
            error_log((string) json_encode([
                'event' => 'install_seed_exception',
                'exception' => $e::class,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $io->error(\Commerce\Core\I18n\CanonicalUiText::get('install.error.seed_failed'));
            return Command::FAILURE;
        }

        $demoInstalled = false;
        $demoWarning = null;
        if ((bool) $input->getOption('demo')) {
            try {
                $this->demoSeeder->install();
                $demoInstalled = true;
            } catch (\Throwable $e) {
                $demoWarning = \Commerce\Core\I18n\CanonicalUiText::get('install.error.demo_failed');
                $io->warning($demoWarning);
            }
        }

        $health = $this->healthVerifier->verify();
        if (!$health['healthy']) {
            $failedHealth = array_values(array_filter($health['checks'], static fn (array $check): bool => !$check['passed']));
            $io->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.pisliainstaliatsiina_diahnostyka_vyiavyla_problemu') . implode('; ', array_map(
                static fn (array $check): string => $check['label'] . ' (' . $check['current'] . ')',
                $failedHealth,
            )));

            return Command::FAILURE;
        }

        $installDir = $this->projectDir . '/var/install';
        if (!is_dir($installDir) && !@mkdir($installDir, 0700, true) && !is_dir($installDir)) {
            $io->warning(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.ne_vdalosia_stvoryty_sluzhbovyi_kataloh_var_install_'));
        } else {
            $lockFile = $installDir . '/installed.lock';
            @file_put_contents($lockFile, gmdate('c') . "\n", LOCK_EX);
            @chmod($lockFile, 0600);
        }

        $message = sprintf(
            \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.vstanovleno_mahazyn_d_rynok_d_administrator_d_vkhid_'),
            $created['store_id'],
            $created['market_id'],
            $created['admin_id'],
        );
        if ((bool) $input->getOption('demo')) {
            $message .= $demoInstalled ? \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.prezentatsiine_demo_vstanovleno') : \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.installcommand.demo_mozhna_povtorno_vstanovyty_komandoiu_commerce_d');
        }
        $io->success($message);

        return Command::SUCCESS;
    }
}
