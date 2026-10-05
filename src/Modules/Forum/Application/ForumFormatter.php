<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

/**
 * Turns the plain text of a forum message into safe HTML. The text is escaped first; only a small, fixed set of
 * Markdown-like marks is then recognised: **bold**, *italic*, ~~strike~~, `code`, fenced code, "> quotes", "- " and
 * "1. " lists, [text](https://link), bare links and @nicknames. Nothing the author types can become a tag or a script.
 */
final class ForumFormatter
{
    /** @param array<string,string> $mentions lower-case nickname => member page URL */
    public function toHtml(string $text, array $mentions = []): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $codeBlocks = [];
        $text = (string) preg_replace_callback('/```[^\n`]*\n(.*?)```/su', static function (array $m) use (&$codeBlocks): string {
            $codeBlocks[] = '<pre class="forum-code"><code>' . htmlspecialchars(rtrim($m[1], "\n"), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';

            return "\n\x02B" . (count($codeBlocks) - 1) . "\x02\n";
        }, $text);

        $html = [];
        $lines = explode("\n", $text);
        $count = count($lines);
        for ($i = 0; $i < $count;) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            if (preg_match('/^\x02B(\d+)\x02$/', trim($line), $m) === 1) {
                $html[] = $codeBlocks[(int) $m[1]] ?? '';
                $i++;
                continue;
            }
            if (preg_match('/^\s{0,3}>\s?/', $line) === 1) {
                $quote = [];
                while ($i < $count && preg_match('/^\s{0,3}>\s?(.*)$/', $lines[$i], $q) === 1) {
                    $quote[] = $q[1];
                    $i++;
                }
                $html[] = '<blockquote class="forum-quote">' . $this->toHtml(implode("\n", $quote), $mentions) . '</blockquote>';
                continue;
            }
            if (preg_match('/^\s{0,3}([-*•])\s+/u', $line) === 1) {
                $items = [];
                while ($i < $count && preg_match('/^\s{0,3}[-*•]\s+(.*)$/u', $lines[$i], $q) === 1) {
                    $items[] = '<li>' . $this->inline($q[1], $mentions) . '</li>';
                    $i++;
                }
                $html[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }
            if (preg_match('/^\s{0,3}\d{1,3}[.)]\s+/', $line) === 1) {
                $items = [];
                while ($i < $count && preg_match('/^\s{0,3}\d{1,3}[.)]\s+(.*)$/', $lines[$i], $q) === 1) {
                    $items[] = '<li>' . $this->inline($q[1], $mentions) . '</li>';
                    $i++;
                }
                $html[] = '<ol>' . implode('', $items) . '</ol>';
                continue;
            }
            $paragraph = [];
            while ($i < $count && trim($lines[$i]) !== '' && preg_match('/^(\s{0,3}>|\s{0,3}[-*•]\s|\s{0,3}\d{1,3}[.)]\s|\x02B\d+\x02$)/u', $lines[$i]) !== 1) {
                $paragraph[] = $this->inline($lines[$i], $mentions);
                $i++;
            }
            if ($paragraph === []) {
                $paragraph[] = $this->inline($line, $mentions);
                $i++;
            }
            $html[] = '<p>' . implode('<br>', $paragraph) . '</p>';
        }

        return implode('', $html);
    }

    /** @param array<string,string> $mentions */
    private function inline(string $line, array $mentions): string
    {
        $stash = [];
        $keep = static function (string $fragment) use (&$stash): string {
            $stash[] = $fragment;

            return "\x01" . (count($stash) - 1) . "\x01";
        };
        $line = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $line = (string) preg_replace_callback('/`([^`\n]{1,300})`/u', static fn (array $m): string => $keep('<code>' . $m[1] . '</code>'), $line);
        $line = (string) preg_replace_callback('/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]{1,500})\)/iu', static fn (array $m): string => $keep('<a href="' . $m[2] . '" rel="nofollow ugc noopener" target="_blank">' . $m[1] . '</a>'), $line);
        $line = (string) preg_replace_callback('/(?<![\w\/"=])(https?:\/\/[^\s<]{3,500}?)(?=[.,;:!?)]*(?:\s|$))/iu', static fn (array $m): string => $keep('<a href="' . $m[1] . '" rel="nofollow ugc noopener" target="_blank">' . $m[1] . '</a>'), $line);
        if ($mentions !== []) {
            $line = (string) preg_replace_callback('/(^|[\s(])@([\p{L}\p{N}_-]{2,64})/u', function (array $m) use ($mentions, $keep): string {
                $url = $mentions[mb_strtolower($m[2], 'UTF-8')] ?? null;

                return $url === null ? $m[0] : $m[1] . $keep('<a class="forum-mention" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">@' . $m[2] . '</a>');
            }, $line);
        }
        $line = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $line);
        $line = (string) preg_replace('/(?<![\w*])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\w*])/u', '<em>$1</em>', $line);
        $line = (string) preg_replace('/(?<![\w])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\w])/u', '<em>$1</em>', $line);
        $line = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '<s>$1</s>', $line);

        return (string) preg_replace_callback('/\x01(\d+)\x01/', static fn (array $m): string => $stash[(int) $m[1]] ?? '', $line);
    }
}
