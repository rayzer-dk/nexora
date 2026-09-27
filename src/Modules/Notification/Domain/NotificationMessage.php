<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Domain;

final readonly class NotificationMessage
{
    public function __construct(
        public string $type,
        public string $subject,
        public string $text,
        public array $context = [],
        public string $emailTemplate = 'generic',
    ) {
    }
}
