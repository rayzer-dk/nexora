<?php

declare(strict_types=1);

namespace Commerce\Modules\Push\Application;

/**
 * Web Push primitives without third-party libraries: VAPID (RFC 8292, ES256 JWT) and message
 * encryption (RFC 8291 / RFC 8188, aes128gcm). The RFC 8291 appendix test vector is part of the
 * unit tests, so the byte layout is verified against the specification.
 */
final class WebPushCrypto
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    private const P256_EC_PRIV_PREFIX = '30770201010420';
    private const P256_EC_PRIV_MIDDLE = 'a00a06082a8648ce3d030107a144034200';

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $encoded): string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        return $raw === false ? '' : $raw;
    }

    /** @return array{private_pem:string,public:string} public key as the raw 65-byte uncompressed point */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('webpush_cannot_generate_key');
        }
        openssl_pkey_export($key, $pem);
        $ec = openssl_pkey_get_details($key)['ec'];

        return ['private_pem' => (string) $pem, 'public' => "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)];
    }

    /** Signed VAPID header value: "vapid t=<jwt>, k=<public key>". */
    public static function vapidHeader(string $endpoint, string $subject, string $privatePem, string $publicRaw, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $header = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = self::b64uEncode(json_encode(['aud' => $audience, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $subject], JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $claims;
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false || !openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('webpush_vapid_signing_failed');
        }

        return 'vapid t=' . $signingInput . '.' . self::b64uEncode(self::derToRaw($der)) . ', k=' . self::b64uEncode($publicRaw);
    }

    /**
     * RFC 8291 aes128gcm body for one push message.
     *
     * @param string $uaPublic raw 65-byte subscription p256dh key
     * @param string $authSecret raw 16-byte subscription auth secret
     * @param string|null $asPrivateD test hook: ephemeral private scalar (32 bytes); random when null
     * @param string|null $salt test hook: 16 bytes; random when null
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $asPrivateD = null, ?string $salt = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) < 16) {
            throw new \InvalidArgumentException('webpush_invalid_subscription_keys');
        }
        if (strlen($payload) > 3800) {
            throw new \InvalidArgumentException('webpush_payload_too_large');
        }
        if ($asPrivateD === null) {
            $pair = self::generateKeyPair();
            $ephemeral = openssl_pkey_get_private($pair['private_pem']);
            $asPublic = $pair['public'];
        } else {
            $ephemeral = null;
            $asPublic = '';
        }
        if ($ephemeral === null || $ephemeral === false) {
            // deterministic path (tests): derive the public point from the scalar via a temporary key
            $probe = openssl_pkey_get_private(self::privatePemFromScalar($asPrivateD ?? '', null));
            $ec = $probe !== false ? openssl_pkey_get_details($probe) : false;
            if ($ec === false) {
                throw new \RuntimeException('webpush_cannot_import_ephemeral_key');
            }
            $asPublic = "\x04" . str_pad($ec['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $ephemeral = $probe;
        }
        $peer = openssl_pkey_get_public(self::publicPemFromPoint($uaPublic));
        if ($peer === false) {
            throw new \InvalidArgumentException('webpush_invalid_subscription_public_key');
        }
        $shared = openssl_pkey_derive($peer, $ephemeral, 32);
        if ($shared === false) {
            throw new \RuntimeException('webpush_ecdh_failed');
        }
        $salt ??= random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('webpush_encryption_failed');
        }

        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    public static function publicPemFromPoint(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX) . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** @param string|null $publicPoint unused hook to keep the signature symmetrical for tests */
    public static function privatePemFromScalar(string $d, ?string $publicPoint): string
    {
        $d = str_pad($d, 32, "\0", STR_PAD_LEFT);
        // The public point is required in the DER structure; compute it through a scalar-only import first.
        $pub = $publicPoint ?? self::pointFromScalar($d);

        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode(hex2bin(self::P256_EC_PRIV_PREFIX) . $d . hex2bin(self::P256_EC_PRIV_MIDDLE) . $pub), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /** Scalar multiplication of the P-256 generator, via OpenSSL (private-only DER without the optional public key). */
    private static function pointFromScalar(string $d): string
    {
        $der = hex2bin('30310201010420') . $d . hex2bin('a00a06082a8648ce3d030107');
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);
        $ec = $key !== false ? openssl_pkey_get_details($key) : false;
        if ($ec === false) {
            throw new \RuntimeException('webpush_cannot_derive_public_point');
        }

        return "\x04" . str_pad($ec['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function derToRaw(string $der): string
    {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }
}
