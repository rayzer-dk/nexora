<?php

declare(strict_types=1);

namespace Commerce\Modules\Feeds\Console;

use Commerce\Modules\Feeds\Application\FeedStorageService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name:'commerce:feeds:generate',description:'Generate atomic product-feed snapshots for external marketplaces.')]
final class GenerateFeedsCommand extends Command
{
    public function __construct(private readonly Connection $db,private readonly FeedStorageService $storage){parent::__construct();}
    protected function configure(): void{$this->addOption('store',null,InputOption::VALUE_REQUIRED,'Store code')->addOption('platform',null,InputOption::VALUE_REQUIRED,'google,meta,pinterest,tiktok,rozetka,prom,csv,json,agentic or all','all')->addOption('locale',null,InputOption::VALUE_REQUIRED,'Locale; defaults to store default')->addOption('in-stock-only',null,InputOption::VALUE_NONE,'Exclude out-of-stock items.');}
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $io=new SymfonyStyle($input,$output);$code=trim((string)$input->getOption('store'));$params=[];$where="status='active'";if($code!==''){$where.=' AND code=?';$params[]=$code;}$stores=$this->db->fetchAllAssociative('SELECT id,code,default_locale,default_currency FROM mc_store WHERE '.$where.' ORDER BY id',$params);if($stores===[]){$io->error('No matching active store.');return Command::FAILURE;}
        $wanted=(string)$input->getOption('platform');$platforms=$wanted==='all'?['google','meta','pinterest','tiktok','rozetka','prom','csv','json','agentic']:[$wanted];$allowed=['google','meta','pinterest','tiktok','rozetka','prom','csv','json','agentic'];foreach($platforms as $p)if(!in_array($p,$allowed,true)){$io->error('Unsupported platform: '.$p);return Command::INVALID;}
        $failed=0;foreach($stores as $store){$storeId=(int)$store['id'];$marketId=(int)$this->db->fetchOne("SELECT id FROM mc_market WHERE store_id=? AND status='active' ORDER BY id LIMIT 1",[$storeId]);if($marketId<1){$io->warning('No active market for '.$store['code']);continue;}$locale=trim((string)$input->getOption('locale'))?: (string)$store['default_locale'];foreach($platforms as $platform){try{$r=$this->storage->generate((string)$store['code'],$platform,$storeId,$marketId,$locale,(string)$store['default_currency'],(bool)$input->getOption('in-stock-only'));$io->writeln(sprintf('%s / %s: %d items, %d skipped, %d bytes',$store['code'],$platform,(int)$r['meta']['count'],(int)$r['meta']['skipped'],(int)$r['meta']['bytes']));}catch(\Throwable $e){$failed++;$io->error($store['code'].' / '.$platform.': '.$e->getMessage());}}}
        return $failed===0?Command::SUCCESS:Command::FAILURE;
    }
}
