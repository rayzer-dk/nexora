<?php

declare(strict_types=1);

namespace Commerce\Modules\Demo\Console;

use Commerce\Modules\Demo\Application\DemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'commerce:demo:remove')]
final class DemoRemoveCommand extends Command
{
    public function __construct(private readonly DemoSeeder $seeder){parent::__construct();}
    protected function configure(): void
    {
        $this->setDescription(\Commerce\Core\I18n\CanonicalUiText::get('php.command.demo_remove.description'));
    }

    protected function execute(InputInterface $input,OutputInterface $output):int{ $io=new SymfonyStyle($input,$output); try{$this->seeder->remove();}catch(\Throwable $e){$io->error($e->getMessage());return Command::FAILURE;} $io->success(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.console.demoremovecommand.demonstratsiini_dani_vydaleno')); return Command::SUCCESS; }
}
