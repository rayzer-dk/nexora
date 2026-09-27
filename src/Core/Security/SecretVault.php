<?php

declare(strict_types=1);

namespace Commerce\Core\Security;

use RuntimeException;

/**
 * Application-level vault for configuration secrets persisted in the database.
 * Master material is derived from APP_SECRET and is never stored alongside ciphertext.
 */
final readonly class SecretVault
{
    private const PREFIX = 'mcsv1:';

    public function __construct(private string $appSecret)
    {
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $this->assertContext($context);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $context,
            $nonce,
            $this->key(),
        );

        return self::PREFIX . sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function decrypt(string $encoded, string $context): string
    {
        $this->assertContext($context);
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7549cce95763'));
        }

        try {
            $binary = sodium_base642bin(substr($encoded, strlen(self::PREFIX)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d73ca7692a43'), 0, $e);
        }

        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($binary) <= $nonceLength) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.71b34f6d8589'));
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($binary, $nonceLength),
            $context,
            substr($binary, 0, $nonceLength),
            $this->key(),
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fb03916d37cc'));
        }

        return $plaintext;
    }

    private function key(): string
    {
        if (strlen($this->appSecret) < 32 || in_array($this->appSecret, ['change-me', 'dev-placeholder-change-in-production'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1b5e082a97c5'));
        }
        return hash('sha256', "nexora-commerce-secret-vault-v1\0" . $this->appSecret, true);
    }

    private function assertContext(string $context): void
    {
        if ($context === '' || strlen($context) > 512 || str_contains($context, "\0")) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f990b1a5c1fc'));
        }
    }
}
