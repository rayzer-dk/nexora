<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** One customer: contacts, group, spending, orders, subscription, bonuses, notes of the team. */
final class CustomerCardAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly Connection $db) {}

    #[Route('/admin/commerce/customers/{id}', name: 'admin_commerce_customer_view', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function view(int $id, Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $customer = $this->db->fetchAssociative('SELECT id,public_id,email,email_normalized,phone_e164,display_name,locale,status,customer_group_code,email_verified_at,phone_verified_at,created_at,last_seen_at FROM mc_customer WHERE id=?', [$id]);
        if (!is_array($customer)) {
            throw $this->createNotFoundException();
        }
        $orders = $this->db->fetchAllAssociative("SELECT public_id,order_number,status,payment_status,fulfillment_status,currency,total_minor,created_at FROM mc_sales_order WHERE customer_id=? ORDER BY id DESC LIMIT 100", [$id]);
        foreach ($orders as &$o) {
            $o['public_id'] = Uuid::fromBinary((string) $o['public_id'])->toRfc4122();
        }
        unset($o);
        $valid = array_filter($orders, static fn (array $o): bool => !in_array($o['status'], ['cancelled', 'expired'], true));
        $spent = array_sum(array_map(static fn (array $o): int => (int) $o['total_minor'], $valid));
        $stats = [
            'orders' => count($valid), 'spent_minor' => $spent, 'avg_minor' => $valid !== [] ? intdiv($spent, count($valid)) : 0,
            'currency' => (string) ($orders[0]['currency'] ?? $ctx->currency), 'last_order' => $orders[0]['created_at'] ?? null,
            'first_order' => $orders !== [] ? end($orders)['created_at'] : null,
        ];
        $subscription = $this->db->fetchAssociative('SELECT status,confirmed_at,unsubscribed_at,consent_source FROM mc_marketing_subscriber WHERE store_id=? AND email_normalized=? LIMIT 1', [$ctx->storeId, $customer['email_normalized']]) ?: null;
        try {
            $loyalty = $this->db->fetchAssociative('SELECT points_balance,lifetime_earned,lifetime_spent FROM mc_loyalty_account WHERE store_id=? AND customer_id=?', [$ctx->storeId, $id]) ?: null;
        } catch (\Throwable) {
            $loyalty = null;
        }
        $counts = [
            'wishlist' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_customer_wishlist WHERE customer_id=?', [$id]),
            'reviews' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_review WHERE customer_id=?', [$id]),
            'inquiries' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_customer_inquiry WHERE customer_id=?', [$id]),
        ];
        $notes = $this->db->fetchAllAssociative('SELECT id,author,body,created_at FROM mc_customer_note WHERE customer_id=? ORDER BY id DESC LIMIT 100', [$id]);
        $groups = array_values(array_unique(array_merge(['default', 'vip', 'wholesale'], array_map('strval', $this->db->fetchFirstColumn("SELECT DISTINCT customer_group_code FROM mc_customer WHERE customer_group_code IS NOT NULL AND customer_group_code<>''")))));

        return $this->render('@storefront/admin/commerce/customer_view.html.twig', ['customer' => $customer, 'orders' => $orders, 'stats' => $stats, 'subscription' => $subscription, 'loyalty' => $loyalty, 'counts' => $counts, 'notes' => $notes, 'groups' => $groups]);
    }

    #[Route('/admin/commerce/customers/{id}/note', name: 'admin_commerce_customer_note', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function note(int $id, Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('customer_note_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $body = trim((string) $request->request->get('body'));
        if ($body !== '' && $this->db->fetchOne('SELECT 1 FROM mc_customer WHERE id=?', [$id]) !== false) {
            $user = $this->getUser();
            $this->db->insert('mc_customer_note', ['store_id' => $ctx->storeId, 'customer_id' => $id, 'author' => $user instanceof AdminUser ? mb_substr((string) $user->getUserIdentifier(), 0, 190) : null, 'body' => mb_substr($body, 0, 4000), 'created_at' => gmdate('Y-m-d H:i:s.u')]);
        }

        return $this->redirectToRoute('admin_commerce_customer_view', ['id' => $id]);
    }

    #[Route('/admin/commerce/customers/{id}/note/{noteId}/delete', name: 'admin_commerce_customer_note_remove', requirements: ['id' => '\d+', 'noteId' => '\d+'], methods: ['POST'])]
    public function removeNote(int $id, int $noteId, Request $request): Response
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('customer_note_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->db->delete('mc_customer_note', ['id' => $noteId, 'customer_id' => $id]);

        return $this->redirectToRoute('admin_commerce_customer_view', ['id' => $id]);
    }

    #[Route('/admin/commerce/customers/{id}/status', name: 'admin_commerce_customer_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function status(int $id, Request $request): Response
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('customer_status_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $status = (string) $request->request->get('status');
        if (in_array($status, ['active', 'blocked'], true)) {
            $this->db->update('mc_customer', ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s.u')], ['id' => $id]);
        }

        return $this->redirectToRoute('admin_commerce_customer_view', ['id' => $id]);
    }
}
