<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Console;

use Commerce\Modules\Marketing\Application\NewsletterCampaignQueueProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'commerce:campaigns:enqueue', description:'Batch confirmed newsletter subscribers into the durable notification outbox.')]
final class NewsletterCampaignEnqueueCommand extends Command
{
    public function __construct(private readonly NewsletterCampaignQueueProcessor $processor) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Subscribers per transaction (1-500)', '250')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Maximum batches per run (1-100)', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch=max(1,min(500,(int)$input->getOption('batch')));
        $max=max(1,min(100,(int)$input->getOption('max-batches')));
        $total=0;
        for($i=0;$i<$max;$i++){
            $result=$this->processor->processOneBatch($batch);
            $total+=$result['enqueued'];
            if($result['campaign_id']===null) break;
            if($result['completed']) continue;
        }
        $output->writeln(sprintf('<info>Campaign recipients queued: %d.</info>',$total));
        return Command::SUCCESS;
    }
}
