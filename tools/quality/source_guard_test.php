<?php
declare(strict_types=1);
$scanner = __DIR__ . '/source_guard.php';
$dir = sys_get_temp_dir() . '/guard-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$test = static function (string $code, int $expected, bool $fix = false) use ($dir, $scanner): void {
    $file = $dir . '/fixture.php';
    file_put_contents($file, $code);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scanner) . ($fix ? ' --fix-safe ' : ' --check ') . escapeshellarg($file) . ' 2>&1';
    $lines = [];
    exec($cmd, $lines, $actual);
    if ($actual !== $expected) { throw new RuntimeException("Expected $expected, got $actual:\n" . implode("\n", $lines)); }
    if ($fix && substr(file_get_contents($file), 0, 3) === "\xEF\xBB\xBF") { throw new RuntimeException('BOM remained'); }
};
try {
    $test('<?php $x = "Українська мова"; // важливий коментар' . "\n", 0);
    $test("<?php\n\$usеr = 1;\n", 1);
    $test("<?php\n\$a = 1; \u{202E}\n", 1);
    $test("<?php\nif (\$a &lt; 4) {}\n", 1);
    $test("<?php\n// ... rest of the code remains the same ...\n", 1);
    $test("\xEF\xBB\xBF<?php\n\$a = 1;\n", 1);
    $test("\xEF\xBB\xBF<?php\n\$a = 1;\n", 0, true);
    $test('<?php $description = "«Українська» — гарний текст";' . "\n", 0);
    $test('<?php $html = "&lt;strong&gt;";' . "\n", 0);
    $test("<?php\n\$x = 1;\n", 1); // U+00A0 in PHP expression
    $test("<?php\n\$x = 1;\n", 1); // U+202F in PHP expression
    $test("<?php\n\$x = 1;\u{00AD}\n", 1); // Soft hyphen
    $test("<?php\n\$x = 1;\u{200B}\n", 1); // ZWSP
    $test("<?php\n\$x = 1;\u{202E}\n", 1); // Bidi override
    $test("<?php\nif (\$x &amp;&amp; \$y) {}\n", 1); // HTML entity in executable PHP
    echo "Source guard fixtures: PASS\n";
} finally { @unlink($dir . '/fixture.php'); @rmdir($dir); }
