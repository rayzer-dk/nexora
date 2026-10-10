<?php

declare(strict_types=1);

const MC_REQUIRED_PHP = '8.4.0';
const MC_MAX_PHP = '9.0.0';
const MC_MIN_MEMORY_BYTES = 268435456; // 256 MiB
const MC_RECOMMENDED_DISK_BYTES = 1073741824; // 1 GiB
const MC_MIN_MYSQL = '8.4.0';
const MC_MIN_MARIADB = '10.11.0';

$projectDir = dirname(__DIR__);
require_once $projectDir . '/src/Core/Install/RegionCatalog.php';

// Installer languages: Ukrainian, English and Russian ship with the full product catalogue; the others only translate this page.
$installerLanguages = [
    'uk-UA' => 'installer.language_ukrainian', 'en-US' => 'installer.language_english', 'ru-RU' => 'installer.language_russian', 'de-DE' => 'Deutsch', 'fr-FR' => 'Français',
    'es-ES' => 'Español', 'it-IT' => 'Italiano', 'pl-PL' => 'Polski', 'pt-BR' => 'Português (Brasil)', 'tr-TR' => 'Türkçe',
];
$installerLocales = array_keys($installerLanguages);
$requestedInstallerLocale = (string) ($_GET['lang'] ?? $_POST['_lang'] ?? $_COOKIE['nexora_setup_lang'] ?? 'uk-UA');
$installerLocale = in_array($requestedInstallerLocale, $installerLocales, true) ? $requestedInstallerLocale : 'uk-UA';
if (!headers_sent()) {
    setcookie('nexora_setup_lang', $installerLocale, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
$installerCatalog = [];
foreach (['en-US', $installerLocale] as $catalogLocale) {
    // The full languages live next to the product translations, the installer-only ones in resources/installer-lang.
    foreach ([$projectDir . '/resources/translations/' . $catalogLocale . '/installer.php', $projectDir . '/resources/installer-lang/' . $catalogLocale . '.php'] as $catalogPath) {
        if (is_file($catalogPath)) {
            $loaded = require $catalogPath;
            if (is_array($loaded)) {
                $installerCatalog = array_merge($installerCatalog, $loaded);
            }
            break;
        }
    }
}
function it(string $key, array $replace = []): string
{
    global $installerCatalog;
    $value = is_array($installerCatalog) && isset($installerCatalog[$key]) ? (string) $installerCatalog[$key] : $key;
    foreach ($replace as $name => $replacement) {
        $value = str_replace('%' . $name . '%', (string) $replacement, $value);
    }
    return $value;
}
$installDir = $projectDir . '/var/install';
$lockFile = $installDir . '/installed.lock';

if (is_file($lockFile)) {
    installedResponse();
}

if (databaseLooksInstalled($projectDir)) {
    if (!is_dir($installDir)) {
        @mkdir($installDir, 0700, true);
    }
    @file_put_contents($lockFile, gmdate('c') . "\n", LOCK_EX);
    @chmod($lockFile, 0600);
    installedResponse();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isHttpsRequest(),
        'samesite' => 'Strict',
    ]);
    session_start();
}
$_SESSION['mc_setup_csrf'] ??= bin2hex(random_bytes(32));

cleanupStaleInstallRequests($installDir);

$checks = [];
$addCheck = static function (string $label, string $current, bool $passed, bool $required = true) use (&$checks): void {
    $checks[] = ['label' => $label, 'current' => $current, 'passed' => $passed, 'required' => $required];
};

$phpOk = version_compare(PHP_VERSION, MC_REQUIRED_PHP, '>=') && version_compare(PHP_VERSION, MC_MAX_PHP, '<');
$addCheck('PHP', PHP_VERSION . it('installer.potribno_8_4_8_6'), $phpOk);

$requiredExtensions = [
    'ctype', 'curl', 'dom', 'fileinfo', 'iconv', 'intl', 'json', 'mbstring', 'openssl', 'pcre',
    'pdo', 'pdo_mysql', 'session', 'simplexml', 'sodium', 'tokenizer', 'gd', 'zip',
];
foreach ($requiredExtensions as $extension) {
    $loaded = extension_loaded($extension);
    $addCheck('ext-' . $extension, $loaded ? it('installer.zavantazheno') : it('installer.vidsutnye'), $loaded);
}

$vendorReady = is_file($projectDir . '/vendor/autoload_runtime.php');
$assetsReady = is_file($projectDir . '/public/build/.vite/manifest.json');
$addCheck(it('installer.composer_dependencies'), $vendorReady ? it('installer.hotovo') : it('installer.vidsutniy'), $vendorReady);
$addCheck(it('installer.frontend_assets'), $assetsReady ? it('installer.hotovo') : it('installer.vidsutniy'), $assetsReady);

foreach ([
    [it('installer.korin_proyektu'), $projectDir],
    [it('installer.kataloh_var'), $projectDir . '/var'],
    [it('installer.kataloh_public_media'), $projectDir . '/public/media'],
] as [$label, $path]) {
    $probe = writableDirectoryProbe((string) $path);
    $addCheck((string) $label, $probe['message'], $probe['passed']);
}

$isLocal = isLocalHost((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$httpsOk = isHttpsRequest() || $isLocal;
$addCheck('HTTPS', isHttpsRequest() ? it('installer.uvimkneno') : ($isLocal ? it('installer.lokalna_rozrobka') : it('installer.vymkneno')), $httpsOk);

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
$expectedDocumentRoot = realpath($projectDir . '/public') ?: $projectDir . '/public';
$sharedHostingGateway = $documentRoot !== ''
    && rtrim($documentRoot, DIRECTORY_SEPARATOR) === rtrim((realpath($projectDir) ?: $projectDir), DIRECTORY_SEPARATOR)
    && is_file($projectDir . '/.htaccess')
    && str_contains((string) @file_get_contents($projectDir . '/.htaccess'), 'Nexora Commerce shared-hosting compatibility gateway');
$documentRootOk = $documentRoot !== '' && rtrim($documentRoot, DIRECTORY_SEPARATOR) === rtrim($expectedDocumentRoot, DIRECTORY_SEPARATOR);
$documentRootCompatible = $documentRootOk || $sharedHostingGateway;
$documentRootMessage = $documentRootOk
    ? it('installer.document_root_ok')
    : ($sharedHostingGateway
        ? it('installer.document_root_shared_hosting')
        : it('installer.document_root_bad', ['current' => $documentRoot ?: it('installer.nevidomo')]));
$addCheck('Document Root', $documentRootMessage, $documentRootCompatible);

$memoryBytes = iniBytes((string) ini_get('memory_limit'));
$memoryOk = $memoryBytes < 0 || $memoryBytes >= MC_MIN_MEMORY_BYTES;
$addCheck('memory_limit', (string) ini_get('memory_limit') . it('installer.rekomendovano_256m'), $memoryOk, false);

foreach ([
    ['upload_max_filesize', 64 * 1024 * 1024, '>=64M'],
    ['post_max_size', 64 * 1024 * 1024, '>=64M'],
] as [$setting, $minimum, $expected]) {
    $value = (string) ini_get((string) $setting);
    $bytes = iniBytes($value);
    $addCheck((string) $setting, $value . ' · ' . $expected, $bytes < 0 || $bytes >= $minimum, false);
}
$maxExecution = (int) ini_get('max_execution_time');
$addCheck('max_execution_time', $maxExecution === 0 ? '0 (unlimited)' : $maxExecution . 's · >=120s', $maxExecution === 0 || $maxExecution >= 120, false);
$maxInputVars = (int) ini_get('max_input_vars');
$addCheck('max_input_vars', $maxInputVars . ' · >=3000', $maxInputVars >= 3000, false);

$freeDisk = @disk_free_space($projectDir);
$diskOk = $freeDisk === false || $freeDisk >= MC_RECOMMENDED_DISK_BYTES;
$addCheck(it('installer.vilne_mistse'), $freeDisk === false ? it('installer.nevidomo') : formatBytes((float) $freeDisk) . it('installer.rekomendovano_1_gb'), $diskOk, false);

$opcacheLoaded = extension_loaded('Zend OPcache') || extension_loaded('opcache');
$opcacheEnabled = $opcacheLoaded && filter_var((string) ini_get('opcache.enable'), FILTER_VALIDATE_BOOL);
$addCheck('OPcache', $opcacheEnabled ? it('installer.opcache_enabled') : ($opcacheLoaded ? it('installer.opcache_disabled') : it('installer.ne_znaydeno_rekomendovano_production')), $opcacheEnabled, false);

$tmpProbe = phpTempProbe($projectDir);
$addCheck('PHP tmp', $tmpProbe['message'], $tmpProbe['passed'], false);

$timezone = date_default_timezone_get();
$addCheck('Timezone', $timezone !== '' ? $timezone : it('installer.nevidomo'), $timezone !== '', false);

$cli = phpCliProbe($projectDir);
$addCheck('PHP CLI', $cli['message'], $cli['passed'], false);
if ($cli['passed']) {
    $addCheck('bin/console', $cli['console_message'], $cli['console_passed'], false);
}

$symlink = symlinkProbe($projectDir . '/var');
$addCheck('Symlink', $symlink['message'], $symlink['passed'], false);

$outbound = outboundHttpsProbe('https://github.com');
$addCheck(it('installer.outbound_https'), $outbound['message'], $outbound['passed'], false);

$runtimeReady = !in_array(false, array_map(static fn (array $check): bool => !$check['required'] || $check['passed'], $checks), true);

$errors = [];
$values = [
    'db_host' => $_POST['db_host'] ?? 'localhost',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? 'nexora_commerce',
    'db_user' => $_POST['db_user'] ?? '',
    'store_name' => $_POST['store_name'] ?? it('installer.miy_mahazyn'),
    'admin_name' => $_POST['admin_name'] ?? it('installer.administrator'),
    'admin_email' => $_POST['admin_email'] ?? '',
    'public_url' => $_POST['public_url'] ?? detectPublicUrl(),
    'site_mode' => $_POST['site_mode'] ?? 'hybrid',
    'country' => strtoupper((string) ($_POST['country'] ?? ($installerLocale === 'uk-UA' ? 'UA' : ($installerLocale === 'ru-RU' ? 'UA' : 'US')))),
    'currency' => strtoupper((string) ($_POST['currency'] ?? '')),
    'store_locale' => (string) ($_POST['store_locale'] ?? $installerLocale),
    'timezone' => (string) ($_POST['timezone'] ?? ''),
];
$regionPreset = \Commerce\Core\Install\RegionCatalog::preset((string) $values['country']);
if ($values['currency'] === '') { $values['currency'] = $regionPreset['currency']; }
if ($values['timezone'] === '') { $values['timezone'] = $regionPreset['timezone']; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string) $_SESSION['mc_setup_csrf'], (string) ($_POST['_csrf'] ?? ''))) {
        $errors[] = it('installer.sesiya_formy_vstanovlennya_zavershylasya_onovit_storinku');
    }
    if (!$runtimeReady) {
        $errors[] = it('installer.server_ne_vidpovidaye_obovyazkovym_vymoham');
    }

    $dbHost = trim((string) ($_POST['db_host'] ?? ''));
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim((string) ($_POST['db_name'] ?? ''));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPassword = (string) ($_POST['db_password'] ?? '');
    $storeName = trim((string) ($_POST['store_name'] ?? ''));
    $adminName = trim((string) ($_POST['admin_name'] ?? ''));
    $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $publicUrl = rtrim(trim((string) ($_POST['public_url'] ?? '')), '/');
    $createDatabase = isset($_POST['create_database']);
    $installDemo = isset($_POST['install_demo']);
    $siteMode = trim((string) ($_POST['site_mode'] ?? 'hybrid'));
    $storeCountry = strtoupper(trim((string) ($_POST['country'] ?? 'UA')));
    $storeCurrency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
    $storeLocale = trim((string) ($_POST['store_locale'] ?? ''));
    $storeTimezone = trim((string) ($_POST['timezone'] ?? ''));
    if (!in_array($storeCountry, \Commerce\Core\Install\RegionCatalog::countryCodes(), true)) {
        $storeCountry = 'OTHER';
    }
    if ($storeCurrency !== '' && !isset(\Commerce\Core\Install\RegionCatalog::currencies()[$storeCurrency])) {
        $storeCurrency = '';
    }
    if (!in_array($storeLocale, \Commerce\Core\Install\RegionCatalog::BUNDLED_LOCALES, true)) {
        $storeLocale = $installerLocale;
    }
    if ($storeTimezone !== '' && !in_array($storeTimezone, \DateTimeZone::listIdentifiers(), true)) {
        $storeTimezone = '';
    }

    if (!preg_match('/^[A-Za-z0-9._:\-\[\]]+$/', $dbHost)) {
        $errors[] = it('installer.adresa_servera_bazy_danykh_mistyt_nepidtrymuvani_symvoly');
    }
    if ($dbPort < 1 || $dbPort > 65535) {
        $errors[] = it('installer.nekorektnyy_port_bazy_danykh');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        $errors[] = it('installer.nazva_bazy_danykh_mozhe_mistyty_lyshe_latynski_litery_ts');
    }
    if ($dbUser === '') {
        $errors[] = it('installer.vkazhit_korystuvacha_bazy_danykh');
    }
    if ($storeName === '') {
        $errors[] = it('installer.vkazhit_nazvu_mahazynu');
    }
    if ($adminName === '') {
        $errors[] = it('installer.vkazhit_imya_administratora');
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = it('installer.nekorektnyy_email_administratora');
    }
    $adminPasswordLength = function_exists('mb_strlen') ? mb_strlen($adminPassword, 'UTF-8') : strlen($adminPassword);
    if ($adminPasswordLength < 12 || $adminPasswordLength > 128) {
        $errors[] = it('installer.admin_password_length');
    }
    if (!filter_var($publicUrl, FILTER_VALIDATE_URL)) {
        $errors[] = it('installer.nekorektna_publichna_adresa_mahazynu');
    } elseif (!str_starts_with(strtolower($publicUrl), 'https://') && !preg_match('#^http://(?:localhost|127\.0\.0\.1)(?::\d+)?(?:/|$)#i', $publicUrl)) {
        $errors[] = it('installer.dlya_robochoho_mahazynu_obovyazkovyy_https_http_dozvolen');
    }
    if (!in_array($siteMode, ['shop','catalog','content','landing','forum','hybrid'], true)) {
        $errors[] = it('installer.nekorektnyy_profil_saytu');
    }

    if ($errors === []) {
        $installStage = 'database_connection';
        try {
            $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
            $pdo = new PDO($serverDsn, $dbUser, $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            if ($createDatabase) {
                $installStage = 'database_create';
                $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $dbName));
            }

            $installStage = 'database_selection';
            $pdo = new PDO($serverDsn . ';dbname=' . $dbName, $dbUser, $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $installStage = 'database_requirements';
            $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $engine = strtolower($serverVersion);
            $numericVersion = databaseNumericVersion($serverVersion);
            if (!str_contains($engine, 'mariadb') && version_compare($numericVersion, MC_MIN_MYSQL, '<')) {
                throw new RuntimeException(it('installer.potriben_mysql_8_4_abo_novishyy_vyyavleno') . $serverVersion);
            }
            if (str_contains($engine, 'mariadb') && version_compare($numericVersion, MC_MIN_MARIADB, '<')) {
                throw new RuntimeException(it('installer.potribna_mariadb_11_4_abo_novisha_vyyavleno') . $serverVersion);
            }

            $defaultEngine = strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn());
            $engineRows = $pdo->query('SHOW ENGINES')->fetchAll(PDO::FETCH_ASSOC);
            $innodbAvailable = false;
            foreach ($engineRows as $engineRow) {
                if (strtolower((string) ($engineRow['Engine'] ?? '')) !== 'innodb') {
                    continue;
                }
                $support = strtoupper((string) ($engineRow['Support'] ?? ''));
                $innodbAvailable = in_array($support, ['YES', 'DEFAULT'], true);
                break;
            }
            if (!$innodbAvailable) {
                throw new RuntimeException(it('installer.innodb_unavailable'));
            }
            // The platform creates its own tables explicitly with ENGINE=InnoDB.
            // A hosting-wide MyISAM default must not block installation when InnoDB is available.
            $pdo->exec('SET SESSION default_storage_engine=InnoDB');
            // Configure only this installer connection. Hosting-wide defaults are not requirements:
            // every Nexora migration creates its tables explicitly as InnoDB + utf8mb4.
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

            $charset = strtolower((string) $pdo->query('SELECT @@character_set_database')->fetchColumn());
            $collation = strtolower((string) $pdo->query('SELECT @@collation_database')->fetchColumn());
            if ($charset !== 'utf8mb4' || !str_starts_with($collation, 'utf8mb4_')) {
                // Best effort only. Some managed hostings do not grant ALTER DATABASE even though
                // CREATE/ALTER TABLE is allowed. That must not block a clean Nexora installation.
                $installStage = 'database_charset';
                try {
                    $pdo->exec(sprintf('ALTER DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $dbName));
                } catch (Throwable) {
                    // Safe to continue: all platform tables explicitly declare utf8mb4.
                }
            }

            $sqlMode = strtoupper((string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn());
            if (!str_contains($sqlMode, 'STRICT_TRANS_TABLES') && !str_contains($sqlMode, 'STRICT_ALL_TABLES')) {
                $modes = array_values(array_filter(array_map('trim', explode(',', $sqlMode)), static fn (string $mode): bool => $mode !== ''));
                $modes[] = 'STRICT_TRANS_TABLES';
                $modes = array_values(array_unique($modes));
                $statement = $pdo->prepare('SET SESSION sql_mode = :sql_mode');
                $statement->execute(['sql_mode' => implode(',', $modes)]);
            }

            // Foreign keys are required by the schema, but a hosting-level session default of 0
            // is self-correctable and therefore must not block installation.
            $pdo->exec('SET SESSION foreign_key_checks=1');
            $installStage = 'database_privileges';
            probeDatabasePrivileges($pdo);

            $installStage = 'configuration_write';
            $doctrineServerVersion = str_contains($engine, 'mariadb') ? 'mariadb-' . $numericVersion : $numericVersion;
            $databaseUrl = sprintf(
                'mysql://%s:%s@%s:%d/%s?charset=utf8mb4',
                rawurlencode($dbUser),
                rawurlencode($dbPassword),
                $dbHost,
                $dbPort,
                $dbName,
            );
            $appSecret = bin2hex(random_bytes(32));
            $env = [
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'APP_SECRET' => $appSecret,
                'SYMFONY_TRUSTED_PROXIES' => '',
                'APP_PUBLIC_URL' => $publicUrl,
                'STORE_DEFAULT_COUNTRY' => $storeCountry,
                'STORE_DEFAULT_LOCALE' => $storeLocale,
                'STORE_DEFAULT_CURRENCY' => $storeCurrency !== '' ? $storeCurrency : \Commerce\Core\Install\RegionCatalog::preset($storeCountry)['currency'],
                'STORE_DEFAULT_TIMEZONE' => $storeTimezone !== '' ? $storeTimezone : \Commerce\Core\Install\RegionCatalog::preset($storeCountry)['timezone'],
                'DATABASE_URL' => $databaseUrl,
                'DATABASE_SERVER_VERSION' => $doctrineServerVersion,
                'LOCK_DSN' => 'flock://' . $projectDir . '/var/lock',
                'REDIS_DSN' => 'redis://127.0.0.1:6379',
                'VALKEY_DSN' => 'valkey://127.0.0.1:6379',
                'VALKEY_SESSION_DSN' => 'redis://127.0.0.1:6379',
                // Like a typical shop engine: mail leaves from the server itself (PHP mail / sendmail) with an address on the shop's own
                // domain; an SMTP server can be added later in the admin (Marketing → Mail and bots).
                'MAILER_DSN' => 'native://default',
                'MAIL_FROM_ADDRESS' => 'no-reply@' . (preg_replace('/^www\./i', '', (string) (parse_url($publicUrl, PHP_URL_HOST) ?: 'localhost'))),
                'MAIL_FROM_NAME' => $storeName,
                'ERROR_WEBHOOK_URL' => '',
                'ADMIN_REQUIRE_MFA' => '0',
                'COMMERCE_CANONICAL_REDIRECT' => '1',
                'COMMERCE_SUPPLIER_ALLOW_PRIVATE_HOSTS' => '0',
                'TELEGRAM_NOTIFICATIONS_ENABLED' => '0',
                'TELEGRAM_BOT_TOKEN' => '',
                'TELEGRAM_DEFAULT_CHAT_ID' => '',
                'TELEGRAM_API_BASE' => 'https://api.telegram.org',
                'SMS_NOTIFICATIONS_ENABLED' => '0',
                'SMS_GATEWAY_ENDPOINT' => '',
                'SMS_GATEWAY_BEARER_TOKEN' => '',
                'SMS_SENDER' => '',
                'GOOGLE_MERCHANT_ENABLED' => '0',
                'GOOGLE_MERCHANT_API_BASE' => 'https://merchantapi.googleapis.com',
                'GOOGLE_MERCHANT_ACCOUNT_ID' => '',
                'GOOGLE_MERCHANT_DATA_SOURCE' => '',
                'GOOGLE_MERCHANT_SERVICE_ACCOUNT_JSON' => '',
                'GOOGLE_MERCHANT_ACCESS_TOKEN' => '',
                'GOOGLE_UCP_ENABLED' => '0',
                'GOOGLE_UCP_VERSION' => '2026-04-08',
                'TURNSTILE_ENABLED' => '0',
                'TURNSTILE_SITE_KEY' => '',
                'TURNSTILE_SECRET_KEY' => '',
                'CORE_UPDATE_PUBLIC_KEY' => '',
                'MARKETING_GA4_ENABLED' => '0',
                'MARKETING_GA4_MEASUREMENT_ID' => '',
                'MARKETING_GA4_API_SECRET' => '',
                'MARKETING_GA4_ENDPOINT' => 'https://region1.google-analytics.com/mp/collect',
                'MARKETING_META_ENABLED' => '0',
                'MARKETING_META_PIXEL_ID' => '',
                'MARKETING_META_ACCESS_TOKEN' => '',
                'MARKETING_META_API_BASE' => 'https://graph.facebook.com',
                'MARKETING_META_API_VERSION' => 'v24.0',
                'MARKETING_TIKTOK_ENABLED' => '0',
                'MARKETING_TIKTOK_PIXEL_CODE' => '',
                'MARKETING_TIKTOK_ACCESS_TOKEN' => '',
                'MARKETING_TIKTOK_ENDPOINT' => 'https://business-api.tiktok.com/open_api/v1.3/event/track/',
                'MEILISEARCH_ENABLED' => '0',
                'MEILISEARCH_ENDPOINT' => 'http://127.0.0.1:7700',
                'MEILISEARCH_API_KEY' => '',
                'MEILISEARCH_INDEX' => 'commerce_products',
                'AI_OPENAI_ENABLED' => '0',
                'AI_OPENAI_API_KEY' => '',
                'AI_OPENAI_MODEL' => 'gpt-5',
                'AI_GEMINI_ENABLED' => '0',
                'AI_GEMINI_API_KEY' => '',
                'AI_GEMINI_MODEL' => 'gemini-3.6-flash',
                'AI_ANTHROPIC_ENABLED' => '0',
                'AI_ANTHROPIC_API_KEY' => '',
                'AI_ANTHROPIC_MODEL' => 'claude-sonnet-5-5',
                'MONOBANK_ACQUIRING_ENABLED' => '0',
                'MONOBANK_ACQUIRING_API_BASE' => 'https://api.monobank.ua',
                'MONOBANK_ACQUIRING_TOKEN' => '',
                'GOOGLE_LOGIN_ENABLED' => '0',
                'GOOGLE_LOGIN_CLIENT_ID' => '',
                'GOOGLE_LOGIN_CLIENT_SECRET' => '',
                'LIQPAY_ENABLED' => '0',
                'LIQPAY_PUBLIC_KEY' => '',
                'LIQPAY_PRIVATE_KEY' => '',
                'LIQPAY_SANDBOX' => '0',
                'STRIPE_ENABLED' => '0',
                'STRIPE_SECRET_KEY' => '',
                'STRIPE_WEBHOOK_SECRET' => '',
                'PAYPAL_ENABLED' => '0',
                'PAYPAL_CLIENT_ID' => '',
                'PAYPAL_SECRET' => '',
                'PAYPAL_SANDBOX' => '0',
                'PAYPAL_WEBHOOK_ID' => '',
                'WAYFORPAY_ENABLED' => '0',
                'WAYFORPAY_MERCHANT_ACCOUNT' => '',
                'WAYFORPAY_SECRET_KEY' => '',
                'WAYFORPAY_DOMAIN' => '',
                'NOVA_POST_API_BASE' => 'https://api.novaposhta.ua/v2.0/json/',
                'NOVA_POST_API_KEY' => '',
                'NOVA_POST_SENDER_REF' => '',
                'NOVA_POST_CONTACT_SENDER_REF' => '',
                'NOVA_POST_CITY_SENDER_REF' => '',
                'NOVA_POST_SENDER_ADDRESS_REF' => '',
                'NOVA_POST_SENDER_PHONE' => '',
                'NOVA_POST_PAYER_TYPE' => 'Sender',
                'NOVA_POST_PAYMENT_METHOD' => 'NonCash',
                'NOVA_POST_LABEL_URL_TEMPLATE' => '',
                'UKRPOSHTA_API_BASE' => '',
                'UKRPOSHTA_BEARER' => '',
                'DELIVERY_AUTO_API_BASE' => '',
                'DHL_API_KEY' => '',
                'DHL_LOCATION_API_BASE' => 'https://api.dhl.com/location-finder/v1',
                'GLS_API_BASE' => '',
                'GLS_API_TOKEN' => '',
                'GLS_POINTS_PATH' => '',
                'MEEST_API_BASE' => '',
                'MEEST_API_TOKEN' => '',
                'MEEST_CITIES_PATH' => '',
                'MEEST_POINTS_PATH' => '',
            ];
            $envBody = "# Generated by Nexora Commerce browser setup. Do not commit this file.\n";
            foreach ($env as $key => $value) {
                $envBody .= $key . '=' . envQuote((string) $value) . "\n";
            }
            atomicWrite($projectDir . '/.env.local', $envBody, 0600);

            // Symfony Runtime loads the base .env before .env.local. Production packages
            // intentionally ship without .env, so browser setup creates a minimal,
            // non-secret bootstrap file locally during installation.
            $baseEnv = "APP_ENV=prod\nAPP_DEBUG=0\n";
            atomicWrite($projectDir . '/.env', $baseEnv, 0600);

            $installStage = 'application_install';
            $installPayload = [
                'store_name' => $storeName,
                'admin_name' => $adminName,
                'admin_email' => $adminEmail,
                'admin_password' => $adminPassword,
                'public_url' => $publicUrl,
                'install_demo' => $installDemo,
                'site_mode' => $siteMode,
                'country' => $storeCountry,
                'currency' => $storeCurrency,
                'locale' => $storeLocale,
                'timezone' => $storeTimezone,
            ];
            $installResult = runApplicationInstall($projectDir, $installPayload, [$dbPassword, $adminPassword]);
            if (($installResult['code'] ?? 1) !== 0 || !is_file($lockFile)) {
                $traceId = (string) ($installResult['trace'] ?? logInstallerTextFailure(
                    $projectDir,
                    'application_install',
                    (string) ($installResult['output'] ?? 'Installation command returned no output.'),
                    [$dbPassword, $adminPassword]
                ));
                // Keep raw console diagnostics in var/log/installer.log. The browser UI must stay
                // concise and readable instead of dumping Symfony console tables into an alert box.
                $errors[] = it('installer.application_install_failed_trace', ['trace' => $traceId]);
            } else {
                @unlink(__FILE__);
                installationSuccessResponse($installDemo);
            }
        } catch (PDOException $e) {
            // Driver messages may echo host/user details; map the vendor code to a safe, actionable hint.
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($driverCode === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $codeMatch) === 1) {
                $driverCode = (int) $codeMatch[1];
            }
            $mappedError = match ($driverCode) {
                1045, 1698 => it('installer.db_access_denied_details', ['user' => $dbUser, 'host' => $dbHost]),
                1049 => it('installer.db_unknown_database'),
                1044, 1142 => (($installStage ?? '') === 'database_create' ? it('installer.db_create_not_allowed') : it('installer.db_insufficient_privileges')),
                2002, 2003, 2005, 2006 => it('installer.db_unreachable_details', ['host' => $dbHost, 'port' => (string) $dbPort]),
                default => '',
            };
            $traceId = logInstallerFailure($projectDir, $installStage ?? 'database', $e, [$dbHost, $dbUser, $dbName, $dbPassword]);
            if ($mappedError !== '') {
                $errors[] = $mappedError . ' ' . it('installer.diagnostic_code', ['trace' => $traceId]);
            } else {
                $errors[] = it('installer.configuration_check_failed_trace', ['trace' => $traceId, 'stage' => installerStageLabel($installStage ?? 'database')]);
            }
        } catch (RuntimeException $e) {
            // Requirement failures above are translated, actionable and contain no secrets.
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $traceId = logInstallerFailure($projectDir, $installStage ?? 'configuration', $e, [$dbHost, $dbUser, $dbName, $dbPassword]);
            $errors[] = it('installer.configuration_check_failed_trace', ['trace' => $traceId, 'stage' => installerStageLabel($installStage ?? 'configuration')]);
        }
    }
}

function databaseNumericVersion(string $version): string
{
    if (preg_match('/(\d+\.\d+(?:\.\d+)?)-MariaDB/i', $version, $match)) {
        return $match[1];
    }
    if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $match)) {
        return $match[1];
    }
    return '0.0.0';
}

function databaseLooksInstalled(string $projectDir): bool
{
    $path = $projectDir . '/.env.local';
    if (!is_file($path) || !extension_loaded('pdo_mysql')) {
        return false;
    }
    $content = (string) @file_get_contents($path);
    if (!preg_match('/^DATABASE_URL=(?:"((?:\\\\.|[^"])*)"|([^\r\n]+))$/m', $content, $match)) {
        return false;
    }
    $raw = ($match[1] ?? '') !== '' ? stripcslashes((string) $match[1]) : trim((string) ($match[2] ?? ''));
    $parts = parse_url($raw);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'mysql' || empty($parts['host']) || empty($parts['path'])) {
        return false;
    }
    try {
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 3306);
        $db = ltrim((string) $parts['path'], '/');
        $user = rawurldecode((string) ($parts['user'] ?? ''));
        $pass = rawurldecode((string) ($parts['pass'] ?? ''));
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db), $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mc_installation'");
        if ((int) $stmt->fetchColumn() !== 1) {
            return false;
        }
        return (int) $pdo->query('SELECT COUNT(*) FROM mc_installation WHERE id = 1')->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

function detectPublicUrl(): string
{
    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    return $forwarded === 'https';
}

function isLocalHost(string $host): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function envQuote(string $value): string
{
    return '"' . str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', '\\n'], $value) . '"';
}

function atomicWrite(string $path, string $contents, int $mode): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException(it('installer.ne_vdalosya_stvoryty_kataloh_dlya_konfihuratsiyi'));
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        throw new RuntimeException(it('installer.ne_vdalosya_zapysaty_tymchasovyy_fayl_konfihuratsiyi'));
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException(it('installer.ne_vdalosya_atomarno_zberehty_konfihuratsiyu'));
    }
    @chmod($path, $mode);
}

function cleanupStaleInstallRequests(string $installDir): void
{
    if (!is_dir($installDir)) {
        return;
    }
    foreach (glob($installDir . '/request-*.json') ?: [] as $path) {
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < time() - 1800) {
            $size=@filesize($path);
            if(is_int($size)&&$size>0&&$size<=1024*1024){@file_put_contents($path,str_repeat("\0",$size),LOCK_EX);}
            @unlink($path);
        }
    }
}

function sanitizeInstallError(string $message): string
{
    return mb_substr(preg_replace('/[\r\n]+/', ' ', $message) ?? it('installer.nevidoma_pomylka'), 0, 800, 'UTF-8');
}

/** @return array{passed:bool,message:string} */
function writableDirectoryProbe(string $path): array
{
    $target = is_dir($path) ? $path : dirname($path);
    if (!is_dir($target) || !is_writable($target)) {
        return ['passed' => false, 'message' => it('installer.write_probe_failed')];
    }
    $base = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.nexora-write-' . bin2hex(random_bytes(6));
    $renamed = $base . '.ok';
    try {
        if (file_put_contents($base, 'probe', LOCK_EX) !== 5) {
            return ['passed' => false, 'message' => it('installer.write_probe_failed')];
        }
        if (!@rename($base, $renamed) || !is_file($renamed)) {
            @unlink($base);
            return ['passed' => false, 'message' => it('installer.rename_probe_failed')];
        }
        if (!@unlink($renamed) || is_file($renamed)) {
            return ['passed' => false, 'message' => it('installer.delete_probe_failed')];
        }
        return ['passed' => true, 'message' => it('installer.write_probe_ok')];
    } catch (Throwable) {
        @unlink($base);
        @unlink($renamed);
        return ['passed' => false, 'message' => it('installer.write_probe_failed')];
    }
}

/** @return array{passed:bool,message:string} */
function phpTempProbe(string $projectDir): array
{
    $configured = trim((string) ini_get('upload_tmp_dir'));
    $tmpDir = $configured !== '' ? $configured : sys_get_temp_dir();
    $probe = writableDirectoryProbe($tmpDir);
    if ($probe['passed']) {
        return ['passed' => true, 'message' => $tmpDir . ' · ' . $probe['message']];
    }

    $fallback = $projectDir . '/var/tmp';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0700, true);
    }
    $fallbackProbe = writableDirectoryProbe($fallback);
    if ($fallbackProbe['passed']) {
        return [
            'passed' => false,
            'message' => it('installer.php_tmp_system_unavailable_fallback', [
                'current' => $tmpDir,
                'fallback' => $fallback,
            ]),
        ];
    }

    return [
        'passed' => false,
        'message' => it('installer.php_tmp_unavailable', ['current' => $tmpDir]),
    ];
}

/** @return array{passed:bool,message:string,console_passed:bool,console_message:string} */
function phpCliProbe(string $projectDir): array
{
    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) {
        return [
            'passed' => false,
            'message' => it('installer.cli_exec_unavailable'),
            'console_passed' => false,
            'console_message' => it('installer.cli_exec_unavailable'),
        ];
    }

    $majorMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $compact = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    $candidateNames = ['php' . $majorMinor, 'php' . $compact, 'php-' . $majorMinor, 'php'];
    $candidates = [];

    foreach ($candidateNames as $name) {
        $out = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        if ($code === 0 && isset($out[0])) {
            $candidates[] = trim((string) $out[0]);
        }
    }

    foreach ([
        '/usr/bin/php' . $majorMinor,
        '/usr/local/bin/php' . $majorMinor,
        '/opt/php' . $majorMinor . '/bin/php',
        '/opt/php/' . $majorMinor . '/bin/php',
        '/usr/bin/php',
        '/usr/local/bin/php',
    ] as $path) {
        $candidates[] = $path;
    }

    $candidates = array_values(array_unique(array_filter($candidates, static fn (string $path): bool => $path !== '' && is_file($path) && is_executable($path))));
    if ($candidates === []) {
        return [
            'passed' => false,
            'message' => it('installer.cli_not_found'),
            'console_passed' => false,
            'console_message' => it('installer.console_not_working'),
        ];
    }

    $best = null;
    $firstDetected = null;
    foreach ($candidates as $binary) {
        $output = [];
        $code = 1;
        @exec(escapeshellarg($binary) . ' -r ' . escapeshellarg('echo PHP_VERSION;'), $output, $code);
        $version = trim(implode('', $output));
        if ($code !== 0 || $version === '') {
            continue;
        }
        $firstDetected ??= ['binary' => $binary, 'version' => $version];
        if (version_compare($version, MC_REQUIRED_PHP, '>=') && version_compare($version, MC_MAX_PHP, '<')) {
            $best = ['binary' => $binary, 'version' => $version];
            break;
        }
    }

    $selected = $best ?? $firstDetected;
    if ($selected === null) {
        return [
            'passed' => false,
            'message' => it('installer.cli_not_found'),
            'console_passed' => false,
            'console_message' => it('installer.console_not_working'),
        ];
    }

    $binary = $selected['binary'];
    $version = $selected['version'];
    $phpOk = $best !== null;
    $message = $phpOk
        ? $version . ' · ' . $binary
        : it('installer.cli_version_mismatch', ['web' => PHP_VERSION, 'cli' => $version, 'binary' => $binary]);

    $consoleOutput = [];
    $consoleCode = 1;
    if ($phpOk && is_file($projectDir . '/bin/console')) {
        @exec(escapeshellarg($binary) . ' -l ' . escapeshellarg($projectDir . '/bin/console') . ' 2>&1', $consoleOutput, $consoleCode);
    }
    $consolePassed = $phpOk && $consoleCode === 0;

    return [
        'passed' => $phpOk,
        'message' => $message,
        'console_passed' => $consolePassed,
        'console_message' => $consolePassed
            ? it('installer.console_syntax_ok')
            : ($phpOk ? it('installer.console_not_working') : it('installer.console_cli_mismatch')),
    ];
}

/** @return array{passed:bool,message:string} */
function symlinkProbe(string $path): array
{
    if (!function_exists('symlink')) {
        return ['passed' => false, 'message' => it('installer.symlink_unavailable')];
    }
    $dir = is_dir($path) ? $path : dirname($path);
    $source = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.nexora-symlink-source-' . bin2hex(random_bytes(5));
    $link = $source . '-link';
    try {
        if (file_put_contents($source, 'probe', LOCK_EX) === false) {
            return ['passed' => false, 'message' => it('installer.symlink_probe_failed')];
        }
        $ok = @symlink($source, $link) && is_link($link);
        @unlink($link);
        @unlink($source);
        return ['passed' => $ok, 'message' => $ok ? it('installer.symlink_ok') : it('installer.symlink_probe_failed')];
    } catch (Throwable) {
        @unlink($link);
        @unlink($source);
        return ['passed' => false, 'message' => it('installer.symlink_probe_failed')];
    }
}

/** @return array{passed:bool,message:string} */
function outboundHttpsProbe(string $url): array
{
    if (!extension_loaded('curl')) {
        return ['passed' => false, 'message' => it('installer.curl_required_for_probe')];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['passed' => false, 'message' => it('installer.outbound_failed')];
    }
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'Nexora-Installer/1.0',
    ]);
    $result = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $ok = $result !== false && $http >= 200 && $http < 500;
    return ['passed' => $ok, 'message' => $ok ? 'HTTPS ' . $http . ' · github.com' : ($error !== '' ? $error : 'HTTP ' . $http)];
}

function probeDatabasePrivileges(PDO $pdo): void
{
    $suffix = bin2hex(random_bytes(5));
    $parent = 'mc_install_probe_parent_' . $suffix;
    $child = 'mc_install_probe_child_' . $suffix;
    try {
        $pdo->exec("CREATE TABLE `{$parent}` (`id` INT NOT NULL PRIMARY KEY, `value` VARCHAR(32) NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE `{$child}` (`id` INT NOT NULL PRIMARY KEY, `parent_id` INT NOT NULL) ENGINE=InnoDB");
        $pdo->exec("ALTER TABLE `{$parent}` ADD COLUMN `probe_flag` TINYINT NOT NULL DEFAULT 0");
        $pdo->exec("CREATE INDEX `idx_parent_id` ON `{$child}` (`parent_id`)");
        $pdo->exec("ALTER TABLE `{$child}` ADD CONSTRAINT `fk_{$suffix}` FOREIGN KEY (`parent_id`) REFERENCES `{$parent}` (`id`) ON DELETE CASCADE");
        $pdo->exec("INSERT INTO `{$parent}` (`id`,`value`) VALUES (1,'ok')");
        $pdo->exec("INSERT INTO `{$child}` (`id`,`parent_id`) VALUES (1,1)");
        if ((string) $pdo->query("SELECT `value` FROM `{$parent}` WHERE `id`=1")->fetchColumn() !== 'ok') {
            throw new RuntimeException(it('installer.db_privileges_failed'));
        }
        $pdo->exec("UPDATE `{$parent}` SET `value`='updated' WHERE `id`=1");
        $pdo->exec("DELETE FROM `{$child}` WHERE `id`=1");
    } catch (Throwable $e) {
        throw new RuntimeException(it('installer.db_privileges_failed'), 0, $e);
    } finally {
        try { $pdo->exec("DROP TABLE IF EXISTS `{$child}`"); } catch (Throwable) {}
        try { $pdo->exec("DROP TABLE IF EXISTS `{$parent}`"); } catch (Throwable) {}
    }
}

function installerStageLabel(string $stage): string
{
    $key = match ($stage) {
        'database_connection' => 'installer.stage_database_connection',
        'database_selection' => 'installer.stage_database_selection',
        'database_requirements' => 'installer.stage_database_requirements',
        'database_privileges' => 'installer.stage_database_privileges',
        'database_create' => 'installer.stage_database_create',
        'database_charset' => 'installer.stage_database_charset',
        'configuration_write' => 'installer.stage_configuration_write',
        'installation_request' => 'installer.stage_installation_request',
        'configuration' => 'installer.stage_configuration',
        default => 'installer.stage_database',
    };
    return it($key);
}

function logInstallerFailure(string $projectDir, string $stage, Throwable $error, array $secrets = []): string
{
    $traceId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    $logDir = $projectDir . '/var/log';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }

    $redact = static function (string $value) use ($secrets): string {
        foreach ($secrets as $secret) {
            $secret = (string) $secret;
            if ($secret !== '') {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }
        return preg_replace('/[\r\n]+/', ' ', $value) ?? 'Installer error';
    };

    $lines = [
        '[' . gmdate('c') . '] trace=' . $traceId . ' stage=' . $stage,
        'exception=' . get_class($error),
        'code=' . (string) $error->getCode(),
        'message=' . $redact($error->getMessage()),
        'location=' . $redact($error->getFile()) . ':' . $error->getLine(),
    ];
    foreach ($error->getTrace() as $index => $frame) {
        if ($index >= 12) {
            break;
        }
        $file = isset($frame['file']) ? $redact((string) $frame['file']) : '[internal]';
        $line = isset($frame['line']) ? (int) $frame['line'] : 0;
        $call = (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . (string) ($frame['function'] ?? '');
        $lines[] = sprintf('trace#%d=%s:%d %s', $index, $file, $line, $call);
    }
    $lines[] = '';
    @file_put_contents($logDir . '/installer.log', implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
    @chmod($logDir . '/installer.log', 0600);
    return $traceId;
}

function runApplicationInstall(string $projectDir, array $payload, array $secrets = []): array
{
    $traceId = logInstallerEvent($projectDir, 'application_install_start', 'Browser installer started application installation.', $secrets);
    $outputText = '';
    $kernel = null;
    try {
        $autoload = $projectDir . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Composer autoload file is missing.');
        }
        require_once $autoload;

        if (class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
            (new \Symfony\Component\Dotenv\Dotenv())->usePutenv()->bootEnv($projectDir . '/.env');
        }

        $environment = (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'prod');
        $kernel = new \Commerce\Kernel($environment, false);
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $application->setAutoExit(false);
        // The password travels in the environment, never on the command line: a failed command is logged with
        // its full input, and the web-server error log must not contain credentials.
        putenv('NEXORA_INSTALL_ADMIN_PASSWORD=' . (string) ($payload['admin_password'] ?? ''));
        $arguments = [
            'command' => 'commerce:install',
            '--store-name' => (string) ($payload['store_name'] ?? ''),
            '--admin-name' => (string) ($payload['admin_name'] ?? ''),
            '--admin-email' => (string) ($payload['admin_email'] ?? ''),
            '--public-url' => (string) ($payload['public_url'] ?? ''),
            '--site-mode' => (string) ($payload['site_mode'] ?? 'hybrid'),
            '--country' => (string) ($payload['country'] ?? 'UA'),
            '--currency' => (string) ($payload['currency'] ?? ''),
            '--locale' => (string) ($payload['locale'] ?? ''),
            '--timezone' => (string) ($payload['timezone'] ?? ''),
            '--no-interaction' => true,
        ];
        if ((bool) ($payload['install_demo'] ?? false)) {
            $arguments['--demo'] = true;
        }

        $input = new \Symfony\Component\Console\Input\ArrayInput($arguments);
        $input->setInteractive(false);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = $application->run($input, $output);
        $outputText = $output->fetch();
        logInstallerEvent($projectDir, 'application_install_result', 'exit_code=' . $code . ' output=' . $outputText, $secrets, $traceId);

        return ['code' => $code, 'output' => $outputText, 'trace' => $traceId];
    } catch (Throwable $error) {
        $failureTrace = logInstallerFailure($projectDir, 'application_install', $error, $secrets);
        return ['code' => 1, 'output' => $outputText !== '' ? $outputText : $error->getMessage(), 'trace' => $failureTrace];
    } finally {
        if ($kernel instanceof \Symfony\Component\HttpKernel\KernelInterface) {
            try { $kernel->shutdown(); } catch (Throwable) {}
        }
    }
}

function logInstallerEvent(string $projectDir, string $stage, string $message, array $secrets = [], ?string $traceId = null): string
{
    $traceId ??= gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    $logDir = $projectDir . '/var/log';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    foreach ($secrets as $secret) {
        $secret = (string) $secret;
        if ($secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }
    $message = preg_replace('/\x1B(?:[@-_][0-?]*[ -\/]*[@-~]|\[[0-?]*[ -\/]*[@-~])/', '', $message) ?? $message;
    $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message) ?? $message;
    $entry = '[' . gmdate('c') . '] trace=' . $traceId . ' stage=' . $stage . "\n" . trim($message) . "\n\n";
    @file_put_contents($logDir . '/installer.log', $entry, FILE_APPEND | LOCK_EX);
    @chmod($logDir . '/installer.log', 0600);
    return $traceId;
}

function logInstallerTextFailure(string $projectDir, string $stage, string $message, array $secrets = []): string
{
    return logInstallerEvent($projectDir, $stage, $message !== '' ? $message : 'Installation failed without console output.', $secrets);
}

function installerOutputSummary(string $output, array $secrets = []): string
{
    foreach ($secrets as $secret) {
        $secret = (string) $secret;
        if ($secret !== '') {
            $output = str_replace($secret, '[redacted]', $output);
        }
    }
    $output = preg_replace('/\x1B(?:[@-_][0-?]*[ -\/]*[@-~]|\[[0-?]*[ -\/]*[@-~])/', '', $output) ?? $output;
    $output = strip_tags($output);
    $output = preg_replace('/\s+/', ' ', trim($output)) ?? '';
    if ($output === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($output, 0, 700, 'UTF-8');
    }
    return substr($output, 0, 700);
}

function installerIcon(string $name): string
{
    static $icons = null;
    if ($icons === null) {
        $file = dirname(__DIR__) . '/resources/icons/lucide.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $icons = is_array($data) && is_array($data['icons'] ?? null) ? $data['icons'] : [];
    }
    $body = isset($icons[$name]) ? (string) $icons[$name] : '';

    return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

function installationSuccessResponse(bool $demoInstalled): never
{
    http_response_code(200);
    $version = '';
    $versionFile = dirname(__DIR__) . '/src/Core/Platform/PlatformVersion.php';
    if (is_file($versionFile) && preg_match("/VERSION\s*=\s*'([^']+)'/", (string) file_get_contents($versionFile), $m) === 1) {
        $version = $m[1];
    }
    $title = e(it('installer.install_success_title'));
    $features = [
        ['layout-grid', it('installer.feat_builder'), it('installer.feat_builder_text')],
        ['languages', it('installer.feat_languages'), it('installer.feat_languages_text')],
        ['credit-card', it('installer.feat_payments'), it('installer.feat_payments_text')],
        ['search', it('installer.feat_seo'), it('installer.feat_seo_text')],
        ['gift', it('installer.feat_marketing'), it('installer.feat_marketing_text')],
        ['shield-check', it('installer.feat_security'), it('installer.feat_security_text')],
        ['zap', it('installer.feat_speed'), it('installer.feat_speed_text')],
        ['users', it('installer.feat_business'), it('installer.feat_business_text')],
    ];
    $steps = [
        [it('installer.step1_title'), it('installer.step1_text')],
        [it('installer.step2_title'), it('installer.step2_text')],
        [it('installer.step3_title'), it('installer.step3_text')],
    ];
    $why = [it('installer.why1'), it('installer.why2'), it('installer.why3')];
    $css = <<<'CSS'
*{box-sizing:border-box}body{margin:0;background:#f4f6fb;color:#172033;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.5}
.wrap{max-width:1120px;margin:0 auto;padding:32px 20px 56px}
.hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr);gap:36px;align-items:center;padding:36px;background:linear-gradient(135deg,#fff 0%,#eef4ff 100%);border:1px solid #dfe5ef;border-radius:24px;box-shadow:0 24px 60px rgba(29,43,76,.10)}
.badge{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:#e7f8ee;color:#12663a;font-weight:700;font-size:14px}
h1{margin:14px 0 10px;font-size:clamp(28px,4vw,42px);line-height:1.12;letter-spacing:-.02em}
.lead{margin:0 0 22px;color:#44506a;font-size:18px}
.actions{display:flex;flex-wrap:wrap;gap:10px}.btn{display:inline-flex;align-items:center;padding:13px 20px;border-radius:12px;background:#165dff;color:#fff;text-decoration:none;font-weight:700;box-shadow:0 8px 20px rgba(22,93,255,.28)}.btn:hover{background:#0f4ad6}.btn.alt{background:#fff;color:#172033;border:1px solid #cfd8e6;box-shadow:none}.btn.alt:hover{background:#f1f5fb}
.note{margin:18px 0 0;color:#1e4f8f;background:#eff6ff;border-left:3px solid #3b82f6;padding:10px 12px;border-radius:8px;font-size:14px}
.shot{position:relative}.frame{border:1px solid #cfd8e6;border-radius:14px;background:#fff;box-shadow:0 20px 50px rgba(29,43,76,.18);overflow:hidden}
.bar{display:flex;align-items:center;gap:6px;padding:9px 12px;background:#eef2f8;border-bottom:1px solid #dfe5ef}.bar i{width:10px;height:10px;border-radius:50%;background:#c7d0df}.bar span{margin-left:10px;flex:1;max-width:260px;padding:2px 10px;border-radius:6px;background:#fff;color:#7a869c;font-size:12px}
.frame img{display:block;width:100%;height:auto}
.tabs input{position:absolute;opacity:0;pointer-events:none}.tabs label{display:inline-block;margin:0 6px 12px 0;padding:7px 14px;border-radius:999px;background:#e8edf6;color:#44506a;font-weight:700;font-size:14px;cursor:pointer}
#t1:checked~.labels label[for=t1],#t2:checked~.labels label[for=t2]{background:#172033;color:#fff}.pane{display:none}#t1:checked~.panes .p1,#t2:checked~.panes .p2{display:block}
.tabs input:focus-visible~.labels label{outline:2px solid #165dff;outline-offset:2px}
h2{margin:44px 0 18px;font-size:26px;letter-spacing:-.01em}
.steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;counter-reset:s}
.step{position:relative;padding:20px 20px 20px 64px;background:#fff;border:1px solid #dfe5ef;border-radius:16px}.step:before{counter-increment:s;content:counter(s);position:absolute;left:18px;top:18px;display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:#165dff;color:#fff;font-weight:800}
.step strong{display:block;margin-bottom:4px}.step p{margin:0;color:#44506a;font-size:14px}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.card{padding:20px;background:#fff;border:1px solid #dfe5ef;border-radius:16px;transition:transform .15s,box-shadow .15s}.card:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(29,43,76,.10)}
.ico{display:grid;place-items:center;width:44px;height:44px;margin-bottom:12px;border-radius:12px;background:#e8f0ff;color:#165dff}.card strong{display:block;margin-bottom:4px}.card p{margin:0;color:#44506a;font-size:14px}
.why{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:24px;background:#172033;color:#fff;border-radius:20px}.why div{display:flex;gap:12px;align-items:flex-start}.why svg{flex:none;color:#7fb0ff}.why p{margin:0;color:#d4dcec;font-size:15px}
.foot{margin-top:28px;text-align:center;color:#7a869c;font-size:13px}
@media(max-width:900px){.hero{grid-template-columns:1fr;padding:24px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.steps,.why{grid-template-columns:1fr}}
@media(max-width:520px){.grid{grid-template-columns:1fr}.btn{width:100%;justify-content:center}}
CSS;
    $out = '<!doctype html><html lang="' . e((string) ($GLOBALS['installerLocale'] ?? 'uk-UA')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $title . '</title><style>' . $css . '</style></head><body><div class="wrap">';
    $out .= '<section class="hero"><div><span class="badge">' . installerIcon('circle-check') . e(it('installer.install_success_badge')) . ($version !== '' ? ' · v' . e($version) : '') . '</span>';
    $out .= '<h1>' . $title . '</h1><p class="lead">' . e(it('installer.install_success_lead')) . '</p>';
    $out .= '<div class="actions"><a class="btn" href="/admin/login">' . e(it('installer.open_admin')) . '</a><a class="btn alt" href="/">' . e(it('installer.open_store')) . '</a></div>';
    $out .= ($demoInstalled ? '<p class="note">' . e(it('installer.install_success_demo')) . '</p>' : '') . '</div>';
    $out .= '<div class="shot tabs"><input type="radio" name="t" id="t1" checked><input type="radio" name="t" id="t2"><div class="labels"><label for="t1">' . e(it('installer.tab_store')) . '</label><label for="t2">' . e(it('installer.tab_admin')) . '</label></div><div class="panes">';
    $out .= '<div class="pane p1"><div class="frame"><div class="bar"><i></i><i></i><i></i><span>' . e(it('installer.tab_store')) . '</span></div><img src="/assets/branding/welcome-store.webp" alt="" width="1200" height="750"></div></div>';
    $out .= '<div class="pane p2"><div class="frame"><div class="bar"><i></i><i></i><i></i><span>/admin</span></div><img src="/assets/branding/welcome-admin.webp" alt="" width="1200" height="750"></div></div></div></div></section>';
    $out .= '<h2>' . e(it('installer.next_title')) . '</h2><div class="steps">';
    foreach ($steps as [$st, $sx]) {
        $out .= '<div class="step"><strong>' . e($st) . '</strong><p>' . e($sx) . '</p></div>';
    }
    $out .= '</div><h2>' . e(it('installer.features_title')) . '</h2><div class="grid">';
    foreach ($features as [$icon, $name, $text]) {
        $out .= '<div class="card"><span class="ico">' . installerIcon($icon) . '</span><strong>' . e($name) . '</strong><p>' . e($text) . '</p></div>';
    }
    $out .= '</div><h2>' . e(it('installer.why_title')) . '</h2><div class="why">';
    foreach ($why as $line) {
        $out .= '<div>' . installerIcon('circle-check') . '<p>' . e($line) . '</p></div>';
    }
    $out .= '</div><p class="foot">Nexora Commerce' . ($version !== '' ? ' v' . e($version) : '') . '</p></div></body></html>';
    echo $out;
    exit;
}

function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '-1') {
        return -1;
    }
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    return match ($unit) {
        'g' => (int) ($number * 1024 ** 3),
        'm' => (int) ($number * 1024 ** 2),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function formatBytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;        $index++;
    }
    return number_format($bytes, $index === 0 ? 0 : 1, '.', '') . ' ' . $units[$index];
}

function installedResponse(): never
{
    // An existing installation: unpacking a full release over it and opening setup.php leads to the upgrade page.
    if (is_file(__DIR__ . '/upgrade.php')) {
        header('Location: /upgrade.php', true, 302);
        exit;
    }
    http_response_code(410);
    echo it('installer.doctype_html_meta_charset_utf_8_meta_name_robots_content');
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!doctype html>
<html lang="<?= e($installerLocale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e(it('installer.page_title')) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/branding/favicon-32.png"><link rel="apple-touch-icon" href="assets/branding/favicon-180.png">
<link rel="shortcut icon" href="favicon.ico">
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f5f7fb}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1320px;margin:32px auto;padding:0 24px}.card{background:#fff;border:1px solid #dfe5ef;border-radius:18px;box-shadow:0 18px 50px rgba(29,43,76,.08);padding:28px;margin-bottom:20px}h1{margin:0 0 8px;font-size:30px}h2{font-size:19px;margin:0 0 18px}.muted{color:#667085;margin:0}.setup-brand{display:flex;align-items:center;gap:14px}.setup-brand img{width:68px;height:68px;flex:0 0 68px}.setup-brand h1{margin:0 0 3px}.setup-brand__copy{min-width:0}.setup-head{display:flex;align-items:center;justify-content:space-between;gap:18px}.language-switch{display:flex;flex-direction:row;align-items:center;gap:6px;width:auto;padding:5px;border:1px solid #e1e6ef;border-radius:12px;background:#f8fafc}.language-switch a{display:inline-flex;align-items:center;gap:8px;min-height:38px;padding:8px 11px;border:1px solid transparent;border-radius:8px;color:#344054;text-decoration:none;font-size:14px;white-space:nowrap;line-height:1.25}.language-switch__label{display:block;margin:0;font-size:13px;font-weight:600;color:#667085;white-space:nowrap}.language-switch select{width:auto;min-width:190px;padding:8px 12px}.language-switch a:hover{background:#fff;border-color:#d7dfeb}.language-switch a.active{border-color:#a9c6ff;background:#eef4ff;color:#165dff;font-weight:700}.lang-flag{display:block;width:28px;height:18px;border-radius:3px;box-shadow:0 0 0 1px rgba(15,23,42,.16);flex:0 0 28px;overflow:hidden}.lang-label{display:block;line-height:1.3;padding:1px 0 2px}.checks{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.check{position:relative;padding:10px 12px 10px 38px;border:1px solid #e4e7ec;border-radius:10px;font-size:14px}.check:before{position:absolute;left:12px;top:10px;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}.ok:before{content:"✓";background:#16a34a;color:#fff}.bad:before{content:"×";background:#dc2626;color:#fff}.warn:before{content:"!";background:#d97706;color:#fff}.ok{border-color:#b7e4c7;background:#f0fff4}.bad{border-color:#f4b9b9;background:#fff5f5}.warn{border-color:#f1d88c;background:#fff9e8}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.full{grid-column:1/-1}.setup-group{grid-column:1/-1;padding:16px;border:1px solid #e4e7ec;border-radius:14px;background:#fbfcfe}.setup-group h3{margin:0 0 4px;font-size:16px}.setup-group p{margin:0;color:#667085;font-size:13px}.password-wrap{position:relative}.password-wrap input{padding-right:48px}.password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:34px;height:34px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#344054}.password-toggle svg{width:18px;height:18px}.password-toggle .eye-off{display:none}.password-toggle[aria-pressed="true"] .eye{display:none}.password-toggle[aria-pressed="true"] .eye-off{display:block}.field-hint{margin:7px 0 0;color:#667085;font-size:12px}.info-note{margin:10px 0 0;padding:9px 11px;border-left:3px solid #3b82f6;background:#eff6ff;color:#1e4f8f;border-radius:7px;font-size:12px}.info-note[hidden]{display:none}.password-toggle:hover{background:#f2f6ff;border-color:#9bb9ff}label{display:block;font-size:13px;font-weight:650;margin-bottom:6px}input,select{width:100%;padding:12px 13px;border:1px solid #cfd6e4;border-radius:10px;font:inherit;background:#fff}input:focus,select:focus{outline:2px solid #1f6feb33;border-color:#1f6feb}.button{display:inline-flex;align-items:center;gap:10px;border:0;border-radius:10px;padding:13px 18px;background:#165dff;color:#fff;font-weight:700;cursor:pointer}.button:disabled{opacity:.45;cursor:not-allowed}.errors-close{float:right;margin:-6px -6px 0 12px;width:30px;height:30px;border:0;border-radius:8px;background:transparent;color:#8a1c1c;font-size:22px;line-height:1;cursor:pointer}.errors-close:hover{background:#fbdcdc}.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:setup-spin .8s linear infinite}.button.is-busy .spinner{display:inline-block}.button.is-busy{opacity:1;cursor:progress}.install-progress{margin:12px 0 0;padding:10px 12px;border-left:3px solid #3b82f6;background:#eff6ff;color:#1e4f8f;border-radius:7px;font-size:13px}@keyframes setup-spin{to{transform:rotate(360deg)}}.errors{background:#fff1f1;border:1px solid #f0b6b6;padding:14px;border-radius:10px;margin:0;color:#8a1c1c}.error-card{border-color:#f0b6b6;box-shadow:0 8px 28px rgba(180,35,24,.10)}.checkbox{display:flex;align-items:center;gap:8px}.checkbox input{width:auto}code{background:#f2f4f7;padding:2px 5px;border-radius:5px}@media(max-width:1100px){.checks{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:820px){.checks{grid-template-columns:repeat(2,minmax(0,1fr))}.setup-head{align-items:flex-start;flex-direction:column}.language-switch{width:auto}}@media(max-width:600px){.grid{grid-template-columns:1fr}.checks{grid-template-columns:1fr}.wrap{margin:16px auto;padding:0 12px}.card{padding:18px}.language-switch{width:100%;display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.language-switch a{justify-content:center;padding:8px 6px}.lang-flag{width:24px;height:16px;flex-basis:24px}.lang-label{overflow:hidden;text-overflow:ellipsis}}
.setup-status{margin:0 0 14px;padding:12px 14px;border-radius:10px;font-size:14px;font-weight:650}.setup-status.ready{color:#166534;background:#f0fff4;border:1px solid #b7e4c7}.setup-status.blocked{color:#9f1d1d;background:#fff5f5;border:1px solid #f4b9b9}.setup-help{margin:0 0 14px;padding:12px 14px;border-radius:10px;color:#344054;background:#eff6ff;border:1px solid #bfdbfe;font-size:14px;line-height:1.5}.checks-issues{grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:16px}.setup-details{border-top:1px solid #e4e7ec;padding-top:14px}.setup-details summary{color:#165dff;font-weight:650;cursor:pointer}.setup-details[open] .checks{margin-top:14px}@media(max-width:600px){.checks-issues{grid-template-columns:1fr}}
</style>
</head>
<body><main class="wrap">
<section class="card"><div class="setup-head"><div class="setup-brand"><img src="assets/branding/nexora-mark.png" alt="Nexora Commerce" width="68" height="68"><div class="setup-brand__copy"><h1>Nexora Commerce</h1></div></div><form class="language-switch" method="get" aria-label="Language"><label class="language-switch__label" for="setup_lang"><?= e(it('installer.interface_language')) ?></label><select id="setup_lang" name="lang"><?php foreach ($installerLanguages as $code => $label): ?><option value="<?= e($code) ?>" lang="<?= e(substr($code, 0, 2)) ?>"<?= $installerLocale === $code ? ' selected' : '' ?>><?= e(str_starts_with($label, 'installer.') ? it($label) : $label) ?></option><?php endforeach; ?></select><noscript><button class="button" type="submit">OK</button></noscript></form>
</div></section>
<?php if ($errors !== []): ?><section class="card error-card" id="install-errors"><div class="errors" role="alert" aria-live="assertive"><button type="button" class="errors-close" data-close-errors aria-label="<?= e(it('installer.close')) ?>" title="<?= e(it('installer.close')) ?>">&times;</button><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div></section><?php endif; ?>
<section class="card"><h2><?= e(it('installer.server_check')) ?></h2>
<p class="setup-status <?= $runtimeReady ? 'ready' : 'blocked' ?>"><?= e(it($runtimeReady ? 'installer.checks_ready' : 'installer.checks_blocked')) ?></p>
<?php if (!$vendorReady || !$assetsReady): ?><p class="setup-help"><?= e(it('installer.source_vendor_notice')) ?></p><?php endif; ?>
<?php $failedChecks = array_values(array_filter($checks, static fn (array $check): bool => !$check['passed'])); ?>
<?php if ($failedChecks !== []): ?><div class="checks checks-issues">
<?php foreach ($failedChecks as $check): ?>
<div class="check <?= $check['required'] ? 'bad' : 'warn' ?>"><strong><?= e((string) $check['label']) ?></strong><br><?= e((string) $check['current']) ?></div>
<?php endforeach; ?>
</div><?php endif; ?>
<details class="setup-details"><summary><?= e(it('installer.all_checks')) ?></summary><div class="checks">
<?php foreach ($checks as $check): ?>
<div class="check <?= $check['passed'] ? 'ok' : ($check['required'] ? 'bad' : 'warn') ?>"><strong><?= e((string) $check['label']) ?></strong><br><?= e((string) $check['current']) ?></div>
<?php endforeach; ?>
</div></details></section>
<section class="card"><h2><?= e(it('installer.store_install')) ?></h2>
<form method="post" autocomplete="off"><input type="hidden" name="_lang" value="<?= e($installerLocale) ?>"><input type="hidden" name="_csrf" value="<?= e((string) $_SESSION['mc_setup_csrf']) ?>"><div class="grid">
<div class="setup-group"><h3><?= e(it('installer.step_database')) ?></h3><p><?= e(it('installer.database_hint')) ?></p></div>
<div><label><?= e(it('installer.db_host')) ?></label><input name="db_host" value="<?= e((string) $values['db_host']) ?>" required></div>
<div><label><?= e(it('installer.db_port')) ?></label><input name="db_port" inputmode="numeric" value="<?= e((string) $values['db_port']) ?>" required></div>
<div><label><?= e(it('installer.db_name')) ?></label><input name="db_name" value="<?= e((string) $values['db_name']) ?>" required></div>
<div><label><?= e(it('installer.db_user')) ?></label><input name="db_user" value="<?= e((string) $values['db_user']) ?>" required></div>
<div class="full"><label><?= e(it('installer.db_password')) ?></label><div class="password-wrap"><input id="db_password" type="password" name="db_password" autocomplete="new-password"><button class="password-toggle" type="button" data-toggle-password="db_password" title="<?= e(it('installer.password_toggle')) ?>" aria-label="<?= e(it('installer.password_toggle')) ?>" aria-pressed="false"><svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.6A2 2 0 0013.4 13.4"/><path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.6 17.6 0 01-2.1 3.1M6.6 6.6C3.7 8.5 2 12 2 12s3.5 8 10 8a10.7 10.7 0 005-1.2"/></svg></button></div></div>
<div class="full checkbox"><input id="create_database" type="checkbox" name="create_database" value="1"><label for="create_database" style="margin:0"><?= e(it('installer.create_database')) ?></label></div>
<div class="setup-group"><h3><?= e(it('installer.step_store')) ?></h3><p><?= e(it('installer.store_hint')) ?></p></div>
<div><label><?= e(it('installer.store_name')) ?></label><input name="store_name" value="<?= e((string) $values['store_name']) ?>" required></div>
<div><label><?= e(it('installer.store_url')) ?></label><input name="public_url" value="<?= e((string) $values['public_url']) ?>" required></div>
<div class="full"><label><?= e(it('installer.site_profile')) ?></label><select name="site_mode"><option value="shop" <?= $values['site_mode'] === 'shop' ? 'selected' : '' ?>><?= e(it('installer.mode_shop')) ?></option><option value="catalog" <?= $values['site_mode'] === 'catalog' ? 'selected' : '' ?>><?= e(it('installer.mode_catalog')) ?></option><option value="content" <?= $values['site_mode'] === 'content' ? 'selected' : '' ?>><?= e(it('installer.mode_content')) ?></option><option value="landing" <?= $values['site_mode'] === 'landing' ? 'selected' : '' ?>><?= e(it('installer.mode_landing')) ?></option><option value="forum" <?= $values['site_mode'] === 'forum' ? 'selected' : '' ?>><?= e(it('installer.mode_forum')) ?></option><option value="hybrid" <?= $values['site_mode'] === 'hybrid' ? 'selected' : '' ?>><?= e(it('installer.mode_hybrid')) ?></option></select><p class="muted" style="margin:7px 0 0"><?= e(it('installer.profile_hint')) ?></p></div>
<div class="setup-group"><h3><?= e(it('installer.step_region')) ?></h3><p><?= e(it('installer.region_hint')) ?></p></div>
<div><label><?= e(it('installer.country')) ?></label><select name="country" id="setup_country"><?php foreach (\Commerce\Core\Install\RegionCatalog::countryCodes() as $cc): $pr = \Commerce\Core\Install\RegionCatalog::preset($cc); ?><option value="<?= e($cc) ?>" data-currency="<?= e($pr['currency']) ?>" data-timezone="<?= e($pr['timezone']) ?>" data-locale="<?= e($pr['locale']) ?>" <?= $values['country'] === $cc ? 'selected' : '' ?>><?= e($cc === 'OTHER' ? it('installer.country_other') : \Commerce\Core\Install\RegionCatalog::countryName($cc, substr($installerLocale, 0, 2)) . ' · ' . $cc) ?></option><?php endforeach; ?></select></div>
<div><label><?= e(it('installer.currency')) ?></label><select name="currency" id="setup_currency"><?php foreach (\Commerce\Core\Install\RegionCatalog::currencies() as $code => $row): ?><option value="<?= e($code) ?>" <?= $values['currency'] === $code ? 'selected' : '' ?>><?= e($code . ' · ' . $row['name']) ?></option><?php endforeach; ?></select></div>
<div><label><?= e(it('installer.store_language')) ?></label><select name="store_locale" id="setup_locale"><?php foreach (\Commerce\Core\Install\RegionCatalog::BUNDLED_LOCALES as $lc): ?><option value="<?= e($lc) ?>" <?= $values['store_locale'] === $lc ? 'selected' : '' ?>><?= e(\Commerce\Core\Install\RegionCatalog::localeName($lc, $lc) . ' · ' . $lc) ?></option><?php endforeach; ?></select></div>
<div><label><?= e(it('installer.timezone')) ?></label><select name="timezone" id="setup_timezone"><?php $tzFound = false; foreach (\DateTimeZone::listIdentifiers() as $tz): if ($tz === $values['timezone']) { $tzFound = true; } ?><option value="<?= e($tz) ?>" <?= $values['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select></div>
<p class="field-hint full"><?= e(it('installer.region_change_later')) ?></p>
<div class="setup-group"><h3><?= e(it('installer.step_admin')) ?></h3><p><?= e(it('installer.admin_hint')) ?></p></div>
<div><label><?= e(it('installer.admin_name')) ?></label><input name="admin_name" value="<?= e((string) $values['admin_name']) ?>" required></div>
<div><label><?= e(it('installer.admin_email')) ?></label><input type="email" name="admin_email" value="<?= e((string) $values['admin_email']) ?>" required></div>
<div class="full"><label><?= e(it('installer.admin_password')) ?></label><div class="password-wrap"><input id="admin_password" type="password" name="admin_password" minlength="12" maxlength="128" autocomplete="new-password" required><button class="password-toggle" type="button" data-toggle-password="admin_password" title="<?= e(it('installer.password_toggle')) ?>" aria-label="<?= e(it('installer.password_toggle')) ?>" aria-pressed="false"><svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.6A2 2 0 0013.4 13.4"/><path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.6 17.6 0 01-2.1 3.1M6.6 6.6C3.7 8.5 2 12 2 12s3.5 8 10 8a10.7 10.7 0 005-1.2"/></svg></button></div><p class="field-hint"><?= e(it('installer.admin_password_hint')) ?></p></div>
<div class="setup-group"><h3><?= e(it('installer.step_demo')) ?></h3><p><?= e(it('installer.demo_hint')) ?></p></div>
<div class="full"><div class="checkbox"><input id="install_demo" type="checkbox" name="install_demo" value="1" checked><label for="install_demo" style="margin:0"><?= e(it('installer.demo_checkbox')) ?></label></div><p id="demo-enabled-note" class="info-note"><?= e(it('installer.demo_enabled_note')) ?></p></div>
<div class="full"><button class="button" data-install-button <?= $runtimeReady ? '' : 'disabled' ?>><span class="spinner" aria-hidden="true"></span><span data-install-label data-idle="<?= e(it('installer.install_button')) ?>" data-busy="<?= e(it('installer.installing')) ?>"><?= e(it('installer.install_button')) ?></span></button><p class="install-progress" data-install-progress role="status" aria-live="polite" hidden><?= e(it('installer.installing_hint')) ?></p></div>
</div></form></section>
</main><script>
(function(){var c=document.getElementById('setup_country');if(!c)return;c.addEventListener('change',function(){var o=c.options[c.selectedIndex];[['setup_currency','currency'],['setup_timezone','timezone'],['setup_locale','locale']].forEach(function(p){var el=document.getElementById(p[0]);var v=o.getAttribute('data-'+p[1]);if(el&&v){el.value=v;}});});})();
document.querySelectorAll('[data-toggle-password]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.getAttribute('data-toggle-password'));if(!input)return;var reveal=input.type==='password';input.type=reveal?'text':'password';button.setAttribute('aria-pressed',reveal?'true':'false');});});
var demoBox=document.getElementById('install_demo');var demoNote=document.getElementById('demo-enabled-note');function syncDemoNote(){if(!demoBox||!demoNote)return;demoNote.hidden=!demoBox.checked;}if(demoBox){demoBox.addEventListener('change',syncDemoNote);syncDemoNote();}
document.querySelectorAll('[data-close-errors]').forEach(function(b){b.addEventListener('click',function(){var card=b.closest('.error-card');if(card)card.hidden=true;});});
var form=document.querySelector('[data-install-button]');form=form&&form.form;if(form){form.addEventListener('submit',function(){var b=form.querySelector('[data-install-button]');var l=form.querySelector('[data-install-label]');var p=form.querySelector('[data-install-progress]');if(b){b.disabled=true;b.classList.add('is-busy');}if(l)l.textContent=l.getAttribute('data-busy');if(p)p.hidden=false;});}
window.addEventListener('pageshow',function(e){if(e.persisted){var b=document.querySelector('[data-install-button]');if(b){b.disabled=false;b.classList.remove('is-busy');}}});
var installerError=document.getElementById('install-errors');if(installerError){requestAnimationFrame(function(){installerError.scrollIntoView({block:'center',behavior:'instant'});});}
</script></body></html>
