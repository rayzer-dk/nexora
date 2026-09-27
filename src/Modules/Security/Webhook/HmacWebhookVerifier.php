<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Webhook;

use DateTimeImmutable;

final class HmacWebhookVerifier
{
    public function __construct(private readonly int $maxClockSkewSeconds = 300)
    {
    }

    public function verify(
        string $rawBody,
        string $timestamp,
        string $providedSignature,
        string $secret,
        ?DateTimeImmutable $now = null,
    ): bool {
        if ($secret === '' || !ctype_digit($timestamp) || $providedSignature === '') {
            return false;
        }

        $now ??= new DateTimeImmutable();
        $timestampInt = (int) $timestamp;
        if (abs($now->getTimestamp() - $timestampInt) > $this->maxClockSkewSeconds) {
            return false;
        }

        $normalizedSignature = str_starts_with($providedSignature, 'sha256=')
            ? substr($providedSignature, 7)
            : $providedSignature;

        if (!preg_match('/^[a-f0-9]{64}$/i', $normalizedSignature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($expected, strtolower($normalizedSignature));
    }
}
