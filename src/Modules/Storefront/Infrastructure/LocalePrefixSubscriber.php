<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Languages live in the URL: "/ru/catalog" is the Russian storefront, "/catalog" the default language.
 *
 *  - the prefix is cut off before routing (so every route works in every language) and remembered in the request;
 *  - generated URLs get the prefix back (the router base URL), and so do the plain "/path" links of the HTML;
 *  - "?lang=ru-RU" and the language cookie of a returning visitor lead to the prefixed address with a redirect;
 *  - pages with a counterpart in other languages carry hreflang links and a canonical address of their own language.
 */
final class LocalePrefixSubscriber implements EventSubscriberInterface
{
    /** Paths that never belong to a language: the admin, the API, files, feeds and technical endpoints. */
    private const NEUTRAL = '#^/(?:(?:admin|api|assets|build|media|uploads|captcha|sitemaps?|robots|llms|manifest|sw|favicon|apple-touch-icon|webhooks?|health|setup)(?=[/?.]|$)|\.well-known|_[a-z])#';

    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly LocalePrefixes $prefixes,
        private readonly RouterInterface $router,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onRequest', 250], ['afterRouting', 30]],
            KernelEvents::RESPONSE => ['onResponse', -30],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (preg_match(self::NEUTRAL, $path) === 1) {
            return;
        }
        $storeId = $this->contexts->storeIdForRequest($request);
        if ($storeId === null) {
            return;
        }
        $map = $this->prefixes->forStore($storeId);
        if ($map['prefixes'] === []) {
            return;
        }
        $byPrefix = array_flip($map['prefixes']);
        $request->attributes->set('_locale_store', $storeId);

        if (preg_match('#^/([a-z]{2,3}(?:-[a-z0-9]{2,8})?)(?=/|$)#', $path, $m) === 1 && isset($byPrefix[$m[1]])) {
            $rest = substr($path, strlen($m[0]));
            $this->strip($request, $rest === '' || $rest === false ? '/' : $rest, $m[1], $byPrefix[$m[1]]);

            return;
        }

        $isRead = in_array($request->getMethod(), ['GET', 'HEAD'], true);
        $lang = $request->query->get('lang');
        if ($isRead && is_string($lang) && $lang !== '' && ($lang === $map['default'] || isset($map['prefixes'][$lang]))) {
            $query = $request->query->all();
            unset($query['lang']);
            $target = ($lang === $map['default'] ? '' : '/' . $map['prefixes'][$lang]) . ($path === '/' && $lang !== $map['default'] ? '' : $path);
            $response = new RedirectResponse(($target === '' ? '/' : $target) . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)), 302);
            $response->headers->setCookie($this->cookie($request, $lang));
            $event->setResponse($response);

            return;
        }

        // A returning visitor who chose another language: pages open in it, at the language address.
        $cookieLocale = (string) $request->cookies->get('store_locale', '');
        if ($isRead && $cookieLocale !== '' && isset($map['prefixes'][$cookieLocale]) && !$request->isXmlHttpRequest()
            && str_contains((string) $request->headers->get('Accept', ''), 'text/html')) {
            $qs = $request->getQueryString();
            $target = '/' . $map['prefixes'][$cookieLocale] . ($path === '/' ? '' : $path);
            $event->setResponse(new RedirectResponse($target . ($qs !== null && $qs !== '' ? '?' . $qs : ''), 302));
        }
    }

    /** After the router has taken the request: URLs generated for this page carry the language prefix. */
    public function afterRouting(RequestEvent $event): void
    {
        $prefix = $event->getRequest()->attributes->get('_locale_prefix');
        if ($event->isMainRequest() && is_string($prefix) && $prefix !== '') {
            $context = $this->router->getContext();
            $context->setBaseUrl(rtrim($context->getBaseUrl(), '/') . '/' . $prefix);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $prefix = $request->attributes->get('_locale_prefix');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : null;

        if ($prefix !== null) {
            $locale = (string) $request->attributes->get('_locale_prefix_locale');
            if ($locale !== '' && $request->cookies->get('store_locale') !== $locale) {
                $response->headers->setCookie($this->cookie($request, $locale));
            }
            $location = (string) $response->headers->get('Location', '');
            if ($response->isRedirection() && $location !== '' && $location[0] === '/' && !str_starts_with($location, '//') && !$this->neutralOrPrefixed($location, $prefix)) {
                $response->headers->set('Location', '/' . $prefix . ($location === '/' ? '' : $location));
            }
        }

        if (!$this->isHtml($response)) {
            return;
        }
        $storeId = $request->attributes->get('_locale_store');
        if (!is_int($storeId)) {
            return;
        }
        $html = (string) $response->getContent();
        if ($prefix !== null) {
            $html = (string) preg_replace_callback(
                '~\b(href|action)=(["\'])(/(?!/)[^"\']*)\2~',
                fn (array $m): string => $this->neutralOrPrefixed($m[3], $prefix) ? $m[0] : $m[1] . '=' . $m[2] . '/' . $prefix . ($m[3] === '/' ? '' : $m[3]) . $m[2],
                $html,
            );
        }
        $html = $this->alternates($request, $storeId, $prefix, $html);
        $response->setContent($html);
        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($html));
        }
    }

    private function strip(Request $request, string $rest, string $prefix, string $locale): void
    {
        $server = $request->server->all();
        $qs = $request->getQueryString();
        $server['REQUEST_URI'] = $rest . ($qs !== null && $qs !== '' ? '?' . $qs : '');
        $attributes = $request->attributes->all();
        $attributes['_original_request_uri'] = $request->getRequestUri();
        $attributes['_locale_prefix'] = $prefix;
        $attributes['_locale_prefix_locale'] = $locale;
        $content = str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'multipart/') ? '' : $request->getContent();
        $request->initialize($request->query->all(), $request->request->all(), $attributes, $request->cookies->all(), $request->files->all(), $server, $content);
    }

    private function neutralOrPrefixed(string $path, string $prefix): bool
    {
        return preg_match(self::NEUTRAL, $path) === 1 || $path === '/' . $prefix || str_starts_with($path, '/' . $prefix . '/') || str_starts_with($path, '/' . $prefix . '?');
    }

    private function isHtml(Response $response): bool
    {
        return $response->getStatusCode() === 200
            && str_contains(strtolower((string) $response->headers->get('Content-Type', '')), 'text/html')
            && !($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse)
            && !($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse);
    }

    private function cookie(Request $request, string $locale): Cookie
    {
        return Cookie::create('store_locale', $locale, new \DateTimeImmutable('+180 days'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    /** hreflang for the same page in every language, and the canonical address of the language the page is in. */
    private function alternates(Request $request, int $storeId, ?string $prefix, string $html): string
    {
        $route = (string) $request->attributes->get('_route', '');
        if (!in_array($route, ['storefront_seo_entity', 'storefront_home'], true) || !str_contains($html, '</head>')) {
            return $html;
        }
        $map = $this->prefixes->forStore($storeId);
        $base = $request->getSchemeAndHttpHost();
        $path = trim(rawurldecode($request->getPathInfo()), '/');
        $urls = [];
        if ($route === 'storefront_home') {
            foreach (array_merge([$map['default'] => ''], array_map(static fn (string $p): string => '/' . $p, $map['prefixes'])) as $locale => $pre) {
                $urls[$locale] = $base . ($pre === '' ? '/' : $pre);
            }
        } else {
            try {
                $entity = $this->connection->fetchAssociative(
                    'SELECT entity_type,entity_public_id FROM mc_seo_route WHERE store_id=? AND path_hash=? ORDER BY (locale=?) DESC LIMIT 1',
                    [$storeId, hash('sha256', $path, true), (string) ($request->attributes->get('_locale_prefix_locale') ?: $map['default'])],
                );
                $rows = is_array($entity) ? $this->connection->fetchAllAssociative(
                    'SELECT locale,path,indexable FROM mc_seo_route WHERE store_id=? AND entity_type=? AND entity_public_id=?',
                    [$storeId, $entity['entity_type'], $entity['entity_public_id']],
                ) : [];
            } catch (\Throwable) {
                return $html;
            }
            foreach ($rows as $row) {
                $locale = (string) $row['locale'];
                if ($locale !== $map['default'] && !isset($map['prefixes'][$locale])) {
                    continue;
                }
                $urls[$locale] = $base . ($locale === $map['default'] ? '' : '/' . $map['prefixes'][$locale]) . '/' . ltrim((string) $row['path'], '/');
            }
        }
        if (count($urls) < 2) {
            return $html;
        }
        $html = (string) preg_replace('~<link\s+rel="alternate"\s+hreflang="[^"]*"\s+href="[^"]*"\s*/?>\s*~i', '', $html);
        $tags = '';
        foreach ($urls as $locale => $url) {
            $tags .= '<link rel="alternate" hreflang="' . htmlspecialchars($locale, ENT_QUOTES) . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '">';
        }
        if (isset($urls[$map['default']])) {
            $tags .= '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($urls[$map['default']], ENT_QUOTES) . '">';
        }
        $html = (string) preg_replace('~</head>~', $tags . '</head>', $html, 1);
        $own = (string) ($request->attributes->get('_locale_prefix_locale') ?: $map['default']);
        if ($prefix !== null && isset($urls[$own])) {
            // The canonical address is the one of this language (the page number and filters of the original stay).
            $html = (string) preg_replace_callback(
                '~(<link\s+rel="canonical"\s+href=")' . preg_quote($base, '~') . '(/[^"]*)(")~i',
                fn (array $m): string => $this->neutralOrPrefixed($m[2], $prefix) ? $m[0] : $m[1] . $base . '/' . $prefix . ($m[2] === '/' ? '' : $m[2]) . $m[3],
                $html,
                1,
            );
        }

        return $html;
    }
}
