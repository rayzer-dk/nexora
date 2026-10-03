<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Marketing\Application\NewsletterCampaignService;
use Commerce\Modules\Notification\Channel\Email\EmailNotificationSender;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Customer and subscriber lists as CSV, and a one-click test of a campaign before it goes to everybody. */
final class MarketingToolsAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly Connection $db,
        private readonly NewsletterCampaignService $campaigns,
        private readonly EmailNotificationSender $mail,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/commerce/customers/export.csv', name: 'admin_commerce_customers_export', methods: ['GET'])]
    public function customers(Request $request): Response
    {
        $this->contexts->resolve($request);
        $rows = $this->db->iterateAssociative(
            "SELECT c.public_id, c.display_name, c.email, c.phone_e164, c.status, c.customer_group_code, c.locale, c.created_at, c.last_seen_at,
                    (SELECT COUNT(*) FROM mc_sales_order o WHERE o.customer_email_normalized = c.email_normalized AND o.status NOT IN ('cancelled','expired')) AS orders
             FROM mc_customer c ORDER BY c.id"
        );

        return $this->csv('customers', ['id', 'name', 'email', 'phone', 'status', 'group', 'language', 'orders', 'registered_at', 'last_seen_at'], static function () use ($rows): \Generator {
            foreach ($rows as $r) {
                yield [Uuid::fromBinary((string) $r['public_id'])->toRfc4122(), $r['display_name'], $r['email'], $r['phone_e164'], $r['status'], $r['customer_group_code'], $r['locale'], $r['orders'], $r['created_at'], $r['last_seen_at']];
            }
        });
    }

    #[Route('/admin/commerce/subscribers/export.csv', name: 'admin_commerce_subscribers_export', methods: ['GET'])]
    public function subscribers(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $rows = $this->db->iterateAssociative(
            'SELECT email, locale, status, consent_source, consent_at, confirmed_at, unsubscribed_at FROM mc_marketing_subscriber WHERE store_id = ? ORDER BY id',
            [$storeId]
        );

        return $this->csv('subscribers', ['email', 'language', 'status', 'source', 'consent_at', 'confirmed_at', 'unsubscribed_at'], static function () use ($rows): \Generator {
            foreach ($rows as $r) {
                yield [$r['email'], $r['locale'], $r['status'], $r['consent_source'], $r['consent_at'], $r['confirmed_at'], $r['unsubscribed_at']];
            }
        });
    }

    #[Route('/admin/commerce/campaigns/test', name: 'admin_commerce_campaign_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('campaign_send', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $to = trim((string) $request->request->get('test_to', ''));
        $format = (string) $request->request->get('format', 'text');
        $subject = trim((string) $request->request->get('subject', ''));
        $body = (string) $request->request->get('body', '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '' || trim($body) === '') {
            $this->addFlash('error', CanonicalUiText::get('admin.campaigns.test_incomplete'));

            return $this->redirectToRoute('admin_commerce_campaigns');
        }
        if (!in_array($format, NewsletterCampaignService::FORMATS, true)) {
            $format = 'text';
        }
        try {
            $this->mail->send(new NotificationMessage(
                'marketing.campaign',
                '[' . CanonicalUiText::get('admin.campaigns.test_prefix') . '] ' . $subject,
                $this->campaigns->cleanBody($body, $format),
                ['unsubscribe_url' => $request->getSchemeAndHttpHost() . '/newsletter/unsubscribe', 'body_html' => $format !== 'text'],
                $format === 'html_raw' ? 'campaign_raw' : 'campaign',
            ), $to);
            $this->addFlash('success', CanonicalUiText::get('admin.campaigns.test_sent', ['to' => $to]));
        } catch (TransportExceptionInterface | \Symfony\Component\Mime\Exception\ExceptionInterface | \LogicException $e) {
            $this->logger->warning('Campaign test e-mail failed', ['exception' => $e]);
            $this->addFlash('error', CanonicalUiText::get('admin.notify_channels.failed', ['error' => mb_substr(strtok($e->getMessage(), "\n") ?: '', 0, 300)]));
        }

        return $this->redirectToRoute('admin_commerce_campaigns');
    }

    /** @param list<string> $header @param callable():\Generator<int,list<mixed>> $rows */
    private function csv(string $name, array $header, callable $rows): Response
    {
        $response = new StreamedResponse(static function () use ($header, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ',', '"', '');
            foreach ($rows() as $row) {
                // A cell that starts with = + - @ would run as a formula in a spreadsheet.
                fputcsv($out, array_map(static fn ($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v, $row), ',', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '-' . gmdate('Ymd-His') . '.csv"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
