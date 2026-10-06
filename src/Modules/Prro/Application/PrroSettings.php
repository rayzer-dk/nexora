<?php

declare(strict_types=1);

namespace Commerce\Modules\Prro\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Core\Security\SecretVault;

/** Settings of the cash register (PRRO): provider account, when to issue a receipt, what to leave out. Password and license key are encrypted at rest. */
final readonly class PrroSettings
{
    public const KEY = 'prro.settings';
    public const AUTO = ['off', 'paid'];
    public const ENVIRONMENTS = ['test', 'prod'];
    /** Payment methods that never get a receipt unless the owner changes it (money goes to a bank account). */
    public const DEFAULT_SKIP = ['bank_transfer', 'b2b_invoice'];
    private const CTX_PASSWORD = 'prro.password';
    private const CTX_LICENSE = 'prro.license';

    public function __construct(private SystemSettingStore $store, private SecretVault $vault)
    {
    }

    /** @return array{enabled:bool,provider:string,environment:string,login:string,password:string,license:string,auto:string,send_email:bool,skip:list<string>,configured:bool} */
    public function get(): array
    {
        $raw = $this->store->getArray(self::KEY) ?? [];
        $password = $this->decrypt((string) ($raw['password_enc'] ?? ''), self::CTX_PASSWORD);
        $license = $this->decrypt((string) ($raw['license_enc'] ?? ''), self::CTX_LICENSE);
        $login = trim((string) ($raw['login'] ?? ''));

        return [
            'enabled' => !empty($raw['enabled']),
            'provider' => 'checkbox',
            'environment' => in_array($raw['environment'] ?? '', self::ENVIRONMENTS, true) ? (string) $raw['environment'] : 'test',
            'login' => $login,
            'password' => $password,
            'license' => $license,
            'auto' => in_array($raw['auto'] ?? '', self::AUTO, true) ? (string) $raw['auto'] : 'off',
            'send_email' => !empty($raw['send_email']),
            'skip' => is_array($raw['skip'] ?? null) ? array_values(array_map('strval', $raw['skip'])) : self::DEFAULT_SKIP,
            'configured' => $login !== '' && $password !== '' && $license !== '',
        ];
    }

    /** @param array<string,mixed> $in */
    public function save(array $in): void
    {
        $current = $this->get();
        $login = trim((string) ($in['login'] ?? ''));
        if (mb_strlen($login) > 190) {
            throw new \InvalidArgumentException('login');
        }
        $password = trim((string) ($in['password'] ?? '')) !== '' ? trim((string) $in['password']) : $current['password'];
        $license = trim((string) ($in['license'] ?? '')) !== '' ? trim((string) $in['license']) : $current['license'];
        if (strlen($password) > 500 || strlen($license) > 500) {
            throw new \InvalidArgumentException('secret');
        }
        $skip = [];
        foreach (['bank_transfer', 'b2b_invoice', 'cash_on_delivery'] as $code) {
            if (!empty($in['skip_' . $code])) {
                $skip[] = $code;
            }
        }
        $this->store->setArray(self::KEY, [
            'enabled' => !empty($in['enabled']),
            'environment' => in_array($in['environment'] ?? '', self::ENVIRONMENTS, true) ? (string) $in['environment'] : 'test',
            'login' => $login,
            'password_enc' => $password !== '' ? $this->vault->encrypt($password, self::CTX_PASSWORD) : '',
            'license_enc' => $license !== '' ? $this->vault->encrypt($license, self::CTX_LICENSE) : '',
            'auto' => in_array($in['auto'] ?? '', self::AUTO, true) ? (string) $in['auto'] : 'off',
            'send_email' => !empty($in['send_email']),
            'skip' => $skip,
        ]);
    }

    private function decrypt(string $enc, string $ctx): string
    {
        if ($enc === '') {
            return '';
        }
        try {
            return $this->vault->decrypt($enc, $ctx);
        } catch (\Throwable) {
            return '';
        }
    }
}
