<?php

declare(strict_types=1);

const MC_REQUIRED_PHP = '8.4.0';
const MC_MAX_PHP = '8.6.0';
const MC_MIN_MEMORY_BYTES = 268435456; // 256 MiB
const MC_RECOMMENDED_DISK_BYTES = 1073741824; // 1 GiB
const MC_MIN_MYSQL = '8.4.0';
const MC_MIN_MARIADB = '10.11.0';

$projectDir = dirname(__DIR__);

$installerLocales = ['uk-UA', 'ru-RU', 'en-US'];
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
$installerCatalogPath = $projectDir . '/resources/translations/' . $installerLocale . '/installer.php';
$installerCatalog = is_file($installerCatalogPath) ? require $installerCatalogPath : [];
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
$addCheck('Composer vendor', $vendorReady ? it('installer.hotovo') : it('installer.vidsutniy'), $vendorReady);

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
$addCheck('bin/console', $cli['console_message'], $cli['console_passed'], false);

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
    'site_mode' => $_POST['site_mode'] ?? 'shop',
];

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
    $siteMode = trim((string) ($_POST['site_mode'] ?? 'shop'));

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
                'STORE_DEFAULT_COUNTRY' => 'UA',
                'STORE_DEFAULT_LOCALE' => $installerLocale,
                'STORE_DEFAULT_CURRENCY' => 'UAH',
                'STORE_DEFAULT_TIMEZONE' => 'Europe/Kyiv',
                'DATABASE_URL' => $databaseUrl,
                'DATABASE_SERVER_VERSION' => $doctrineServerVersion,
                'LOCK_DSN' => 'flock',
                'REDIS_DSN' => 'redis://127.0.0.1:6379',
                'VALKEY_DSN' => 'valkey://127.0.0.1:6379',
                'VALKEY_SESSION_DSN' => 'redis://127.0.0.1:6379',
                'MAILER_DSN' => 'null://null',
                'MAIL_FROM_ADDRESS' => 'no-reply@localhost',
                'MAIL_FROM_NAME' => $storeName,
                'TELEGRAM_NOTIFICATIONS_ENABLED' => '0',
                'TELEGRAM_BOT_TOKEN' => '',
                'TELEGRAM_DEFAULT_CHAT_ID' => '',
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
                'MONOBANK_ACQUIRING_ENABLED' => '0',
                'MONOBANK_ACQUIRING_API_BASE' => 'https://api.monobank.ua',
                'MONOBANK_ACQUIRING_TOKEN' => '',
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
                'locale' => $installerLocale,
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
        $arguments = [
            'command' => 'commerce:install',
            '--store-name' => (string) ($payload['store_name'] ?? ''),
            '--admin-name' => (string) ($payload['admin_name'] ?? ''),
            '--admin-email' => (string) ($payload['admin_email'] ?? ''),
            '--admin-password' => (string) ($payload['admin_password'] ?? ''),
            '--public-url' => (string) ($payload['public_url'] ?? ''),
            '--site-mode' => (string) ($payload['site_mode'] ?? 'shop'),
            '--locale' => (string) ($payload['locale'] ?? 'uk-UA'),
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

function installationSuccessResponse(bool $demoInstalled): never
{
    http_response_code(200);
    $title = e(it('installer.install_success_title'));
    $message = e(it('installer.install_success_message'));
    $demo = $demoInstalled ? '<p class="note">' . e(it('installer.install_success_demo')) . '</p>' : '';
    echo '<!doctype html><html lang="' . e((string) ($GLOBALS['installerLocale'] ?? 'uk-UA')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $title . '</title><style>body{margin:0;background:#f5f7fb;color:#172033;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.box{max-width:760px;margin:8vh auto;padding:28px;background:#fff;border:1px solid #dfe5ef;border-radius:18px;box-shadow:0 18px 50px rgba(29,43,76,.08)}h1{margin:0 0 12px}.ok{padding:14px;border:1px solid #b7e4c7;background:#f0fff4;border-radius:10px}.note{color:#1e4f8f;background:#eff6ff;border-left:3px solid #3b82f6;padding:10px 12px;border-radius:7px}.actions{display:flex;gap:10px;margin-top:20px}.btn{display:inline-block;padding:11px 16px;border-radius:9px;background:#165dff;color:#fff;text-decoration:none;font-weight:700}.btn.alt{background:#eef2f7;color:#172033}</style></head><body><main class="box"><h1>' . $title . '</h1><div class="ok">' . $message . '</div>' . $demo . '<div class="actions"><a class="btn" href="/admin/login">' . e(it('installer.open_admin')) . '</a><a class="btn alt" href="/">' . e(it('installer.open_store')) . '</a></div></main></body></html>';
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
<link rel="icon" type="image/svg+xml" href="assets/branding/nexora-mark.svg">
<link rel="shortcut icon" href="assets/branding/nexora-mark.svg">
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f5f7fb}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1320px;margin:32px auto;padding:0 24px}.card{background:#fff;border:1px solid #dfe5ef;border-radius:18px;box-shadow:0 18px 50px rgba(29,43,76,.08);padding:28px;margin-bottom:20px}h1{margin:0 0 8px;font-size:30px}h2{font-size:19px;margin:0 0 18px}.muted{color:#667085;margin:0}.setup-brand{display:flex;align-items:center;gap:14px}.setup-brand img{width:68px;height:68px;flex:0 0 68px}.setup-brand h1{margin:0 0 3px}.setup-brand__copy{min-width:0}.setup-head{display:flex;align-items:center;justify-content:space-between;gap:18px}.language-switch{display:flex;flex-direction:row;align-items:center;gap:6px;width:auto;padding:5px;border:1px solid #e1e6ef;border-radius:12px;background:#f8fafc}.language-switch a{display:inline-flex;align-items:center;gap:8px;min-height:38px;padding:8px 11px;border:1px solid transparent;border-radius:8px;color:#344054;text-decoration:none;font-size:14px;white-space:nowrap;line-height:1.25}.language-switch a:hover{background:#fff;border-color:#d7dfeb}.language-switch a.active{border-color:#a9c6ff;background:#eef4ff;color:#165dff;font-weight:700}.lang-flag{display:block;width:28px;height:18px;border-radius:3px;box-shadow:0 0 0 1px rgba(15,23,42,.16);flex:0 0 28px;overflow:hidden}.lang-label{display:block;line-height:1.3;padding:1px 0 2px}.checks{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.check{position:relative;padding:10px 12px 10px 38px;border:1px solid #e4e7ec;border-radius:10px;font-size:14px}.check:before{position:absolute;left:12px;top:10px;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}.ok:before{content:"✓";background:#16a34a;color:#fff}.bad:before{content:"×";background:#dc2626;color:#fff}.warn:before{content:"!";background:#d97706;color:#fff}.ok{border-color:#b7e4c7;background:#f0fff4}.bad{border-color:#f4b9b9;background:#fff5f5}.warn{border-color:#f1d88c;background:#fff9e8}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.full{grid-column:1/-1}.setup-group{grid-column:1/-1;padding:16px;border:1px solid #e4e7ec;border-radius:14px;background:#fbfcfe}.setup-group h3{margin:0 0 4px;font-size:16px}.setup-group p{margin:0;color:#667085;font-size:13px}.password-wrap{position:relative}.password-wrap input{padding-right:48px}.password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:34px;height:34px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#344054}.password-toggle svg{width:18px;height:18px}.password-toggle .eye-off{display:none}.password-toggle[aria-pressed="true"] .eye{display:none}.password-toggle[aria-pressed="true"] .eye-off{display:block}.field-hint{margin:7px 0 0;color:#667085;font-size:12px}.info-note{margin:10px 0 0;padding:9px 11px;border-left:3px solid #3b82f6;background:#eff6ff;color:#1e4f8f;border-radius:7px;font-size:12px}.info-note[hidden]{display:none}.password-toggle:hover{background:#f2f6ff;border-color:#9bb9ff}label{display:block;font-size:13px;font-weight:650;margin-bottom:6px}input,select{width:100%;padding:12px 13px;border:1px solid #cfd6e4;border-radius:10px;font:inherit;background:#fff}input:focus,select:focus{outline:2px solid #1f6feb33;border-color:#1f6feb}.button{display:inline-flex;border:0;border-radius:10px;padding:13px 18px;background:#165dff;color:#fff;font-weight:700;cursor:pointer}.button:disabled{opacity:.45;cursor:not-allowed}.errors{background:#fff1f1;border:1px solid #f0b6b6;padding:14px;border-radius:10px;margin:0;color:#8a1c1c}.error-card{border-color:#f0b6b6;box-shadow:0 8px 28px rgba(180,35,24,.10)}.checkbox{display:flex;align-items:center;gap:8px}.checkbox input{width:auto}code{background:#f2f4f7;padding:2px 5px;border-radius:5px}@media(max-width:1100px){.checks{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:820px){.checks{grid-template-columns:repeat(2,minmax(0,1fr))}.setup-head{align-items:flex-start;flex-direction:column}.language-switch{width:auto}}@media(max-width:600px){.grid{grid-template-columns:1fr}.checks{grid-template-columns:1fr}.wrap{margin:16px auto;padding:0 12px}.card{padding:18px}.language-switch{width:100%;display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.language-switch a{justify-content:center;padding:8px 6px}.lang-flag{width:24px;height:16px;flex-basis:24px}.lang-label{overflow:hidden;text-overflow:ellipsis}}
</style>
</head>
<body><main class="wrap">
<section class="card"><div class="setup-head"><div class="setup-brand"><img src="assets/branding/nexora-mark.svg" alt="Nexora Commerce" width="68" height="68"><div class="setup-brand__copy"><h1>Nexora Commerce</h1><p class="muted"><?= e(it('installer.intro')) ?></p></div></div><nav class="language-switch" aria-label="Language">
<a href="?lang=uk-UA" class="<?= $installerLocale === 'uk-UA' ? 'active' : '' ?>" hreflang="uk"><svg class="lang-flag" viewBox="0 0 22 15" preserveAspectRatio="none" aria-hidden="true"><rect width="22" height="7.5" fill="#0057b7"/><rect y="7.5" width="22" height="7.5" fill="#ffd700"/></svg><span class="lang-label"><?= h(it('installer.language_ukrainian')) ?></span></a>
<a href="?lang=ru-RU" class="<?= $installerLocale === 'ru-RU' ? 'active' : '' ?>" hreflang="ru"><svg class="lang-flag" viewBox="0 0 22 15" preserveAspectRatio="none" aria-hidden="true"><rect width="22" height="5" fill="#fff"/><rect y="5" width="22" height="5" fill="#1f5aa6"/><rect y="10" width="22" height="5" fill="#d52b1e"/></svg><span class="lang-label"><?= h(it('installer.language_russian')) ?></span></a>
<a href="?lang=en-US" class="<?= $installerLocale === 'en-US' ? 'active' : '' ?>" hreflang="en"><svg class="lang-flag" viewBox="0 0 60 30" preserveAspectRatio="none" aria-hidden="true"><clipPath id="ukFlagClip"><rect width="60" height="30" rx="1.5"/></clipPath><g clip-path="url(#ukFlagClip)"><rect width="60" height="30" fill="#012169"/><path d="M0 0L60 30M60 0L0 30" stroke="#fff" stroke-width="6"/><path d="M0 0L60 30M60 0L0 30" stroke="#C8102E" stroke-width="3.4"/><path d="M30 0V30M0 15H60" stroke="#fff" stroke-width="10"/><path d="M30 0V30M0 15H60" stroke="#C8102E" stroke-width="6"/></g></svg><span class="lang-label"><?= h(it('installer.language_english')) ?></span></a>
</nav></div></section>
<?php if ($errors !== []): ?><section class="card error-card" id="install-errors"><div class="errors" role="alert" aria-live="assertive"><?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div></section><?php endif; ?>
<section class="card"><h2><?= e(it('installer.server_check')) ?></h2><div class="checks">
<?php foreach ($checks as $check): ?>
<div class="check <?= $check['passed'] ? 'ok' : ($check['required'] ? 'bad' : 'warn') ?>"><strong><?= e((string) $check['label']) ?></strong><br><?= e((string) $check['current']) ?></div>
<?php endforeach; ?>
</div><?php if (!$vendorReady): ?><p class="muted" style="margin-top:14px"><?= e(it('installer.source_vendor_notice')) ?></p><?php endif; ?></section>
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
<div class="setup-group"><h3><?= e(it('installer.step_admin')) ?></h3><p><?= e(it('installer.admin_hint')) ?></p></div>
<div><label><?= e(it('installer.admin_name')) ?></label><input name="admin_name" value="<?= e((string) $values['admin_name']) ?>" required></div>
<div><label><?= e(it('installer.admin_email')) ?></label><input type="email" name="admin_email" value="<?= e((string) $values['admin_email']) ?>" required></div>
<div class="full"><label><?= e(it('installer.admin_password')) ?></label><div class="password-wrap"><input id="admin_password" type="password" name="admin_password" minlength="12" maxlength="128" autocomplete="new-password" required><button class="password-toggle" type="button" data-toggle-password="admin_password" title="<?= e(it('installer.password_toggle')) ?>" aria-label="<?= e(it('installer.password_toggle')) ?>" aria-pressed="false"><svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 10.6A2 2 0 0013.4 13.4"/><path d="M9.9 4.2A10.8 10.8 0 0112 4c6.5 0 10 8 10 8a17.6 17.6 0 01-2.1 3.1M6.6 6.6C3.7 8.5 2 12 2 12s3.5 8 10 8a10.7 10.7 0 005-1.2"/></svg></button></div><p class="field-hint"><?= e(it('installer.admin_password_hint')) ?></p></div>
<div class="setup-group"><h3><?= e(it('installer.step_demo')) ?></h3><p><?= e(it('installer.demo_hint')) ?></p></div>
<div class="full"><div class="checkbox"><input id="install_demo" type="checkbox" name="install_demo" value="1" checked><label for="install_demo" style="margin:0"><?= e(it('installer.demo_checkbox')) ?></label></div><p id="demo-enabled-note" class="info-note"><?= e(it('installer.demo_enabled_note')) ?></p></div>
<div class="full"><button class="button" <?= $runtimeReady ? '' : 'disabled' ?>><?= e(it('installer.install_button')) ?></button></div>
</div></form></section>
</main><script>
document.querySelectorAll('[data-toggle-password]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.getAttribute('data-toggle-password'));if(!input)return;var reveal=input.type==='password';input.type=reveal?'text':'password';button.setAttribute('aria-pressed',reveal?'true':'false');});});
var demoBox=document.getElementById('install_demo');var demoNote=document.getElementById('demo-enabled-note');function syncDemoNote(){if(!demoBox||!demoNote)return;demoNote.hidden=!demoBox.checked;}if(demoBox){demoBox.addEventListener('change',syncDemoNote);syncDemoNote();}
var installerError=document.getElementById('install-errors');if(installerError){requestAnimationFrame(function(){installerError.scrollIntoView({block:'center',behavior:'instant'});});}
</script></body></html>