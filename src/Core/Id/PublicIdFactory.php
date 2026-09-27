<?php

declare(strict_types=1);

namespace Commerce\Core\Id;

use Symfony\Component\Uid\Uuid;

final class PublicIdFactory
{
    public function generate(): Uuid
    {
        return Uuid::v7();
    }

    public function binary(): string
    {
        return $this->generate()->toBinary();
    }

    public function rfc4122(): string
    {
        return $this->generate()->toRfc4122();
    }
}
