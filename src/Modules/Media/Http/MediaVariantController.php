<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Http;

use Commerce\Modules\Media\Application\MediaVariantService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a named image size that does not exist on disk yet. The first request makes the file (under a lock); from then
 * on the web server answers that URL straight from disk and this controller is no longer involved.
 * Only the closed set of presets of the current generation can be made, so the URL cannot be used to request arbitrary work.
 */
final class MediaVariantController
{
    private const TYPES = ['webp' => 'image/webp', 'avif' => 'image/avif', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public function __construct(private readonly MediaVariantService $variants)
    {
    }

    #[Route('/media/{key}', name: 'media_variant', requirements: ['key' => 'cache/(?:thumb|card|product|zoom)-g\d{1,4}/[A-Za-z0-9_/\-]+\.(?:webp|avif|jpg)'], methods: ['GET', 'HEAD'], priority: 500)]
    public function __invoke(string $key): Response
    {
        $current = $this->variants->currentGenerationUrl($key);
        if ($current !== null) {
            $redirect = new RedirectResponse($current, 302);
            $redirect->headers->set('Cache-Control', 'public, max-age=300');

            return $redirect;
        }
        $path = $this->variants->ensure($key);
        $parsed = $this->variants->parse($key);
        if ($path === null) {
            // The size could not be made (unreadable file, no free disk space): show the original instead of a broken picture.
            $original = $parsed !== null && $parsed['gen'] === $this->variants->profile()['generation'] ? $this->variants->findOriginal($parsed['stem']) : null;
            if ($original === null) {
                return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'public, max-age=60']);
            }
            $fallback = new BinaryFileResponse($this->variants->path($original), 200, ['Content-Type' => self::TYPES[strtolower(pathinfo($original, PATHINFO_EXTENSION))] ?? 'application/octet-stream'], false);
            $fallback->headers->set('Cache-Control', 'public, max-age=60');
            $fallback->headers->set('X-Content-Type-Options', 'nosniff');

            return $fallback;
        }
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => self::TYPES[$parsed['ext'] ?? 'jpg'] ?? 'application/octet-stream'], false);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
