<?php

declare(strict_types=1);

// Browser upgrade after the release archive was unpacked over an existing installation.
// Protected by APP_SECRET (from .env.local / .env); the same work is available as `php bin/upgrade.php`.
$projectDir = dirname(__DIR__);
require_once $projectDir . '/bootstrap/upgrade.php';

$locales = ['uk-UA', 'en-US', 'ru-RU', 'de-DE', 'fr-FR', 'es-ES', 'it-IT', 'pl-PL', 'pt-BR', 'tr-TR'];
$requested = (string) ($_GET['lang'] ?? $_POST['_lang'] ?? $_COOKIE['nexora_setup_lang'] ?? 'uk-UA');
$locale = in_array($requested, $locales, true) ? $requested : 'uk-UA';
$catalog = [];
foreach (['en-US', $locale] as $catalogLocale) {
    foreach ([$projectDir . '/resources/translations/' . $catalogLocale . '/installer.php', $projectDir . '/resources/installer-lang/' . $catalogLocale . '.php'] as $catalogPath) {
        if (is_file($catalogPath)) {
            $loaded = require $catalogPath;
            if (is_array($loaded)) {
                $catalog = array_merge($catalog, $loaded);
            }
            break;
        }
    }
}
$it = static function (string $key, array $replace = []) use ($catalog): string {
    $value = is_array($catalog) && isset($catalog[$key]) ? (string) $catalog[$key] : $key;
    foreach ($replace as $name => $replacement) {
        $value = str_replace('%' . $name . '%', (string) $replacement, $value);
    }

    return $value;
};
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'");

$message = '';
$messageType = 'error';
$done = null;
$installed = is_file($projectDir . '/var/install/installed.lock');
$secret = nexora_upgrade_secret($projectDir);

if (!$installed) {
    $message = $it('upgrade.not_installed');
} elseif ($secret === '') {
    $message = $it('upgrade.secret_missing');
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $given = (string) ($_POST['secret'] ?? '');
    if ($given === '' || !hash_equals($secret, $given)) {
        sleep(2);
        $message = $it('upgrade.wrong_secret');
    } else {
        $done = nexora_upgrade_run($projectDir, static function (string $step): void {});
        if ($done['ok']) {
            $message = $it('upgrade.ok');
            $messageType = 'success';
        } else {
            $message = $done['error'] === 'upgrade_running' ? $it('upgrade.running') : $it('upgrade.failed') . ' ' . $done['error'];
        }
    }
}
$canRun = $installed && $secret !== '' && !($done !== null && $done['ok']);
?><!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $e($it('upgrade.page_title')) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/branding/nexora-mark.svg">
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#172033;background:#f5f7fb}*{box-sizing:border-box}body{margin:0}.wrap{max-width:640px;margin:48px auto;padding:0 20px}.card{background:#fff;border:1px solid #dfe5ef;border-radius:18px;box-shadow:0 18px 50px rgba(29,43,76,.08);padding:28px}h1{margin:0 0 12px;font-size:26px}p{line-height:1.55;color:#344054}label{display:block;margin:18px 0 6px;font-weight:650}input{width:100%;padding:11px 12px;border:1px solid #c8d1e0;border-radius:10px;font:inherit}small{display:block;margin-top:6px;color:#667085}.msg{margin:16px 0;padding:12px 14px;border-radius:10px;font-weight:600;word-break:break-word}.msg.error{color:#9f1d1d;background:#fff5f5;border:1px solid #f4b9b9}.msg.success{color:#166534;background:#f0fff4;border:1px solid #b7e4c7}.button{display:inline-flex;align-items:center;gap:10px;margin-top:20px;padding:12px 20px;border:0;border-radius:10px;background:#2457d6;color:#fff;font:inherit;font-weight:700;cursor:pointer}.button:disabled{opacity:.8;cursor:progress}.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}.is-busy .spinner{display:inline-block}.links a{display:inline-block;margin:12px 16px 0 0;color:#2457d6;font-weight:650}pre{overflow:auto;max-height:240px;padding:12px;border-radius:10px;background:#f1f4f9;font-size:12px}@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body><main class="wrap"><section class="card">
<h1><?= $e($it('upgrade.title')) ?></h1>
<p><?= $e($it('upgrade.intro')) ?></p>
<?php if ($message !== ''): ?><div class="msg <?= $e($messageType) ?>" role="alert"><?= $e($message) ?></div><?php endif; ?>
<?php if ($done !== null && $done['ok']): ?>
<p><?= $e($it('upgrade.version', ['version' => $done['version'], 'schema' => $done['schema'], 'removed' => $done['removed']])) ?></p>
<?php if ($done['output'] !== ''): ?><pre><?= $e($done['output']) ?></pre><?php endif; ?>
<div class="links"><a href="/admin"><?= $e($it('upgrade.open_admin')) ?></a><a href="/"><?= $e($it('upgrade.open_store')) ?></a></div>
<small><?= $e($it('upgrade.cleanup_hint')) ?></small>
<?php elseif ($canRun): ?>
<form method="post" data-upgrade-form autocomplete="off">
<input type="hidden" name="_lang" value="<?= $e($locale) ?>">
<label for="secret"><?= $e($it('upgrade.secret_label')) ?></label>
<input id="secret" name="secret" type="password" required autocomplete="off">
<small><?= $e($it('upgrade.secret_hint')) ?></small>
<button class="button" data-upgrade-button><span class="spinner" aria-hidden="true"></span><span data-label data-busy="<?= $e($it('upgrade.busy')) ?>"><?= $e($it('upgrade.button')) ?></span></button>
<p data-progress role="status" aria-live="polite" hidden><?= $e($it('upgrade.busy_hint')) ?></p>
<?php if ($done !== null): ?><small><?= $e($it('upgrade.log_hint')) ?></small><?php endif; ?>
</form>
<script>
(function(){var f=document.querySelector('[data-upgrade-form]');if(!f)return;f.addEventListener('submit',function(){var b=f.querySelector('[data-upgrade-button]'),l=f.querySelector('[data-label]'),p=f.querySelector('[data-progress]');b.disabled=true;b.classList.add('is-busy');l.textContent=l.getAttribute('data-busy');p.hidden=false;});window.addEventListener('pageshow',function(e){if(e.persisted){var b=f.querySelector('[data-upgrade-button]');b.disabled=false;b.classList.remove('is-busy');}});})();
</script>
<?php endif; ?>
</section></main></body>
</html>
