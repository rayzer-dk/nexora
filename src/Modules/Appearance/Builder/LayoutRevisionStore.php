<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class LayoutRevisionStore
{
    public function __construct(private Connection $db, private LayoutSchemaValidator $validator, private SafeLayoutProvider $safe, private ?LayoutPreviewToken $preview = null, private ?\Symfony\Component\HttpFoundation\RequestStack $requests = null)
    {
    }

    /** @return array<string,mixed> */
    public function active(int $storeId, string $type): array
    {
        $this->assertType($type);
        // A signed preview link shows the saved draft instead of the published layout (only for that page view).
        $token = (string) $this->requests?->getMainRequest()?->query->get('_layout_preview', '');
        if ($token !== '' && $this->preview?->valid($token, $storeId, $type) === true) {
            $draft = $this->draft($storeId, $type);
            if ($draft !== null) {
                return $draft;
            }
        }
        try {
            $raw = $this->db->fetchOne('SELECT payload FROM mc_layout_revision WHERE store_id=? AND layout_type=? AND status=? ORDER BY id DESC LIMIT 1', [$storeId,$type,'published']);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    return $this->validator->validate($data, $type);
                }
            }
        } catch (\Throwable) {
        }
        return match ($type) { 'product' => $this->safe->product(), 'checkout' => $this->safe->checkout(), 'category' => $this->safe->category(), 'cart' => $this->safe->cart(), default => $this->safe->storefront() };
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

    /** @param array<string,mixed> $layout */
    public function saveDraft(int $storeId, string $type, array $layout, ?int $adminId = null): int
    {
        $this->assertType($type);
        $validated=$this->validator->validate($layout, $type); $now=gmdate('Y-m-d H:i:s.u');
        return $this->db->transactional(function(Connection $db) use($storeId,$type,$validated,$adminId,$now): int {
            $db->delete('mc_layout_revision',['store_id'=>$storeId,'layout_type'=>$type,'status'=>'draft']);
            $db->insert('mc_layout_revision',['store_id'=>$storeId,'layout_type'=>$type,'status'=>'draft','schema_version'=>1,'payload'=>json_encode($validated,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_by'=>$adminId,'created_at'=>$now,'published_at'=>null]);
            return (int)$db->lastInsertId();
        });
    }

    /** @param array<string,mixed> $layout */
    public function publish(int $storeId, string $type, array $layout, ?int $adminId = null): int
    {
        $this->assertType($type);
        $validated = $this->validator->validate($layout, $type);
        $now = gmdate('Y-m-d H:i:s.u');
        return $this->db->transactional(function (Connection $db) use ($storeId,$type,$validated,$adminId,$now): int {
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

    public function rollback(int $storeId, string $type, int $revisionId, ?int $adminId = null): int
    {
        $raw = $this->db->fetchOne('SELECT payload FROM mc_layout_revision WHERE id=? AND store_id=? AND layout_type=?', [$revisionId,$storeId,$type]);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.61cf9158f649'));
        }
        $layout = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($layout)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0b19595a5f32'));
        }
        return $this->publish($storeId,$type,$layout,$adminId);
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, ['home','product','checkout','category','cart'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6b74c5d04543'));
        }
    }
}
