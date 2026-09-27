<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Contract;

use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;

interface NotificationSenderInterface
{
    public function channel(): NotificationChannel;

    public function send(NotificationMessage $message, string $recipient): void;
}
