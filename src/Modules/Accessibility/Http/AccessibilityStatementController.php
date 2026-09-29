<?php

declare(strict_types=1);

namespace Commerce\Modules\Accessibility\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Public accessibility statement required by the European Accessibility Act (Directive (EU) 2019/882). */
final class AccessibilityStatementController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts)
    {
    }

    #[Route('/accessibility', name: 'storefront_accessibility_statement', methods: ['GET'], priority: 120)]
    public function __invoke(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $response = $this->render('@storefront/accessibility/statement.html.twig', [
            'page_title' => CanonicalUiText::get('a11y_title'),
            'store_name' => $context->storeName,
            'seo_head' => ['robots' => 'index,follow'],
        ]);
        $response->setPublic();
        $response->setMaxAge(3600);
        return $response;
    }
}
