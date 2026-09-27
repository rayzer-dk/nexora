<?php

declare(strict_types=1);
namespace Commerce\Modules\Marketing\Console;
use Commerce\Modules\Marketing\Application\MarketingAutomationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'commerce:marketing:automations',description:'Queue consent-safe lifecycle marketing automations.')]
final class MarketingAutomationCommand extends Command
{
    public function __construct(private readonly MarketingAutomationService $service){parent::__construct();}
    protected function configure():void{$this->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum candidates per run','200');}
    protected function execute(InputInterface $input,OutputInterface $output):int{$r=$this->service->scan((int)$input->getOption('limit'));$output->writeln(sprintf('Scanned %d, queued %d.',$r['scanned'],$r['queued']));return Command::SUCCESS;}
}
