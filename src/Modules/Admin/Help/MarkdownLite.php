<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Help;

/**
 * Small, safe Markdown renderer for the bundled documentation: headings, paragraphs, lists, tables, fenced code,
 * inline code, bold and links. All text is HTML-escaped first; links are limited to https, mailto and the
 * documents of the help catalogue, so a document can never inject markup or script.
 */
final class MarkdownLite
{
    /** @param callable(string):?string $docLink maps a relative *.md link to a help URL, or null */
    public function __construct(private readonly mixed $docLink = null)
    {
    }

    public function render(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $html = [];
        $count = count($lines);
        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];
            if (preg_match('/^```/u', $line) === 1) {
                $code = [];
                for (++$i; $i < $count && preg_match('/^```/u', $lines[$i]) !== 1; ++$i) {
                    $code[] = $lines[$i];
                }
                $html[] = '<pre><code>' . $this->e(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.+)$/u', $line, $m) === 1) {
                $level = strlen($m[1]) + 1;
                $html[] = sprintf('<h%d>%s</h%d>', $level, $this->inline($m[2]), $level);
                continue;
            }
            if (preg_match('/^\s*[-*]\s+/u', $line) === 1 || preg_match('/^\s*\d+[.)]\s+/u', $line) === 1) {
                $ordered = preg_match('/^\s*\d+[.)]\s+/u', $line) === 1;
                $items = [];
                for (; $i < $count && preg_match($ordered ? '/^\s*\d+[.)]\s+(.*)$/u' : '/^\s*[-*]\s+(.*)$/u', $lines[$i], $m) === 1; ++$i) {
                    $items[] = '<li>' . $this->inline($m[1]) . '</li>';
                }
                --$i;
                $tag = $ordered ? 'ol' : 'ul';
                $html[] = "<$tag>" . implode('', $items) . "</$tag>";
                continue;
            }
            if (str_starts_with(ltrim($line), '|') && $i + 1 < $count && preg_match('/^\s*\|?\s*:?-{2,}/u', $lines[$i + 1]) === 1) {
                $head = $this->cells($line);
                $i += 2;
                $rows = [];
                for (; $i < $count && str_starts_with(ltrim($lines[$i]), '|'); ++$i) {
                    $rows[] = '<tr>' . implode('', array_map(fn (string $c): string => '<td>' . $this->inline($c) . '</td>', $this->cells($lines[$i]))) . '</tr>';
                }
                --$i;
                $html[] = '<div class="admin-table-wrap"><table class="admin-table"><thead><tr>' . implode('', array_map(fn (string $c): string => '<th>' . $this->inline($c) . '</th>', $head)) . '</tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div>';
                continue;
            }
            if (preg_match('/^(?: {4}|\t)\S/u', $line) === 1) {
                $code = [];
                for (; $i < $count && (preg_match('/^(?: {4}|\t)/u', $lines[$i]) === 1 || trim($lines[$i]) === ''); ++$i) {
                    $code[] = preg_replace('/^(?: {4}|\t)/u', '', $lines[$i]);
                }
                --$i;
                $html[] = '<pre><code>' . $this->e(rtrim(implode("\n", $code))) . '</code></pre>';
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $paragraph = [$line];
            while ($i + 1 < $count && trim($lines[$i + 1]) !== '' && preg_match('/^(#{1,4}\s|```|\s*[-*]\s|\s*\d+[.)]\s|\s*\||(?: {4}|\t)\S)/u', $lines[$i + 1]) !== 1) {
                $paragraph[] = $lines[++$i];
            }
            $html[] = '<p>' . $this->inline(implode(' ', array_map('trim', $paragraph))) . '</p>';
        }

        return implode("\n", $html);
    }

    /** @return list<string> */
    private function cells(string $line): array
    {
        $line = trim(trim($line), '|');

        return array_map('trim', explode('|', $line));
    }

    private function inline(string $text): string
    {
        $codes = [];
        $text = (string) preg_replace_callback('/`([^`]+)`/u', function (array $m) use (&$codes): string {
            $codes[] = '<code>' . $this->e($m[1]) . '</code>';

            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);
        $text = $this->e($text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', fn (array $m): string => $this->link($m[1], html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5)), $text);

        return (string) preg_replace_callback('/\x00(\d+)\x00/u', static fn (array $m): string => $codes[(int) $m[1]] ?? '', $text);
    }

    private function link(string $label, string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('~^https://[A-Za-z0-9.-]+(/\S*)?$~u', $url) === 1 || preg_match('~^mailto:[^\s<>"]+$~u', $url) === 1) {
            return '<a href="' . $this->e($url) . '" rel="noopener noreferrer" target="_blank">' . $label . '</a>';
        }
        if (is_callable($this->docLink) && str_ends_with(strtolower($path), '.md')) {
            $target = ($this->docLink)(basename($path));
            if (is_string($target) && $target !== '') {
                return '<a href="' . $this->e($target) . '">' . $label . '</a>';
            }
        }

        return $label;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
