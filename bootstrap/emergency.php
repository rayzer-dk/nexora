<?php

declare(strict_types=1);

/**
 * Pre-framework emergency responder.
 *
 * It is deliberately dependency-free so it can still respond if Composer, Symfony,
 * a compiled container or an application class fails during bootstrap. It cannot
 * recover from web-server/PHP-process/database outages, but it prevents many PHP
 * bootstrap failures from becoming a blank storefront.
 */
(function (): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $projectDir = dirname(__DIR__);
    $emergencyCatalogPath = $projectDir . '/resources/translations/uk-UA/emergency.php';
    $emergencyCatalog = is_file($emergencyCatalogPath) ? require $emergencyCatalogPath : [];
    $emergencyText = static fn (string $key): string => is_array($emergencyCatalog) ? (string) ($emergencyCatalog[$key] ?? $key) : $key;

    // Dependency-free maintenance gate. Recovery/update operations can keep serving a
    // controlled response even when Composer or the framework is temporarily unavailable.
    $maintenancePath = $projectDir . '/var/maintenance.flag';
    if (is_file($maintenancePath)) {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        // During a Core update every normal visitor stays on the dependency-free
        // maintenance page. A single private probe request may boot the newly
        // switched release so the updater can verify the real HTTP/runtime path
        // before maintenance is released. The token lives outside the web root.
        $probeAllowed = false;
        if ($path === '/__health/core-update') {
            $candidate = trim((string) ($_SERVER['HTTP_X_COMMERCE_UPDATE_PROBE'] ?? ''));
            $probeRaw = @file_get_contents($projectDir . '/var/update/probe.token');
            $stored = is_string($probeRaw) ? trim($probeRaw) : '';
            $probeAllowed = $candidate !== '' && $stored !== '' && strlen($candidate) <= 256 && hash_equals($stored, $candidate);
        }

        if (!$probeAllowed) {
            $state = ['reason' => 'maintenance', 'retry_after' => 30];
            $raw = @file_get_contents($maintenancePath);
            if (is_string($raw) && $raw !== '') {
                try {
                    $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $state = array_merge($state, $decoded);
                    }
                } catch (Throwable) {
                    // Corrupt maintenance metadata must still fail closed into maintenance mode.
                }
            }
            $retryAfter = max(5, min(3600, (int) ($state['retry_after'] ?? 30)));
            $isApi = $path === '/api' || str_starts_with($path, '/api/') || str_contains($path, '/api/');
            http_response_code(503);
            header('Retry-After: ' . $retryAfter);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Commerce-Maintenance: active');
            if ($isApi) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['error' => 'maintenance', 'message' => $emergencyText('maintenance.api_message')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="uk-UA"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars($emergencyText('maintenance.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title><style>body{margin:0;background:#f5f7fb;color:#172033;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}.w{max-width:720px;margin:12vh auto;padding:20px}.c{background:#fff;border:1px solid #dfe5ef;border-top:4px solid #0057d9;border-radius:18px;padding:28px;box-shadow:0 20px 60px rgba(15,23,42,.08)}h1{margin:0 0 12px;font-size:28px}p{line-height:1.65;color:#526176}</style></head><body><main class="w"><section class="c"><h1>' . htmlspecialchars($emergencyText('maintenance.heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p>' . htmlspecialchars($emergencyText('maintenance.text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></section></main></body></html>';
            exit;
        }
    }

    register_shutdown_function(static function () use ($projectDir, $emergencyText): void {
        $error = error_get_last();
        if (!is_array($error) || !in_array((int) ($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        $requestId = bin2hex(random_bytes(10));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $isAdmin = $path === '/admin' || str_starts_with($path, '/admin/');
        $isApi = $path === '/api' || str_starts_with($path, '/api/') || str_contains($path, '/api/');

        $summary = preg_replace('/[\r\n\t]+/', ' ', (string) ($error['message'] ?? 'Fatal runtime error')) ?: 'Fatal runtime error';
        $summary = substr($summary, 0, 1200);
        $logDir = $projectDir . '/var/log';
        if ((is_dir($logDir) || @mkdir($logDir, 0750, true)) && is_writable($logDir)) {
            $line = sprintf(
                "%s request=%s type=%d area=%s path_sha256=%s error=%s\n",
                gmdate('c'),
                $requestId,
                (int) ($error['type'] ?? 0),
                $isAdmin ? 'admin' : ($isApi ? 'api' : 'storefront'),
                hash('sha256', $path),
                $summary,
            );
            @file_put_contents($logDir . '/emergency.log', $line, FILE_APPEND | LOCK_EX);
        }

        if (headers_sent()) {
            return;
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!$isAdmin && !$isApi && $method === 'GET' && (string) (parse_url($uri, PHP_URL_QUERY) ?? '') === '') {
            $snapshot = $projectDir . '/var/failsafe/storefront/' . hash('sha256', $path) . '.json';
            $raw = @file_get_contents($snapshot);
            if (is_string($raw) && $raw !== '') {
                try {
                    $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                    $html = is_array($payload) ? ($payload['html'] ?? null) : null;
                    if (is_string($html) && $html !== '') {
                        http_response_code(200);
                        header('Content-Type: text/html; charset=UTF-8');
                        header('Cache-Control: no-cache, no-store, must-revalidate');
                        header('X-Commerce-Emergency: last-known-good');
                        header('X-Request-Id: ' . $requestId);
                        header('Warning: 110 - "Stale response served after bootstrap failure"');
                        echo $html;
                        return;
                    }
                } catch (Throwable) {
                    // Continue to the static emergency page.
                }
            }
        }

        if ($isApi) {
            http_response_code(503);
            header('Content-Type: application/json; charset=UTF-8');
            header('Retry-After: 15');
            header('X-Commerce-Emergency: safe-json');
            header('X-Request-Id: ' . $requestId);
            echo json_encode([
                'error' => 'temporary_unavailable',
                'message' => $emergencyText('emergency.api_message'),
                'request_id' => $requestId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('Retry-After: 15');
        header('X-Commerce-Emergency: static-safe-error');
        header('X-Request-Id: ' . $requestId);
        $title = $isAdmin ? $emergencyText('emergency.admin_title') : $emergencyText('emergency.storefront_title');
        $text = $isAdmin
            ? $emergencyText('emergency.admin_text')
            : $emergencyText('emergency.storefront_text');
        $back = $isAdmin ? '/admin' : '/';
        echo '<!doctype html><html lang="uk-UA"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title><style>body{margin:0;background:#f5f7fb;color:#172033;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}.w{max-width:760px;margin:10vh auto;padding:20px}.c{background:#fff;border:1px solid #dfe5ef;border-top:4px solid #0057d9;border-radius:18px;padding:28px;box-shadow:0 20px 60px rgba(15,23,42,.08)}h1{margin:0 0 12px;font-size:28px}p{line-height:1.65;color:#526176}.id{font-family:ui-monospace,monospace;background:#f2f4f7;border-radius:7px;padding:3px 6px}.b{display:inline-flex;margin-top:8px;background:#0b63f6;color:#fff;text-decoration:none;border-radius:10px;padding:11px 15px;font-weight:700}</style></head><body><main class="w"><section class="c"><h1>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</h1><p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' ' . htmlspecialchars($emergencyText('emergency.event_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' <span class="id">'
            . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</span>.</p><a class="b" href="' . $back . '">' . htmlspecialchars($emergencyText('emergency.back'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></section></main></body></html>';
    });
})();
