<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Push\Application\WebPushCrypto as C;
use PHPUnit\Framework\TestCase;

final class WebPushCryptoTest extends TestCase
{
    /** RFC 8291 Appendix A: the exact aes128gcm body for the published test values. */
    public function testEncryptionMatchesRfc8291Vector(): void
    {
        $body = C::encrypt(
            'When I grow up, I want to be a watermelon',
            C::b64uDecode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            C::b64uDecode('BTBZMqHH6r4Tts7J_aSIgg'),
            C::b64uDecode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
            C::b64uDecode('DGv6ra1nlYgDCS1FRnbzlw'),
        );
        self::assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            C::b64uEncode($body),
        );
    }

    public function testVapidHeaderIsSignedJwtWithPublicKey(): void
    {
        $k = C::generateKeyPair();
        self::assertSame(65, strlen($k['public']));
        $header = C::vapidHeader('https://push.example.test/send/abc', 'mailto:ops@example.test', $k['private_pem'], $k['public'], 1_800_000_000);
        self::assertStringStartsWith('vapid t=', $header);
        self::assertStringContainsString(', k=' . C::b64uEncode($k['public']), $header);
        $jwt = explode('.', substr($header, 8, strpos($header, ',') - 8));
        self::assertCount(3, $jwt);
        $claims = json_decode(C::b64uDecode($jwt[1]), true);
        self::assertSame('https://push.example.test', $claims['aud']);
        self::assertSame('mailto:ops@example.test', $claims['sub']);
        self::assertSame(64, strlen(C::b64uDecode($jwt[2])));
    }

    public function testOversizedPayloadIsRejected(): void
    {
        $k = C::generateKeyPair();
        $this->expectException(\InvalidArgumentException::class);
        C::encrypt(str_repeat('x', 4000), $k['public'], random_bytes(16));
    }
}
