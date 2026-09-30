<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Security\Spam\PublicFormProtection;
use Commerce\Modules\Security\Spam\PublicFormSpamGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class PublicFormProtectionTest extends TestCase
{
    private function protection(int $limit = 3): PublicFormProtection
    {
        return new PublicFormProtection(
            new PublicFormSpamGuard(),
            new RateLimiterFactory(['id' => 'public_form', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage()),
        );
    }

    /** @param array<string,string> $data */
    private function request(array $data): Request
    {
        return Request::create('/contact/send', 'POST', $data, [], [], ['REMOTE_ADDR' => '203.0.113.7']);
    }

    public function testHoneypotAndMissingTimestampAreRejected(): void
    {
        $p = $this->protection();
        $t = (string) (time() - 10);
        self::assertFalse($p->allow($this->request(['_rendered_at' => $t, '_website' => 'x']), 'a'));
        self::assertFalse($p->allow($this->request([]), 'a'));
        self::assertTrue($p->allow($this->request(['_rendered_at' => $t, '_website' => '']), 'a'));
    }

    public function testRateLimitIsPerScopeAndClient(): void
    {
        $p = $this->protection(2);
        $r = $this->request(['_rendered_at' => (string) (time() - 10)]);
        self::assertTrue($p->allow($r, 'a'));
        self::assertTrue($p->allow($r, 'a'));
        self::assertFalse($p->allow($r, 'a'));
        self::assertTrue($p->allow($r, 'b'));
    }
}
