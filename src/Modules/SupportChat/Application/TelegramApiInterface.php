<?php

declare(strict_types=1);

namespace Commerce\Modules\SupportChat\Application;

interface TelegramApiInterface
{
    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function call(string $token, string $method, array $payload = []): array;
}
