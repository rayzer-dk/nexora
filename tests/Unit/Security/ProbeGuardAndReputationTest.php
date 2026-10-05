<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit\Security;

use Commerce\Modules\Security\Bots\BotProtection;
use Commerce\Modules\Security\Bots\IpReputation;
use Commerce\Tests\Unit\Support\ArraySystemSettingStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProbeGuardAndReputationTest extends TestCase
{
    private function bots(array $settings = []): BotProtection
    {
        $store = new ArraySystemSettingStore();
        $bots = new BotProtection($store, new ArrayAdapter());
        $bots->save(['enabled' => '1', 'probe_enabled' => '1', 'probe_max' => 5, 'probe_window' => 10, 'probe_ban' => 30] + $settings);

        return $bots;
    }

    public function testManyNotFoundAnswersBanTheClient(): void
    {
        $bots = $this->bots();
        for ($i = 0; $i < 4; ++$i) {
            $bots->recordNotFound('203.0.113.9', 'curl/8');
        }
        self::assertNull($bots->verdict('Mozilla/5.0', '203.0.113.9'));
        $bots->recordNotFound('203.0.113.9', 'curl/8');
        self::assertSame('probe', $bots->verdict('Mozilla/5.0', '203.0.113.9'));
        self::assertNull($bots->verdict('Mozilla/5.0', '203.0.113.10'));
        self::assertSame('203.0.113.9', $bots->recentProbeBans()[0]['ip']);
        $bots->liftProbeBans();
        self::assertNull($bots->verdict('Mozilla/5.0', '203.0.113.9'));
    }

    public function testSearchEnginesAndLocalAddressesAreNeverBanned(): void
    {
        $bots = $this->bots();
        for ($i = 0; $i < 20; ++$i) {
            $bots->recordNotFound('203.0.113.9', 'Mozilla/5.0 (compatible; Googlebot/2.1)');
            $bots->recordNotFound('127.0.0.1', 'curl/8');
            $bots->recordNotFound('10.0.0.5', 'curl/8');
        }
        self::assertNull($bots->verdict('Mozilla/5.0', '203.0.113.9'));
        self::assertNull($bots->verdict('Mozilla/5.0', '127.0.0.1'));
        self::assertNull($bots->verdict('Mozilla/5.0', '10.0.0.5'));
    }

    public function testReputationFlagsKnownSpammersAndFailsOpen(): void
    {
        $bots = $this->bots(['rep_sfs' => '1']);
        $spam = new MockHttpClient(new MockResponse(json_encode(['success' => 1, 'ip' => ['appears' => 1, 'frequency' => 12, 'confidence' => 80.0]], JSON_THROW_ON_ERROR)));
        self::assertSame('stopforumspam', (new IpReputation($bots, $spam, new ArrayAdapter()))->risky('198.51.100.20'));

        $clean = new MockHttpClient(new MockResponse(json_encode(['success' => 1, 'ip' => ['appears' => 0]], JSON_THROW_ON_ERROR)));
        self::assertNull((new IpReputation($bots, $clean, new ArrayAdapter()))->risky('198.51.100.21'));

        $down = new MockHttpClient(new MockResponse('', ['http_code' => 503]));
        self::assertNull((new IpReputation($bots, $down, new ArrayAdapter()))->risky('198.51.100.22'));
        // switched off or private address: no request at all
        $off = new MockHttpClient(static fn () => throw new \LogicException('must not be called'));
        self::assertNull((new IpReputation($this->bots(), $off, new ArrayAdapter()))->risky('198.51.100.23'));
        self::assertNull((new IpReputation($bots, $off, new ArrayAdapter()))->risky('192.168.1.5'));
    }
}
