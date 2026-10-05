<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Console;

use Commerce\Modules\Quality\Application\DailyDigestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:digest:send', description: 'Send the daily letter to the owner (once a day, after the chosen hour).')]
final class DailyDigestCommand extends Command
{
    public function __construct(private readonly DailyDigestService $digest)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Send now, ignoring the schedule and the on/off switch.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = $this->digest->sendIfDue((bool) $input->getOption('force'));
        $output->writeln($sent ? 'queued' : 'not due');

        return Command::SUCCESS;
    }
}
