<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

/**
 * Cuts a long text (usually product or article HTML) into pieces a model translates reliably, without losing anything:
 * joining the pieces gives back the original text byte for byte. Cuts go after block tags first (paragraphs, headings,
 * lists, tables), then after list items and line breaks, then after sentences, and only as a last resort in the middle.
 */
final class TranslationChunker
{
    private const LEVELS = [
        '~(?<=</p>|</h1>|</h2>|</h3>|</h4>|</h5>|</h6>|</ul>|</ol>|</table>|</blockquote>|</div>|</figure>|</section>|\n\n)~i',
        '~(?<=</li>|</tr>|<br>|<br/>|<br />|\n)~i',
        '~(?<=[.!?…])(?=\s)~u',
    ];

    /** @return list<string> */
    public static function split(string $text, int $max): array
    {
        $max = max(200, $max);
        if (mb_strlen($text) <= $max) {
            return [$text];
        }

        return self::pack(self::pieces($text, $max, 0), $max);
    }

    /** @return list<string> */
    private static function pieces(string $text, int $max, int $level): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text];
        }
        if (!isset(self::LEVELS[$level])) {
            return self::hardCut($text, $max);
        }
        $parts = preg_split(self::LEVELS[$level], $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) < 2) {
            return self::pieces($text, $max, $level + 1);
        }
        $out = [];
        foreach ($parts as $part) {
            array_push($out, ...self::pieces($part, $max, $level + 1));
        }

        return $out;
    }

    /** @return list<string> */
    private static function hardCut(string $text, int $max): array
    {
        $out = [];
        for ($i = 0, $n = mb_strlen($text); $i < $n; $i += $max) {
            $out[] = mb_substr($text, $i, $max);
        }

        return $out;
    }

    /**
     * Greedily joins neighbouring pieces back together while they fit.
     *
     * @param list<string> $pieces
     * @return list<string>
     */
    private static function pack(array $pieces, int $max): array
    {
        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) > $max) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $piece;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
