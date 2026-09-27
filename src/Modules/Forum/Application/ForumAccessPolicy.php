<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Doctrine\DBAL\Connection;

final readonly class ForumAccessPolicy
{
    public function __construct(private Connection $connection)
    {
    }

    public function canParticipate(int $customerId): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM mc_customer
             WHERE id=? AND status='active' AND email_verified_at IS NOT NULL
             LIMIT 1",
            [$customerId],
        );
    }

    public function assertCanParticipate(int $customerId): void
    {
        if (!$this->canParticipate($customerId)) {
            throw new \DomainException('Verify your email before participating in the forum.');
        }
    }
}
