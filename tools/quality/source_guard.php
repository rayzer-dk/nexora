<?php
declare(strict_types=1);

// Detect corruption with PHP tokenization. Only leading BOM is safely auto-repaired.
$fix = in_array('--fix-safe', $argv, true);
$paths = array_values(array_filter(array_slice($argv, 1), static fn ($x) => !in_array($x, ['--check', '--fix-safe'], true)));
if (!$paths) { fwrite(STDERR, "Usage: source_guard.php [--check|--fix-safe] paths...\n"); exit(2); }
$extensions = ['php', 'js', 'jsx', 'ts', 'tsx', 'twig', 'css', 'scss', 'xml', 'json', 'yml', 'yaml', 'html'];
$ignore = ['.git', 'vendor', 'node_modules', 'cache', 'dist', 'build'];
$files = [];
foreach ($paths as $path) {
    if (is_file($path)) { $files[$path] = true; continue; }
    if (!is_dir($path)) { fwrite(STDERR, "Missing path: $path\n"); exit(2); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $item) {
        if (!$item->isFile()) { continue; }
        $file = $item->getPathname();
        if (array_intersect(preg_split('~[/\\\\]+~', $file), $ignore)) { continue; }
        $files[$file] = true;
    }
}
ksort($files);
$errors = []; $count = 0; $fixed = 0;
$record = static function ($file, $source, $offset, $message) use (&$errors) {
    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
    $errors[] = "$file:$line: $message";
};
foreach (array_keys($files) as $file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) { continue; }
    $source = file_get_contents($file);
    if ($source === false) { $errors[] = "$file: cannot read"; continue; }
    ++$count;
    if (substr($source, 0, 3) === "\xEF\xBB\xBF") {
        if (!$fix) { $record($file, $source, 0, 'leading UTF-8 BOM'); }
        else {
            $tmp = tempnam(dirname($file), '.quality-');
            if ($tmp === false || file_put_contents($tmp, substr($source, 3)) === false) {
                $errors[] = "$file: BOM removal failed"; continue;
            }
            @chmod($tmp, fileperms($file) & 0777);
            if (!rename($tmp, $file)) { @unlink($tmp); $errors[] = "$file: BOM replacement failed"; continue; }
            $source = substr($source, 3); ++$fixed;
        }
    }
    if (preg_match('//u', $source) !== 1) { $errors[] = "$file: invalid UTF-8"; continue; }
    if (preg_match_all('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', $source, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $m) { $record($file, $source, $m[1], 'invisible/bidirectional Unicode control'); }
    }
    if ($ext !== 'php') { continue; }
    $probe = '';
    foreach (token_get_all($source) as $token) {
        if (is_string($token)) { $probe .= $token; continue; }
        [$type, $value] = $token;
        $kind = token_name($type);
        if (in_array($kind, ['T_VARIABLE', 'T_STRING', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'], true)
            && preg_match('/[^\x00-\x7F]/u', $value)) {
            $offset = strpos($source, $value);
            $record($file, $source, $offset === false ? 0 : $offset, 'non-ASCII PHP identifier / confusable');
        }
        if (in_array($kind, ['T_COMMENT','T_DOC_COMMENT'], true)
            && preg_match('/rest of (?:the )?(?:code|file) (?:remains|unchanged)|remaining code omitted/i', $value)) {
            $offset = strpos($source, $value);
            $record($file, $source, $offset === false ? 0 : $offset, 'AI omitted-code placeholder');
        }
        $probe .= in_array($kind, ['T_COMMENT','T_DOC_COMMENT','T_INLINE_HTML','T_CONSTANT_ENCAPSED_STRING','T_ENCAPSED_AND_WHITESPACE'], true)
            ? preg_replace('/[^\r\n]/u', ' ', $value) : $value;
    }
    foreach ([
        '/[\x{00A0}\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}]/u' => 'typographic quote/dash or NBSP in executable PHP',
        '/&(?:lt|gt|amp);/i' => 'HTML-escaped PHP operator',
        '/^\h*\x60{3,}(?:php|js|html|xml)?\h*$/mi' => 'Markdown fence in executable PHP',
    ] as $pattern => $message) {
        if (preg_match_all($pattern, $probe, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $m) { $record($file, $probe, $m[1], $message); }
        }
    }
}
foreach ($errors as $err) { fwrite(STDERR, "SOURCE GUARD: $err\n"); }
echo "Source guard: checked $count, BOM fixed $fixed, errors " . count($errors) . "\n";
exit($errors ? 1 : 0);
