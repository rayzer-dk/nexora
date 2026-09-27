<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

final readonly class ImageUploadResult
{
    /** @param list<array{format:string,width:int,height:int,key:string,mime:string,bytes:int}> $derivatives */
    public function __construct(
        public int $assetId,
        public string $publicId,
        public string $url,
        public string $storageKey,
        public int $width,
        public int $height,
        public array $derivatives,
    ) {
    }
}
