<?php

declare(strict_types=1);

/**
 * Receiver of Nexora order webhooks: a single file with no framework, to be placed behind HTTPS on the CRM side.
 *
 * Nexora signs every delivery:
 *   X-Nexora-Timestamp: unix seconds
 *   X-Nexora-Signature: sha256=<hex hmac_sha256(timestamp . "." . raw body, secret)>
 *   X-Nexora-Event / X-Nexora-Delivery: event name / delivery uuid
 * Body: {"id","type","version","aggregate":{"type","id"},"data":{...},"occurred_at"}
 *
 * The shared secret is the `secret` returned once by POST /api/v1/webhooks; keep it in the environment variable below.
 */

/** True when the signature matches and the timestamp is fresh (replay window in seconds). */
function nexoraSignatureIsValid(string $secret, string $timestamp, string $rawBody, string $signatureHeader, int $now, int $window = 300): bool
{
    if ($secret === '' || preg_match('/^\d{9,12}$/D', $timestamp) !== 1 || abs($now - (int) $timestamp) > $window) {
        return false;
    }
    if (preg_match('/^sha256=([0-9a-f]{64})$/D', $signatureHeader, $match) !== 1) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), $match[1]);
}

/** Hand the order over to the CRM. Replace the body with a call of the real CRM API. */
function forwardOrderToCrm(array $event, string $logFile): void
{
    $data = (array) ($event['data'] ?? []);
    $line = json_encode([
        'order_number' => (string) ($data['order_number'] ?? ''),
        'currency' => (string) ($data['currency'] ?? ''),
        'total_minor' => (int) ($data['total_minor'] ?? 0),
        'event_id' => (string) ($event['id'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

/** @return array{0:int,1:string} HTTP status and a short text */
function handleNexoraWebhook(string $secret, array $headers, string $rawBody, string $logFile, int $now): array
{
    $headers = array_change_key_case($headers, CASE_LOWER);
    if (!nexoraSignatureIsValid($secret, (string) ($headers['x-nexora-timestamp'] ?? ''), $rawBody, (string) ($headers['x-nexora-signature'] ?? ''), $now)) {
        return [401, 'bad signature'];
    }
    $event = json_decode($rawBody, true);
    if (!is_array($event) || !isset($event['id'], $event['type'])) {
        return [400, 'bad payload'];
    }
    // Nexora retries until it gets 2xx, so the same delivery can arrive twice: remember event ids.
    $seenFile = $logFile . '.seen';
    $seen = is_file($seenFile) ? (file($seenFile, FILE_IGNORE_NEW_LINES) ?: []) : [];
    if (in_array((string) $event['id'], $seen, true)) {
        return [200, 'duplicate'];
    }
    if ($event['type'] === 'commerce.order.placed') {
        forwardOrderToCrm($event, $logFile);
    }
    file_put_contents($seenFile, $event['id'] . "\n", FILE_APPEND | LOCK_EX);

    return [200, 'ok'];
}

// Run only when the file is requested directly, not when a test includes it.
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    [$status, $text] = handleNexoraWebhook(
        (string) getenv('NEXORA_WEBHOOK_SECRET'),
        function_exists('getallheaders') ? (array) getallheaders() : [],
        (string) file_get_contents('php://input'),
        (string) (getenv('CRM_ORDER_LOG') ?: sys_get_temp_dir() . '/crm-orders.jsonl'),
        time(),
    );
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $text;
}
