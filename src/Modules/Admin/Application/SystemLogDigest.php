<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Application;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only view over var/log/*.log for the admin: the list of log files, the severity of a line and the "unique errors" grouping.
 *
 * The log files themselves are never modified. Grouping is done only on request (the "Unique errors" button) and for the
 * header counter, which is cached per file. The scan reads the file backwards in fixed chunks, so memory is bounded by
 * SCAN_BYTES, CHUNK_BYTES and MAX_GROUPS, and when there are too many groups it is the oldest ones that are dropped.
 */
final class SystemLogDigest
{
    public const SCAN_BYTES = 16_777_216;
    public const MAX_GROUPS = 2000;
    private const MAX_SPILLED = 10_000;
    public const COUNTER_TTL = 60;

    private const CHUNK_BYTES = 262_144;
    private const MAX_LINE_BYTES = 8192;
    private const MAX_MESSAGE_CHARS = 500;
    private const COUNTER_KEY = 'commerce.admin.log_unique.';
    private const PATH_SEGMENT = '[^\s"\'<>|:*?()\[\]{}\\\\\/]+';
    /**
     * An absolute path to a source file followed by " on line N", " line N" or ":N". The path must start where a path cannot continue
     * (lookbehind) and its segments cannot contain separators (possessive group), so a long run of slashes is matched once, in linear time.
     */
    private const FILE_LINE = '/(?<![^\s"\'<>|:*?()\[\]{}])((?:[A-Za-z]:)?[\\\\\/](?:' . self::PATH_SEGMENT . '[\\\\\/])*+' . self::PATH_SEGMENT . '\.(?:php|twig|phtml|inc))(?:\s+on\s+line\s+|\s+line\s+|:)(\d+)/u';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** @return array<string,array{path:string,size:int,modified:int}> newest file first */
    public function files(): array
    {
        clearstatcache(); // sizes and times of live log files must not come from a long-running worker's stat cache
        $out = [];
        foreach (glob($this->projectDir . '/var/log/*.log') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                $out[basename($path)] = ['path' => $path, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
            }
        }
        uasort($out, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $out;
    }

    /** 'error' | 'warning' | 'other' */
    public function severity(string $line): string
    {
        if (preg_match('/\[(critical|alert|emergency|error)\]|\.(CRITICAL|ALERT|EMERGENCY|ERROR):|\b(Fatal error|Uncaught)\b/i', $line) === 1) {
            return 'error';
        }
        if (preg_match('/\[warning\]|\.WARNING:|\bWarning:/i', $line) === 1) {
            return 'warning';
        }

        return 'other';
    }

    /**
     * Groups the entries of the file that match the level and the search text; the same filter as the plain journal.
     * Newest group first; every group keeps the first and the last time stamp of the log line and the number of repeats.
     *
     * @return array{groups:list<array{severity:string,type:string,message:string,file:string,line:int,count:int,first:string,last:string}>,entries:int,overflow:int,scanned:int,partial:bool}
     */
    public function group(string $path, string $level = 'all', string $search = ''): array
    {
        $result = ['groups' => [], 'entries' => 0, 'overflow' => 0, 'scanned' => 0, 'partial' => false];
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size === 0) {
            return $result;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $result;
        }
        $start = max(0, $size - self::SCAN_BYTES);
        $result['scanned'] = $size - $start;
        $result['partial'] = $start > 0;

        /** @var array<string,array{severity:string,type:string,message:string,file:string,line:int,count:int,first:string,last:string}> $groups */
        $groups = [];
        /** @var array<string,true> older groups that did not fit; only their number is reported */
        $spilled = [];
        $consume = function (string $raw) use (&$groups, &$spilled, &$result, $level, $search): void {
            // control characters (ESC, lone CR) are replaced: the text is shown and copied to the clipboard
            $line = rtrim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', mb_scrub(substr($raw, 0, self::MAX_LINE_BYTES), 'UTF-8')));
            if ($line === '' || $this->isContinuation($line)) {
                return;
            }
            $severity = $this->severity($line);
            if (($level === 'error' && $severity !== 'error') || ($level === 'warning' && $severity === 'other')) {
                return;
            }
            if ($search !== '' && mb_stripos($line, $search) === false) {
                return;
            }
            ++$result['entries'];
            $parsed = $this->parse($line);
            $key = $parsed['fingerprint'];
            if (isset($groups[$key])) {
                ++$groups[$key]['count'];
                if ($parsed['time'] !== '') {
                    $groups[$key]['first'] = $parsed['time'];
                }

                return;
            }
            if (count($groups) >= self::MAX_GROUPS) {
                if (count($spilled) < self::MAX_SPILLED) {
                    $spilled[$key] = true;
                }

                return;
            }
            $groups[$key] = [
                'severity' => $severity, 'type' => $parsed['type'], 'message' => $parsed['message'], 'file' => $parsed['file'],
                'line' => $parsed['line'], 'count' => 1, 'first' => $parsed['time'], 'last' => $parsed['time'],
            ];
        };

        $position = $size;
        $carry = '';
        while ($position > $start) {
            $read = min(self::CHUNK_BYTES, $position - $start);
            $position -= $read;
            fseek($handle, $position);
            $parts = explode("\n", (string) fread($handle, $read) . $carry);
            // The beginning of the chunk may be the end of a line cut by the chunk boundary. Only the first MAX_LINE_BYTES of a line
            // are ever used, so a longer remainder is shortened and a one-line file cannot grow the buffer up to SCAN_BYTES.
            $carry = substr((string) array_shift($parts), 0, self::MAX_LINE_BYTES);
            for ($i = count($parts) - 1; $i >= 0; --$i) {
                $consume($parts[$i]);
            }
        }
        if ($start === 0) {
            $consume($carry);
        }
        fclose($handle);

        $result['groups'] = array_values($groups);
        $result['overflow'] = count($spilled);

        return $result;
    }

    /** Sums the unique errors of every log file. A file is scanned again only after it changed and the stored number is older than COUNTER_TTL. */
    public function uniqueErrorCount(): int
    {
        $total = 0;
        foreach ($this->files() as $name => $info) {
            $total += $this->fileErrorCount($name, $info);
        }

        return $total;
    }

    /** Called after the file was cleared, so the counter does not outlive the entries. */
    public function forget(string $name): void
    {
        $this->cache->deleteItem($this->counterKey($name));
    }

    /** @param array{path:string,size:int,modified:int} $info */
    private function fileErrorCount(string $name, array $info): int
    {
        if ($info['size'] === 0) {
            return 0;
        }
        $item = $this->cache->getItem($this->counterKey($name));
        $stored = $item->isHit() ? $item->get() : null;
        if (is_array($stored) && isset($stored['size'], $stored['modified'], $stored['at'], $stored['count'])) {
            $unchanged = $stored['size'] === $info['size'] && $stored['modified'] === $info['modified'];
            $grewRecently = $info['size'] >= $stored['size'] && time() - $stored['at'] < self::COUNTER_TTL;
            if ($unchanged || $grewRecently) {
                return (int) $stored['count'];
            }
        }
        $digest = $this->group($info['path'], 'error');
        $count = count($digest['groups']) + $digest['overflow'];
        $item->set(['size' => $info['size'], 'modified' => $info['modified'], 'at' => time(), 'count' => $count])->expiresAfter(86400);
        $this->cache->save($item);

        return $count;
    }

    private function counterKey(string $name): string
    {
        return self::COUNTER_KEY . hash('xxh3', $name);
    }

    /** Stack trace lines and other indented lines belong to the entry above them. */
    private function isContinuation(string $line): bool
    {
        return preg_match('/^(?:\s|#\d|Stack trace:|thrown in |\{main\})/', $line) === 1;
    }

    /** @return array{time:string,type:string,message:string,file:string,line:int,fingerprint:string} */
    private function parse(string $line): array
    {
        $time = '';
        $rest = $line;
        if (preg_match('/^\[([^\]]{6,40})\]\s*(.*)$/su', $line, $m) === 1 && preg_match('/\d/', $m[1]) === 1) {
            [$time, $rest] = [$m[1], $m[2]];
        }
        if (preg_match('/^([A-Za-z0-9_.-]+)\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):\s*(.*)$/su', $rest, $m) === 1) {
            [$type, $message] = [$m[1] . '.' . $m[2], $m[3]];
        } elseif (preg_match('/^(?:PHP\s+)?(Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated)\s*:\s*(.*)$/isu', $rest, $m) === 1) {
            [$type, $message] = ['PHP ' . $m[1], $m[2]];
        } elseif (preg_match('/^\[(critical|alert|emergency|error|warning|notice|info|debug)\]:?\s*(.*)$/isu', $rest, $m) === 1) {
            [$type, $message] = [strtoupper($m[1]), $m[2]];
        } else {
            [$type, $message] = ['LOG', $rest];
        }

        $cut = strlen($message);
        foreach ([' {"', ' []', ' {}'] as $marker) { // Monolog appends " {context} {extra}"
            $at = strpos($message, $marker);
            if ($at !== false && $at < $cut) {
                $cut = $at;
            }
        }
        $message = trim(substr($message, 0, $cut));

        $file = '';
        $lineNo = 0;
        if (preg_match(self::FILE_LINE, $message, $m) === 1 || preg_match(self::FILE_LINE, $rest, $m) === 1) {
            [$file, $lineNo] = [$m[1], (int) $m[2]];
        }

        $normalized = preg_replace(
            ['/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '/\b(?:0x)?[0-9a-f]{12,}\b/i', '/\d+/'],
            ['{uuid}', '{hex}', '#'],
            $message,
        ) ?? $message;

        return [
            'time' => $time,
            'type' => $type,
            'message' => mb_substr($message, 0, self::MAX_MESSAGE_CHARS),
            'file' => $file,
            'line' => $lineNo,
            'fingerprint' => hash('xxh3', $type . "\n" . $normalized . "\n" . $file . ':' . $lineNo),
        ];
    }
}
