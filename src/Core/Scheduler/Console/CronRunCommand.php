<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler\Console;

use Commerce\Core\Scheduler\CronRunner;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'commerce:cron:run', description:'Run only due built-in scheduled tasks in one bounded pass. Safe for one cron entry every 5 minutes.')]
final class CronRunCommand extends Command
{
    public function __construct(private readonly CronRunner $runner) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('task', null, InputOption::VALUE_REQUIRED, 'Run one registered task code regardless of due time.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore next_due_at for selected task(s).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        $selected = trim((string) $input->getOption('task'));
        $result = $this->runner->run(
            'cli',
            $selected !== '' ? $selected : null,
            (bool) $input->getOption('force') || $selected !== '',
            0.0,
            $application instanceof Application ? $application : null,
            static function (string $line, bool $error) use ($output): void {
                $output->writeln($error ? '<error>' . OutputFormatter::escape($line) . '</error>' : OutputFormatter::escape($line));
            },
        );
        if ($result['locked']) {
            $output->writeln('<comment>Another cron runner is active; skipping.</comment>');

            return Command::SUCCESS;
        }
        if ($result['unknown']) {
            $output->writeln('<error>Unknown task code.</error>');

            return Command::INVALID;
        }

        $output->writeln(sprintf('Cron pass finished: ran=%d failed=%d skipped(not due)=%d', count($result['ran']), count($result['failed']), $result['skipped']), OutputInterface::VERBOSITY_VERBOSE);

        return $result['failed'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
