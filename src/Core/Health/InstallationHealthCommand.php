<?php

declare(strict_types=1);

namespace Commerce\Core\Health;

use Commerce\Core\Install\InstallationHealthVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:install:verify')] 
final class InstallationHealthCommand extends Command
{
    public function __construct(private readonly InstallationHealthVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(\Commerce\Core\I18n\CanonicalUiText::get('php.command.install_verify.description'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->verifier->verify();
        $rows = array_map(
            static fn (array $check): array => [$check['passed'] ? 'OK' : 'FAIL', $check['label'], $check['current'], $check['expected']],
            $report['checks'],
        );
        $io->table([\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.installationhealthcommand.stan'), \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.installationhealthcommand.perevirka'), \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.installationhealthcommand.potochne'), \Commerce\Core\I18n\CanonicalUiText::get('php.core.health.installationhealthcommand.ochikuietsia')], $rows);

        if (!$report['healthy']) {
            $io->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.installationhealthcommand.pisliainstaliatsiina_perevirka_vyiavyla_problemu'));
            return Command::FAILURE;
        }

        $io->success(\Commerce\Core\I18n\CanonicalUiText::get('php.core.health.installationhealthcommand.bazova_instaliatsiia_tsilisna'));
        return Command::SUCCESS;
    }
}
