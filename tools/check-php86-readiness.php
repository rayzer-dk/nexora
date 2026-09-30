<?php

declare(strict_types=1);

/**
 * Static readiness scan for PHP 8.6: reports use of constructs deprecated by the
 * "Deprecations for PHP 8.6" RFC. It does not certify the platform (that needs a real 8.6 run);
 * it only proves the code base does not depend on what is being deprecated.
 */

$root = dirname(__DIR__);
$functions = array_flip(['is_double', 'is_integer', 'is_long', 'doubleval', 'spl_object_hash', 'spl_classes', 'metaphone', 'strcoll', 'mysqli_stmt_init', 'mysqli_get_charset', 'session_set_save_handler', 'deflate_init', 'inflate_init', 'mb_convert_variables']);
$constants = array_flip(['SORT_LOCALE_STRING']);
$findings = [];

$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static fn (SplFileInfo $f): bool => !in_array($f->getFilename(), ['vendor', 'node_modules', 'var', '.git', 'build'], true),
));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $tokens = token_get_all((string) file_get_contents($file->getPathname()));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        $next = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $next = $tokens[$j];
            break;
        }
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            $prev = $tokens[$j];
            break;
        }
        $isMemberAccess = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
        $name = strtolower($t[1]);
        if ($t[0] === T_LIST && $next === '(' && !$isMemberAccess) {
            $findings[] = [$file->getPathname(), $t[2], 'list() construct'];
        } elseif (in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && isset($functions[ltrim($name, '\\')]) && $next === '(' && !$isMemberAccess) {
            $findings[] = [$file->getPathname(), $t[2], $name . '()'];
        } elseif ($t[0] === T_STRING && isset($constants[$t[1]])) {
            $findings[] = [$file->getPathname(), $t[2], $t[1]];
        }
    }
}

foreach ($findings as [$path, $line, $what]) {
    fwrite(STDOUT, str_replace($root . '/', '', $path) . ':' . $line . ' ' . $what . PHP_EOL);
}
fwrite(STDOUT, sprintf('php86-readiness: %d finding(s)' . PHP_EOL, count($findings)));
exit($findings === [] ? 0 : 1);
