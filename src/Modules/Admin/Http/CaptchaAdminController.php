<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Security\Captcha\CaptchaSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CaptchaAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly CaptchaSettings $settings)
    {
    }

    #[Route('/admin/system/captcha', name: 'admin_system_captcha', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_captcha', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            try {
                $this->settings->save($context->storeId, $request->request->all());
                $this->addFlash('success', CanonicalUiText::get('admin.captcha.saved'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('admin_system_captcha');
        }
        $s = $this->settings->get($context->storeId);
        unset($s['secret']);

        return $this->render('@storefront/admin/system/captcha.html.twig', [
            's' => $s,
            'providers' => CaptchaSettings::PROVIDERS,
            'form_labels' => [
                'register' => CanonicalUiText::get('admin.captcha.form.register'),
                'password_recovery' => CanonicalUiText::get('admin.captcha.form.password_recovery'),
                'contact' => CanonicalUiText::get('admin.captcha.form.contact'),
                'callback' => CanonicalUiText::get('admin.captcha.form.callback'),
                'price_request' => CanonicalUiText::get('admin.captcha.form.price_request'),
                'quick_order' => CanonicalUiText::get('admin.captcha.form.quick_order'),
                'newsletter' => CanonicalUiText::get('admin.captcha.form.newsletter'),
                'stock_notify' => CanonicalUiText::get('admin.captcha.form.stock_notify'),
                'review' => CanonicalUiText::get('admin.captcha.form.review'),
                'question' => CanonicalUiText::get('admin.captcha.form.question'),
                'forum' => CanonicalUiText::get('admin.captcha.form.forum'),
                'withdrawal' => CanonicalUiText::get('admin.captcha.form.withdrawal'),
            ],
            'provider_labels' => [
                'none' => CanonicalUiText::get('admin.captcha.provider.none'),
                'builtin' => CanonicalUiText::get('admin.captcha.provider.builtin'),
                'recaptcha_v2' => CanonicalUiText::get('admin.captcha.provider.recaptcha_v2'),
                'recaptcha_v3' => CanonicalUiText::get('admin.captcha.provider.recaptcha_v3'),
                'turnstile' => CanonicalUiText::get('admin.captcha.provider.turnstile'),
            ],
        ]);
    }
}
