<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Domain;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** @param list<string> $roles */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly array $roles,
        public readonly string $displayName,
        public readonly string $status = 'active',
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_ADMIN';
        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
    }
}
