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
<link rel="icon" type="image/png" sizes="32x32" href="assets/branding/favicon-32.png"><link rel="apple-touch-icon" href="assets/branding/favicon-180.png">
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#172033;background:#eef2f9}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(1200px 500px at 50% -10%,#dbe6ff 0,transparent 70%),#eef2f9}
.wrap{max-width:720px;margin:0 auto;padding:56px 20px}
.brand{display:flex;align-items:center;gap:14px;margin-bottom:22px}.brand img{width:56px;height:56px}.brand strong{display:block;font-size:20px;letter-spacing:-.01em}.brand span{color:#667085;font-size:14px}
.card{background:#fff;border:1px solid #dfe5ef;border-radius:22px;box-shadow:0 24px 70px rgba(29,43,76,.10);padding:36px}
h1{margin:0 0 10px;font-size:30px;line-height:1.2;letter-spacing:-.02em}p{line-height:1.6;color:#475467;margin:0 0 6px}
label{display:block;margin:24px 0 8px;font-weight:650}input{width:100%;padding:14px 16px;border:1px solid #c8d1e0;border-radius:12px;font:inherit;background:#fff}input:focus{outline:3px solid #2457d633;border-color:#2457d6}
small{display:block;margin-top:8px;color:#667085;line-height:1.5}
.msg{margin:20px 0;padding:14px 16px;border-radius:12px;font-weight:600;word-break:break-word}.msg.error{color:#9f1d1d;background:#fff5f5;border:1px solid #f4b9b9}.msg.success{color:#166534;background:#f0fff4;border:1px solid #b7e4c7}
.button{display:inline-flex;align-items:center;justify-content:center;gap:10px;margin-top:24px;padding:15px 26px;min-width:220px;border:0;border-radius:12px;background:#2457d6;color:#fff;font:inherit;font-size:16px;font-weight:700;cursor:pointer;box-shadow:0 8px 20px rgba(36,87,214,.28)}.button:hover{background:#1d49b8}.button:disabled{opacity:.8;cursor:progress}
.spinner{display:none;width:18px;height:18px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}.is-busy .spinner{display:inline-block}
.done{display:flex;align-items:center;gap:16px;margin:22px 0;padding:18px 20px;border-radius:16px;background:#f0fff4;border:1px solid #b7e4c7;color:#14532d;font-size:20px;font-weight:700}.done i{display:grid;place-items:center;flex:none;width:44px;height:44px;border-radius:50%;background:#16a34a;color:#fff;font-style:normal;font-size:24px}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 22px}.stats div{padding:16px;border:1px solid #e4e7ec;border-radius:14px;background:#f8fafc}.stats b{display:block;font-size:22px;color:#172033}.stats span{font-size:13px;color:#667085}
details{margin:6px 0 0;border:1px solid #e4e7ec;border-radius:14px;background:#fbfcfe}summary{padding:12px 16px;cursor:pointer;font-weight:650;color:#344054}pre{overflow:auto;max-height:240px;margin:0;padding:14px 16px;border-top:1px solid #e4e7ec;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.links{display:flex;flex-wrap:wrap;gap:12px;margin:24px 0 8px}.links a{display:inline-flex;align-items:center;padding:14px 24px;border-radius:12px;border:1px solid #c8d1e0;color:#2457d6;font-weight:700;text-decoration:none;background:#fff}.links a:first-child{background:#2457d6;border-color:#2457d6;color:#fff;box-shadow:0 8px 20px rgba(36,87,214,.28)}
@media(max-width:560px){.card{padding:24px}.stats{grid-template-columns:1fr}.button{width:100%}}@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body><main class="wrap"><div class="brand"><img src="assets/branding/nexora-mark.png" alt=""><div><strong>Nexora Commerce</strong><span><?= $e($it('upgrade.page_title')) ?></span></div></div><section class="card">
<h1><?= $e($it('upgrade.title')) ?></h1>
<p><?= $e($it('upgrade.intro')) ?></p>
<?php if ($message !== ''): ?><div class="msg <?= $e($messageType) ?>" role="alert"><?= $e($message) ?></div><?php endif; ?>
<?php if ($done !== null && $done['ok']): ?>
<div class="done"><i aria-hidden="true">&#10003;</i><span><?= $e($it('upgrade.done')) ?></span></div>
<div class="stats"><div><b><?= $e($done['version']) ?></b><span><?= $e($it('upgrade.stat_version')) ?></span></div><div><b><?= $e((string) $done['schema']) ?></b><span><?= $e($it('upgrade.stat_schema')) ?></span></div><div><b><?= $e((string) $done['removed']) ?></b><span><?= $e($it('upgrade.stat_removed')) ?></span></div></div>
<?php if ($done['output'] !== ''): ?><details><summary><?= $e($it('upgrade.show_log')) ?></summary><pre><?= $e($done['output']) ?></pre></details><?php endif; ?>
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
