<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Integration\Crm\CrmConnectionRepository;
use Commerce\Modules\Integration\Crm\CrmDispatcher;
use Commerce\Modules\Integration\Crm\CrmPresets;
use Commerce\Modules\Integration\Crm\CrmTemplateRenderer;
use Commerce\Modules\Integration\Crm\OrderSnapshotBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Admin → System → Integrations → Order connections: send orders to a CRM, an accounting program or any address, with a body template of your own. */
final class CrmConnectionsAdminController extends AbstractController
{
    public function __construct(
        private readonly CrmConnectionRepository $connections,
        private readonly CrmDispatcher $dispatcher,
        private readonly CrmTemplateRenderer $renderer,
        private readonly OrderSnapshotBuilder $snapshots,
        private readonly AdminContextResolver $contexts,
        private readonly Connection $db,
    ) {
    }

    #[Route('/admin/system/integrations/orders', name: 'admin_system_integrations_orders', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $edit = null;
        $editId = $request->query->getInt('edit', 0);
        if ($editId > 0) {
            $edit = $this->connections->find($editId, $ctx->storeId);
            if ($edit !== null) {
                $edit['headers_text'] = CrmConnectionRepository::headersText($edit['headers']);
            }
        }
        $presets = CrmPresets::all();
        $new = $request->query->has('new') || $edit !== null;

        return $this->render('@storefront/admin/system/crm_connections.html.twig', [
            'connections' => $this->connections->all($ctx->storeId), 'edit' => $edit, 'show_form' => $new, 'presets' => $presets,
            'presets_json' => json_encode(array_map(static fn (array $p): array => ['url' => $p['url_hint'], 'headers' => $p['headers_hint'], 'events' => $p['events'], 'template' => $p['template']], $presets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'events' => CrmPresets::EVENTS, 'deliveries' => $this->connections->deliveries($ctx->storeId),
        ]);
    }

    #[Route('/admin/system/integrations/orders/save', name: 'admin_system_integrations_orders_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $ctx = $this->contexts->resolve($request);
        $this->guard($request);
        $r = $request->request;
        $id = $r->getInt('id', 0) ?: null;
        $url = trim((string) $r->get('url', ''));
        $template = (string) $r->get('body_template', '');
        $events = array_values(array_intersect(CrmPresets::EVENTS, array_map('strval', $r->all('events'))));
        try {
            if (trim((string) $r->get('name', '')) === '' || preg_match('~^https?://[^\s]+$~i', $url) !== 1 || trim($template) === '' || $events === []) {
                throw new \DomainException(CanonicalUiText::get('admin.crm.error_required'));
            }
            // The template is tried on the latest order, so a broken one is caught before it is saved.
            $sample = $this->latestSnapshot($ctx->storeId);
            if ($sample !== null) {
                $this->renderer->render($template, $sample, 'placed');
            }
            $saved = $this->connections->save($ctx->storeId, $id, [
                'name' => (string) $r->get('name'), 'preset' => (string) $r->get('preset', 'json'), 'url' => $url, 'method' => (string) $r->get('method', 'POST'),
                'headers_text' => (string) $r->get('headers_text', ''), 'body_template' => $template, 'events' => $events, 'enabled' => $r->getBoolean('enabled'),
            ]);
            $this->addFlash('success', CanonicalUiText::get('admin.crm.saved'));

            return $this->redirectToRoute('admin_system_integrations_orders', ['edit' => $saved]);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_system_integrations_orders', $id !== null ? ['edit' => $id] : ['new' => 1]);
        }
    }

    #[Route('/admin/system/integrations/orders/{id}/toggle', name: 'admin_system_integrations_orders_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): RedirectResponse
    {
        $ctx = $this->contexts->resolve($request);
        $this->guard($request);
        $this->connections->setEnabled($id, $ctx->storeId, $request->request->getBoolean('enabled'));

        return $this->redirectToRoute('admin_system_integrations_orders');
    }

    #[Route('/admin/system/integrations/orders/{id}/delete', name: 'admin_system_integrations_orders_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $ctx = $this->contexts->resolve($request);
        $this->guard($request);
        $this->connections->delete($id, $ctx->storeId);
        $this->addFlash('success', CanonicalUiText::get('admin.crm.deleted'));

        return $this->redirectToRoute('admin_system_integrations_orders');
    }

    /** Sends the latest order of the shop to the connection at once and shows what the receiver answered. */
    #[Route('/admin/system/integrations/orders/{id}/test', name: 'admin_system_integrations_orders_test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(int $id, Request $request): RedirectResponse
    {
        $ctx = $this->contexts->resolve($request);
        $this->guard($request);
        $connection = $this->connections->find($id, $ctx->storeId);
        $snapshot = $this->latestSnapshot($ctx->storeId);
        if ($connection === null) {
            throw $this->createNotFoundException();
        }
        if ($snapshot === null) {
            $this->addFlash('error', CanonicalUiText::get('admin.crm.no_order'));
        } else {
            try {
                $result = $this->dispatcher->deliver($connection, $snapshot, (string) ($connection['events'][0] ?? 'placed'));
                $this->addFlash('success', CanonicalUiText::get('admin.crm.test_ok', ['status' => (string) $result['status'], 'number' => (string) $snapshot['order']['number']]));
            } catch (\Throwable $e) {
                $this->addFlash('error', CanonicalUiText::get('admin.crm.test_failed', ['detail' => mb_substr($e->getMessage(), 0, 300)]));
            }
        }

        return $this->redirectToRoute('admin_system_integrations_orders', ['edit' => $id]);
    }

    /** @return array<string,mixed>|null */
    private function latestSnapshot(int $storeId): ?array
    {
        $public = $this->db->fetchOne('SELECT public_id FROM mc_sales_order WHERE store_id=? ORDER BY id DESC LIMIT 1', [$storeId]);

        return $public === false ? null : $this->snapshots->build(Uuid::fromBinary((string) $public)->toRfc4122());
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('crm_connections', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
