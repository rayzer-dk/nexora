<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class CustomerProfileService
{
    public function __construct(private Connection $db)
    {
    }

    public function update(int $customerId, string $displayName, string $phone, string $locale): void
    {
        $displayName = trim($displayName);
        $phone = preg_replace('/[\s().-]+/u', '', trim($phone)) ?? '';
        $locale = trim($locale);

        if ($displayName === '' || mb_strlen($displayName) > 190) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.vkazhit_imia'));
        }
        if ($phone !== '' && preg_match('/^\+[1-9][0-9]{6,14}$/', $phone) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerprofileservice.telefon_maie_buty_u_mizhnarodnomu_formati_napryklad_'));
        }
        if ($locale === '' || strlen($locale) > 16 || preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/', $locale) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerprofileservice.nekorektna_mova_profiliu'));
        }

        $updated = $this->db->update('mc_customer', [
            'display_name' => $displayName,
            'phone_e164' => $phone !== '' ? $phone : null,
            'locale' => str_replace('_', '-', $locale),
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ], ['id' => $customerId, 'status' => 'active']);

        if ($updated !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerprofileservice.oblikovyi_zapys_bilshe_ne_aktyvnyi'));
        }
    }
}
