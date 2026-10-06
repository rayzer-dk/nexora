<?php

declare(strict_types=1);

namespace Commerce\Modules\Prro\Application;

final class CheckboxException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }
}
