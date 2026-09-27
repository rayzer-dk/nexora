<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Security;

use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/** @implements UserProviderInterface<AdminUser> */
final readonly class DbalAdminUserProvider implements UserProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $email = mb_strtolower(trim($identifier), 'UTF-8');
        $row = $this->connection->fetchAssociative(
            'SELECT id, public_id, email, display_name, password_hash, roles, status FROM mc_admin_user WHERE email_normalized = ? LIMIT 1',
            [$email],
        );

        if (!is_array($row) || (string) $row['status'] !== 'active') {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }

        $roles = json_decode((string) $row['roles'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($roles)) {
            $roles = [];
        }

        return new AdminUser(
            id: (int) $row['id'],
            publicId: Uuid::fromBinary((string) $row['public_id'])->toRfc4122(),
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            roles: array_values(array_filter($roles, 'is_string')),
            displayName: (string) $row['display_name'],
            status: (string) $row['status'],
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AdminUser) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2f8f61035e3a'));
        }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, AdminUser::class, true);
    }
}
