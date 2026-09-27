<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Security;

use Commerce\Modules\Customer\Domain\CustomerUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DbalCustomerUserProvider implements UserProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $email = mb_strtolower(trim($identifier), 'UTF-8');
        $row = $this->connection->fetchAssociative(
            "SELECT id,public_id,email,password_hash,display_name,status,customer_group_code FROM mc_customer WHERE email_normalized=? LIMIT 1",
            [$email],
        );
        if (!is_array($row) || (string) $row['status'] !== 'active' || !is_string($row['password_hash']) || $row['password_hash'] === '') {
            $e = new UserNotFoundException('Customer account was not found.');
            $e->setUserIdentifier($identifier);
            throw $e;
        }
        return new CustomerUser(
            (int) $row['id'],
            Uuid::fromBinary((string) $row['public_id'])->toRfc4122(),
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) ($row['display_name'] ?? ''),
            (string) $row['status'],
            (string) ($row['customer_group_code'] ?? 'default'),
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof CustomerUser) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c85806e810aa'));
        }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, CustomerUser::class, true);
    }
}
