<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler\Console;

use Commerce\Core\Scheduler\ScheduledTaskRegistry;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'commerce:cron:run', description:'Run only due built-in scheduled tasks in one bounded pass. Safe for one cron entry every 5 minutes.')]
final class CronRunCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly ScheduledTaskRegistry $registry) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('task', null, InputOption::VALUE_REQUIRED, 'Run one registered task code regardless of due time.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore next_due_at for selected task(s).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((int)$this->db->fetchOne("SELECT GET_LOCK('nexora_commerce_cron_runner',0)") !== 1) {
            $output->writeln('<comment>Another cron runner is active; skipping.</comment>');
            return Command::SUCCESS;
        }
        try {
            $selected = trim((string)$input->getOption('task'));
            $tasks = $this->registry->all();
            if ($selected !== '') {
                if (!isset($tasks[$selected])) { $output->writeln('<error>Unknown task code.</error>'); return Command::INVALID; }
                $tasks = [$selected => $tasks[$selected]];
            }
            $failures=0;
            foreach ($tasks as $code=>$task) {
                if (!$input->getOption('force') && !$this->due($code)) continue;
                if (!$this->runTask($code,$task,$output)) $failures++;
            }
            return $failures===0 ? Command::SUCCESS : Command::FAILURE;
        } finally {
            try { $this->db->fetchOne("SELECT RELEASE_LOCK('nexora_commerce_cron_runner')"); } catch (\Throwable) {}
        }
    }

    /** @param array{label:string,command:string,args:array<string,string|bool|int>,interval:int,group:string,description:string} $task */
    private function runTask(string $code, array $task, OutputInterface $output): bool
    {
        $started=microtime(true); $now=$this->now();
        $this->upsert($code,['last_started_at'=>$now,'last_status'=>'running','last_message'=>null]);
        $buffer=new BufferedOutput();
        try {
            $application=$this->getApplication(); if($application===null) throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('scheduler.error.console_unavailable'));
            $command=$application->find($task['command']);
            $args=['command'=>$task['command']]; foreach($task['args'] as $k=>$v){$args[$k]=$v;}
            $child=new ArrayInput($args); $child->setInteractive(false);
            $exit=$command->run($child,$buffer); $message=$this->clean($buffer->fetch());
            $ok=$exit===Command::SUCCESS; $duration=(int)round((microtime(true)-$started)*1000);
            $next=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$task['interval'].' seconds')->format('Y-m-d H:i:s.u');
            $this->upsert($code,['last_finished_at'=>$this->now(),'last_status'=>$ok?'success':'failed','last_message'=>$message,'last_duration_ms'=>$duration,'next_due_at'=>$next], true, !$ok);
            $output->writeln(sprintf('%s: %s%s',$code,$ok?'OK':'FAILED',$message!==''?' · '.$message:''));
            return $ok;
        } catch (\Throwable $e) {
            $duration=(int)round((microtime(true)-$started)*1000); $next=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$task['interval'].' seconds')->format('Y-m-d H:i:s.u');
            $this->upsert($code,['last_finished_at'=>$this->now(),'last_status'=>'failed','last_message'=>$this->clean($e->getMessage()),'last_duration_ms'=>$duration,'next_due_at'=>$next],true,true);
            $output->writeln('<error>'.$code.': '.$this->clean($e->getMessage()).'</error>'); return false;
        }
    }

    private function due(string $code): bool
    {
        try{$next=$this->db->fetchOne('SELECT next_due_at FROM mc_scheduled_task_state WHERE task_code=?',[$code]);return $next===false||$next===null||strtotime((string)$next)<=time();}catch(\Throwable){return true;}
    }
    /** @param array<string,mixed> $data */
    private function upsert(string $code,array $data,bool $incrementRun=false,bool $incrementFail=false): void
    {
        $exists=(bool)$this->db->fetchOne('SELECT 1 FROM mc_scheduled_task_state WHERE task_code=?',[$code]);
        if(!$exists){$this->db->insert('mc_scheduled_task_state',array_merge(['task_code'=>$code,'run_count'=>0,'fail_count'=>0],$data));}
        else{$this->db->update('mc_scheduled_task_state',$data,['task_code'=>$code]);}
        if($incrementRun)$this->db->executeStatement('UPDATE mc_scheduled_task_state SET run_count=run_count+1, fail_count=fail_count+? WHERE task_code=?',[$incrementFail?1:0,$code]);
    }
    private function now(): string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
    private function clean(string $message): string{$message=trim(preg_replace('/\s+/u',' ',$message)??'');return mb_substr(strip_tags($message),0,900,'UTF-8');}
}
