<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use Commerce\Core\Extension\ExtensionPackageValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:extension:test', description: 'Run package-level SDK checks without modifying the store.')]
final class ExtensionTestCommand extends Command
{
    public function __construct(private readonly ExtensionPackageValidator $validator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('archive', InputArgument::REQUIRED, 'Extension ZIP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $inspection = $this->validator->inspect((string) $input->getArgument('archive'));
        } catch (\Throwable $e) {
            $output->writeln('<error>FAIL package validation: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $checks = [
            'manifest' => isset($inspection->manifest['code'], $inspection->manifest['version'], $inspection->manifest['extension_api']),
            'namespace/isolation' => (($inspection->manifest['isolation'] ?? '') === 'contract_only' || ($inspection->manifest['isolation'] ?? '') === 'sandboxed_app'),
            'hot-disable' => (($inspection->manifest['hot_disable'] ?? false) === true),
            'localization' => isset($inspection->manifest['localization']['default_locale']),
            'settings-schema' => !isset($inspection->manifest['settings_schema']) || in_array((string) $inspection->manifest['settings_schema'], $inspection->files, true),
        ];
        $failed = false;
        foreach ($checks as $name => $ok) {
            $output->writeln(($ok ? '<info>PASS</info> ' : '<error>FAIL</error> ') . $name);
            $failed = $failed || !$ok;
        }
        foreach ($inspection->warnings as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }
        if ($inspection->quarantined) {
            $output->writeln('<comment>Signature/trust check requires attention before activation: ' . $inspection->quarantineReason . '</comment>');
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
