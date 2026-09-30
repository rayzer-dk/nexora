<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Content\Application\ArticleProductLinkService;
use Commerce\Modules\Customer\Device\DeviceTrustService;
use Commerce\Modules\Fraud\Application\FraudService;
use Commerce\Modules\Identity\Google\GoogleIdentityProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class FraudAndIdentityTest extends TestCase
{
    private const SETTINGS = ['review_score' => 40, 'high_score' => 80, 'velocity_limit' => 3, 'high_value_minor' => 5000000];

    public function testCleanOrderIsLow(): void
    {
        $r = FraudService::evaluate(['total_minor' => 100000, 'guest' => true, 'cod' => true], self::SETTINGS);
        self::assertSame(['score' => 0, 'level' => 'low', 'reasons' => []], $r);
    }

    public function testSignalsAccumulateAndAreExplained(): void
    {
        $r = FraudService::evaluate(['ip_orders_1h' => 3, 'disposable_email' => true], self::SETTINGS);
        self::assertSame(55, $r['score']);
        self::assertSame('review', $r['level']);
        self::assertSame(['velocity_ip', 'disposable_email'], $r['reasons']);
    }

    public function testHighRiskAndCap(): void
    {
        $r = FraudService::evaluate(['ip_orders_1h' => 9, 'email_orders_24h' => 9, 'ip_failed_24h' => 5, 'phone_emails_7d' => 4, 'disposable_email' => true, 'total_minor' => 9000000, 'guest' => true, 'cod' => true], self::SETTINGS);
        self::assertSame(100, $r['score']);
        self::assertSame('high', $r['level']);
        self::assertContains('high_value_guest_cod', $r['reasons']);
    }

    public function testHighValueDisabledWhenZero(): void
    {
        $r = FraudService::evaluate(['total_minor' => 99999999], ['high_value_minor' => 0] + self::SETTINGS);
        self::assertSame('low', $r['level']);
    }

    public function testBlocklistValueNormalisation(): void
    {
        self::assertSame('a@b.com', FraudService::normalizeValue('email', ' A@B.com '));
        self::assertSame('spam.com', FraudService::normalizeValue('domain', '@Spam.com'));
        self::assertSame('380501234567', FraudService::normalizeValue('phone', '+38 (050) 123-45-67'));
        self::assertSame('203.0.113.9', FraudService::normalizeValue('ip', ' 203.0.113.9 '));
    }

    public function testSkuListParsing(): void
    {
        self::assertSame(['A-1', 'B2', 'C3'], ArticleProductLinkService::parseSkus("A-1, B2;\nC3 A-1"));
        self::assertSame([], ArticleProductLinkService::parseSkus('  '));
        self::assertCount(ArticleProductLinkService::MAX_LINKS, ArticleProductLinkService::parseSkus(implode(',', range(1, 50))));
    }

    public function testDeviceLabel(): void
    {
        self::assertSame('Chrome / Windows', DeviceTrustService::label('Mozilla/5.0 (Windows NT 10.0) AppleWebKit Chrome/126 Safari/537'));
        self::assertSame('Safari / iOS', DeviceTrustService::label('Mozilla/5.0 (iPhone; CPU iPhone OS 17) AppleWebKit Version/17 Safari/604'));
        self::assertSame('Browser / Device', DeviceTrustService::label(''));
    }

    private function jwt(array $claims): string
    {
        $b = static fn (array $a): string => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b(['alg' => 'RS256']) . '.' . $b($claims) . '.sig';
    }

    private function google(): GoogleIdentityProvider
    {
        return new GoogleIdentityProvider(new MockHttpClient(), true, 'client-id', 'secret');
    }

    public function testGoogleTokenClaimsAreValidated(): void
    {
        $ok = ['iss' => 'https://accounts.google.com', 'aud' => 'client-id', 'exp' => time() + 600, 'sub' => '1234', 'email' => 'Jane@Example.com', 'email_verified' => true, 'name' => 'Jane'];
        $identity = $this->google()->verify($this->jwt($ok));
        self::assertSame('google', $identity->provider);
        self::assertSame('1234', $identity->subject);
        self::assertSame('jane@example.com', $identity->email);
        self::assertTrue($identity->emailVerified);

        foreach ([['aud' => 'other'], ['iss' => 'https://evil.test'], ['exp' => time() - 5], ['sub' => '']] as $bad) {
            try {
                $this->google()->verify($this->jwt($bad + $ok));
                self::fail('expected rejection');
            } catch (\DomainException $e) {
                self::assertSame('google_token_invalid', $e->getMessage());
            }
        }
    }

    public function testGoogleUnverifiedEmailFlagged(): void
    {
        $claims = ['iss' => 'accounts.google.com', 'aud' => 'client-id', 'exp' => time() + 60, 'sub' => '9', 'email' => 'a@b.co', 'email_verified' => false];
        self::assertFalse($this->google()->verify($this->jwt($claims))->emailVerified);
    }

    public function testGoogleDisabledWithoutCredentials(): void
    {
        self::assertFalse((new GoogleIdentityProvider(new MockHttpClient(), true, '', ''))->enabled());
        self::assertTrue($this->google()->enabled());
        self::assertStringContainsString('client_id=client-id', $this->google()->authorizationUrl('https://s.test/cb', 'st', 'no'));
    }
}
