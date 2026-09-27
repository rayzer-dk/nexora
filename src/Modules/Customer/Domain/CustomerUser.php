<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Domain;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class CustomerUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private readonly int $id,
        private readonly string $publicId,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $displayName,
        private readonly string $status,
        private readonly string $customerGroupCode = 'default',
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function groupCode(): string { return $this->customerGroupCode; }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_CUSTOMER'];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }
}
