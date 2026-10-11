<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

/**
 * Carrier keys that can be typed in the admin. They are kept in var/config/carrier-secrets.json (outside the web root);
 * bootstrap/carrier_secrets.php puts them into the environment before the kernel boots, so they win over the .env file.
 * Values are never sent back to the page: it only learns which names are set.
 */
final class CarrierSecretStore
{
    /** name => true when the value is a secret (shown as a password field). */
    public const FIELDS = [
        'nova_post' => ['NOVA_POST_API_KEY' => true, 'NOVA_POST_SENDER_REF' => false, 'NOVA_POST_CONTACT_SENDER_REF' => false, 'NOVA_POST_CITY_SENDER_REF' => false, 'NOVA_POST_SENDER_ADDRESS_REF' => false, 'NOVA_POST_SENDER_PHONE' => false],
        'ukrposhta' => ['UKRPOSHTA_BEARER' => true],
        'delivery_auto' => ['DELIVERY_AUTO_API_BASE' => false],
        'dhl' => ['DHL_API_KEY' => true],
        'gls' => ['GLS_API_BASE' => false, 'GLS_API_TOKEN' => true, 'GLS_POINTS_PATH' => false],
        'meest' => ['MEEST_API_BASE' => false, 'MEEST_API_TOKEN' => true, 'MEEST_CITIES_PATH' => false, 'MEEST_POINTS_PATH' => false],
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    private function file(): string
    {
        return $this->projectDir . '/var/config/carrier-secrets.json';
    }

    /** @return array<string,string> */
    private function read(): array
    {
        $data = is_file($this->file()) ? json_decode((string) file_get_contents($this->file()), true) : null;

        return is_array($data) ? array_filter($data, static fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH) : [];
    }

    /** True when the name has a value typed in the admin (the .env value is checked by the caller). */
    public function isStored(string $name): bool
    {
        return ($this->read()[$name] ?? '') !== '';
    }

    /** Whether any name in $names is allowed to be stored. */
    public static function allowed(string $name): bool
    {
        foreach (self::FIELDS as $fields) {
            if (array_key_exists($name, $fields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An empty value keeps what is stored; a name listed in $clear is removed.
     *
     * @param array<string,mixed> $values
     * @param list<string> $clear
     */
    public function save(array $values, array $clear): void
    {
        $data = $this->read();
        foreach ($values as $name => $value) {
            $value = is_string($value) ? trim($value) : '';
            if (self::allowed((string) $name) && $value !== '' && strlen($value) <= 2000 && !preg_match('/[\r\n\0]/', $value)) {
                $data[(string) $name] = $value;
            }
        }
        foreach ($clear as $name) {
            unset($data[$name]);
        }
        $dir = dirname($this->file());
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $tmp = $this->file() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($tmp, 0600);
        rename($tmp, $this->file());
    }
}
