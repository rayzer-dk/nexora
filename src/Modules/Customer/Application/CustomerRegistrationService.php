<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Customer\Domain\CustomerUser;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CustomerRegistrationService
{
    public function __construct(
        private Connection $db,
        private PublicIdFactory $ids,
        private UserPasswordHasherInterface $passwords,
        private CustomerStoreMembershipService $memberships,
        private EventBusInterface $events,
        private DomainEventFactory $eventFactory,
    ) {
    }

    /** @return array{id:int,public_id:string,email:string,display_name:string} */
    public function register(int $storeId, string $email, string $displayName, string $password, string $locale): array
    {
        $email = mb_strtolower(trim($email));
        $displayName = trim($displayName);
        $locale = trim($locale) !== '' ? trim($locale) : 'uk-UA';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 320) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.vkazhit_korektnu_email_adresu'));
        }
        if ($displayName === '' || mb_strlen($displayName) > 190) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.vkazhit_imia'));
        }
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.parol_maie_mistyty_shchonaimenshe_12_symvoliv'));
        }

        $publicId = $this->ids->generate();
        $candidate = new CustomerUser(0, $publicId->toRfc4122(), $email, '', $displayName, 'active');
        $passwordHash = $this->passwords->hashPassword($candidate, $password);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        try {
            $result = $this->db->transactional(function (Connection $db) use ($storeId, $publicId, $email, $displayName, $locale, $passwordHash, $now): array {
                $db->insert('mc_customer', [
                    'public_id' => $publicId->toBinary(),
                    'email' => $email,
                    'email_normalized' => $email,
                    'phone_e164' => null,
                    'display_name' => $displayName,
                    'locale' => mb_substr($locale, 0, 16),
                    'password_hash' => $passwordHash,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_seen_at' => null,
                ]);
                $customerId = (int) $db->lastInsertId();
                $this->memberships->ensure($storeId, $customerId);
                $this->events->publish($this->eventFactory->create(
                    EventNames::CUSTOMER_REGISTERED,
                    'customer',
                    $publicId->toRfc4122(),
                    ['store_id' => $storeId, 'locale' => mb_substr($locale, 0, 16)],
                    ['source' => 'customer_account'],
                ));
                return [
                    'id' => $customerId,
                    'public_id' => $publicId->toRfc4122(),
                    'email' => $email,
                    'display_name' => $displayName,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // Do not reveal whether an account exists. This avoids a trivial account-enumeration oracle.
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.ne_vdalosia_stvoryty_oblikovyi_zapys_z_tsymy_danymy'));
        }

        return $result;
    }
}
