<?php

declare(strict_types=1);

namespace Commerce\Modules\Downloads\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Downloads\Application\DownloadCenterService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DownloadsController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts, private readonly DownloadCenterService $downloads)
    {
    }

    #[Route('/downloads', name: 'storefront_downloads', methods: ['GET'], priority: 300)]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);

        return $this->render('@storefront/downloads/index.html.twig', [
            'page_title' => CanonicalUiText::get('downloads_title'),
            'store_name' => $context->storeName,
            'groups' => $this->downloads->publicGroups($context->storeId, $context->locale, $q),
            'q' => $q,
            'seo_head' => ['canonical' => $request->getSchemeAndHttpHost() . '/downloads', 'robots' => $q === '' ? 'index,follow' : 'noindex,follow'],
        ]);
    }

    #[Route('/downloads/{id}/get', name: 'storefront_download_get', methods: ['GET'], requirements: ['id' => '\d+'], priority: 300)]
    public function get(int $id, Request $request): Response
    {
        $url = $this->downloads->hit($this->contexts->resolve($request)->storeId, $id);
        if ($url === null) {
            throw $this->createNotFoundException();
        }

        return new RedirectResponse($url, 302, ['Cache-Control' => 'no-store']);
    }
}
