<?php
// A stand-in for the Telegram Bot API used by the support-chat end-to-end test (php -S 127.0.0.1:8099 telegram-mock.php).
// POST /bot<token>/<method> answers like Telegram and records the call; GET /__log lists the calls, POST /__reset clears them.
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/nexora-telegram-mock';
@mkdir($dir);
$logFile = $dir . '/calls.jsonl';
$stateFile = $dir . '/state.json';
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$save = static function () use (&$state, $stateFile): void { file_put_contents($stateFile, json_encode($state)); };
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
header('Content-Type: application/json');

// A supplier price list for the supplier-sync end-to-end test: /supplier-feed.xml?sku=A&price=12.5&stock=4 (YML with that one offer).
if ($path === '/supplier-feed.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    $sku = htmlspecialchars((string) ($_GET['sku'] ?? ''), ENT_XML1);
    $price = htmlspecialchars((string) ($_GET['price'] ?? '1'), ENT_XML1);
    $stock = htmlspecialchars((string) ($_GET['stock'] ?? '1'), ENT_XML1);
    echo '<?xml version="1.0" encoding="UTF-8"?><yml_catalog><shop><offers>'
        . '<offer id="1" available="true"><vendorCode>' . $sku . '</vendorCode><name>Fixture offer</name><price>' . $price . '</price><stock_quantity>' . $stock . '</stock_quantity></offer>'
        . '<offer id="2" available="true"><vendorCode>NEW-' . $sku . '</vendorCode><name>Brand new offer</name><price>9.99</price><stock_quantity>2</stock_quantity></offer>'
        . '</offers></shop></yml_catalog>';
    return;
}
if ($path === '/__log') {
    $lines = is_file($logFile) ? array_filter(explode("\n", (string) file_get_contents($logFile))) : [];
    echo json_encode(array_map(static fn (string $l): mixed => json_decode($l, true), array_values($lines)));
    return;
}
if ($path === '/__reset') {
    @unlink($logFile);
    @unlink($stateFile);
    echo '{"ok":true}';
    return;
}
if (!preg_match('~^/bot(\d+:[A-Za-z0-9_-]+)/([A-Za-z]+)$~', $path, $m)) {
    http_response_code(404);
    echo '{"ok":false,"description":"Not Found"}';
    return;
}
$payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
file_put_contents($logFile, json_encode(['method' => $m[2], 'payload' => $payload]) . "\n", FILE_APPEND | LOCK_EX);
$result = match ($m[2]) {
    'getMe' => ['id' => 111222333, 'is_bot' => true, 'username' => 'e2e_support_bot'],
    'setWebhook' => (function () use (&$state, $save, $payload): bool { $state['webhook'] = (string) ($payload['url'] ?? ''); $save(); return true; })(),
    'deleteWebhook' => (function () use (&$state, $save): bool { $state['webhook'] = ''; $save(); return true; })(),
    'getWebhookInfo' => ['url' => (string) ($state['webhook'] ?? ''), 'pending_update_count' => 0],
    'getChat' => ['id' => (int) ($payload['chat_id'] ?? 0), 'type' => 'supergroup', 'title' => 'E2E staff', 'is_forum' => true],
    'getChatMember' => ['status' => 'administrator', 'can_manage_topics' => true],
    'createForumTopic' => (function () use (&$state, $save): array { $state['topic'] = (int) ($state['topic'] ?? 700) + 1; $save(); return ['message_thread_id' => $state['topic'], 'name' => 'topic']; })(),
    'sendMessage', 'copyMessage' => (function () use (&$state, $save): array { $state['message'] = (int) ($state['message'] ?? 5000) + 1; $save(); return ['message_id' => $state['message']]; })(),
    default => true,
};
echo json_encode(['ok' => true, 'result' => $result]);
