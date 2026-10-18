<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;

final readonly class LayoutRevisionStore
{
    public function __construct(private Connection $db, private LayoutSchemaValidator $validator, private SafeLayoutProvider $safe, private ?LayoutPreviewToken $preview = null, private ?\Symfony\Component\HttpFoundation\RequestStack $requests = null)
    {
    }

    /** @return array<string,mixed> the layout a storefront request shows, else the built-in layout of the type */
    public function active(int $storeId, string $type): array
    {
        return $this->activeOrNull($storeId, $type)
            ?? match ($type) { 'product' => $this->safe->product(), 'checkout' => $this->safe->checkout(), 'category' => $this->safe->category(), 'cart' => $this->safe->cart(), default => $this->safe->storefront() };
    }

    /** @return array<string,mixed>|null the draft under a valid preview link of this store and type, else the published layout */
    public function activeOrNull(int $storeId, string $type): ?array
    {
        $this->assertType($type);
        $token = (string) $this->requests?->getMainRequest()?->query->get('_layout_preview', '');
        if ($token !== '' && $this->preview?->valid($token, $storeId, $type) === true) {
            $draft = $this->draft($storeId, $type);
            if ($draft !== null) {
                return $draft;
            }
        }

        return $this->publishedOrNull($storeId, $type);
    }

    /** @return array<string,mixed>|null */
    public function publishedOrNull(int $storeId, string $type): ?array
    {
        $this->assertType($type);
        try {
            $raw = $this->db->fetchOne('SELECT payload FROM mc_layout_revision WHERE store_id=? AND layout_type=? AND status=? ORDER BY id DESC LIMIT 1', [$storeId,$type,'published']);
            if (!is_string($raw) || $raw === '') return null;
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            return is_array($data) ? $this->validator->validate($data, $type) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public function draft(int $storeId, string $type): ?array
    {
        $this->assertType($type);
        $raw = $this->db->fetchOne('SELECT payload FROM mc_layout_revision WHERE store_id=? AND layout_type=? AND status=? ORDER BY id DESC LIMIT 1', [$storeId,$type,'draft']);
        if (!is_string($raw) || $raw === '') return null;
        try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); return is_array($data) ? $this->validator->validate($data, $type) : null; } catch (\Throwable) { return null; }
    }

    /**
     * The newest revision of the type is its version: a draft save, a publication and a rollback all add one.
     *
     * @return array{draft_id:?int, published_id:?int, version:int, saved_by:?int, saved_at:?string}
     */
    public function current(int $storeId, string $type): array
    {
        $this->assertType($type);
        $ids = $this->db->fetchAllKeyValue("SELECT status, MAX(id) FROM mc_layout_revision WHERE store_id=? AND layout_type=? AND status IN ('draft','published') GROUP BY status", [$storeId, $type]);
        $latest = $this->latest($this->db, $storeId, $type, false);

        return ['draft_id' => isset($ids['draft']) ? (int) $ids['draft'] : null, 'published_id' => isset($ids['published']) ? (int) $ids['published'] : null] + $latest;
    }

    /**
     * Without a base version the last write wins, as before. With one, a newer revision stops the write unless forced;
     * a forced draft archives the draft it replaces so the other version stays in history.
     *
     * @param array<string,mixed> $layout
     */
    public function saveDraft(int $storeId, string $type, array $layout, ?int $adminId = null, ?int $baseVersion = null, bool $force = false): int
    {
        $this->assertType($type);
        $validated=$this->validator->validate($layout, $type, true); $now=gmdate('Y-m-d H:i:s.u');
        return $this->db->transactional(function(Connection $db) use($storeId,$type,$validated,$adminId,$now,$baseVersion,$force): int {
            $overwritten = $this->guard($db, $storeId, $type, $baseVersion, $force);
            if ($overwritten) {
                $db->update('mc_layout_revision',['status'=>'archived'],['store_id'=>$storeId,'layout_type'=>$type,'status'=>'draft']);
            } else {
                $db->delete('mc_layout_revision',['store_id'=>$storeId,'layout_type'=>$type,'status'=>'draft']);
            }
            $db->insert('mc_layout_revision',['store_id'=>$storeId,'layout_type'=>$type,'status'=>'draft','schema_version'=>1,'payload'=>json_encode($validated,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_by'=>$adminId,'created_at'=>$now,'published_at'=>null]);
            return (int)$db->lastInsertId();
        });
    }

    /** @param array<string,mixed> $layout */
    public function publish(int $storeId, string $type, array $layout, ?int $adminId = null, ?int $baseVersion = null, bool $force = false): int
    {
        $this->assertType($type);
        $validated = $this->validator->validate($layout, $type, true);
        $now = gmdate('Y-m-d H:i:s.u');
        return $this->db->transactional(function (Connection $db) use ($storeId,$type,$validated,$adminId,$now,$baseVersion,$force): int {
            $this->guard($db, $storeId, $type, $baseVersion, $force);
            $db->update('mc_layout_revision', ['status'=>'archived'], ['store_id'=>$storeId,'layout_type'=>$type,'status'=>'published']);
            $db->insert('mc_layout_revision', [
                'store_id'=>$storeId,'layout_type'=>$type,'status'=>'published','schema_version'=>1,
                'payload'=>json_encode($validated, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'created_by'=>$adminId,'created_at'=>$now,'published_at'=>$now,
            ]);
            return (int) $db->lastInsertId();
        });
    }

    /** @return list<array<string,mixed>> */
    public function history(int $storeId, string $type, int $limit = 20): array
    {
        $this->assertType($type);
        $limit = max(1, min(50, $limit));
        return $this->db->fetchAllAssociative('SELECT id,status,created_by,created_at,published_at FROM mc_layout_revision WHERE store_id=? AND layout_type=? ORDER BY id DESC LIMIT ' . $limit, [$storeId,$type]);
    }

    public function rollback(int $storeId, string $type, int $revisionId, ?int $adminId = null, ?int $baseVersion = null, bool $force = false): int
    {
        $raw = $this->db->fetchOne('SELECT payload FROM mc_layout_revision WHERE id=? AND store_id=? AND layout_type=?', [$revisionId,$storeId,$type]);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.61cf9158f649'));
        }
        $layout = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($layout)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0b19595a5f32'));
        }
        // An old revision may hold values that today's field checks reject; they are dropped as on reading, not refused.
        return $this->publish($storeId,$type,$this->validator->validate($layout, $type),$adminId,$baseVersion,$force);
    }

    /**
     * Inside the write transaction: the store row is locked first, so two editors of one layout queue here and the
     * second one reads the revision the first has just committed. Returns whether a newer revision is being overwritten.
     */
    private function guard(Connection $db, int $storeId, string $type, ?int $baseVersion, bool $force): bool
    {
        if ($baseVersion === null) {
            return false;
        }
        $mysql = $db->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql) {
            $db->fetchOne('SELECT id FROM mc_store WHERE id=? FOR UPDATE', [$storeId]);
        }
        $latest = $this->latest($db, $storeId, $type, $mysql);
        if ($latest['version'] === $baseVersion) {
            return false;
        }
        if (!$force) {
            throw new LayoutConflictException($latest['version'], $latest['saved_by'], (string) $latest['saved_at']);
        }

        return true;
    }

    /** @return array{version:int, saved_by:?int, saved_at:?string} */
    private function latest(Connection $db, int $storeId, string $type, bool $locking): array
    {
        $row = $db->fetchAssociative('SELECT id, created_by, created_at FROM mc_layout_revision WHERE store_id=? AND layout_type=? ORDER BY id DESC LIMIT 1' . ($locking ? ' FOR UPDATE' : ''), [$storeId, $type]);
        if ($row === false) {
            return ['version' => 0, 'saved_by' => null, 'saved_at' => null];
        }

        return [
            'version' => (int) $row['id'],
            'saved_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'saved_at' => (new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, ['home','product','checkout','category','cart'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6b74c5d04543'));
        }
    }
}
