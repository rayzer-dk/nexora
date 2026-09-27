<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

final readonly class MediaMetadata
{
    /** @param list<string> $tags */
    public function __construct(
        public ?int $folderId = null,
        public string $altText = '',
        public string $title = '',
        public float $focalX = 50.0,
        public float $focalY = 50.0,
        public array $tags = [],
    ) {
    }
}
