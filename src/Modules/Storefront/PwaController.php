<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        // Installability must never depend on a merchant-uploaded icon's unknown size.
        // Always advertise genuine bundled 192/512 PNG assets; SVG is an optional
        // store-specific scalable icon. Never mislabel raster uploads as "any".
        $customRaster = $this->localRasterIcon($icon);
        $icons = $customRaster !== null
            ? [
                ['src' => '/pwa-icon/192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa-icon/512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ]
            : [
                ['src' => '/assets/branding/nexora-mark-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/branding/nexora-mark-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/branding/nexora-mark-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ];
        if ($icon !== '' && $extension === 'svg') {
            array_unshift($icons, ['src' => $icon, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']);
        }

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
        $settings = $this->presentation->get($context->storeId);
        $pwaEnabled = ($settings['brand']['pwa'] ?? '0') === '1';
        $workboxEnabled = $pwaEnabled && ($settings['brand']['workbox'] ?? '0') === '1'
            && is_file($this->projectDir . '/public/assets/workbox/v7.4.1/workbox-sw.js');
        $source = $workboxEnabled ? (string) file_get_contents($this->projectDir . '/resources/pwa/sw.js') : '';
        if ($source === '') {
            // PWA remains installable without Workbox. The fallback never caches responses.
            // The standalone push worker is retained when PWA is disabled but push is subscribed.
            $source = <<<'JS'
importScripts('/nexora-push-sw.js');
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil((async () => {
  for (const name of await caches.keys()) {
    if (name.startsWith('nx-static-') || name.startsWith('nx-images-') || name.startsWith('nx-workbox-')) {
      await caches.delete(name);
    }
  }
  if (__PWA_DISABLED__) {
    const push = self.registration.pushManager
      ? await self.registration.pushManager.getSubscription().catch(() => null)
      : null;
    if (!push) await self.registration.unregister();
  } else {
    await self.clients.claim();
  }
})()));
self.addEventListener('fetch', (event) => {
  if (event.request.method === 'GET' && event.request.mode === 'navigate' &&
      new URL(event.request.url).origin === self.location.origin) {
    event.respondWith(fetch(event.request));
  }
});
JS;
            $source = str_replace('__PWA_DISABLED__', $pwaEnabled ? 'false' : 'true', $source);
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

    /**
     * Only a real, small raster file under public/media or public/assets may
     * become an install icon. URLs are normalized by presentation settings.
     * Never download a merchant-provided URL or follow out-of-root symlinks.
     */
    private function localRasterIcon(string $icon): ?string
    {
        if (!preg_match('#^/(?:media|assets)/[A-Za-z0-9_./-]+\.(?:png|jpe?g|webp|gif)$#i', $icon) ||
            str_contains($icon, '..') ||
            !function_exists('imagecreatetruecolor') ||
            !function_exists('imagecreatefromstring')) {
            return null;
        }

        $root = realpath($this->projectDir . '/public');
        $file = realpath($this->projectDir . '/public' . $icon);
        if (!is_string($root) || !is_string($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) ||
            !is_file($file) || filesize($file) > 8 * 1024 * 1024) {
            return null;
        }

        $info = @getimagesize($file);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1 ||
            $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 16000000) {
            return null;
        }

        return $file;
    }

    #[Route('/pwa-icon/{size}.png', name: 'storefront_pwa_install_icon', requirements: ['size' => '192|512'], methods: ['GET'], priority: 901)]
    public function appIcon(Request $request, int $size): Response
    {
        $context = $this->contexts->resolve($request);
        $settings = $this->presentation->get($context->storeId);
        if (($settings['brand']['pwa'] ?? '1') !== '1') {
            throw $this->createNotFoundException();
        }

        $fallbackPath = $this->projectDir . '/public/assets/branding/nexora-mark-' . $size . '.png';
        $raster = $this->localRasterIcon((string) ($settings['brand']['icon'] ?? ''));
        if ($raster !== null) {
            $raw = @file_get_contents($raster);
            $source = is_string($raw) ? @imagecreatefromstring($raw) : false;
            if ($source !== false) {
                $canvas = imagecreatetruecolor($size, $size);
                if ($canvas !== false) {
                    $hex = (string) ($settings['theme']['primary'] ?? '#0B63F6');
                    if (!preg_match('/^#[a-fA-F0-9]{6}$/D', $hex)) {
                        $hex = '#0B63F6';
                    }
                    $background = imagecolorallocate(
                        $canvas, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))
                    );
                    imagefilledrectangle($canvas, 0, 0, $size, $size, $background);
                    // 70% content size stays within the maskable safe zone.
                    $box = (int) round($size * 0.70);
                    $width = imagesx($source);
                    $height = imagesy($source);
                    $factor = min($box / max(1, $width), $box / max(1, $height));
                    $targetWidth = max(1, (int) round($width * $factor));
                    $targetHeight = max(1, (int) round($height * $factor));
                    imagecopyresampled(
                        $canvas, $source,
                        (int) floor(($size - $targetWidth) / 2), (int) floor(($size - $targetHeight) / 2),
                        0, 0, $targetWidth, $targetHeight, $width, $height
                    );
                    ob_start();
                    imagepng($canvas, null, 6);
                    $png = ob_get_clean();
                    imagedestroy($canvas);
                    imagedestroy($source);
                    if (is_string($png) && $png !== '') {
                        return new Response($png, 200, [
                            'Content-Type' => 'image/png',
                            'Cache-Control' => 'public, max-age=3600',
                            'X-Content-Type-Options' => 'nosniff',
                        ]);
                    }
                } else {
                    imagedestroy($source);
                }
            }
        }

        return new BinaryFileResponse($fallbackPath, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

}
