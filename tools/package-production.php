<?php

declare(strict_types=1);

/*
 * Builds the production archive Nexora_Commerce_v<version>_PRODUCTION.zip without rsync, zip or sha256sum,
 * so it runs on Windows as well as on Linux. It follows tools/package-production.sh: the same release checks,
 * the same exclusions, the same var/ layout, release manifest, packaged-release verification and SHA256SUMS.txt.
 * Differences: vendor/ is installed without dev packages in the stage copy (the working tree is not touched),
 * public/build is rebuilt from the current sources first, and the stage is checked for leaked secrets.
 *
 * Usage:
 *   php tools/package-production.php [--out=<dir>] [--composer=<composer or composer.phar>] [--skip-build] [--skip-checks]
 *
 * --out          where the zip and its .sha256 file go (default: build/)
 * --composer     composer to use (default: COMPOSER_BIN, then composer on PATH, then C:/laragon/bin/composer/composer.phar)
 * --skip-build   do not run "npm run build:production" (public/build must already be current)
 * --skip-checks  do not run the bin/*-check scripts (the packaged-release verification and the leak check always run)
 * --allow-failing comma-separated bin/ scripts whose failure is reported but does not stop the build
 *                (for example bin/i18n-critical-journey-check.php); every check still runs and is listed
 */

$root = dirname(__DIR__);
chdir($root);

$options = getopt('', ['out:', 'composer:', 'skip-build', 'skip-checks', 'allow-failing:']);
$allowFailing = array_values(array_filter(array_map('trim', explode(',', (string) ($options['allow-failing'] ?? '')))));
$outDir = rtrim(str_replace('\\', '/', (string) ($options['out'] ?? $root . '/build')), '/');
$php = PHP_BINARY;

require $root . '/src/Core/Platform/PlatformVersion.php';
$version = Commerce\Core\Platform\PlatformVersion::VERSION;
$stage = $root . '/build/production-' . $version;
$zipName = 'Nexora_Commerce_v' . $version . '_PRODUCTION.zip';
$zipPath = $outDir . '/' . $zipName;

function step(string $text): void
{
    fwrite(STDOUT, '==> ' . $text . "\n");
}

function fail(string $text, int $code): never
{
    fwrite(STDERR, 'ERROR: ' . $text . "\n");
    exit($code);
}

/** @param list<string> $command */
function run(array $command, ?string $cwd = null, bool $quiet = false): void
{
    $code = runCode($command, $cwd, $quiet);
    if ($code !== 0) {
        fail('command failed (' . $code . '): ' . implode(' ', $command), 41);
    }
}

/** @param list<string> $command */
function runCode(array $command, ?string $cwd = null, bool $quiet = false): int
{
    $line = implode(' ', array_map(static fn (string $part): string => preg_match('/^[A-Za-z0-9_\/:.=\-]+$/', $part) === 1 ? $part : escapeshellarg($part), $command));
    $spec = [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $quiet ? ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'] : STDOUT, 2 => STDERR];
    $process = proc_open($command, $spec, $pipes, $cwd);
    if (!is_resource($process)) {
        fail('cannot start: ' . $line, 40);
    }
    return proc_close($process);
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @chmod($path, 0666);
        unlink($path);
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
            continue;
        }
        @chmod($item->getPathname(), 0666);
        unlink($item->getPathname());
    }
    rmdir($path);
}

/** Path relative to the project root, with forward slashes. */
function excluded(string $relative, bool $isDir): bool
{
    $top = explode('/', $relative, 2)[0];
    if (in_array($top, ['.git', '.build-tools', 'node_modules', 'build', 'tests', '.github', '.devcontainer', '.claude', '.agents', '.codex', '.autopilot', 'vendor'], true)) {
        return true; // vendor/ is copied separately and reinstalled without dev packages
    }
    if (in_array($relative, ['phpunit.xml.dist', 'pw.local.config.ts', 'playwright.local.config.ts', 'SHA256SUMS.txt', 'release-manifest.json', 'docs/CHANGE_NECESSITY.md', 'docs/CODECART_STANDARD.md', 'docs/NEXORA_STANDARD.md', 'docs/TEAM_PROCESS.md'], true)) {
        return true; // SHA256SUMS.txt and release-manifest.json are written fresh for the package
    }
    if (!str_contains($relative, '/') && str_starts_with($relative, '.env') && $relative !== '.env.example') {
        return true;
    }
    if ($top === 'var' && $relative !== 'var') {
        return true;
    }
    if (str_starts_with($relative, 'public/media/')) {
        if ($relative === 'public/media/.gitkeep') {
            return false;
        }
        if ($relative === 'public/media/demo/dummyjson' || str_starts_with($relative, 'public/media/demo/dummyjson/')) {
            return true;
        }
        return !($relative === 'public/media/demo' || str_starts_with($relative, 'public/media/demo/'));
    }
    return false;
}

function copyTree(string $from, string $to, ?callable $skip = null): int
{
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $item) use ($from, $skip): bool {
                if ($skip === null) {
                    return true;
                }
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
                return !$skip($relative, $item->isDir());
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $target = $to . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0775, true)) {
                fail('cannot create ' . $target, 42);
            }
            continue;
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        if (!copy($item->getPathname(), $target)) {
            fail('cannot copy ' . $item->getPathname(), 43);
        }
        ++$count;
    }
    return $count;
}

/** @return list<string> relative paths of every file under $dir, sorted */
function listFiles(string $dir): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $item) {
        if ($item->isFile()) {
            $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

function composerCommand(?string $given): array
{
    $candidates = array_filter([$given, getenv('COMPOSER_BIN') ?: null]);
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            fail('composer not found at ' . $candidate, 44);
        }
        return str_ends_with(strtolower($candidate), '.phar') ? [PHP_BINARY, $candidate] : [$candidate];
    }
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if (is_file($dir . '/composer.phar')) {
            return [PHP_BINARY, $dir . '/composer.phar'];
        }
        if (PHP_OS_FAMILY !== 'Windows' && is_file($dir . '/composer') && is_executable($dir . '/composer')) {
            return [$dir . '/composer'];
        }
    }
    if (is_file('C:/laragon/bin/composer/composer.phar')) {
        return [PHP_BINARY, 'C:/laragon/bin/composer/composer.phar'];
    }
    fail('composer not found; pass --composer=<path to composer.phar>', 44);
}

/** Values of secret-looking variables from the local env files; only the names are ever printed. */
function localSecrets(string $root): array
{
    $secrets = [];
    // Values that are also in the shipped .env.example are public defaults (for example redis://127.0.0.1:6379).
    $public = is_file($root . '/.env.example') ? (string) file_get_contents($root . '/.env.example') : '';
    foreach (['.env', '.env.local', '.env.prod.local'] as $file) {
        if (!is_file($root . '/' . $file)) {
            continue;
        }
        foreach (file($root . '/' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $value = trim(trim($m[2]), "\"'");
            if (preg_match('/SECRET|KEY|PASSWORD|PASS|TOKEN|DSN|DATABASE_URL|SALT|PRIVATE/', $m[1]) === 1 && strlen($value) >= 12 && !str_contains($public, $value)) {
                $secrets[$m[1]] = $value;
            }
        }
    }
    return $secrets;
}

/** @return list<string> problems found in the stage */
function leakCheck(string $stage, string $root): array
{
    $problems = [];
    foreach (['.git', '.claude', '.agents', '.codex', '.autopilot', 'tests', 'node_modules', '.github', 'build'] as $dir) {
        if (file_exists($stage . '/' . $dir)) {
            $problems[] = $dir . '/ is in the package';
        }
    }
    $secrets = localSecrets($root);
    foreach (listFiles($stage) as $relative) {
        $name = basename($relative);
        $inVendor = str_starts_with($relative, 'vendor/');
        if (str_starts_with($name, '.env') && $name !== '.env.example' && !$inVendor) {
            $problems[] = 'env file ' . $relative;
        }
        if (str_starts_with($relative, 'var/') && $name !== '.gitkeep') {
            $problems[] = 'runtime data ' . $relative;
        }
        if (!$inVendor && preg_match('/\.(pem|key|p12|pfx|jks|keystore)$|^id_(rsa|ed25519|ecdsa)/i', $name) === 1) {
            $problems[] = 'key file ' . $relative;
        }
        if (!$inVendor && preg_match('#(^|/)(auth\.json|\.npmrc|\.htpasswd)$#', $relative) === 1) {
            $problems[] = 'credentials file ' . $relative;
        }
        $path = $stage . '/' . $relative;
        if ($secrets !== [] && filesize($path) <= 4 * 1024 * 1024) {
            $content = (string) file_get_contents($path);
            foreach ($secrets as $variable => $value) {
                if (str_contains($content, $value)) {
                    $problems[] = 'value of ' . $variable . ' from the local env file found in ' . $relative;
                }
            }
        }
        if (!$inVendor && preg_match('/\.(php|json|ya?ml|txt|md|js|ts|twig|env|example|ini|conf|xml)$/i', $name) === 1 && filesize($path) <= 4 * 1024 * 1024) {
            $content = $content ?? (string) file_get_contents($path);
            if (preg_match('#-----BEGIN (RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----\s*[A-Za-z0-9+/=]{40}#', $content) === 1) {
                $problems[] = 'private key block in ' . $relative;
            }
            if (preg_match('/\b(sk-ant-[A-Za-z0-9_\-]{20,}|sk-(proj-)?[A-Za-z0-9]{32,}|AIza[0-9A-Za-z_\-]{35}|ghp_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{40,}|sk_live_[A-Za-z0-9]{20,}|xox[bp]-[A-Za-z0-9\-]{20,})\b/', $content) === 1) {
                $problems[] = 'API key pattern in ' . $relative;
            }
        }
        unset($content);
    }
    return $problems;
}

step('Nexora Commerce ' . $version . ' -> ' . $zipPath);
if (!class_exists(ZipArchive::class)) {
    fail('PHP zip extension is required.', 21);
}
if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fail('cannot create output directory ' . $outDir, 22);
}

if (!isset($options['skip-checks'])) {
    step('release checks');
    $failed = [];
    foreach ([
        ['bin/dependency-integrity-check.php'], ['bin/release-check.php'], ['bin/release-contract-check.php', '--production'],
        ['bin/system-completeness-check.php'], ['bin/full-release-static-check.php'], ['bin/i18n-critical-journey-check.php'],
        ['bin/i18n-runtime-hardcode-audit.php', '--strict'], ['bin/twig-syntax-check.php', 'themes/default/templates'],
        ['bin/admin-access-check.php'], ['bin/admin-confirm-check.php'], ['bin/commerce-analytics-check.php'], ['bin/license-audit.php'],
        ['bin/catalog-scale-audit.php'], ['bin/production-critical-check.php'], ['bin/security-audit.php'], ['bin/security-regression-check.php'],
        ['bin/commerce-operations-check.php'], ['bin/commerce-ux-check.php'], ['bin/backup-feed-seo-check.php'], ['bin/demo-showcase-check.php'],
    ] as $check) {
        $code = runCode(array_merge([$php], $check), $root, true);
        fwrite(STDOUT, '  ' . ($code === 0 ? 'ok    ' : 'FAILED') . ' ' . implode(' ', $check) . "\n");
        if ($code !== 0) {
            $failed[] = $check[0];
        }
    }
    $blocking = array_diff($failed, $allowFailing);
    if ($blocking !== []) {
        fail('release checks failed: ' . implode(', ', $blocking) . ' (run each one for details; --allow-failing=<check> to continue)', 41);
    }
    if ($failed !== []) {
        fwrite(STDOUT, '  continuing with allowed failures: ' . implode(', ', $failed) . "\n");
    }
}

if (!isset($options['skip-build'])) {
    // The steps of "npm run build:production", called through node directly (npm.cmd is unreliable from PHP on Windows).
    step('build:production (vite build, asset budgets, precompression)');
    if (!is_file($root . '/node_modules/vite/bin/vite.js')) {
        fail('node_modules is missing; run npm ci first.', 24);
    }
    run(['node', 'node_modules/vite/bin/vite.js', 'build'], $root, true);
    run(['node', 'tools/check-asset-budgets.mjs'], $root);
    run(['node', 'tools/precompress-assets.mjs', 'public/build'], $root, true);
}

step('stage ' . $stage);
removeTree($stage);
mkdir($stage, 0775, true);
$copied = copyTree($root, $stage, 'excluded');
fwrite(STDOUT, '  ' . $copied . " project files\n");

step('vendor without dev packages');
if (!is_file($root . '/vendor/autoload.php')) {
    fail('vendor/ is missing in the working tree; run composer install first.', 30);
}
copyTree($root . '/vendor', $stage . '/vendor');
// The stage copy is reduced to the production packages from composer.lock; the authoritative class map is built
// from the files that are actually in the package (a stale map lacks every class added since).
run(array_merge(composerCommand($options['composer'] ?? null), ['install', '--no-dev', '--no-scripts', '--no-interaction', '--no-progress', '--quiet', '--prefer-dist', '--optimize-autoloader', '--classmap-authoritative']), $stage, true);
if (is_dir($stage . '/vendor/phpunit')) {
    fail('vendor/ of the package still contains dev packages.', 20);
}

step('var/ layout');
foreach (['cache', 'log', 'install', 'extensions', 'feeds', 'recovery', 'sessions'] as $dir) {
    if (!is_dir($stage . '/var/' . $dir)) {
        mkdir($stage . '/var/' . $dir, 0775, true);
    }
    file_put_contents($stage . '/var/' . $dir . '/.gitkeep', '');
}
file_put_contents($stage . '/var/.gitkeep', '');
if (!is_dir($stage . '/public/media')) {
    mkdir($stage . '/public/media', 0775, true);
}

if (!is_file($stage . '/vendor/autoload_runtime.php')) fail('vendor missing from production stage.', 30);
if (!is_file($stage . '/public/build/.vite/manifest.json')) fail('Vite manifest missing from production stage.', 31);
if (is_dir($stage . '/node_modules')) fail('node_modules leaked into production stage.', 32);
if (file_exists($stage . '/.env')) fail('.env leaked into production stage.', 33);
if (file_exists($stage . '/.env.local')) fail('.env.local leaked into production stage.', 34);
if (file_exists($stage . '/var/install/installed.lock')) fail('installed.lock leaked into package (installer would refuse to run).', 35);
foreach (listFiles($stage . '/var') as $file) {
    if (basename($file) !== '.gitkeep') fail('runtime data leaked into var/: ' . $file, 36);
}

step('release manifest and verification');
run([$php, 'tools/create-release-manifest.php', $stage], $root);
run([$php, 'tools/verify-packaged-release.php', $stage], $root);

step('leak check');
$problems = leakCheck($stage, $root);
if ($problems !== []) {
    fwrite(STDERR, "LEAK CHECK: FAILED\n- " . implode("\n- ", array_slice($problems, 0, 50)) . "\n");
    exit(37);
}
fwrite(STDOUT, "LEAK CHECK: PASSED\n");

step('SHA256SUMS.txt');
$sums = '';
foreach (listFiles($stage) as $file) {
    if ($file !== 'SHA256SUMS.txt') {
        $sums .= hash_file('sha256', $stage . '/' . $file) . ' *./' . $file . "\n";
    }
}
file_put_contents($stage . '/SHA256SUMS.txt', $sums);

step('zip');
$tmpZip = $zipPath . '.tmp';
@unlink($tmpZip);
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail('cannot create ' . $tmpZip, 50);
}
$dirs = [];
foreach (listFiles($stage) as $file) {
    for ($dir = dirname($file); $dir !== '.' && !isset($dirs[$dir]); $dir = dirname($dir)) {
        $dirs[$dir] = true;
    }
    $zip->addFile($stage . '/' . $file, $file);
}
foreach (array_keys($dirs) as $dir) {
    $zip->addEmptyDir($dir);
}
if (!$zip->close()) {
    fail('cannot write ' . $tmpZip, 51);
}
@unlink($zipPath);
rename($tmpZip, $zipPath);
$hash = hash_file('sha256', $zipPath);
file_put_contents($zipPath . '.sha256', $hash . '  ' . $zipName . "\n");

fwrite(STDOUT, $hash . '  ' . $zipPath . "\n");
fwrite(STDOUT, 'size=' . filesize($zipPath) . "\n");
