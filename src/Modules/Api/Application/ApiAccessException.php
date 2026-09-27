<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Application;

final class ApiAccessException extends \RuntimeException
{
    public function __construct(public readonly string $apiCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
