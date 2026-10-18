<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Extension\ExtensionDiagnostics;
use Commerce\Core\Extension\ExtensionPackageManager;
use Commerce\Core\Platform\PlatformVersion;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → Modules → journal of lifecycle events (filter, CSV) and the per-module diagnostics card. */
final class ExtensionJournalAdminController extends AbstractController
{
    private const LIMIT = 200;
    private const CSV_LIMIT = 500;

    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly ExtensionPackageManager $extensions,
        private readonly ExtensionDiagnostics $diagnostics,
    ) {
    }

    #[Route('/admin/system/extensions/journal', name: 'admin_system_extension_journal', methods: ['GET'])]
    public function journal(Request $request): Response
    {
        $this->contexts->resolve($request);
        $filters = $this->extensions->lifecycleFilters();
        [$code, $action] = $this->filter($request, $filters);

        return $this->render('@storefront/admin/system/extension_journal.html.twig', [
            'platform_version' => PlatformVersion::VERSION,
            'events' => $this->extensions->lifecycle(self::LIMIT, $code, $action),
            'limit' => self::LIMIT,
            'filters' => $filters,
            'code' => $code,
            'action' => $action,
        ]);
    }

    #[Route('/admin/system/extensions/journal.csv', name: 'admin_system_extension_journal_csv', methods: ['GET'])]
    public function csv(Request $request): Response
    {
        $this->contexts->resolve($request);
        [$code, $action] = $this->filter($request, $this->extensions->lifecycleFilters());
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['time', 'module', 'version', 'event', 'from_status', 'to_status', 'result', 'message'], ';', '"', '');
        foreach ($this->extensions->lifecycle(self::CSV_LIMIT, $code, $action) as $event) {
            fputcsv($handle, array_map($this->cell(...), [
                $event['created_at'], $event['code'], $event['version'], $event['action'], $event['from_status'], $event['to_status'], $event['result'], $event['message'],
            ]), ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="extension-journal-' . gmdate('Ymd-His') . '.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/admin/system/extensions/{id}/diagnostics', name: 'admin_system_extension_diagnostics', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function diagnostics(Request $request, int $id): Response
    {
        $this->contexts->resolve($request);
        try {
            $card = $this->diagnostics->for($id);
        } catch (DomainException) {
            throw $this->createNotFoundException();
        }

        return $this->render('@storefront/admin/system/extension_diagnostics.html.twig', ['platform_version' => PlatformVersion::VERSION, ...$card]);
    }

    /**
     * @param array{codes:list<string>,actions:list<string>} $filters
     * @return array{0:?string,1:?string} only values the journal really has are accepted
     */
    private function filter(Request $request, array $filters): array
    {
        $code = (string) $request->query->get('code', '');
        $action = (string) $request->query->get('action', '');

        return [in_array($code, $filters['codes'], true) ? $code : null, in_array($action, $filters['actions'], true) ? $action : null];
    }

    private function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'" . $text : $text;
    }
}
