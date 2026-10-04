<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Notification\Application\SmsService;
use Commerce\Modules\Notification\Application\SmsSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** SMS to customers: the gateway, the automatic messages with their texts, a test message and the manual message from an order. */
final class SmsAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly SmsSettings $settings,
        private readonly SmsService $sms,
        private readonly Connection $db,
    ) {
    }

    #[Route('/admin/commerce/sms', name: 'admin_commerce_sms', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        if ($request->isMethod('POST')) {
            $this->guard($request);
            $in = $request->request;
            $input = [
                'enabled' => $in->getBoolean('enabled'),
                'endpoint' => (string) $in->get('endpoint', ''),
                'token' => (string) $in->get('token', ''),
                'sender' => (string) $in->get('sender', ''),
                'flash_supported' => $in->getBoolean('flash_supported'),
            ];
            foreach (SmsSettings::EVENTS as $event) {
                $input['auto_' . $event] = $in->getBoolean('auto_' . $event);
                $input['tpl_' . $event] = (string) $in->get('tpl_' . $event, '');
            }
            try {
                $this->settings->save($storeId, $input);
                $this->addFlash('success', CanonicalUiText::get('admin.sms.saved'));
            } catch (\InvalidArgumentException) {
                $this->addFlash('error', CanonicalUiText::get('admin.sms.invalid'));
            }

            return $this->redirectToRoute('admin_commerce_sms');
        }
        $s = $this->settings->get($storeId);
        unset($s['token']);

        return $this->render('@storefront/admin/commerce/sms.html.twig', [
            's' => $s,
            'env_enabled' => !$s['configured'] && $this->sms->enabled($storeId),
            'log' => $this->sms->log($storeId, null, 20),
            'events' => SmsSettings::EVENTS,
        ]);
    }

    #[Route('/admin/commerce/sms/test', name: 'admin_commerce_sms_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $this->guard($request);
        $storeId = $this->contexts->resolve($request)->storeId;
        $result = $this->sms->sendTest($storeId, (string) $request->request->get('phone', ''), (string) $request->request->get('text', ''), $this->adminId());
        $this->flashResult($result, 'admin.sms.test_ok');

        return $this->redirectToRoute('admin_commerce_sms');
    }

    #[Route('/admin/orders/{publicId}/sms', name: 'admin_order_sms', methods: ['POST'])]
    public function orderSms(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_order_' . $publicId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $storeId = $this->contexts->resolve($request)->storeId;
        try {
            $orderId = $this->db->fetchOne('SELECT id FROM mc_sales_order WHERE public_id=? AND store_id=?', [Uuid::fromString($publicId)->toBinary(), $storeId]);
        } catch (\Throwable) {
            $orderId = false;
        }
        if ($orderId === false) {
            throw $this->createNotFoundException();
        }
        $result = $this->sms->sendManual($storeId, (int) $orderId, (string) $request->request->get('phone', ''), (string) $request->request->get('text', ''), $request->request->getBoolean('flash'), $this->adminId());
        $this->flashResult($result, 'admin.sms.order_sent');

        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    /** @param array{ok:bool,error:string} $result */
    private function flashResult(array $result, string $okKey): void
    {
        if ($result['ok']) {
            $this->addFlash('success', CanonicalUiText::get($okKey));

            return;
        }
        $known = ['phone' => 'admin.sms.err_phone', 'text' => 'admin.sms.err_text', 'disabled' => 'admin.sms.err_disabled'];
        $this->addFlash('error', isset($known[$result['error']]) ? CanonicalUiText::get($known[$result['error']]) : CanonicalUiText::get('admin.sms.test_fail', ['error' => $result['error']]));
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_sms', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }

    private function adminId(): ?int
    {
        $user = $this->getUser();

        return $user instanceof AdminUser ? $user->id : null;
    }
}
