<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Event\DomainEvent;
use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Extension\TrustedExtensionRuntimeLoader;
use Commerce\Core\Extension\TrustedExtensionRuntimeRegistry;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Security\SecretVault;
use Commerce\Modules\Ai\Application\AiBatchService;
use Commerce\Modules\Ai\Application\AiGlossary;
use Commerce\Modules\Ai\Application\AiProviderRegistry;
use Commerce\Modules\Ai\Application\AiSettings;
use Commerce\Modules\Ai\Application\AiTaskService;
use Commerce\Modules\Ai\Application\TranslationProviderRegistry;
use Commerce\Modules\Ai\Contract\TextGenerationProviderInterface;
use Commerce\Modules\Catalog\Application\CatalogTranslationService;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\ProductPage\Application\ProductBlockRegistry;
use Commerce\Modules\Seo\Application\SeoPathPolicy;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Application\SlugGenerator;
use Commerce\Modules\Seo\Contract\SeoUrlRepositoryInterface;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Commerce\Modules\Seo\Domain\SeoRoute;
use Commerce\Modules\Seo\Domain\SeoRouteResolution;
use Commerce\Modules\Shipping\Application\DeliveryProviderRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Uid\Uuid;

final class AiBatchServiceTest extends TestCase
{
    private Connection $db;
    private AiBatchService $batches;
    private TranslationProviderRegistry $translationProviders;
    /** @var TextGenerationProviderInterface&object{prompts:list<string>,fail:bool} */
    private object $model;
    /** @var list<string> */
    private array $routes = [];

    protected function setUp(): void
    {
        $db = $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE mc_store (id INTEGER PRIMARY KEY, default_locale TEXT)',
            'CREATE TABLE mc_locale (code TEXT PRIMARY KEY, native_name TEXT)',
            'CREATE TABLE mc_store_locale (store_id INT, locale_code TEXT, enabled INT, is_default INT, sort_order INT)',
            'CREATE TABLE mc_product (id INTEGER PRIMARY KEY, public_id BLOB, brand_id INT)',
            'CREATE TABLE mc_product_variant (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INT, sku TEXT, sort_order INT)',
            'CREATE TABLE mc_product_category (product_id INT, category_id INT)',
            'CREATE TABLE mc_store_product (store_id INT, product_id INT, status TEXT)',
            'CREATE TABLE mc_product_translation (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INT, store_id INT, locale TEXT, name TEXT, h1 TEXT, slug TEXT, short_description TEXT, description TEXT, meta_title TEXT, meta_description TEXT, created_at TEXT, updated_at TEXT, is_fallback INT NOT NULL DEFAULT 0, UNIQUE(product_id, store_id, locale))',
            'CREATE TABLE mc_category (id INTEGER PRIMARY KEY, public_id BLOB)',
            'CREATE TABLE mc_store_category (store_id INT, category_id INT, status TEXT)',
            'CREATE TABLE mc_category_translation (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INT, store_id INT, locale TEXT, name TEXT, h1 TEXT, slug TEXT, description TEXT, description_bottom TEXT, meta_title TEXT, meta_description TEXT, is_fallback INT NOT NULL DEFAULT 0)',
            'CREATE TABLE mc_brand (id INTEGER PRIMARY KEY, public_id BLOB, name TEXT)',
            'CREATE TABLE mc_store_brand (store_id INT, brand_id INT, status TEXT)',
            'CREATE TABLE mc_brand_translation (id INTEGER PRIMARY KEY AUTOINCREMENT, brand_id INT, store_id INT, locale TEXT, slug TEXT, description TEXT, meta_title TEXT, meta_description TEXT, is_fallback INT NOT NULL DEFAULT 0)',
            'CREATE TABLE mc_ai_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INT, admin_subject TEXT, task TEXT, provider TEXT, model TEXT, input_chars INT, output_chars INT, status TEXT, created_at TEXT)',
            'CREATE TABLE mc_ai_settings (store_id INT PRIMARY KEY, daily_limit INT, updated_at TEXT)',
            'CREATE TABLE mc_ai_provider_config (store_id INT, provider TEXT, enabled INT, model TEXT, api_key_enc TEXT, updated_at TEXT, PRIMARY KEY (store_id, provider))',
            'CREATE TABLE mc_ai_glossary (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INT, term TEXT, mode TEXT, translations_json TEXT, created_at TEXT, updated_at TEXT, UNIQUE(store_id, term))',
            'CREATE TABLE mc_ai_batch (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT, store_id INT, kind TEXT, options_json TEXT, status TEXT, created_by TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE mc_ai_batch_job (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INT, store_id INT, entity_type TEXT, entity_id INT, locale TEXT, task TEXT, status TEXT NOT NULL DEFAULT \'pending\', attempts INT NOT NULL DEFAULT 0, error TEXT, result_json TEXT, previous_json TEXT, processed_at TEXT)',
        ] as $sql) {
            $db->executeStatement($sql);
        }
        $db->insert('mc_store', ['id' => 1, 'default_locale' => 'uk-UA']);
        foreach (['uk-UA' => 'Українська', 'en-US' => 'English', 'de-DE' => 'Deutsch'] as $code => $name) {
            $db->insert('mc_locale', ['code' => $code, 'native_name' => $name]);
            $db->insert('mc_store_locale', ['store_id' => 1, 'locale_code' => $code, 'enabled' => 1, 'is_default' => $code === 'uk-UA' ? 1 : 0, 'sort_order' => 0]);
        }
        // 1: no English row · 2: English is a fallback copy · 3: English name is a copy of the Ukrainian one
        // 4: properly translated · 5: English description missing
        for ($id = 1; $id <= 5; ++$id) {
            $db->insert('mc_product', ['id' => $id, 'public_id' => Uuid::v4()->toBinary()]);
            $db->insert('mc_store_product', ['store_id' => 1, 'product_id' => $id, 'status' => 'active']);
            $this->product($id, 'uk-UA', ['name' => 'Чайник ' . $id, 'description' => '<p>Опис ' . $id . '</p>']);
        }
        $this->product(2, 'en-US', ['name' => 'Чайник 2', 'description' => '<p>Опис 2</p>'], 1);
        $this->product(3, 'en-US', ['name' => 'Чайник 3', 'description' => '<p>Description 3</p>']);
        $this->product(4, 'en-US', ['name' => 'Kettle 4', 'description' => '<p>Description 4</p>']);
        $this->product(5, 'en-US', ['name' => 'Kettle 5', 'description' => '']);

        $this->model = new class implements TextGenerationProviderInterface {
            /** @var list<string> */
            public array $prompts = [];
            public bool $fail = false;

            public function code(): string
            {
                return 'stub';
            }

            public function enabled(): bool
            {
                return true;
            }

            public function generate(string $prompt, ?string $systemInstruction = null): string
            {
                $this->prompts[] = $prompt;
                if ($this->fail) {
                    throw new \RuntimeException('HTTP 500: provider down');
                }
                preg_match('~Output language: (\w+)~', $prompt, $lang);
                if (!str_contains($prompt, '<data name="fields">')) {
                    // a writing task (descriptions, SEO): one text per requested key, built from the product name
                    preg_match('~JSON keys: ([^\n]+)~', $prompt, $keys);
                    preg_match('~<data name="(?:name|title)">(.*?)</data>~s', $prompt, $name);

                    return json_encode(array_fill_keys(array_map('trim', explode(',', $keys[1])), 'AI for ' . ($name[1] ?? '?')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
                preg_match('~<data name="fields">(.*)</data>~s', $prompt, $m);
                $fields = json_decode($m[1], true, 8, JSON_THROW_ON_ERROR);

                return json_encode(array_map(static fn (string $v): string => strtoupper(substr($lang[1], 0, 2)) . ':' . $v, $fields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        };
        $settings = new AiSettings($db, new SecretVault(str_repeat('k', 32)));
        $ai = new AiProviderRegistry([$this->model]);
        $translations = $this->translationProviders = new TranslationProviderRegistry();
        $loader = new TrustedExtensionRuntimeLoader($db, new TrustedExtensionRuntimeRegistry(), new PaymentProviderRegistry([]), new DeliveryProviderRegistry([]), new ProductBlockRegistry([]), $ai, $translations, new \Commerce\Core\Extension\ExtensionServiceRegistry());
        $tasks = new AiTaskService($settings, new MockHttpClient(), $ai, $translations, $loader, new AiGlossary($db));
        $routes = &$this->routes;
        $repository = new class ($routes) implements SeoUrlRepositoryInterface {
            /** @param list<string> $routes */
            public function __construct(private array &$routes)
            {
            }

            public function pathIsReserved(int $storeId, string $locale, string $path, ?int $exceptRouteId = null): bool
            {
                return false;
            }

            public function findByEntity(int $storeId, string $locale, SeoEntityType $entityType, string $entityPublicId): ?SeoRoute
            {
                $this->routes[] = $entityType->value . ':' . $locale;

                return new SeoRoute(1, $storeId, $locale, $entityType, $entityPublicId, 'slug', 'slug');
            }

            public function create(int $storeId, string $locale, SeoEntityType $entityType, string $entityPublicId, string $slug, string $path): SeoRoute
            {
                throw new \LogicException('not used');
            }

            public function changeCanonical(SeoRoute $route, string $slug, string $path): SeoRoute
            {
                return $route;
            }

            public function addRedirectAlias(SeoRoute $route, string $sourcePath, string $reason = 'legacy_import'): void
            {
            }

            public function setIndexable(int $storeId, string $locale, SeoEntityType $entityType, string $entityPublicId, bool $indexable): void
            {
            }

            public function resolve(int $storeId, string $locale, string $path): SeoRouteResolution
            {
                throw new \LogicException('not used');
            }
        };
        $seo = new SeoUrlManager($repository, new SlugGenerator(), (new \ReflectionClass(SeoPathPolicy::class))->newInstanceWithoutConstructor());
        $bus = new class implements EventBusInterface {
            public function publish(DomainEvent ...$events): void
            {
            }
        };
        $catalog = new CatalogTranslationService($db, $seo, new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements()), $bus, new DomainEventFactory(new PublicIdFactory()));
        $this->batches = new AiBatchService($db, $tasks, $settings, $catalog, $seo, $loader, $ai, $translations);
    }

    /** @param array<string,string> $fields */
    private function product(int $id, string $locale, array $fields, int $fallback = 0): void
    {
        $this->db->insert('mc_product_translation', $fields + ['product_id' => $id, 'store_id' => 1, 'locale' => $locale, 'created_at' => 'x', 'updated_at' => 'x', 'is_fallback' => $fallback]);
    }

    /** @return list<int> product ids with a job in the given locale */
    private function queued(string $locale = 'en-US'): array
    {
        return array_map('intval', $this->db->fetchFirstColumn("SELECT entity_id FROM mc_ai_batch_job WHERE entity_type='product' AND locale=? ORDER BY entity_id", [$locale]));
    }

    private function start(string $mode = 'missing', array $locales = ['en-US']): string
    {
        return $this->batches->start(1, 'owner@example.test', ['kind' => 'translate', 'entity_types' => ['product'], 'locales' => $locales, 'mode' => $mode, 'provider' => 'stub']);
    }

    public function testOnlyUntranslatedIsQueuedByDefaultAndEverythingWhenOverwriting(): void
    {
        $this->start();
        self::assertSame([1, 2, 3, 5], $this->queued(), 'absent, fallback, copy of the main language and an empty field');
        $this->db->executeStatement("UPDATE mc_ai_batch SET status='done'");
        $this->start('overwrite');
        self::assertSame([1, 2, 3, 4, 5], array_values(array_unique($this->queued())));
    }

    public function testStartingAgainDoesNotQueueTheSameJobTwice(): void
    {
        $this->start();
        try {
            $this->start();
            self::fail('nothing new to queue');
        } catch (\DomainException) {
        }
        $this->start('missing', ['en-US', 'de-DE']);
        self::assertSame([1, 2, 3, 5], $this->queued('en-US'), 'still one job per product and language');
        self::assertSame([1, 2, 3, 4, 5], $this->queued('de-DE'));
    }

    public function testOneRequestPerProductAndLanguageAndTheResultIsSavedAsARealTranslation(): void
    {
        $id = $this->start();
        $result = $this->batches->process(20, 30.0);

        self::assertSame(['done' => 4, 'failed' => 0, 'pending' => 0, 'paused_for_limit' => false], $result);
        self::assertCount(4, $this->model->prompts, 'one model call per product and language');
        $row = $this->db->fetchAssociative("SELECT name,description,is_fallback FROM mc_product_translation WHERE product_id=1 AND locale='en-US'");
        self::assertSame(['name' => 'EN:Чайник 1', 'description' => 'EN:<p>Опис 1</p>', 'is_fallback' => 0], ['name' => $row['name'], 'description' => $row['description'], 'is_fallback' => (int) $row['is_fallback']]);
        self::assertSame(0, (int) $this->db->fetchOne("SELECT is_fallback FROM mc_product_translation WHERE product_id=2 AND locale='en-US'"));
        $five = $this->db->fetchAssociative("SELECT name,description FROM mc_product_translation WHERE product_id=5 AND locale='en-US'");
        self::assertSame('Kettle 5', $five['name'], 'a translated field stays as it was');
        self::assertSame('EN:<p>Опис 5</p>', $five['description']);
        self::assertStringNotContainsString('Чайник 5', implode('', $this->model->prompts), 'only the missing field of product 5 was sent');
        self::assertContains('product:en-US', $this->routes, 'the address of the language is ensured like a manual save');

        $progress = $this->batches->progress(1);
        self::assertSame($id, $progress[0]['batch']['public_id']);
        self::assertSame('done', $progress[0]['batch']['status']);
        self::assertSame(['done' => 4, 'failed' => 0, 'pending' => 0, 'skipped' => 0, 'review' => 0], $progress[0]['per_locale']['en-US']);
    }

    public function testDailyLimitPausesTheBatchUntilTheNextDay(): void
    {
        $this->db->insert('mc_ai_settings', ['store_id' => 1, 'daily_limit' => 2, 'updated_at' => 'x']);
        $id = $this->start();
        $result = $this->batches->process(20, 30.0);

        self::assertSame(['done' => 2, 'failed' => 0, 'pending' => 2, 'paused_for_limit' => true], $result);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_ai_usage'), 'never more requests than the limit');
        self::assertSame('limit', $this->batches->progress(1)[0]['batch']['status']);
        $this->batches->process(20, 30.0);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_ai_usage'), 'the same day nothing more is sent');

        $this->db->executeStatement("UPDATE mc_ai_usage SET created_at='2000-01-01 10:00:00'"); // a new day
        $result = $this->batches->process(20, 30.0);
        self::assertSame(['done' => 2, 'failed' => 0, 'pending' => 0, 'paused_for_limit' => false], $result);
        self::assertSame('done', $this->batches->progress(1)[0]['batch']['status']);
        self::assertSame($id, $this->batches->progress(1)[0]['batch']['public_id']);
    }

    public function testProviderErrorsAreRetriedThreeTimesThenTheJobFailsAndCanBeRetried(): void
    {
        $id = $this->start();
        $this->model->fail = true;
        for ($round = 0; $round < 3; ++$round) {
            $this->batches->process(20, 30.0);
        }
        $progress = $this->batches->progress(1)[0];
        self::assertSame(['done' => 0, 'failed' => 4, 'pending' => 0, 'skipped' => 0, 'review' => 0], $progress['per_locale']['en-US']);
        self::assertStringContainsString('HTTP 500: provider down', (string) $this->db->fetchOne("SELECT error FROM mc_ai_batch_job LIMIT 1"));

        $this->model->fail = false;
        self::assertSame(4, $this->batches->retryFailed($id));
        self::assertSame(4, $this->batches->process(20, 30.0)['done']);
    }

    public function testPausedOrCancelledBatchIsNotProcessed(): void
    {
        $id = $this->start();
        $this->batches->pause($id);
        self::assertSame(0, $this->batches->process(20, 30.0)['done']);
        self::assertSame([], $this->model->prompts);
        $this->batches->resume($id);
        self::assertSame(4, $this->batches->process(20, 30.0)['done']);

        $this->db->executeStatement("UPDATE mc_ai_batch SET status='done'");
        $other = $this->start('missing', ['de-DE']);
        $this->batches->cancel($other);
        self::assertSame(0, $this->batches->process(20, 30.0)['done']);
        self::assertSame('cancelled', $this->batches->progress(1)[0]['batch']['status']);
    }
    public function testLimitSwitchedOffPausesInsteadOfFailing(): void
    {
        $this->db->insert('mc_ai_settings', ['store_id' => 1, 'daily_limit' => 0, 'updated_at' => 'x']);
        $this->start();
        self::assertSame(['done' => 0, 'failed' => 0, 'pending' => 4, 'paused_for_limit' => true], $this->batches->process(20, 30.0));
        self::assertSame([], $this->model->prompts);
        self::assertSame('limit', $this->batches->progress(1)[0]['batch']['status']);
    }

    /** @param list<int> $ids @return list<string> */
    private function publicIds(array $ids): array
    {
        return array_map(fn (int $id): string => Uuid::fromBinary((string) $this->db->fetchOne('SELECT public_id FROM mc_product WHERE id=?', [$id]))->toRfc4122(), $ids);
    }

    /** @param list<int> $ids */
    private function generate(string $task = 'product_draft', array $ids = [1, 2], string $mode = 'missing', string $provider = 'stub'): string
    {
        return $this->batches->start(1, 'owner@example.test', ['kind' => 'generate', 'task' => $task, 'product_ids' => $this->publicIds($ids), 'mode' => $mode, 'provider' => $provider]);
    }

    private function text(int $id, string $field, string $locale = 'uk-UA'): ?string
    {
        $value = $this->db->fetchOne("SELECT $field FROM mc_product_translation WHERE product_id=? AND locale=?", [$id, $locale]);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return list<int> */
    private function reviewIds(): array
    {
        return array_map('intval', $this->db->fetchFirstColumn("SELECT id FROM mc_ai_batch_job WHERE status='review' ORDER BY id"));
    }

    public function testGeneratedTextWaitsForReviewAndTheProductStaysAsItWas(): void
    {
        $this->generate();
        $result = $this->batches->process(20, 30.0);

        self::assertSame(['done' => 2, 'failed' => 0, 'pending' => 0, 'paused_for_limit' => false], $result);
        self::assertCount(2, $this->reviewIds());
        self::assertNull($this->text(1, 'short_description'), 'nothing is written before the owner applies it');
        self::assertSame('<p>Опис 1</p>', $this->text(1, 'description'));
        self::assertCount(2, $this->model->prompts, 'one request per product');
    }

    public function testOnlyEmptyFieldsAreFilledByDefaultAndAllFieldsWhenOverwriting(): void
    {
        $this->generate();
        $this->batches->process(20, 30.0);
        $items = $this->batches->review(1, 1);
        self::assertSame(['short_description'], array_column($items[0]['changes'], 'field'), 'the description is filled, so only the empty short text is proposed');
        self::assertSame('', $items[0]['changes'][0]['before']);
        self::assertSame('AI for Чайник 1', $items[0]['changes'][0]['after']);
        self::assertSame('Чайник 1', $items[0]['name']);

        $this->db->executeStatement("UPDATE mc_ai_batch_job SET status='rejected'");
        $this->generate('product_draft', [1], 'overwrite');
        $this->batches->process(20, 30.0);
        self::assertSame(['short_description', 'description'], array_column($this->batches->review(1, 1)[0]['changes'], 'field'));
    }

    public function testApplyWritesTheFieldsAndUndoPutsTheOldTextBack(): void
    {
        $this->generate('seo_meta', [1, 2]);
        $this->batches->process(20, 30.0);
        [$first, $second] = $this->reviewIds();

        self::assertSame([$first], $this->batches->applyJobs(1, [$first]));
        self::assertSame('AI for Чайник 1', $this->text(1, 'meta_title'));
        self::assertSame('AI for Чайник 1', $this->text(1, 'meta_description'));
        self::assertNull($this->text(2, 'meta_title'), 'the other product is still waiting');
        self::assertSame([$second], $this->reviewIds());
        self::assertSame('applied', $this->db->fetchOne('SELECT status FROM mc_ai_batch_job WHERE id=?', [$first]));
        self::assertSame([], $this->batches->applyJobs(1, [$first]), 'a job is applied once');

        self::assertSame(1, $this->batches->undo(1, [$first]));
        self::assertNull($this->text(1, 'meta_title'), 'the previous (empty) text is back');
        self::assertSame([$first, $second], $this->reviewIds(), 'an undone job can be looked at again');
    }

    public function testUndoDoesNotTouchATextTheOwnerEditedAfterwards(): void
    {
        $this->generate('seo_meta', [1]);
        $this->batches->process(20, 30.0);
        $ids = $this->batches->applyJobs(1, $this->reviewIds());
        $this->db->executeStatement("UPDATE mc_product_translation SET meta_title='Мій заголовок' WHERE product_id=1 AND locale='uk-UA'");

        self::assertSame(1, $this->batches->undo(1, $ids));
        self::assertSame('Мій заголовок', $this->text(1, 'meta_title'));
        self::assertNull($this->text(1, 'meta_description'));
    }

    public function testApplyKeepsATextTheOwnerFilledInWhileTheJobWaited(): void
    {
        $this->generate('seo_meta', [1]);
        $this->batches->process(20, 30.0);
        $this->db->executeStatement("UPDATE mc_product_translation SET meta_title='Мій заголовок' WHERE product_id=1 AND locale='uk-UA'");

        $this->batches->applyJobs(1, $this->reviewIds());
        self::assertSame('Мій заголовок', $this->text(1, 'meta_title'));
        self::assertSame('AI for Чайник 1', $this->text(1, 'meta_description'));
    }

    public function testRejectedJobsChangeNothing(): void
    {
        $this->generate();
        $this->batches->process(20, 30.0);
        self::assertSame(2, $this->batches->reject(1, $this->reviewIds()));
        self::assertNull($this->text(1, 'short_description'));
        self::assertSame([], $this->reviewIds());
        self::assertSame([], $this->batches->review(1, 1));
    }

    public function testAnotherStoreCannotApplyTheJob(): void
    {
        $this->generate();
        $this->batches->process(20, 30.0);
        self::assertSame([], $this->batches->applyJobs(2, $this->reviewIds()));
        self::assertSame(0, $this->batches->reject(2, $this->reviewIds()));
        self::assertCount(2, $this->reviewIds());
    }

    public function testATranslationOnlyProviderCannotWriteTexts(): void
    {
        $this->translationProviders->register(new class implements \Commerce\Modules\Ai\Contract\TranslationProviderInterface {
            public function code(): string
            {
                return 'deepl_stub';
            }

            public function label(): string
            {
                return 'DeepL';
            }

            public function enabled(): bool
            {
                return true;
            }

            public function translate(string $text, string $sourceLocale, string $targetLocale): string
            {
                return $text;
            }
        });
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(\Commerce\Core\I18n\CanonicalUiText::get('admin.ai.batch.error_provider'));
        $this->generate('product_draft', [1], 'missing', 'deepl_stub');
    }

    public function testNoSecondJobForAProductAndTaskWhileOneWaitsForReview(): void
    {
        $this->generate();
        $this->batches->process(20, 30.0);
        $this->db->executeStatement("UPDATE mc_ai_batch SET status='done'"); // the batch is over, the texts still wait for the owner
        try {
            $this->generate('product_draft', [1, 2]);
            self::fail('both products already wait for review');
        } catch (\DomainException) {
        }
        $this->generate('seo_meta', [1]);
        $this->batches->reject(1, $this->reviewIds());
        $this->generate('product_draft', [1]);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_ai_batch_job WHERE status='pending' AND task='product_draft'"), 'a rejected job no longer blocks a new one');
    }

    public function testProgressCountsTextsWaitingForReviewApartFromDone(): void
    {
        $this->generate();
        $this->batches->process(20, 30.0);
        $progress = $this->batches->progress(1)[0];

        self::assertSame(['done' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0, 'review' => 2], $progress['totals']);
        self::assertSame(2, $progress['per_locale']['uk-UA']['review']);
        self::assertSame('done', $progress['batch']['status'], 'the work is over, the reviewing is the owner\'s');
        $this->batches->applyJobs(1, [$this->reviewIds()[0]]);
        $after = $this->batches->progress(1)[0]['totals'];
        self::assertSame(1, $after['done']);
        self::assertSame(1, $after['review']);
        self::assertSame(1, $this->batches->reviewCount(1));
    }

    public function testNothingIsQueuedForProductsThatAreAlreadyFilled(): void
    {
        $this->db->executeStatement("UPDATE mc_product_translation SET short_description='Коротко' WHERE locale='uk-UA'");
        $this->expectException(\DomainException::class);
        $this->generate('product_draft', [1, 2]);
    }

    public function testALimitHitDuringGenerationKeepsTheJobsWaiting(): void
    {
        $this->db->insert('mc_ai_settings', ['store_id' => 1, 'daily_limit' => 1, 'updated_at' => 'x']);
        $this->generate();
        $result = $this->batches->process(20, 30.0);
        self::assertSame(['done' => 1, 'failed' => 0, 'pending' => 1, 'paused_for_limit' => true], $result);
        self::assertCount(1, $this->reviewIds());
    }
}
