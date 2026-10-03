<?php

declare(strict_types=1);

namespace Commerce\Modules\SupportChat\Infrastructure;

/** A failed Telegram Bot API call; the message is safe to show to an administrator (the token is scrubbed). */
final class TelegramApiException extends \RuntimeException
{
}
