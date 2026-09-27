<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Modules\Admin\Authorization\AdminAuthorizationService;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminExtensionRouteController extends AbstractController
{
    public function __construct(
        private readonly ExtensionContributionRegistry $contributions,
        private readonly TrustedExtensionRuntimeLoader $trustedLoader,
        private readonly TrustedExtensionRuntimeRegistry $trustedRuntime,
        private readonly Security $security,
        private readonly AdminAuthorizationService $authorization,
        private readonly AdminContextResolver $contexts,
    ) {
    }

    #[Route('/admin/extensions/{extensionPath}', name: 'admin_extension_dynamic', methods: ['GET','POST','PUT','PATCH','DELETE'], requirements: ['extensionPath' => '.+'], priority: -900)]
    public function __invoke(Request $request, string $extensionPath): Response
    {
        $path = '/admin/extensions/' . ltrim($extensionPath, '/');
        $method = strtoupper($request->getMethod());
        $route = null;
        foreach ($this->contributions->routes() as $candidate) {
            if ((string) ($candidate['path'] ?? '') === $path && in_array($method, array_map('strtoupper', (array) ($candidate['methods'] ?? [])), true)) {
                $route = $candidate;
                break;
            }
        }
        if (!is_array($route) || (string) ($route['mode'] ?? '') !== 'trusted_handler') {
            throw $this->createNotFoundException();
        }
        $permission = (string) ($route['permission'] ?? '');
        $user = $this->security->getUser();
        if (!$user instanceof AdminUser || $permission === '') {
            throw $this->createAccessDeniedException();
        }
        $storeId = null;
        try {
            $storeId = $this->contexts->resolve($request)->storeId;
        } catch (\Throwable) {
        }
        if (!$this->authorization->isGranted($user, $permission, $storeId)) {
            throw $this->createAccessDeniedException();
        }
        $this->trustedLoader->bootActive();
        $handler = $this->trustedRuntime->routeHandler((string) ($route['name'] ?? ''));
        if (!is_callable($handler)) {
            throw $this->createNotFoundException();
        }
        $response = $handler($request, $route);
        if (!$response instanceof Response) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.5561f56c05b0'));
        }
        return $response;
    }
}
