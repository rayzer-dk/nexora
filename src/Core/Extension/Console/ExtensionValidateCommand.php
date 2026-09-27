<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use Commerce\Core\Extension\ExtensionPackageValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:extension:validate', description: 'Validate a Nexora extension ZIP without installing it.')]
final class ExtensionValidateCommand extends Command
{
    public function __construct(private readonly ExtensionPackageValidator $validator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('archive', InputArgument::REQUIRED, 'Path to extension ZIP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $inspection = $this->validator->inspect((string) $input->getArgument('archive'));
        } catch (\Throwable $e) {
            $output->writeln('<error>INVALID: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $manifest = $inspection->manifest;
        $output->writeln('<info>VALID</info>');
        $output->writeln('Code: ' . (string) ($manifest['code'] ?? 'unknown'));
        $output->writeln('Version: ' . (string) ($manifest['version'] ?? 'unknown'));
        $output->writeln('Execution: ' . (string) ($manifest['execution'] ?? 'declarative'));
        $output->writeln('Files: ' . count($inspection->files));
        foreach ($inspection->warnings as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }
        if ($inspection->quarantined) {
            $output->writeln('<comment>Package is valid for staging but will be quarantined: ' . $inspection->quarantineReason . '</comment>');
        }
        return Command::SUCCESS;
    }
}
