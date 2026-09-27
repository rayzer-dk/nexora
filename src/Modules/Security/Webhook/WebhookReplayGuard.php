<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class WebhookReplayGuard
{
    public function __construct(private Connection $db) {}

    public function claim(string $provider, string $fingerprint): bool
    {
        $provider = strtolower(trim($provider));
        if (preg_match('/^[a-z0-9_.-]{2,32}$/D', $provider) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('security.webhook.invalid_replay_key'));
        }
        try {
            $this->db->insert('mc_webhook_replay', [
                'provider' => $provider,
                'fingerprint' => hex2bin($fingerprint),
                'received_at' => $this->now(),
            ]);
            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function release(string $provider, string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return;
        }
        $this->db->delete('mc_webhook_replay', ['provider' => strtolower(trim($provider)), 'fingerprint' => hex2bin($fingerprint)]);
    }

    public function purgeOlderThanDays(int $days = 30): int
    {
        $days = max(1, min(365, $days));
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d H:i:s.u');
        return $this->db->executeStatement('DELETE FROM mc_webhook_replay WHERE received_at < ?', [$cutoff]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
