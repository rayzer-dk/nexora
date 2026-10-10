<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Customer\Application\CustomerGroupService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Customer groups: a percentage discount shown in prices after login and taken off in the cart. */
final class CustomerGroupAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly CustomerGroupService $groups, private readonly Connection $db) {}

    #[Route('/admin/commerce/customer-groups', name: 'admin_customer_groups', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('customer_groups', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                if ($request->request->get('action') === 'delete') {
                    $this->groups->delete((string) $request->request->get('code'));
                } else {
                    $code = trim((string) $request->request->get('code', ''));
                    if ($code === '') {
                        $code = $this->codeFromName((string) $request->request->get('name', ''));
                    }
                    $this->groups->save($code, $request->request->all());
                }
                $this->addFlash('success', CanonicalUiText::get('admin.customer_groups.saved'));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('admin_customer_groups');
        }
        $counts = [];
        foreach ($this->db->fetchAllAssociative('SELECT customer_group_code AS c, COUNT(*) AS n FROM mc_customer GROUP BY customer_group_code') as $r) {
            $counts[(string) $r['c']] = (int) $r['n'];
        }

        return $this->render('@storefront/admin/commerce/customer_groups.html.twig', ['groups' => array_values($this->groups->all()), 'counts' => $counts]);
    }

    /** The technical code of a new group is made from its name, so nobody has to invent one. */
    private function codeFromName(string $name): string
    {
        $base = str_replace('-', '_', (new \Commerce\Modules\Seo\Application\SlugGenerator())->generate($name, 'uk-UA'));
        $base = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($base)), '_');
        $base = $base === '' ? 'group' : substr($base, 0, 56);
        $code = $base;
        for ($i = 2; isset($this->groups->all()[$code]) && $i < 100; ++$i) {
            $code = $base . '_' . $i;
        }

        return $code;
    }
}
