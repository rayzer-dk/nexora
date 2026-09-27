<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Content\System\InformationPageCatalog;
use Commerce\Modules\Seo\System\SystemPageRouteCatalog;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContentAdminPageController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $context,
        private readonly Connection $connection,
        private readonly InformationPageCatalog $definitions,
        private readonly SystemPageRouteCatalog $routes,
        private readonly HtmlSanitizerInterface $richTextSanitizer,
    ) {
    }

    #[Route('/admin/content/pages', name: 'admin_content_pages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->context->resolve($request);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT ce.system_key,ce.status,ct.title,ct.updated_at
             FROM mc_content_entry ce
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
             WHERE ce.store_id=? AND ce.content_type='page' AND ce.system_key IS NOT NULL
             ORDER BY ce.id ASC",
            [$context->locale, $context->storeId],
        );
        $items = [];
        foreach ($rows as $row) {
            try {
                $definition = $this->definitions->get((string) $row['system_key']);
                $route = $this->routes->route($definition->routeKey, $context->locale);
                $row['url'] = '/' . $route->path;
            } catch (\Throwable) {
                $row['url'] = null;
            }
            $items[] = $row;
        }
        return $this->render('@storefront/admin/content/pages.html.twig', ['items' => $items]);
    }

    #[Route('/admin/content/pages/{systemKey}', name: 'admin_content_page_edit', methods: ['GET', 'POST'], requirements: ['systemKey' => '[a-z0-9_-]+'])]
    public function edit(Request $request, string $systemKey): Response
    {
        $context = $this->context->resolve($request);
        $definition = $this->definitions->get($systemKey);
        $row = $this->connection->fetchAssociative(
            "SELECT ce.id,ce.status,ct.title,ct.excerpt,ct.body_html,ct.meta_title,ct.meta_description
             FROM mc_content_entry ce
             JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
             WHERE ce.store_id=? AND ce.content_type='page' AND ce.system_key=? LIMIT 1",
            [$context->locale, $context->storeId, $systemKey],
        );
        if (!is_array($row)) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_content_page_' . $systemKey, (string) $request->request->get('_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            } else {
                $title = trim((string) $request->request->get('title', ''));
                $body = trim((string) $request->request->get('body_html', ''));
                $status = (string) $request->request->get('status', 'draft');
                if ($title === '') {
                    $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.nazva_storinky_oboviazkova'));
                } elseif (!in_array($status, ['draft', 'published'], true)) {
                    $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.nevidomyi_status_storinky'));
                } elseif ($status === 'published' && $body === '') {
                    $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.pered_publikatsiieiu_zapovnit_zmist_storinky'));
                } else {
                    $safeBody = $body === '' ? null : $this->richTextSanitizer->sanitize($body);
                    $now = $this->now();
                    $this->connection->transactional(function (Connection $db) use ($row, $title, $safeBody, $status, $request, $now, $context): void {
                        $db->update('mc_content_translation', [
                            'title' => $title,
                            'excerpt' => $this->nullable((string) $request->request->get('excerpt', '')),
                            'body_html' => $safeBody,
                            'meta_title' => $this->nullable((string) $request->request->get('meta_title', '')),
                            'meta_description' => $this->nullable((string) $request->request->get('meta_description', '')),
                            'updated_at' => $now,
                        ], ['content_id' => (int) $row['id'], 'locale' => $context->locale]);
                        $db->update('mc_content_entry', [
                            'status' => $status,
                            'published_at' => $status === 'published' ? $now : null,
                            'updated_at' => $now,
                        ], ['id' => (int) $row['id']]);
                    });
                    $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.storinku_zberezheno'));
                    return $this->redirectToRoute('admin_content_pages');
                }
            }
            $row = array_merge($row, [
                'title' => (string) $request->request->get('title', $row['title']),
                'excerpt' => (string) $request->request->get('excerpt', $row['excerpt'] ?? ''),
                'body_html' => (string) $request->request->get('body_html', $row['body_html'] ?? ''),
                'meta_title' => (string) $request->request->get('meta_title', $row['meta_title'] ?? ''),
                'meta_description' => (string) $request->request->get('meta_description', $row['meta_description'] ?? ''),
                'status' => (string) $request->request->get('status', $row['status']),
            ]);
        }

        $route = $this->routes->route($definition->routeKey, $context->locale);
        return $this->render('@storefront/admin/content/page_form.html.twig', [
            'page' => $row,
            'system_key' => $systemKey,
            'public_url' => '/' . $route->path,
            'csrf_id' => 'admin_content_page_' . $systemKey,
        ]);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
