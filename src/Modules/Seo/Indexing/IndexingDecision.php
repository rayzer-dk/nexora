<?php

declare(strict_types=1);
namespace Commerce\Modules\Seo\Indexing;

final readonly class IndexingDecision
{
    public function __construct(
        public bool $index,
        public bool $follow,
        public string $canonicalUrl,
        public string $reason,
        public int $httpStatus = 200,
    ) {}

    public function robots(): string
    {
        return ($this->index ? 'index' : 'noindex') . ',' . ($this->follow ? 'follow' : 'nofollow');
    }
}
