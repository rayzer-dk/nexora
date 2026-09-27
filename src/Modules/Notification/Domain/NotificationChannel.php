<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Domain;

enum NotificationChannel: string
{
    case Email = 'email';
    case Telegram = 'telegram';
    case Sms = 'sms';
    case InApp = 'in_app';
    case WebPush = 'web_push';
}
