<?php

declare(strict_types=1);

namespace Commerce\Core\Store;

use Commerce\Core\Configuration\ConfigurationRevisionStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class StoreIdentitySettings
{
    public function __construct(
        private Connection $connection,
        private ConfigurationRevisionStore $revisions,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(int $storeId): array
    {
        try {
            $store = $this->connection->fetchAssociative(
                'SELECT id,name,default_locale,default_currency,timezone,status FROM mc_store WHERE id=? LIMIT 1',
                [$storeId],
            );
            if (!is_array($store)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2d5107e22d3c'));
            }
            $profile = $this->connection->fetchAssociative(
                'SELECT legal_name,registration_number,tax_number,vat_number,country_code,registration_address,iban,bank_name,email,phone,privacy_contact,return_contact,warranty_contact FROM mc_store_profile WHERE store_id=? LIMIT 1',
                [$storeId],
            ) ?: [];

            $domains = [];
            try {
                $domains = array_map('strval', $this->connection->fetchFirstColumn("SELECT host FROM mc_store_domain WHERE store_id=? AND status='active' ORDER BY is_primary DESC,id ASC", [$storeId]));
            } catch (\Throwable) {
            }
            return $this->normalize(array_merge($store, $profile, ['domains' => $domains]));
        } catch (\Throwable) {
            return $this->defaults();
        }
    }

    /** @param array<string,mixed> $input */
    public function save(int $storeId, array $input, ?string $actorSubject = null): int
    {
        $clean = $this->normalize($input);
        $this->assertReferences($clean);

        return $this->connection->transactional(function (Connection $db) use ($storeId, $clean, $actorSubject): int {
            $exists = $db->fetchOne('SELECT id FROM mc_store WHERE id=? FOR UPDATE', [$storeId]);
            if ($exists === false) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2d5107e22d3c'));
            }

            $now = $this->now();
            $db->update('mc_store', [
                'name' => $clean['name'],
                'default_locale' => $clean['default_locale'],
                'default_currency' => $clean['default_currency'],
                'timezone' => $clean['timezone'],
                'updated_at' => $now,
            ], ['id' => $storeId]);

            $db->executeStatement(
                'INSERT INTO mc_store_profile (store_id,legal_name,registration_number,tax_number,vat_number,country_code,registration_address,iban,bank_name,email,phone,privacy_contact,return_contact,warranty_contact,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE legal_name=VALUES(legal_name),registration_number=VALUES(registration_number),tax_number=VALUES(tax_number),vat_number=VALUES(vat_number),country_code=VALUES(country_code),registration_address=VALUES(registration_address),iban=VALUES(iban),bank_name=VALUES(bank_name),email=VALUES(email),phone=VALUES(phone),privacy_contact=VALUES(privacy_contact),return_contact=VALUES(return_contact),warranty_contact=VALUES(warranty_contact),updated_at=VALUES(updated_at)',
                [
                    $storeId,
                    $clean['legal_name'] ?: null,
                    $clean['registration_number'] ?: null,
                    $clean['tax_number'] ?: null,
                    $clean['vat_number'] ?: null,
                    $clean['country_code'],
                    $clean['registration_address'] ?: null,
                    $clean['iban'] ?: null,
                    $clean['bank_name'] ?: null,
                    $clean['email'] ?: null,
                    $clean['phone'] ?: null,
                    $clean['privacy_contact'] ?: null,
                    $clean['return_contact'] ?: null,
                    $clean['warranty_contact'] ?: null,
                    $now,
                ],
            );

            try {
                $db->delete('mc_store_domain', ['store_id' => $storeId]);
                foreach ($clean['domains'] as $index => $host) {
                    $db->insert('mc_store_domain', [
                        'store_id' => $storeId,
                        'host' => $host,
                        'is_primary' => $index === 0 ? 1 : 0,
                        'redirect_to_primary' => $index === 0 ? 0 : 1,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            } catch (\Doctrine\DBAL\Exception $e) {
                if (!str_contains(strtolower($e->getMessage()), 'mc_store_domain')) {
                    throw $e;
                }
            }

            $db->executeStatement(
                'INSERT INTO mc_store_locale (store_id,locale_code,enabled,is_default,sort_order) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),is_default=VALUES(is_default)',
                [$storeId, $clean['default_locale'], 1, 1, 0],
            );
            $db->executeStatement('UPDATE mc_store_locale SET is_default=0 WHERE store_id=? AND locale_code<>?', [$storeId, $clean['default_locale']]);
            $db->executeStatement(
                'INSERT INTO mc_store_currency (store_id,currency_code,enabled,is_default,auto_convert,rounding_increment_minor,sort_order) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),is_default=VALUES(is_default)',
                [$storeId, $clean['default_currency'], 1, 1, 0, 1, 0],
            );
            $db->executeStatement('UPDATE mc_store_currency SET is_default=0 WHERE store_id=? AND currency_code<>?', [$storeId, $clean['default_currency']]);

            return $this->revisions->activateStoreJson($storeId, 'store', 'identity', $clean, $actorSubject);
        });
    }

    /** @return list<array{id:int,public_id:string,revision_number:int,status:string,actor_subject:?string,created_at:string,activated_at:?string}> */
    public function history(int $storeId, int $limit = 20): array
    {
        return $this->revisions->history($storeId, 'store', 'identity', $limit);
    }

    public function rollback(int $storeId, int $revisionId, ?string $actorSubject = null): int
    {
        $payload = $this->revisions->payload($storeId, $revisionId, 'store', 'identity');
        return $this->save($storeId, $payload, $actorSubject);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalize(array $input): array
    {
        $defaults = $this->defaults();
        $name = $this->text($input['name'] ?? $defaults['name'], 190);
        if ($name === '') {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8dcc774799fd'));
        }

        $locale = trim((string) ($input['default_locale'] ?? $defaults['default_locale']));
        if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.14457ccf76df'));
        }
        $currency = strtoupper(trim((string) ($input['default_currency'] ?? $defaults['default_currency'])));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.86a26b53d652'));
        }
        $timezone = trim((string) ($input['timezone'] ?? $defaults['timezone']));
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a3a7e4680ab4'));
        }
        $country = strtoupper(trim((string) ($input['country_code'] ?? $defaults['country_code'])));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.38a9b9b2af02'));
        }

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d8c480f038af'));
        }
        $privacy = strtolower(trim((string) ($input['privacy_contact'] ?? '')));
        $returns = strtolower(trim((string) ($input['return_contact'] ?? '')));
        $warranty = strtolower(trim((string) ($input['warranty_contact'] ?? '')));
        foreach (['privacy_contact' => $privacy, 'return_contact' => $returns, 'warranty_contact' => $warranty] as $label => $value) {
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a9274f377008') . $label . '.');
            }
        }

        $domainInput = $input['domains'] ?? [];
        if (is_string($domainInput)) {
            $domainInput = preg_split('/[\r\n,;]+/', $domainInput) ?: [];
        }
        $domains = [];
        foreach ((array) $domainInput as $domain) {
            $domain = strtolower(rtrim(trim((string) $domain), '.'));
            $domain = preg_replace('#^https?://#i', '', $domain) ?? '';
            $domain = explode('/', $domain, 2)[0];
            $domain = preg_replace('/:\d+$/', '', $domain) ?? '';
            if ($domain === '') { continue; }
            if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false && $domain !== 'localhost') {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.912f1c110161') . $domain . '.');
            }
            $domains[$domain] = $domain;
        }

        return [
            'name' => $name,
            'default_locale' => $locale,
            'default_currency' => $currency,
            'timezone' => $timezone,
            'legal_name' => $this->text($input['legal_name'] ?? '', 255),
            'registration_number' => $this->text($input['registration_number'] ?? '', 128),
            'tax_number' => $this->text($input['tax_number'] ?? '', 128),
            'vat_number' => $this->text($input['vat_number'] ?? '', 128),
            'country_code' => $country,
            'registration_address' => $this->text($input['registration_address'] ?? '', 1000),
            'iban' => strtoupper(preg_replace('/\s+/', '', $this->text($input['iban'] ?? '', 64)) ?? ''),
            'bank_name' => $this->text($input['bank_name'] ?? '', 255),
            'email' => $email,
            'phone' => $this->text($input['phone'] ?? '', 64),
            'privacy_contact' => $privacy,
            'return_contact' => $returns,
            'warranty_contact' => $warranty,
            'domains' => array_values($domains),
        ];
    }

    /** @param array<string,mixed> $clean */
    private function assertReferences(array $clean): void
    {
        try {
            $localeExists = (bool) $this->connection->fetchOne('SELECT 1 FROM mc_locale WHERE code=? AND enabled=1 LIMIT 1', [$clean['default_locale']]);
        } catch (\Throwable) {
            $localeExists = true; // Legacy installs may not have localization tables yet; migration/health checks handle that separately.
        }
        if (!$localeExists) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5609ef03856e'));
        }

        try {
            $currencyExists = (bool) $this->connection->fetchOne('SELECT 1 FROM mc_currency WHERE code=? AND enabled=1 LIMIT 1', [$clean['default_currency']]);
        } catch (\Throwable) {
            $currencyExists = true;
        }
        if (!$currencyExists) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bbdb6bc1f60f'));
        }
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'name' => 'Nexora Commerce',
            'default_locale' => 'uk-UA',
            'default_currency' => 'UAH',
            'timezone' => 'Europe/Kyiv',
            'legal_name' => '',
            'registration_number' => '',
            'tax_number' => '',
            'vat_number' => '',
            'country_code' => 'UA',
            'registration_address' => '',
            'iban' => '',
            'bank_name' => '',
            'email' => '',
            'phone' => '',
            'privacy_contact' => '',
            'return_contact' => '',
            'warranty_contact' => '',
            'domains' => [],
        ];
    }

    private function text(mixed $value, int $max): string
    {
        $value = trim(strip_tags((string) $value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
