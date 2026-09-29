<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Admin\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // RFC 6238 test key "12345678901234567890"

    public function testRfc6238Vectors(): void
    {
        self::assertSame('287082', Totp::code(self::SECRET, intdiv(59, 30)));
        self::assertSame('081804', Totp::code(self::SECRET, intdiv(1111111109, 30)));
        self::assertSame('050471', Totp::code(self::SECRET, intdiv(1111111111, 30)));
    }

    public function testVerifyAcceptsOneStepOfDriftAndReturnsTheStep(): void
    {
        $time = 1111111109;
        self::assertSame(intdiv($time, 30), Totp::verify(self::SECRET, '081804', $time));
        self::assertSame(intdiv($time, 30), Totp::verify(self::SECRET, '081 804', $time));
        self::assertNull(Totp::verify(self::SECRET, '081804', $time + 120));
        self::assertNull(Totp::verify(self::SECRET, '12345', $time));
    }

    public function testBase32RoundTrip(): void
    {
        $raw = random_bytes(20);
        self::assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::generateSecret());
    }
}
