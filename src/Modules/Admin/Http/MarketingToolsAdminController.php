<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Marketing\Application\CampaignTemplateService;
use Commerce\Modules\Marketing\Application\NewsletterCampaignService;
use Commerce\Modules\Notification\Channel\Email\EmailNotificationSender;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly CampaignTemplateService $templates,
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

    #[Route('/admin/commerce/campaigns/templates/{id}.json', name: 'admin_commerce_campaign_template_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function templateShow(Request $request, int $id): Response
    {
        $row = $this->templates->find($this->contexts->resolve($request)->storeId, $id);

        return $row === null ? new JsonResponse(['ok' => false], Response::HTTP_NOT_FOUND) : new JsonResponse(['ok' => true] + $row);
    }

    #[Route('/admin/commerce/campaigns/templates', name: 'admin_commerce_campaign_template_save', methods: ['POST'])]
    public function templateSave(Request $request): Response
    {
        $this->guardCampaign($request);
        try {
            $saved = $this->templates->save($this->contexts->resolve($request)->storeId, (string) $request->request->get('template_name', ''), (string) $request->request->get('subject', ''), (string) $request->request->get('body', ''), (string) $request->request->get('format', 'text'));

            return new JsonResponse(['ok' => true, 'message' => CanonicalUiText::get('admin.campaigns.template_saved', ['name' => $saved['name']])] + $saved);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['ok' => false, 'message' => CanonicalUiText::get('admin.campaigns.template_invalid')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/admin/commerce/campaigns/templates/{id}/delete', name: 'admin_commerce_campaign_template_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function templateDelete(Request $request, int $id): Response
    {
        $this->guardCampaign($request);
        $done = $this->templates->delete($this->contexts->resolve($request)->storeId, $id);

        return new JsonResponse(['ok' => $done, 'message' => CanonicalUiText::get($done ? 'admin.campaigns.template_deleted' : 'admin.campaigns.template_missing')], $done ? 200 : Response::HTTP_NOT_FOUND);
    }

    /** The e-mail as it will look, rendered from the text typed in the form (nothing is sent or stored). */
    #[Route('/admin/commerce/campaigns/preview', name: 'admin_commerce_campaign_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->guardCampaign($request);
        $format = (string) $request->request->get('format', 'text');
        if (!in_array($format, NewsletterCampaignService::FORMATS, true)) {
            $format = 'text';
        }
        $response = $this->render('@storefront/email/' . ($format === 'html_raw' ? 'campaign_raw' : 'campaign') . '.html.twig', [
            'notification_subject' => trim((string) $request->request->get('subject', '')),
            'notification_text' => $this->campaigns->cleanBody((string) $request->request->get('body', ''), $format),
            'body_html' => $format !== 'text',
            'unsubscribe_url' => '#',
            'footer_text' => CanonicalUiText::get('php.modules.marketing.application.newslettercampaignqueueprocessor.vy_otrymaly_tsei_lyst_tomu_shcho_pidtverdyly_markety'),
            'locale' => $request->getLocale(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/admin/commerce/campaigns/{id}.json', name: 'admin_commerce_campaign_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function campaignShow(Request $request, int $id): Response
    {
        $row = $this->db->fetchAssociative('SELECT subject,body_text,body_format FROM mc_marketing_campaign WHERE store_id=? AND id=?', [$this->contexts->resolve($request)->storeId, $id]);

        return is_array($row) ? new JsonResponse(['ok' => true, 'subject' => (string) $row['subject'], 'body' => (string) $row['body_text'], 'body_format' => (string) $row['body_format']]) : new JsonResponse(['ok' => false], Response::HTTP_NOT_FOUND);
    }

    /** A finished or failed campaign leaves the history; one that is still being queued stays. */
    #[Route('/admin/commerce/campaigns/{id}/delete', name: 'admin_commerce_campaign_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function campaignDelete(Request $request, int $id): Response
    {
        $this->guardCampaign($request);
        $done = $this->db->executeStatement("DELETE FROM mc_marketing_campaign WHERE store_id=? AND id=? AND status NOT IN ('queued','enqueuing')", [$this->contexts->resolve($request)->storeId, $id]) > 0;

        return new JsonResponse(['ok' => $done, 'message' => CanonicalUiText::get($done ? 'admin.campaigns.deleted' : 'admin.campaigns.delete_busy')], $done ? 200 : Response::HTTP_CONFLICT);
    }

    #[Route('/admin/commerce/subscribers', name: 'admin_commerce_subscribers', methods: ['GET'])]
    public function subscriberList(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $search = trim((string) $request->query->get('search', ''));
        $status = (string) $request->query->get('status', '');
        $status = in_array($status, ['active', 'pending', 'unsubscribed'], true) ? $status : '';
        $page = max(1, (int) $request->query->get('page', 1));
        $where = ['store_id=?'];
        $params = [$storeId];
        if ($search !== '') {
            $where[] = 'email_normalized LIKE ?';
            $params[] = '%' . mb_strtolower($search) . '%';
        }
        if ($status !== '') {
            $where[] = 'status=?';
            $params[] = $status;
        }
        $sql = implode(' AND ', $where);
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_marketing_subscriber WHERE ' . $sql, $params);
        $pages = max(1, (int) ceil($count / 50));
        $page = min($page, $pages);
        $rows = $this->db->fetchAllAssociative('SELECT id,email,locale,status,consent_source,confirmed_at,unsubscribed_at,created_at FROM mc_marketing_subscriber WHERE ' . $sql . ' ORDER BY id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50), $params);
        $totals = $this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM mc_marketing_subscriber WHERE store_id=? GROUP BY status', [$storeId]);

        return $this->render('@storefront/admin/commerce/subscribers.html.twig', [
            'rows' => $rows, 'search' => $search, 'status' => $status, 'page' => $page, 'pages' => $pages, 'count' => $count,
            'totals' => array_map('intval', $totals),
        ]);
    }

    #[Route('/admin/commerce/subscribers/{id}/delete', name: 'admin_commerce_subscriber_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function subscriberDelete(Request $request, int $id): Response
    {
        $this->guardCampaign($request);
        $done = $this->db->delete('mc_marketing_subscriber', ['store_id' => $this->contexts->resolve($request)->storeId, 'id' => $id]) > 0;

        return new JsonResponse(['ok' => $done, 'message' => CanonicalUiText::get($done ? 'admin.subscribers.deleted' : 'admin.campaigns.template_missing')], $done ? 200 : Response::HTTP_NOT_FOUND);
    }

    private function guardCampaign(Request $request): void
    {
        if (!$this->isCsrfTokenValid('campaign_send', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
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
