<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Doctrine\DBAL\Connection;

final readonly class ForumAccessPolicy
{
    public function __construct(
        private Connection $connection,
        private ForumModerationService $moderation,
    ) {
    }

    public function canParticipate(int $storeId, int $customerId): bool
    {
        $verified = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM mc_customer
             WHERE id=? AND status='active' AND (email_verified_at IS NOT NULL OR phone_verified_at IS NOT NULL)
             LIMIT 1",
            [$customerId],
        );
        return $verified && $this->moderation->activeBan($storeId, $customerId) === null;
    }

    public function assertCanParticipate(int $storeId, int $customerId): void
    {
        $verified = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM mc_customer
             WHERE id=? AND status='active' AND (email_verified_at IS NOT NULL OR phone_verified_at IS NOT NULL)
             LIMIT 1",
            [$customerId],
        );
        if (!$verified) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.verify_required'));
        }
        if ($this->moderation->activeBan($storeId, $customerId) !== null) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.banned'));
        }
    }
}
