<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Authorization\AdminAuthorizationService;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Security\AdminCsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminSessionController extends AbstractController
{
    #[Route('/admin/api/session', name: 'admin_api_session', methods: ['GET'])]
    public function __invoke(CsrfTokenManagerInterface $tokens, AdminAuthorizationService $authorization): JsonResponse
    {
        $user = $this->getUser();
        return $this->json([
            'authenticated' => $user instanceof AdminUser,
            'user' => $user instanceof AdminUser ? ['id' => $user->publicId, 'email' => $user->getUserIdentifier(), 'name' => $user->displayName, 'roles' => $user->getRoles(), 'permissions' => $authorization->permissions($user)] : null,
            'csrf_token' => $tokens->getToken(AdminCsrfGuard::TOKEN_ID)->getValue(),
        ]);
    }
}
