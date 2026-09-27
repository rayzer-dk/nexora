#!/usr/bin/env php
<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$forbidden=['eval','shell_exec','exec','passthru','proc_open','popen','assert','create_function','gzinflate','str_rot13'];
$violations=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $raw=(string)file_get_contents($file->getPathname());
    foreach ($forbidden as $needle) { if (preg_match('/(?<!->)(?<!::)\b' . preg_quote($needle,'/') . '\s*\(/i', $raw)) $violations[]=$file->getPathname() . ' -> ' . $needle . '()'; }
    if (preg_match('/\$_(?:GET|POST|REQUEST)\s*[^;\n]*\b(?:SELECT|INSERT|UPDATE|DELETE)\b/i',$raw)) $violations[]=$file->getPathname() . ' -> direct request/sql pattern';
}
if ($violations) { fwrite(STDERR,"Security static gate failed:\n" . implode("\n",$violations) . "\n"); exit(1); }
echo "Security static gate passed.\n";
