<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class StorefrontLastKnownGoodCache
{
    private const MAX_BYTES = 4194304;

    public function __construct(private string $projectDir)
    {
    }

    public function capture(Request $request, Response $response): void
    {
        if (!$this->eligibleRequest($request) || !$response->isSuccessful()) {
            return;
        }
        $contentType = strtolower((string) $response->headers->get('content-type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return;
        }
        $html = $response->getContent();
        if (!is_string($html) || $html === '' || strlen($html) > self::MAX_BYTES) {
            return;
        }

        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return;
        }
        $payload = json_encode([
            'captured_at' => gmdate('c'),
            'status' => $response->getStatusCode(),
            'content_type' => $response->headers->get('content-type', 'text/html; charset=UTF-8'),
            'html' => $html,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return;
        }
        $path = $this->path($request);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    public function restore(Request $request): ?Response
    {
        if (!$this->eligibleRequest($request)) {
            return null;
        }
        $path = $this->path($request);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload) || !is_string($payload['html'] ?? null) || $payload['html'] === '') {
            return null;
        }
        $response = new Response($payload['html'], Response::HTTP_OK, [
            'Content-Type' => (string) ($payload['content_type'] ?? 'text/html; charset=UTF-8'),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Commerce-Fallback' => 'last-known-good',
            'Warning' => '110 - "Response is stale because live rendering failed"',
        ]);
        return $response;
    }

    public function eligibleRequest(Request $request): bool
    {
        if (!$request->isMethodSafe() || $request->getQueryString() !== null) {
            return false;
        }
        $path = $request->getPathInfo();
        foreach (['/admin', '/api', '/install', '/setup.php', '/cart', '/checkout', '/account', '/payment', '/webhook'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }
        return true;
    }

    private function path(Request $request): string
    {
        return $this->directory() . '/' . hash('sha256', (string) $request->attributes->get('_locale_prefix', '') . ':' . $request->getPathInfo()) . '.json';
    }

    private function directory(): string
    {
        return rtrim($this->projectDir, '/\\') . '/var/failsafe/storefront';
    }
}
