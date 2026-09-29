<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Doctrine\DBAL\Connection;

final readonly class CustomerStoreMembershipService
{
    public function __construct(private Connection $db)
    {
    }

    public function ensure(int $storeId, int $customerId): void
    {
        if ($storeId < 1 || $customerId < 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.store_membership_invalid'));
        }

        $exists = (bool) $this->db->fetchOne(
            'SELECT 1 FROM mc_store_customer WHERE store_id=? AND customer_id=?',
            [$storeId, $customerId],
        );
        if ($exists) {
            return;
        }

        $valid = (bool) $this->db->fetchOne(
            "SELECT 1 FROM mc_store s JOIN mc_customer c ON c.id=? WHERE s.id=? AND s.status='active' AND c.status='active'",
            [$customerId, $storeId],
        );
        if (!$valid) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_store_customer', [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'customer_group_code' => 'default',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function groupCode(int $storeId, int $customerId): string
    {
        $group = $this->db->fetchOne(
            'SELECT customer_group_code FROM mc_store_customer WHERE store_id=? AND customer_id=? LIMIT 1',
            [$storeId, $customerId],
        );

        return is_string($group) && $group !== '' ? $group : 'default';
    }

    public function setGroupCode(int $storeId, int $customerId, string $groupCode): void
    {
        $groupCode = strtolower(trim($groupCode));
        if (preg_match('/^[a-z0-9_-]{1,64}$/D', $groupCode) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.nekorektnyi_kod_hrupy'));
        }

        $updated = $this->db->update(
            'mc_store_customer',
            ['customer_group_code' => $groupCode, 'updated_at' => gmdate('Y-m-d H:i:s.u')],
            ['store_id' => $storeId, 'customer_id' => $customerId],
        );
        if ($updated !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
        }
    }
}
