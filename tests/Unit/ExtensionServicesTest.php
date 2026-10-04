<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionServiceRegistry;
use Commerce\Modules\Feeds\Application\CanonicalProductExportService;
use Commerce\Modules\Feeds\Application\ProductFeedGenerator;
use Commerce\Modules\Feeds\Contract\FeedFormatProviderInterface;
use Commerce\Modules\Notification\Application\NotificationDispatcher;
use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Commerce\Modules\Pricing\Application\ExchangeRateService;
use Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface;
use Commerce\Modules\Pricing\Domain\ReferenceRateTable;
use Commerce\Modules\Search\Contract\SearchCandidateProviderInterface;
use Commerce\Modules\Search\Domain\SearchCandidateResult;
use Commerce\Modules\Search\Infrastructure\ExtensionAwareSearchCandidateProvider;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ExtensionServicesTest extends TestCase
{
    public function testRegistryRejectsAServiceThatDoesNotMatchTheContract(): void
    {
        $this->expectException(\LogicException::class);
        (new ExtensionServiceRegistry())->add('provider.exchange_rate', new \stdClass());
    }

    public function testRegistryRejectsAnUnknownCapability(): void
    {
        $this->expectException(\LogicException::class);
        (new ExtensionServiceRegistry())->add('provider.unknown', new \stdClass());
    }

    public function testExchangeRateSourceOfAModuleIsOfferedAndCannotReplaceABuiltInOne(): void
    {
        $registry = new ExtensionServiceRegistry();
        $registry->add('provider.exchange_rate', $this->rateSource('acme_bank'));
        $registry->add('provider.exchange_rate', $this->rateSource('ecb'));
        $service = new ExchangeRateService(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), [$this->rateSource('ecb')], null, $registry);

        $choices = $service->sourceChoices();
        $this->assertContains('acme_bank', $choices);
        $this->assertSame(1, count(array_keys($choices, 'ecb', true)));
    }

    public function testNotificationSenderOfAModuleTakesOverItsChannel(): void
    {
        $log = new \ArrayObject();
        $registry = new ExtensionServiceRegistry();
        $registry->add('provider.notification_sender', $this->sender(NotificationChannel::Sms, 'module', $log));
        $dispatcher = new NotificationDispatcher([$this->sender(NotificationChannel::Sms, 'builtin', $log), $this->sender(NotificationChannel::Email, 'builtin-mail', $log)], $registry);
        $message = new NotificationMessage('t', 's', 'x');

        $dispatcher->send(NotificationChannel::Sms, $message, '+380000000');
        $dispatcher->send(NotificationChannel::Email, $message, 'a@b.c');

        $this->assertSame(['module', 'builtin-mail'], $log->getArrayCopy());
    }

    public function testSearchEngineOfAModuleIsAskedFirstAndFailureFallsBackToTheBuiltInOne(): void
    {
        $context = new StorefrontContext(1, 1, 'uk-UA', 'UAH', 'UA', 'Shop');
        $builtIn = $this->engine(new SearchCandidateResult([1], 1, 'builtin'));
        $registry = new ExtensionServiceRegistry();
        $this->assertSame('builtin', (new ExtensionAwareSearchCandidateProvider($builtIn, $registry))->candidates($context, 'x')?->provider);

        $registry->add('provider.search', $this->engine(new \RuntimeException('down')));
        $this->assertSame('builtin', (new ExtensionAwareSearchCandidateProvider($builtIn, $registry))->candidates($context, 'x')?->provider);

        $registry->add('provider.search', $this->engine(null));
        $registry->add('provider.search', $this->engine(new SearchCandidateResult([2], 1, 'module')));
        $this->assertSame('module', (new ExtensionAwareSearchCandidateProvider($builtIn, $registry))->candidates($context, 'x')?->provider);
    }

    public function testFeedFormatOfAModuleIsListedButCannotShadowABuiltInFormat(): void
    {
        $registry = new ExtensionServiceRegistry();
        $registry->add('provider.feed', $this->format('hotline'));
        $registry->add('provider.feed', $this->format('google'));
        $registry->add('provider.feed', $this->format('Bad Code'));
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $generator = new ProductFeedGenerator(new CanonicalProductExportService($db, 'https://x.test'), $db, 'https://x.test', $registry);

        $this->assertSame(['hotline'], array_keys($generator->extensionFormats()));
    }

    public function testBooterRunsOnceBeforeTheFirstLookup(): void
    {
        $registry = new ExtensionServiceRegistry();
        $calls = 0;
        $subscriber = new class ($calls) {
            public function __construct(private int &$calls)
            {
            }

            public function ensureBooted(): void
            {
                ++$this->calls;
            }
        };
        $registry->setBooter(static fn (): object => $subscriber);
        $registry->all('provider.search');
        $registry->all('provider.feed');

        $this->assertSame(1, $calls);
    }

    private function rateSource(string $code): ReferenceRateSourceInterface
    {
        return new class ($code) implements ReferenceRateSourceInterface {
            public function __construct(private string $code)
            {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function table(): ReferenceRateTable
            {
                return new ReferenceRateTable($this->code, 'EUR', '2026-01-01', ['EUR' => 1.0]);
            }
        };
    }

    private function sender(NotificationChannel $channel, string $name, \ArrayObject $log): NotificationSenderInterface
    {
        return new class ($channel, $name, $log) implements NotificationSenderInterface {
            public function __construct(private NotificationChannel $channel, private string $name, private \ArrayObject $log)
            {
            }

            public function channel(): NotificationChannel
            {
                return $this->channel;
            }

            public function send(NotificationMessage $message, string $recipient): void
            {
                $this->log[] = $this->name;
            }
        };
    }

    private function engine(SearchCandidateResult|\Throwable|null $answer): SearchCandidateProviderInterface
    {
        return new class ($answer) implements SearchCandidateProviderInterface {
            public function __construct(private SearchCandidateResult|\Throwable|null $answer)
            {
            }

            public function candidates(StorefrontContext $context, string $query, int $limit = 5000): ?SearchCandidateResult
            {
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }

    private function format(string $code): FeedFormatProviderInterface
    {
        return new class ($code) implements FeedFormatProviderInterface {
            public function __construct(private string $code)
            {
            }

            public function code(): string
            {
                return $this->code;
            }

            public function label(): string
            {
                return 'Test';
            }

            public function render(array $products, int $storeId, string $locale, string $currency): array
            {
                return ['content' => '', 'content_type' => 'text/plain', 'extension' => 'txt'];
            }
        };
    }
}
