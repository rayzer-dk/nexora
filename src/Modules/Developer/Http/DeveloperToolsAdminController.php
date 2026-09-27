<?php

declare(strict_types=1);

namespace Commerce\Modules\Developer\Http;

use Commerce\Core\Module\SystemModuleCatalog;
use Commerce\Core\Platform\PlatformVersion;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

final class DeveloperToolsAdminController extends AbstractController
{
    public function __construct(private readonly RouterInterface $router, private readonly Connection $db) {}

    #[Route('/admin/system/developer', name:'admin_system_developer', methods:['GET'])]
    public function index(Request $request): Response
    {
        $query=mb_substr(trim((string)$request->query->get('q','')),0,120,'UTF-8');
        $routes=$this->routes($query);
        return $this->render('@storefront/admin/system/developer.html.twig',[
            'platform'=>$this->platform(),
            'modules'=>$this->modules(),
            'routes'=>$routes,
            'route_query'=>$query,
            'runtime'=>$this->runtime(),
            'database'=>$this->database(),
            'operations'=>$this->operations(),
        ]);
    }

    #[Route('/admin/system/developer/report.json', name:'admin_system_developer_report', methods:['GET'])]
    public function report(): JsonResponse
    {
        return $this->json([
            'platform'=>$this->platform(),
            'runtime'=>$this->runtime(),
            'database'=>$this->database(),
            'operations'=>$this->operations(),
            'modules'=>$this->modules(),
            'routes'=>$this->routes(''),
            'generated_at'=>gmdate('c'),
        ],200,['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
    }

    /** @return array<string,mixed> */
    private function platform(): array
    {
        $release=[];
        $path=(string)$this->getParameter('kernel.project_dir').'/resources/platform/release.json';
        try{$decoded=json_decode((string)@file_get_contents($path),true,64,JSON_THROW_ON_ERROR);if(is_array($decoded))$release=$decoded;}catch(\Throwable){}
        return ['version'=>PlatformVersion::VERSION,'schema'=>(string)($release['database_schema']??''),'channel'=>(string)($release['channel']??'development'),'extension_api'=>(string)($release['extension_api']??'')];
    }

    /** @return list<array<string,mixed>> */
    private function modules(): array
    {
        $rows=[];
        foreach(SystemModuleCatalog::all() as $module){$rows[]=['code'=>$module->code,'name'=>$module->name,'maturity'=>$module->maturity->value,'enabled_by_default'=>$module->enabledByDefault,'dependencies'=>$module->dependencies];}
        usort($rows,static fn(array $a,array $b):int=>strcmp((string)$a['code'],(string)$b['code']));
        return $rows;
    }

    /** @return list<array{name:string,path:string,methods:string,controller:string}> */
    private function routes(string $filter): array
    {
        $rows=[];
        foreach($this->router->getRouteCollection()->all() as $name=>$route){
            $controller=(string)$route->getDefault('_controller');
            $methods=$route->getMethods();
            $row=['name'=>(string)$name,'path'=>$route->getPath(),'methods'=>$methods===[]?'ANY':implode(',',$methods),'controller'=>$controller];
            if($filter!==''&&!str_contains(mb_strtolower(implode(' ',array_values($row)),'UTF-8'),mb_strtolower($filter,'UTF-8')))continue;
            $rows[]=$row;
        }
        usort($rows,static fn(array $a,array $b):int=>strcmp($a['path'],$b['path'])?:strcmp($a['name'],$b['name']));
        return array_slice($rows,0,500);
    }

    /** @return array<string,mixed> */
    private function runtime(): array
    {
        $extensions=['pdo_mysql','intl','mbstring','openssl','json','fileinfo','gd'];
        $loaded=[];foreach($extensions as $extension)$loaded[$extension]=extension_loaded($extension);
        return ['php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'memory_limit'=>(string)ini_get('memory_limit'),'timezone'=>(string)date_default_timezone_get(),'extensions'=>$loaded];
    }

    /** @return array<string,mixed> */
    private function database(): array
    {
        $out=['server_version'=>'unknown','tables'=>null];
        try{$out['server_version']=(string)$this->db->fetchOne('SELECT VERSION()');}catch(\Throwable){}
        try{$out['tables']=(int)$this->db->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'mc\\_%'");}catch(\Throwable){}
        return $out;
    }

    /** @return array<string,int|null> */
    private function operations(): array
    {
        $queries=[
            'queue_pending'=>"SELECT COUNT(*) FROM mc_queue_job WHERE status IN ('pending','retry')",
            'queue_failed'=>"SELECT COUNT(*) FROM mc_queue_job WHERE status IN ('failed','dead')",
            'outbox_pending'=>"SELECT COUNT(*) FROM mc_outbox_event WHERE published_at IS NULL",
            'runtime_incidents_24h'=>"SELECT COUNT(*) FROM mc_runtime_incident WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)",
            'active_extensions'=>"SELECT COUNT(*) FROM mc_extension_installation WHERE status='active'",
        ];
        $out=[];foreach($queries as $key=>$sql){try{$out[$key]=(int)$this->db->fetchOne($sql);}catch(\Throwable){$out[$key]=null;}}
        return $out;
    }
}
