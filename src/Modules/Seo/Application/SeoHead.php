<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\Application;

final readonly class SeoHead
{
    /** @param array<string,string> $hreflang */
    public function __construct(
        public string $canonical,
        public string $robots = 'index,follow',
        public array $hreflang = [],
    ) {}
}
