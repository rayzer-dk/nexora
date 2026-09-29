<?php

declare(strict_types=1);

namespace Commerce\Modules\ConsumerRights\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\ConsumerRights\Withdrawal\WithdrawalNoticeService;
use Commerce\Modules\Security\Bot\TurnstileVerifier;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Two-step "withdraw from contract here" / "confirm withdrawal here" flow required for distance contracts. */
final class WithdrawalController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly WithdrawalNoticeService $notices,
        private readonly TurnstileVerifier $turnstile,
        private readonly string $turnstileSiteKey,
    ) {
    }

    #[Route('/withdrawal', name: 'storefront_withdrawal', methods: ['GET', 'POST'], priority: 120)]
    public function withdrawal(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $form = ['name' => '', 'email' => '', 'order' => '', 'scope' => ''];
        $error = null;
        $step = 'form';
        if ($request->isMethod('POST')) {
            foreach (array_keys($form) as $key) {
                $form[$key] = trim((string) $request->request->get($key, ''));
            }
            $stage = (string) $request->request->get('stage', 'review');
            if (!$this->isCsrfTokenValid('withdrawal', (string) $request->request->get('_csrf_token'))) {
                $error = 'withdrawal_error_token';
            } elseif ($form['name'] === '' || $form['order'] === '' || filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
                $error = 'withdrawal_error_invalid';
            } elseif ($stage === 'review') {
                $step = 'confirm';
            } elseif (!$this->turnstile->verify((string) $request->request->get('cf-turnstile-response', ''), $request->getClientIp())) {
                $error = 'withdrawal_error_bot';
                $step = 'confirm';
            } else {
                $receipt = $this->notices->record($context->storeId, $context->locale, $form['name'], $form['email'], $form['order'], $form['scope'], $request->getClientIp());
                $response = $this->render('@storefront/withdrawal/done.html.twig', $this->common($context->storeName) + [
                    'reference' => $receipt['reference'],
                    'received_at' => $receipt['received_at']->format('Y-m-d H:i:s') . ' UTC',
                    'email' => $form['email'],
                ]);
                $response->headers->set('Cache-Control', 'no-store, private');
                return $response;
            }
        }
        $response = $this->render('@storefront/withdrawal/form.html.twig', $this->common($context->storeName) + [
            'form' => $form,
            'error' => $error,
            'step' => $step,
            'turnstile_enabled' => $this->turnstile->enabled(),
            'turnstile_site_key' => $this->turnstileSiteKey,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }

    /** @return array<string,mixed> */
    private function common(string $storeName): array
    {
        return [
            'page_title' => CanonicalUiText::get('withdrawal_title'),
            'store_name' => $storeName,
            'seo_head' => ['robots' => 'noindex,follow'],
        ];
    }
}
