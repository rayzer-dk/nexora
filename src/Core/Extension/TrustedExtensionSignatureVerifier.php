<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use RuntimeException;
use ZipArchive;

/**
 * Verifies trusted executable extension packages against publisher keys shipped
 * or provisioned by the platform owner. Signature payload is the canonical
 * SHA-256 digest of all package entries except SIGNATURE.ed25519.
 */
final class TrustedExtensionSignatureVerifier
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /** @param array<string,mixed> $manifest */
    public function verify(ZipArchive $zip, array $manifest): void
    {
        $signature = $manifest['signature'] ?? null;
        $publisher = $manifest['publisher'] ?? null;
        if (!is_array($signature) || !is_array($publisher) || ($signature['algorithm'] ?? null) !== 'ed25519') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.22406ed879fd'));
        }
        $keyId = (string) ($signature['key_id'] ?? $publisher['key_id'] ?? '');
        if ($keyId === '' || $keyId !== (string) ($publisher['key_id'] ?? '')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.de1faa9f038c'));
        }
        $signatureFile = (string) ($signature['file'] ?? 'SIGNATURE.ed25519');
        if ($signatureFile !== 'SIGNATURE.ed25519') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.723a55907ac6'));
        }
        $rawSignature = $zip->getFromName($signatureFile);
        if (!is_string($rawSignature)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.5b25dd98327d'));
        }
        $decodedSignature = base64_decode(trim($rawSignature), true);
        if ($decodedSignature === false || strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.1ec67c7506d3'));
        }
        $publicKey = $this->publisherKey($keyId);
        $digest = $this->canonicalDigest($zip, $signatureFile);
        if (!sodium_crypto_sign_verify_detached($decodedSignature, $digest, $publicKey)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.2ee9925b860d'));
        }
    }

    private function publisherKey(string $keyId): string
    {
        $path = rtrim($this->projectDir, '/\\') . '/config/extensions/trusted-publishers.json';
        if (!is_file($path)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.b79abf7706c1'));
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.c5696e4228b9'), 0, $e);
        }
        $encoded = is_array($data) ? ($data['publishers'][$keyId]['public_key'] ?? null) : null;
        if (!is_string($encoded)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.3cf712c87738') . $keyId);
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.bb5bf1d24c2c'));
        }
        return $key;
    }

    private function canonicalDigest(ZipArchive $zip, string $signatureFile): string
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/') || $name === $signatureFile) {
                continue;
            }
            $contents = $zip->getFromIndex($i);
            if (!is_string($contents)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.93960f7f41c6') . $name);
            }
            $entries[$name] = hash('sha256', $contents);
        }
        ksort($entries, SORT_STRING);
        $payload = '';
        foreach ($entries as $name => $hash) {
            $payload .= $name . "\0" . $hash . "\n";
        }
        return hash('sha256', $payload, true);
    }
}
