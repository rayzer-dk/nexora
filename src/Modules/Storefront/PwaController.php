<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Installable-app support: web manifest, service worker and the offline page. All follow the Appearance → PWA setting. */
final class PwaController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly StorefrontPresentationSettings $presentation,
        private readonly string $projectDir,
    ) {
    }

    #[Route('/manifest.webmanifest', name: 'storefront_pwa_manifest', methods: ['GET'], priority: 900)]
    public function manifest(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $settings = $this->presentation->get($context->storeId);
        if (($settings['brand']['pwa'] ?? '1') !== '1') {
            throw $this->createNotFoundException();
        }
        $name = trim((string) ($settings['brand']['title'] ?? '')) !== '' ? (string) $settings['brand']['title'] : $context->storeName;
        $icon = (string) ($settings['brand']['icon'] ?? '');
        $extension = strtolower(pathinfo(parse_url($icon, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $type = match ($extension) { 'png' => 'image/png', 'webp' => 'image/webp', 'jpg', 'jpeg' => 'image/jpeg', default => 'image/svg+xml' };
        // The store's own icon when it has one, otherwise the platform's PNG set (the installable app needs real 192/512 sizes).
        $icons = $icon !== ''
            ? [['src' => $icon, 'sizes' => 'any', 'type' => $type, 'purpose' => 'any'], ['src' => $icon, 'sizes' => 'any', 'type' => $type, 'purpose' => 'maskable']]
            : [
                ['src' => '/assets/branding/nexora-mark-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/branding/nexora-mark-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/branding/nexora-mark-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ];

        $manifest = [
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => (string) ($settings['brand']['subtitle'] ?? ''),
            'lang' => $context->locale,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => (string) ($settings['theme']['primary'] ?? '#0B63F6'),
            'icons' => $icons,
        ];
        $response = new JsonResponse($manifest, 200, ['Content-Type' => 'application/manifest+json; charset=UTF-8']);
        $response->setPublic();
        $response->setMaxAge(3600);
        return $response;
    }

    #[Route('/sw.js', name: 'storefront_pwa_service_worker', methods: ['GET'], priority: 900)]
    public function serviceWorker(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $source = (string) @file_get_contents($this->projectDir . '/resources/pwa/sw.js');
        if ($source === '' || ($this->presentation->get($context->storeId)['brand']['pwa'] ?? '1') !== '1') {
            // Disabled: a self-removing worker cleans up anything installed earlier.
            $source = "self.addEventListener('install',()=>self.skipWaiting());self.addEventListener('activate',(e)=>e.waitUntil((async()=>{for(const k of await caches.keys()){if(k.startsWith('nx-'))await caches.delete(k);}await self.registration.unregister();})()));";
        }
        return new Response(str_replace('__VERSION__', PlatformVersion::VERSION, $source), 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    #[Route('/offline', name: 'storefront_pwa_offline', methods: ['GET'], priority: 900)]
    public function offline(): Response
    {
        $response = $this->render('@storefront/pwa/offline.html.twig', ['seo_head' => ['robots' => 'noindex,nofollow']]);
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $response;
    }
}
