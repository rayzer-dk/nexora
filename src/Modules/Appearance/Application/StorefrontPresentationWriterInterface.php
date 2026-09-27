<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Application;

interface StorefrontPresentationWriterInterface
{
    /** @param array<string,mixed> $settings */
    public function save(int $storeId, array $settings, ?string $actorSubject = null): int;
}
