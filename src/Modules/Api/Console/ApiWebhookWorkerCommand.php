<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Console;

use Commerce\Modules\Api\Webhook\ApiWebhookDeliveryWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'commerce:webhooks:work')]
final class ApiWebhookWorkerCommand extends Command
{
    public function __construct(private readonly ApiWebhookDeliveryWorker $worker){parent::__construct();}
    protected function configure():void{$this->setDescription(\Commerce\Core\I18n\CanonicalUiText::get('php.command.commerce_webhooks_work.description'))->addOption('limit',null,InputOption::VALUE_REQUIRED,\Commerce\Core\I18n\CanonicalUiText::get('php.command.common.limit'),100);}
    protected function execute(InputInterface $input,OutputInterface $output):int{$stats=$this->worker->run((int)$input->getOption('limit'));$output->writeln(json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return Command::SUCCESS;}
}
