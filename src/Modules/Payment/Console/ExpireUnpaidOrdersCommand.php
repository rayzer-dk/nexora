<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Console;

use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:orders:expire-unpaid')]
final class ExpireUnpaidOrdersCommand extends Command
{
    public function __construct(private readonly PaymentLifecycleService $lifecycle){parent::__construct();}
    protected function configure(): void {$this->setDescription(\Commerce\Core\I18n\CanonicalUiText::get('php.command.expire_unpaid.description'));$this->addOption('limit',null,InputOption::VALUE_REQUIRED,\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.console.expireunpaidorderscommand.maksymum_zamovlen_za_zapusk'),'100');}
    protected function execute(InputInterface $input,OutputInterface $output): int {$count=$this->lifecycle->expireUnpaidReservations((int)$input->getOption('limit')); $output->writeln(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.console.expireunpaidorderscommand.zvilneno').$count); return Command::SUCCESS;}
}
