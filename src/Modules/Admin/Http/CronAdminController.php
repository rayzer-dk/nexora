<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Scheduler\ScheduledTaskRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CronAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly ScheduledTaskRegistry $registry, private readonly Connection $db) {}

    #[Route('/admin/system/cron', name:'admin_system_cron', methods:['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        $states=[];
        try { foreach($this->db->fetchAllAssociative('SELECT * FROM mc_scheduled_task_state ORDER BY task_code') as $row){$states[(string)$row['task_code']]=$row;} } catch(\Throwable) {}
        return $this->render('@storefront/admin/system/cron.html.twig',[
            'tasks'=>$this->registry->all(),
            'states'=>$states,
            'command'=>'php bin/console commerce:cron:run',
        ]);
    }
}
