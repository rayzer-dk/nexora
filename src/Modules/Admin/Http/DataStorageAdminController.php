<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Health\Console\DataRetentionCommand;
use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Shows which tables take the space and lets an operator run the retention cleanup on demand. */
final class DataStorageAdminController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly DataRetentionCommand $retention)
    {
    }

    #[Route('/admin/system/data', name: 'admin_system_data', methods: ['GET'])]
    public function index(): Response
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT table_name AS name, table_rows AS row_count, (data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC LIMIT 20',
        );
        $total = (int) $this->db->fetchOne('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $report = $this->preview();

        return $this->render('@storefront/admin/system/data.html.twig', ['tables' => $rows, 'total_bytes' => $total, 'pending' => $report['pending'], 'pending_total' => $report['total']]);
    }

    #[Route('/admin/system/data/run', name: 'admin_system_data_run', methods: ['POST'])]
    public function run(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_data', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $output = new BufferedOutput();
        $this->retention->run(new ArrayInput(['--max-batches' => 20]), $output);
        preg_match('/removed=(\d+)/', $output->fetch(), $m);
        $this->addFlash('success', CanonicalUiText::get('admin.data.done', ['count' => $m[1] ?? '0']));

        return $this->redirectToRoute('admin_system_data');
    }

    /** @return array{pending:array<string,int>,total:int} rows a cleanup would remove now, per rule */
    private function preview(): array
    {
        $output = new BufferedOutput();
        $this->retention->run(new ArrayInput(['--dry-run' => true]), $output);
        $pending = [];
        $total = 0;
        foreach (explode("\n", $output->fetch()) as $line) {
            if (preg_match('/^([a-z_]+)\s+(\d+)$/', trim($line), $m) === 1 && (int) $m[2] > 0) {
                $pending[$m[1]] = (int) $m[2];
                $total += (int) $m[2];
            }
        }

        return ['pending' => $pending, 'total' => $total];
    }
}
