<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Help\HelpCatalog;
use Commerce\Modules\Admin\Help\MarkdownLite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Help and documents inside the admin: installation, update, restore, modules and the developer guides. */
final class AdminHelpController extends AbstractController
{
    public function __construct(private readonly HelpCatalog $catalog)
    {
    }

    #[Route('/admin/help', name: 'admin_help', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@storefront/admin/help/index.html.twig', ['docs' => $this->catalog->all()]);
    }

    #[Route('/admin/help/{slug}', name: 'admin_help_doc', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]{2,40}'])]
    public function doc(string $slug): Response
    {
        $doc = $this->catalog->find($slug);
        if ($doc === null) {
            throw $this->createNotFoundException();
        }
        $renderer = new MarkdownLite($this->catalog->urlForFile(...));

        return $this->render('@storefront/admin/help/doc.html.twig', [
            'doc' => $doc,
            'body' => $renderer->render($doc['source']),
            'docs' => $this->catalog->all(),
        ]);
    }
}
