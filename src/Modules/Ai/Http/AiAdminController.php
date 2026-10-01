<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Ai\Application\AiTaskService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AiAdminController extends AbstractController
{
    public function __construct(private readonly AiTaskService $tasks, private readonly AdminContextResolver $contexts)
    {
    }

    /** Generic assistant endpoint: the result is only returned to the form, never saved. */
    #[Route('/admin/api/ai/task', name: 'admin_ai_task', methods: ['POST'])]
    public function task(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_ai', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => CanonicalUiText::get('common.security.invalid_csrf')], 403);
        }
        $fields = $request->request->all('fields');

        return $this->execute($request, (string) $request->request->get('task', ''), $fields);
    }

    /** Kept for the product form of earlier versions. */
    #[Route('/admin/api/ai/product-draft', name: 'admin_ai_product_draft', methods: ['POST'])]
    public function productDraft(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_ai_product', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => CanonicalUiText::get('common.security.invalid_csrf')], 403);
        }
        $r = $request->request;
        $response = $this->execute($request, 'product_draft', ['name' => (string) $r->get('name', ''), 'sku' => (string) $r->get('sku', ''), 'brand' => (string) $r->get('brand', ''), 'categories' => (string) $r->get('categories', ''), 'attributes' => (string) $r->get('attributes', ''), 'short' => (string) $r->get('short', ''), 'current' => (string) $r->get('description', '')]);
        $data = json_decode((string) $response->getContent(), true);
        if (is_array($data) && ($data['ok'] ?? false) === true) {
            return $this->json(['ok' => true, 'short_description' => $data['fields']['short_description'] ?? '', 'description' => $data['fields']['description'] ?? '']);
        }

        return $response;
    }

    /** @param array<string,mixed> $fields */
    private function execute(Request $request, string $task, array $fields): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        $user = $this->getUser();
        try {
            $result = $this->tasks->run($context->storeId, $user?->getUserIdentifier() ?? 'admin', $task, (string) $request->request->get('provider', ''), $fields, $context->locale);

            return $this->json(['ok' => true] + $result);
        } catch (\DomainException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
