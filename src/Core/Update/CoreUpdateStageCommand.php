<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'commerce:update:stage', description: 'Verify and stage a signed Core Update bundle without touching the live release.')]
final class CoreUpdateStageCommand extends Command
{
    public function __construct(private readonly CoreUpdateManager $updates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('bundle', InputArgument::REQUIRED, 'Path to signed Core Update ZIP.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('bundle');
        try {
            $result = $this->updates->stage($path);
            $io->success(sprintf(
                'Core Update %s staged safely as attempt #%d. Live files and database were not changed.',
                $result['target_version'],
                $result['id'],
            ));
            $io->note('Apply only after reviewing the staged version: php bin/console commerce:update:apply ' . $result['id'] . ' --yes');
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
