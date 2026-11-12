<?php

declare(strict_types=1);

namespace Commerce\Core\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/** Marks the back-office account type so Core (installer) can hash an administrator password without knowing the Admin module. */
interface AdminAccount extends PasswordAuthenticatedUserInterface
{
}
