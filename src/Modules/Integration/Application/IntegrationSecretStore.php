<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Application;

/**
 * Settings of outside services typed in the admin. They are kept in var/config/integration-secrets.json (outside the web root);
 * bootstrap/integration_secrets.php puts them into the environment before the kernel boots, so they win over the .env file.
 * Secret values are never sent back to the page: it only learns which names are set.
 */
final class IntegrationSecretStore
{
    public function __construct(private readonly string $projectDir)
    {
    }

    private function file(): string
    {
        return $this->projectDir . '/var/config/integration-secrets.json';
    }

    /** @return array<string,string> */
    public function read(): array
    {
        $data = [];
        foreach ([$this->projectDir . '/var/config/carrier-secrets.json', $this->file()] as $path) {
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (is_array($decoded)) {
                foreach ($decoded as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $data[$name] = $value;
                    }
                }
            }
        }

        return $data;
    }

    public function isStored(string $name): bool
    {
        return ($this->read()[$name] ?? '') !== '';
    }

    /** The value the shop uses now (an admin value, else the .env file), or '' when nothing is set. */
    public static function effective(string $name): string
    {
        return trim((string) ($_ENV[$name] ?? $_SERVER[$name] ?? (getenv($name) ?: '')));
    }

    public static function allowed(string $name): bool
    {
        return array_key_exists($name, IntegrationCatalog::allFields());
    }

    /**
     * An empty value keeps what is stored; a name listed in $clear is removed. A bool field takes "1" or "0".
     *
     * @param array<string,mixed> $values
     * @param list<string> $clear
     */
    public function save(array $values, array $clear): void
    {
        $types = IntegrationCatalog::allFields();
        $data = $this->read();
        foreach ($values as $name => $value) {
            $name = (string) $name;
            $value = is_string($value) ? trim($value) : '';
            if (!isset($types[$name]) || $value === '' || strlen($value) > 2000 || preg_match('/[\r\n\0]/', $value) === 1) {
                continue;
            }
            if ($types[$name] === 'bool' && !in_array($value, ['0', '1'], true)) {
                continue;
            }
            $data[$name] = $value;
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
        // The old carrier file is merged into this one; drop it so a removed value does not come back.
        $legacy = $this->projectDir . '/var/config/carrier-secrets.json';
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }
}
