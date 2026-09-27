<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\AdminInterfaceLocale;
use Commerce\Modules\Admin\Domain\AdminUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AdminInterfaceLocaleController extends AbstractController
{
    public function __construct(private readonly AdminInterfaceLocale $locales)
    {
    }

    #[Route('/admin/interface-language', name: 'admin_interface_language', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_interface_language', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user = $this->getUser();
        $this->locales->remember($request, $user instanceof AdminUser ? $user->id : null, (string) $request->request->get('ui_locale'));
        $back = (string) $request->request->get('back', '/admin');

        return $this->redirect(str_starts_with($back, '/admin') && !str_starts_with($back, '//') ? $back : '/admin');
    }
}
