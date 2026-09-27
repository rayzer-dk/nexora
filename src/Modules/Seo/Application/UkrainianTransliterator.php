<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

/** Predictable Ukrainian -> Latin transliteration for stable ASCII slugs. */
final class UkrainianTransliterator
{
    /** @var array<string,string> */
    private const SIMPLE = [
        'А'=>'A','а'=>'a','Б'=>'B','б'=>'b','В'=>'V','в'=>'v','Г'=>'H','г'=>'h','Ґ'=>'G','ґ'=>'g',
        'Д'=>'D','д'=>'d','Е'=>'E','е'=>'e','Ж'=>'Zh','ж'=>'zh','З'=>'Z','з'=>'z','И'=>'Y','и'=>'y',
        'І'=>'I','і'=>'i','К'=>'K','к'=>'k','Л'=>'L','л'=>'l','М'=>'M','м'=>'m','Н'=>'N','н'=>'n',
        'О'=>'O','о'=>'o','П'=>'P','п'=>'p','Р'=>'R','р'=>'r','С'=>'S','с'=>'s','Т'=>'T','т'=>'t',
        'У'=>'U','у'=>'u','Ф'=>'F','ф'=>'f','Х'=>'Kh','х'=>'kh','Ц'=>'Ts','ц'=>'ts','Ч'=>'Ch','ч'=>'ch',
        'Ш'=>'Sh','ш'=>'sh','Щ'=>'Shch','щ'=>'shch','Ь'=>'','ь'=>'','ʼ'=>'','’'=>'','\''=>'',
    ];

    /** @var array<string,array{start:string,inside:string}> */
    private const CONTEXTUAL = [
        'Є'=>['start'=>'Ye','inside'=>'Ie'], 'є'=>['start'=>'ye','inside'=>'ie'],
        'Ї'=>['start'=>'Yi','inside'=>'I'], 'ї'=>['start'=>'yi','inside'=>'i'],
        'Й'=>['start'=>'Y','inside'=>'I'], 'й'=>['start'=>'y','inside'=>'i'],
        'Ю'=>['start'=>'Yu','inside'=>'Iu'], 'ю'=>['start'=>'yu','inside'=>'iu'],
        'Я'=>['start'=>'Ya','inside'=>'Ia'], 'я'=>['start'=>'ya','inside'=>'ia'],
    ];

    public function transliterate(string $value): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $wordStart = true;

        foreach ($chars as $char) {
            if (isset(self::CONTEXTUAL[$char])) {
                $out .= self::CONTEXTUAL[$char][$wordStart ? 'start' : 'inside'];
                $wordStart = false;
                continue;
            }
            if (array_key_exists($char, self::SIMPLE)) {
                $out .= self::SIMPLE[$char];
                if (self::SIMPLE[$char] !== '') {
                    $wordStart = false;
                }
                continue;
            }

            $out .= $char;
            $wordStart = preg_match('/[\p{L}\p{N}]/u', $char) !== 1;
        }

        return $out;
    }
}
