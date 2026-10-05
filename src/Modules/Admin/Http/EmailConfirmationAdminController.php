<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\EmailConfirmationSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Where the shop asks a shopper to confirm the e-mail address by a link (each place has its own switch). */
final class EmailConfirmationAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly EmailConfirmationSettings $settings)
    {
    }

    #[Route('/admin/commerce/customer-email-confirmation', name: 'admin_commerce_customer_email_confirmation', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_email_confirmation', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $this->settings->save((array) $request->request->all('confirm'));
        $this->addFlash('success', CanonicalUiText::get('admin.emailconfirm.saved'));
        $back = (string) $request->request->get('back', '');

        return $this->redirectToRoute($back === 'subscribers' ? 'admin_commerce_subscribers' : 'admin_commerce_stock_requests');
    }
}
