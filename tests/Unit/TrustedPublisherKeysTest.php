<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\TrustedExtensionSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class TrustedPublisherKeysTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nexora-publishers-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config/extensions', 0777, true);
        mkdir($this->dir . '/var/config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/config/extensions/trusted-publishers.json', '/var/config/trusted-publishers.json'] as $file) {
            @unlink($this->dir . $file);
        }
        foreach (['/config/extensions', '/config', '/var/config', '/var', ''] as $folder) {
            @rmdir($this->dir . $folder);
        }
    }

    public function testKeysOfTheOwnerSurviveBecauseTheyLiveInVar(): void
    {
        $nexora = base64_encode(str_repeat('a', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
        $own = base64_encode(str_repeat('b', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
        file_put_contents($this->dir . '/config/extensions/trusted-publishers.json', json_encode(['schema_version' => 2, 'publishers' => ['nexora.bonus.2026' => ['name' => 'Nexora', 'public_key' => $nexora]]]));
        file_put_contents($this->dir . '/var/config/trusted-publishers.json', json_encode(['schema_version' => 2, 'publishers' => [
            'acme.2026' => ['name' => 'Acme', 'public_key' => $own],
            'nexora.bonus.2026' => ['name' => 'Fake', 'public_key' => $own],
        ]]));
        $verifier = new TrustedExtensionSignatureVerifier($this->dir);
        $publisherKey = new \ReflectionMethod($verifier, 'publisherKey');

        self::assertSame(str_repeat('b', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES), $publisherKey->invoke($verifier, 'acme.2026'));
        self::assertSame(str_repeat('a', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES), $publisherKey->invoke($verifier, 'nexora.bonus.2026'), 'a shipped publisher cannot be replaced from var/');
    }
}
