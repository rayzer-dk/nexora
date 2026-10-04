<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\TrustedProviderBootSubscriber;
use PHPUnit\Framework\TestCase;

final class TrustedProviderBootTest extends TestCase
{
    public function testOnlyTrustedModulesWithAProviderCapabilityBootBeforeTheRequest(): void
    {
        self::assertFalse(TrustedProviderBootSubscriber::needsBoot([]));
        self::assertFalse(TrustedProviderBootSubscriber::needsBoot([['execution' => 'declarative', 'capabilities' => ['provider.payment']]]));
        self::assertFalse(TrustedProviderBootSubscriber::needsBoot([['execution' => 'trusted_release', 'capabilities' => ['events.subscribe']]]));
        self::assertTrue(TrustedProviderBootSubscriber::needsBoot([['execution' => 'trusted_release', 'capabilities' => ['provider.translation']]]));
        self::assertTrue(TrustedProviderBootSubscriber::needsBoot([['execution' => 'declarative'], ['execution' => 'trusted_release', 'capabilities' => ['provider.payment', 'x']]]));
    }
}
