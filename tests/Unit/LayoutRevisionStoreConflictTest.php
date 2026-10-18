<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\Appearance\Builder\LayoutConflictException;
use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;
use Commerce\Modules\Appearance\Builder\LayoutSchemaValidator;
use Commerce\Modules\Appearance\Builder\SafeLayoutProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/** A save, publication or rollback started from an older version stops with the author of the newer one; `force` writes and keeps the other version in history. */
final class LayoutRevisionStoreConflictTest extends TestCase
{
    private const STORE = 2;

    private Connection $db;

    private LayoutRevisionStore $store;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_layout_revision (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INT, layout_type TEXT, status TEXT, schema_version INT, payload TEXT, created_by INT NULL, created_at TEXT, published_at TEXT NULL)');
        $this->db->executeStatement('CREATE TABLE mc_extension_installation (id INTEGER PRIMARY KEY, code TEXT, version TEXT, install_path TEXT, manifest_json TEXT, status TEXT)');
        $this->store = new LayoutRevisionStore($this->db, new LayoutSchemaValidator(new ExtensionContributionRegistry($this->db)), new SafeLayoutProvider());
    }

    public function testCurrentIsTheNewestRevisionOfTheType(): void
    {
        self::assertSame(['draft_id' => null, 'published_id' => null, 'version' => 0, 'saved_by' => null, 'saved_at' => null], $this->store->current(self::STORE, 'home'));
        $published = $this->store->publish(self::STORE, 'home', $this->layout('one'), 7);
        $draft = $this->store->saveDraft(self::STORE, 'home', $this->layout('two'), 8);
        $this->store->saveDraft(self::STORE, 'cart', ['schema_version' => 1, 'blocks' => [['id' => 'cart_lines_1', 'component' => 'cart_lines']]], 9);

        $current = $this->store->current(self::STORE, 'home');
        self::assertSame([$draft, $published, $draft, 8], [$current['draft_id'], $current['published_id'], $current['version'], $current['saved_by']]);
        self::assertNotNull($current['saved_at']);
    }

    public function testStaleDraftSaveIsRefusedWithTheNewerAuthor(): void
    {
        $opened = $this->store->current(self::STORE, 'home')['version'];
        $theirs = $this->store->saveDraft(self::STORE, 'home', $this->layout('theirs'), 5, $opened);

        try {
            $this->store->saveDraft(self::STORE, 'home', $this->layout('mine'), 6, $opened);
            self::fail('A stale draft save must conflict.');
        } catch (LayoutConflictException $e) {
            self::assertSame([$theirs, 5], [$e->currentVersion, $e->savedBy]);
            self::assertNotSame('', $e->savedAt);
        }
        self::assertSame('theirs', $this->store->draft(self::STORE, 'home')['blocks'][0]['props']['title'] ?? null);
    }

    public function testSameAuthorInTwoTabsAlsoConflicts(): void
    {
        $opened = $this->store->current(self::STORE, 'home')['version'];
        $this->store->saveDraft(self::STORE, 'home', $this->layout('tab one'), 5, $opened);

        $this->expectException(LayoutConflictException::class);
        $this->store->saveDraft(self::STORE, 'home', $this->layout('tab two'), 5, $opened);
    }

    public function testOwnNextSaveFromReturnedVersionPasses(): void
    {
        $version = $this->store->saveDraft(self::STORE, 'home', $this->layout('first'), 5, 0);
        $next = $this->store->saveDraft(self::STORE, 'home', $this->layout('second'), 5, $version);

        self::assertGreaterThan($version, $next);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_layout_revision WHERE status='draft'"));
    }

    public function testForcedDraftKeepsTheOverwrittenDraftInHistory(): void
    {
        $theirs = $this->store->saveDraft(self::STORE, 'home', $this->layout('theirs'), 5, 0);
        $mine = $this->store->saveDraft(self::STORE, 'home', $this->layout('mine'), 6, 0, true);

        self::assertSame('mine', $this->store->draft(self::STORE, 'home')['blocks'][0]['props']['title'] ?? null);
        $history = array_column($this->store->history(self::STORE, 'home'), 'status', 'id');
        self::assertSame('draft', $history[$mine] ?? null);
        self::assertSame('archived', $history[$theirs] ?? null);
    }

    public function testStalePublishConflictsAndForcedPublishArchivesTheirs(): void
    {
        $opened = $this->store->current(self::STORE, 'home')['version'];
        $theirs = $this->store->publish(self::STORE, 'home', $this->layout('theirs'), 5, $opened);

        try {
            $this->store->publish(self::STORE, 'home', $this->layout('mine'), 6, $opened);
            self::fail('A stale publication must conflict.');
        } catch (LayoutConflictException $e) {
            self::assertSame($theirs, $e->currentVersion);
        }
        $this->store->publish(self::STORE, 'home', $this->layout('mine'), 6, $opened, true);

        self::assertSame('mine', $this->store->publishedOrNull(self::STORE, 'home')['blocks'][0]['props']['title'] ?? null);
        self::assertSame('archived', array_column($this->store->history(self::STORE, 'home'), 'status', 'id')[$theirs] ?? null);
    }

    public function testRollbackChecksTheVersionToo(): void
    {
        $old = $this->store->publish(self::STORE, 'home', $this->layout('old'), 5);
        $opened = $this->store->current(self::STORE, 'home')['version'];
        $this->store->publish(self::STORE, 'home', $this->layout('their new'), 7, $opened);

        try {
            $this->store->rollback(self::STORE, 'home', $old, 6, $opened);
            self::fail('A rollback over a newer publication must conflict.');
        } catch (LayoutConflictException $e) {
            self::assertSame(7, $e->savedBy);
        }
        $this->store->rollback(self::STORE, 'home', $old, 6, $opened, true);
        self::assertSame('old', $this->store->publishedOrNull(self::STORE, 'home')['blocks'][0]['props']['title'] ?? null);
    }

    public function testCallersWithoutBaseKeepLastWriteWins(): void
    {
        $this->store->saveDraft(self::STORE, 'home', $this->layout('one'), 5);
        $this->store->saveDraft(self::STORE, 'home', $this->layout('two'), 6);

        self::assertSame('two', $this->store->draft(self::STORE, 'home')['blocks'][0]['props']['title'] ?? null);
    }

    /** @return array<string,mixed> */
    private function layout(string $title): array
    {
        return ['schema_version' => 1, 'blocks' => [['id' => 'hero_1', 'component' => 'hero', 'enabled' => true, 'props' => ['title' => $title], 'style' => [], 'visibility' => ['desktop' => true, 'tablet' => true, 'mobile' => true]]]];
    }
}
