<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use RuntimeException;

final class SignedUpdateManifestVerifier
{
    public function verify(UpdateManifest $manifest, string $base64PublicKey): void
    {
        $publicKey = base64_decode($base64PublicKey, true);
        $signature = base64_decode($manifest->signature, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.95d280bb7bae'));
        }

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9fbe27d0ab3f'));
        }

        if (!sodium_crypto_sign_verify_detached($signature, $manifest->signedPayload(), $publicKey)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.30036e2d6461'));
        }
    }

    public function verifyPackageHash(string $packagePath, string $expectedSha256): void
    {
        if (!is_file($packagePath)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2c6cf88b6151'));
        }

        $actual = hash_file('sha256', $packagePath);
        if (!hash_equals(strtolower($expectedSha256), strtolower($actual))) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3ad90c033e35'));
        }
    }
}
