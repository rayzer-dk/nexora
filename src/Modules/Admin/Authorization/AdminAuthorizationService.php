<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Authorization;

use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class AdminAuthorizationService
{
    public function __construct(private Connection $connection)
    {
    }

    public function isGranted(AdminUser $user, string $permission, ?int $storeId = null): bool
    {
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{2,127}$/D', $permission) !== 1) {
            return false;
        }

        try {
            $roles = array_values(array_filter(
                $user->getRoles(),
                static fn (string $role): bool => str_starts_with($role, 'ROLE_') && $role !== 'ROLE_ADMIN',
            ));
            if ($roles === []) {
                return false;
            }
            $allowed = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM mc_admin_role_permission WHERE role_code IN (?) AND permission_code=?',
                [$roles, $permission],
                [ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING],
            ) > 0;
            if (!$allowed) {
                return false;
            }
            if ($storeId === null) {
                return true;
            }
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM mc_admin_store_scope WHERE admin_user_id=? AND store_id=?',
                [$user->id, $storeId],
            ) === 1;
        } catch (Throwable) {
            // Authorization infrastructure must fail closed for non-super-admin users.
            return false;
        }
    }

    /** @return list<string> */
    public function permissions(AdminUser $user): array
    {
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            try {
                return array_values(array_map('strval', $this->connection->fetchFirstColumn('SELECT code FROM mc_admin_permission ORDER BY code')));
            } catch (Throwable) {
                return ['*'];
            }
        }
        try {
            $roles = array_values(array_filter($user->getRoles(), static fn (string $role): bool => $role !== 'ROLE_ADMIN'));
            if ($roles === []) {
                return [];
            }
            return array_values(array_map('strval', $this->connection->fetchFirstColumn(
                'SELECT DISTINCT permission_code FROM mc_admin_role_permission WHERE role_code IN (?) ORDER BY permission_code',
                [$roles],
                [ArrayParameterType::STRING],
            )));
        } catch (Throwable) {
            return [];
        }
    }
}
