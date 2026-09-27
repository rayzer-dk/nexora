<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class AdminCsrfGuard
{
    public const TOKEN_ID = 'admin_api';

    public function __construct(private CsrfTokenManagerInterface $tokens)
    {
    }

    public function assertValid(Request $request): void
    {
        $value = (string) $request->headers->get('X-CSRF-Token', '');
        if ($value === '' || !$this->tokens->isTokenValid(new CsrfToken(self::TOKEN_ID, $value))) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
