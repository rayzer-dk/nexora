<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Application;

use Doctrine\DBAL\Connection;

/**
 * Remembers the contact details a shopper typed into the checkout before leaving, so an abandoned cart
 * is not anonymous: the shop can see who it was and write to them.
 */
final readonly class CheckoutLeadService
{
    public function __construct(private Connection $db) {}

    /** @return bool true when something worth keeping was stored */
    public function capture(int $storeId, int $cartId, string $name, string $email, string $phone, string $locale): bool
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 190);
        $email = mb_strtolower(trim($email));
        if ($email !== '' && (mb_strlen($email) > 320 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $email = '';
        }
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
        $phone = strlen(preg_replace('/\D/', '', $phone) ?? '') >= 7 ? mb_substr($phone, 0, 32) : '';
        if ($email === '' && $phone === '') {
            return false;
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->executeStatement(
            'INSERT INTO mc_checkout_lead (store_id,cart_id,customer_name,email,phone,locale,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE customer_name=COALESCE(NULLIF(VALUES(customer_name),\'\'),customer_name),email=COALESCE(NULLIF(VALUES(email),\'\'),email),phone=COALESCE(NULLIF(VALUES(phone),\'\'),phone),locale=VALUES(locale),updated_at=VALUES(updated_at)',
            [$storeId, $cartId, $name !== '' ? $name : null, $email !== '' ? $email : null, $phone !== '' ? $phone : null, mb_substr($locale, 0, 16), $now, $now],
        );

        return true;
    }
}
