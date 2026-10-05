<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Seo\Application\SeoTemplateService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Default page titles and descriptions for items that have none of their own. */
final class SeoTemplateAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly SeoTemplateService $templates, private readonly Connection $db)
    {
    }

    #[Route('/admin/system/seo-templates', name: 'admin_system_seo_templates', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_seo_templates', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            $this->templates->save($storeId, (array) $request->request->all('tpl'));
            $this->addFlash('success', CanonicalUiText::get('admin.seotemplates.saved'));

            return $this->redirectToRoute('admin_system_seo_templates');
        }
        $locales = array_map('strval', $this->db->fetchFirstColumn('SELECT locale_code FROM mc_store_locale WHERE store_id=? AND enabled=1 ORDER BY locale_code', [$storeId]));

        return $this->render('@storefront/admin/system/seo_templates.html.twig', [
            'templates' => $this->templates->all($storeId),
            'locales' => $locales,
            'types' => SeoTemplateService::TYPES,
            'variables' => SeoTemplateService::VARIABLES,
        ]);
    }
}
