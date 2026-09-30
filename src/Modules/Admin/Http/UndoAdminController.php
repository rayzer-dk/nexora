<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Authorization\AdminAuthorizationService;
use Commerce\Modules\Admin\Authorization\AdminPermissionCatalog;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Undo\AdminUndoService;
use Commerce\Modules\Catalog\Application\ProductBulkEditor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Puts back the administrator's own last reversible action (see AdminUndoService). */
final class UndoAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly AdminUndoService $undo,
        private readonly AdminAuthorizationService $authorization,
        private readonly ProductBulkEditor $products,
    ) {
    }

    #[Route('/admin/undo/{id}', name: 'admin_undo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_undo', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }
        $ctx = $this->contexts->resolve($request);
        $back = $this->safeBack($request);
        $ticket = $this->undo->claim($id, $ctx->storeId, $user->id);
        if ($ticket === null) {
            $this->addFlash('error', CanonicalUiText::get('admin.undo.expired'));

            return $this->redirect($back);
        }
        if ($ticket['kind'] === 'product_bulk' && $this->authorization->isGranted($user, AdminPermissionCatalog::CATALOG_BULK, $ctx->storeId)) {
            $rows = is_array($ticket['payload']['rows'] ?? null) ? $ticket['payload']['rows'] : [];
            $result = $this->products->apply($ctx->storeId, $ctx->marketId, $ctx->locale, $ctx->currency, $rows);
            $this->addFlash($result['failed'] === [] ? 'success' : 'warning', sprintf(CanonicalUiText::get('admin.undo.done'), $result['ok'], count($result['failed'])));

            return $this->redirect($back);
        }
        $this->addFlash('error', CanonicalUiText::get('admin.undo.forbidden'));

        return $this->redirect($back);
    }

    private function safeBack(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $path = (string) parse_url($referer, PHP_URL_PATH);
        $query = (string) parse_url($referer, PHP_URL_QUERY);
        if (!str_starts_with($path, '/admin') || str_starts_with($path, '//')) {
            return '/admin';
        }

        return $path . ($query !== '' ? '?' . $query : '');
    }
}
