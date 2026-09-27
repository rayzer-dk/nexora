<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Domain;

enum SlugMode: string
{
    /** Public storefront policy: readable lowercase ASCII transliteration only. */
    case TransliterateAscii = 'transliterate_ascii';
}
