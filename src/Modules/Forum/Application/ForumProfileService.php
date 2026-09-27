<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class ForumProfileService
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string,mixed> */
    public function getOrCreate(int $storeId, int $customerId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id,nickname,bio,show_email,show_phone,allow_private_messages FROM mc_forum_profile WHERE store_id=? AND customer_id=? LIMIT 1',
            [$storeId, $customerId],
        );
        if (is_array($row)) {
            return $row;
        }

        $customer = $this->connection->fetchAssociative("SELECT public_id FROM mc_customer WHERE id=? AND status='active' LIMIT 1", [$customerId]);
        if (!is_array($customer) || !is_string($customer['public_id'])) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.customer_missing'));
        }

        $nickname = 'member-' . substr(bin2hex($customer['public_id']), 0, 8);
        $now = $this->now();
        $this->connection->insert('mc_forum_profile', [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'nickname' => $nickname,
            'bio' => null,
            'show_email' => 0,
            'show_phone' => 0,
            'allow_private_messages' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => (int) $this->connection->lastInsertId(),
            'nickname' => $nickname,
            'bio' => null,
            'show_email' => 0,
            'show_phone' => 0,
            'allow_private_messages' => 1,
        ];
    }

    public function nickname(int $storeId, int $customerId): string
    {
        return (string) ($this->getOrCreate($storeId, $customerId)['nickname'] ?? '');
    }

    public function update(
        int $storeId,
        int $customerId,
        string $nickname,
        string $bio,
        bool $showEmail,
        bool $showPhone,
        bool $allowPrivateMessages,
    ): void {
        $nickname = trim(preg_replace('/\s+/u', ' ', strip_tags($nickname)) ?? '');
        if ($nickname === '' || mb_strlen($nickname, 'UTF-8') < 3 || mb_strlen($nickname, 'UTF-8') > 64) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.nickname_length'));
        }
        if (preg_match('/^[\p{L}\p{N}._ -]+$/u', $nickname) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.nickname_chars'));
        }

        $bio = mb_substr(trim(strip_tags($bio)), 0, 500, 'UTF-8');
        $this->getOrCreate($storeId, $customerId);
        try {
            $this->connection->update('mc_forum_profile', [
                'nickname' => $nickname,
                'bio' => $bio !== '' ? $bio : null,
                'show_email' => $showEmail ? 1 : 0,
                'show_phone' => $showPhone ? 1 : 0,
                'allow_private_messages' => $allowPrivateMessages ? 1 : 0,
                'updated_at' => $this->now(),
            ], ['store_id' => $storeId, 'customer_id' => $customerId]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.nickname_taken'));
        }
    }

    /** @return array<string,mixed>|null */
    public function publicProfile(int $storeId, int $customerId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT p.customer_id,p.nickname,p.bio,p.show_email,p.show_phone,p.allow_private_messages,
                    c.email,c.phone_e164,c.created_at
             FROM mc_forum_profile p
             JOIN mc_customer c ON c.id=p.customer_id
             WHERE p.store_id=? AND p.customer_id=? AND c.status='active' LIMIT 1",
            [$storeId, $customerId],
        );
        if (!is_array($row)) {
            return null;
        }

        $row['email'] = (int) $row['show_email'] === 1 ? (string) $row['email'] : null;
        $row['phone_e164'] = (int) $row['show_phone'] === 1 ? (string) ($row['phone_e164'] ?? '') : null;
        unset($row['show_email'], $row['show_phone']);
        return $row;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
