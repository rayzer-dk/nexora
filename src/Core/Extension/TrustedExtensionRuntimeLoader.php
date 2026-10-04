<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Modules\Ai\Application\AiProviderRegistry;
use Commerce\Modules\Ai\Application\TranslationProviderRegistry;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\ProductPage\Application\ProductBlockRegistry;
use Commerce\Modules\Shipping\Application\DeliveryProviderRegistry;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;

final class TrustedExtensionRuntimeLoader
{
    private bool $booted = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly TrustedExtensionRuntimeRegistry $runtime,
        private readonly PaymentProviderRegistry $payments,
        private readonly DeliveryProviderRegistry $shipping,
        private readonly ProductBlockRegistry $productBlocks,
        private readonly AiProviderRegistry $ai,
        private readonly TranslationProviderRegistry $translations,
        private readonly ExtensionServiceRegistry $services,
    ) {}

    public function bootActive(): void
    {
        if ($this->booted) return;
        $this->booted = true;
        try {
            $rows = $this->connection->fetchAllAssociative("SELECT id,code,version,install_path,manifest_json,failure_count FROM mc_extension_installation WHERE status='active' ORDER BY code,id");
        } catch (\Throwable) {
            return;
        }
        foreach ($rows as $row) {
            try {
                $this->bootRow($row);
            } catch (\Throwable $e) {
                $this->isolateFailedExtension($row, $e);
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function bootRow(array $row): void
    {
        $manifest = json_decode((string) ($row['manifest_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || (string) ($manifest['execution'] ?? '') !== 'trusted_release') return;
        // Signed theme packages may contain trusted Twig overrides without a PHP entrypoint.
        if ((string) ($manifest['type'] ?? '') === 'theme' && !isset($manifest['entrypoint'])) return;
        $path = (string) ($row['install_path'] ?? '');
        $this->registerAutoload($path, (array) ($manifest['autoload']['psr4'] ?? []));
        $entrypoint = (string) ($manifest['entrypoint'] ?? '');
        if ($entrypoint === '' || !class_exists($entrypoint)) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.6f3ac88af1e0') . $entrypoint);
        $instance = new $entrypoint();
        if (!$instance instanceof TrustedExtensionEntrypointInterface) throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.88ae161799ff'));
        $declaredRoutes=[];foreach((array)($manifest['routes']??[]) as $route){if(is_array($route)&&is_string($route['name']??null))$declaredRoutes[]=(string)$route['name'];}
        $declaredEvents=array_values(array_filter((array)($manifest['events']??[]),'is_string'));
        $declaredTasks=[];foreach((array)($manifest['scheduled_tasks']??[]) as $task){if(is_array($task)&&is_string($task['code']??null))$declaredTasks[(string)$task['code']]=['interval'=>(int)($task['interval']??3600),'label'=>(string)($task['label']??$task['code']),'description'=>(string)($task['description']??'')];}
        $instance->boot(new TrustedExtensionContext((string)$row['code'],(string)$row['version'],$path,$this->runtime,$this->payments,$this->shipping,$this->productBlocks,$this->ai,$this->translations,$this->services,$declaredRoutes,$declaredEvents,array_values(array_filter((array)($manifest['capabilities']??[]),'is_string')),$declaredTasks));
    }

    /** @param array<string,mixed> $row */
    private function isolateFailedExtension(array $row, \Throwable $e): void
    {
        try {
            $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $message=mb_substr(preg_replace('/[\r\n\t]+/',' ',$e->getMessage())?:'Extension runtime failure',0,1000,'UTF-8');
            $this->connection->update('mc_extension_installation',['status'=>'disabled','disabled_at'=>$now,'failure_count'=>((int)($row['failure_count']??0))+1,'last_error'=>$message],['id'=>(int)$row['id']]);
            if($this->connection->createSchemaManager()->tablesExist(['mc_extension_lifecycle_event'])){
                $this->connection->insert('mc_extension_lifecycle_event',['installation_id'=>(int)$row['id'],'code'=>(string)$row['code'],'version'=>(string)$row['version'],'action'=>'runtime_isolation','from_status'=>'active','to_status'=>'disabled','result'=>'error','message'=>$message,'created_at'=>$now]);
            }
        } catch (\Throwable) {
            // Isolation is best-effort; Core must keep booting even when diagnostics fail.
        }
    }

    /** @param array<string,mixed> $map */
    private function registerAutoload(string $base, array $map): void
    {
        $normalized=[];foreach($map as $prefix=>$relative){if(!is_string($prefix)||!is_string($relative)||!str_ends_with($prefix,'\\'))continue;$dir=realpath($base.'/'.trim(str_replace('\\','/',$relative),'/'));$root=realpath($base);if($dir===false||$root===false||!str_starts_with($dir.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR))continue;$normalized[$prefix]=$dir;}
        if($normalized===[])return;
        spl_autoload_register(static function(string $class)use($normalized):void{foreach($normalized as $prefix=>$dir){if(!str_starts_with($class,$prefix))continue;$relative=str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(preg_match('#(^|/)\.\.(?:/|$)#',$relative)===1)return;$file=$dir.'/'.$relative;if(is_file($file))require_once $file;return;}},true,true);
    }
}
