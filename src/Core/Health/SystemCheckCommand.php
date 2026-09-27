<?php

declare(strict_types=1);
namespace Commerce\Core\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name:'commerce:system:check', description:'Validate PHP, server and database requirements before install/update.')]
final class SystemCheckCommand extends Command
{
    public function __construct(private readonly SystemPreflightInspector $inspector, private readonly Connection $connection){ parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io=new SymfonyStyle($input,$output); $results=[...$this->inspector->runtime(),...$this->inspector->database($this->connection)];
        $rows=[];$failed=false;
        foreach($results as $r){$state=$r->passed?'OK':'FAIL'; if(!$r->passed && $r->level===RequirementLevel::Required)$failed=true; $rows[]=[$state,$r->level->value,$r->label,$r->current,$r->required,$r->action??''];}
        $io->table(['State','Level','Check','Current','Expected','Action'],$rows);
        $failed ? $io->error('Required platform checks failed.') : $io->success('Platform requirements passed.');
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
