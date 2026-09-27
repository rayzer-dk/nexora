<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Core\I18n\StorefrontUiTranslator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExtensionRouteController extends AbstractController
{
    public function __construct(
        private readonly ExtensionContributionRegistry $contributions,
        private readonly TrustedExtensionRuntimeLoader $trustedLoader,
        private readonly TrustedExtensionRuntimeRegistry $trustedRuntime,
        private readonly StorefrontUiTranslator $translator,
    ) {
    }

    public function dispatch(Request $request, string $extensionPath): ?Response
    {
        $path = '/' . ltrim($extensionPath, '/');
        $method = strtoupper($request->getMethod());
        $route = null;
        foreach ($this->contributions->routes() as $candidate) {
            if ((string) ($candidate['path'] ?? '') !== $path) {
                continue;
            }
            if (!in_array($method, array_map('strtoupper', (array) ($candidate['methods'] ?? [])), true)) {
                continue;
            }
            $route = $candidate;
            break;
        }
        if (!is_array($route)) {
            return null;
        }
        $routeName = (string) ($route['name'] ?? '');
        $request->attributes->set('_extension_route', $routeName);
        $request->attributes->set('_route', $routeName);
        $mode = (string) ($route['mode'] ?? 'page');
        if ($mode === 'page') {
            $pageId = (string) ($route['page'] ?? '');
            $page = $this->page($pageId);
            if ($page === null) {
                throw $this->createNotFoundException();
            }
            $locale = (string) ($request->attributes->get('_locale') ?? 'uk-UA');
            return $this->render('@storefront/extension/page.html.twig', [
                'page_title' => $this->translator->translate((string) $page['title_key'], $locale),
                'extension_page' => $page,
                'extension_content' => isset($page['content_key']) ? $this->translator->translate((string) $page['content_key'], $locale) : '',
                'seo_head' => ['robots' => 'index,follow'],
            ]);
        }
        if ($mode === 'trusted_handler') {
            $this->trustedLoader->bootActive();
            $handler = $this->trustedRuntime->routeHandler((string) ($route['name'] ?? ''));
            if (!is_callable($handler)) {
                throw $this->createNotFoundException();
            }
            $response = $handler($request, $route);
            if (!$response instanceof Response) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.234883975e56'));
            }
            return $response;
        }
        throw $this->createNotFoundException();
    }

    /** @return array<string,mixed>|null */
    private function page(string $id): ?array
    {
        foreach ($this->contributions->activeManifests() as $manifest) {
            foreach ((array) ($manifest['pages'] ?? []) as $page) {
                if (is_array($page) && (string) ($page['id'] ?? '') === $id) {
                    return $page + ['extension_code' => (string) ($manifest['code'] ?? '')];
                }
            }
        }
        return null;
    }
}
