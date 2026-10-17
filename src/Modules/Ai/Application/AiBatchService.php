<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\Extension\TrustedExtensionRuntimeLoader;
use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Catalog\Application\CatalogTranslationService;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Uid\Uuid;

/**
 * Catalogue work for the AI that is too big for one click: a batch is a list of jobs (one entity in one language), worked off in
 * the background by the scheduler and by the "process now" button. Each translate job is one translate_fields call, saved like a
 * manual translation. When today's AI limit is used up the batch waits in status "limit" and goes on by itself the next day.
 */
final class AiBatchService
{
    public const ENTITY_TYPES = ['product', 'category', 'brand'];
    public const ACTIVE = ['running', 'paused', 'limit'];
    private const MAX_ATTEMPTS = 3;
    private const LOCK = 'nexora_ai_batch';
    private const BRAND_FIELDS = ['description' => 100000, 'meta_title' => 255, 'meta_description' => 500];
    /** entity type => [store link table, its key, translation table, its key, entity table] */
    private const TABLES = [
        'product' => ['mc_store_product', 'product_id', 'mc_product_translation', 'product_id', 'mc_product'],
        'category' => ['mc_store_category', 'category_id', 'mc_category_translation', 'category_id', 'mc_category'],
        'brand' => ['mc_store_brand', 'brand_id', 'mc_brand_translation', 'brand_id', 'mc_brand'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly AiTaskService $tasks,
        private readonly AiSettings $settings,
        private readonly CatalogTranslationService $catalog,
        private readonly SeoUrlManager $seo,
        private readonly TrustedExtensionRuntimeLoader $extensions,
        private readonly AiProviderRegistry $registry,
        private readonly TranslationProviderRegistry $translations,
    ) {
    }

    /** @return array<string,int> field => max length of the entity type */
    public static function fields(string $entityType): array
    {
        return match ($entityType) {
            'product' => CatalogTranslationService::PRODUCT_FIELDS,
            'category' => CatalogTranslationService::CATEGORY_FIELDS,
            default => self::BRAND_FIELDS,
        };
    }

    /** @return list<array{code:string,label:string,translation_only:bool}> providers that can run for the store now */
    public function providers(int $storeId): array
    {
        try {
            $this->extensions->bootActive();
        } catch (\Throwable) {
        }
        $list = [];
        foreach ($this->settings->enabledProviders($storeId) as $p) {
            $list[] = $p + ['translation_only' => false];
        }
        foreach ($this->registry->enabledCodes() as $code) {
            $list[] = ['code' => $code, 'label' => $code, 'translation_only' => false];
        }
        foreach ($this->translations->enabled() as $p) {
            $list[] = $p + ['translation_only' => true];
        }

        return $list;
    }

    /**
     * Queues the jobs and returns the public id of the batch. A job that is already waiting in an active batch (same entity,
     * language and task) is not queued again; when nothing is left to queue, a \DomainException says so.
     *
     * @param array{kind:string,entity_types?:list<string>,locales?:list<string>,mode?:string,provider:string,task?:string,product_ids?:list<string>} $options
     */
    public function start(int $storeId, string $admin, array $options): string
    {
        $kind = ($options['kind'] ?? '') === 'generate' ? 'generate' : 'translate';
        $mode = ($options['mode'] ?? 'missing') === 'overwrite' ? 'overwrite' : 'missing';
        $provider = trim((string) ($options['provider'] ?? ''));
        if ($provider === '' || !in_array($provider, array_column($this->providers($storeId), 'code'), true)) {
            throw new \DomainException(CanonicalUiText::get('admin.ai.batch.error_provider'));
        }
        $default = $this->catalog->defaultLocale($storeId);
        $enabled = array_column($this->catalog->locales($storeId), 'code');
        $productIds = isset($options['product_ids']) ? $this->productIds($storeId, (array) $options['product_ids']) : null;
        if ($kind === 'generate') {
            $task = (string) ($options['task'] ?? '');
            if (!in_array($task, AiTaskService::tasks(), true)) {
                throw new \DomainException(CanonicalUiText::get('admin.ai.error_task'));
            }
            $types = ['product'];
            $locales = [$default];
        } else {
            $task = 'translate';
            $types = array_values(array_intersect(self::ENTITY_TYPES, (array) ($options['entity_types'] ?? [])));
            $locales = array_values(array_intersect($enabled, array_diff((array) ($options['locales'] ?? []), [$default])));
            if ($productIds !== null) {
                $types = ['product'];
            }
        }
        if ($types === []) {
            throw new \DomainException(CanonicalUiText::get('admin.ai.batch.error_types'));
        }
        if ($locales === []) {
            throw new \DomainException(CanonicalUiText::get('admin.ai.batch.error_locales'));
        }

        $queued = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT j.entity_type,j.entity_id,j.locale,j.task FROM mc_ai_batch_job j JOIN mc_ai_batch b ON b.id=j.batch_id WHERE j.store_id=? AND j.status='pending' AND b.status IN ('running','paused','limit')",
            [$storeId],
        ) as $row) {
            $queued[$row['entity_type'] . ':' . $row['entity_id'] . ':' . $row['locale'] . ':' . $row['task']] = true;
        }
        $jobs = [];
        foreach ($types as $type) {
            foreach ($locales as $locale) {
                $ids = $kind === 'translate' ? $this->untranslated($storeId, $type, $default, $locale, $mode, $productIds) : ($productIds ?? []);
                foreach ($ids as $id) {
                    if (!isset($queued[$type . ':' . $id . ':' . $locale . ':' . $task])) {
                        $jobs[] = [$type, $id, $locale];
                    }
                }
            }
        }
        if ($jobs === []) {
            throw new \DomainException(CanonicalUiText::get('admin.ai.batch.nothing'));
        }

        $publicId = Uuid::v4()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->transactional(function (Connection $db) use ($storeId, $admin, $kind, $types, $locales, $mode, $provider, $task, $productIds, $jobs, $publicId, $now): void {
            $db->insert('mc_ai_batch', [
                'public_id' => $publicId, 'store_id' => $storeId, 'kind' => $kind, 'status' => 'running', 'created_by' => mb_substr($admin, 0, 120),
                'options_json' => json_encode(['entity_types' => $types, 'locales' => $locales, 'mode' => $mode, 'provider' => $provider, 'task' => $task, 'products' => $productIds === null ? null : count($productIds)], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $batchId = (int) $db->lastInsertId();
            foreach (array_chunk($jobs, 200) as $chunk) {
                $sql = 'INSERT INTO mc_ai_batch_job (batch_id,store_id,entity_type,entity_id,locale,task,status,attempts) VALUES ' . implode(',', array_fill(0, count($chunk), "(?,?,?,?,?,?,'pending',0)"));
                $params = [];
                foreach ($chunk as [$type, $id, $locale]) {
                    array_push($params, $batchId, $storeId, $type, $id, $locale, $task);
                }
                $db->executeStatement($sql, $params);
            }
        });

        return $publicId;
    }

    /**
     * Works off the next jobs of running batches within the time budget. One process at a time (advisory lock on MySQL).
     *
     * @return array{done:int,failed:int,pending:int,paused_for_limit:bool}
     */
    public function process(int $limit, float $budgetSeconds, ?int $storeId = null): array
    {
        $result = ['done' => 0, 'failed' => 0, 'pending' => 0, 'paused_for_limit' => false];
        $mysql = $this->db->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql && (int) $this->db->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            $result['pending'] = $this->pendingCount($storeId);

            return $result;
        }
        try {
            $started = microtime(true);
            $this->wakeUpLimited($storeId);
            $blocked = []; // batches that hit the limit in this round
            $rows = $this->db->fetchAllAssociative(
                "SELECT j.id,j.batch_id,j.store_id,j.entity_type,j.entity_id,j.locale,j.attempts,b.options_json,b.created_by FROM mc_ai_batch_job j JOIN mc_ai_batch b ON b.id=j.batch_id
                 WHERE j.status='pending' AND j.task='translate' AND b.status='running'" . ($storeId !== null ? ' AND j.store_id=' . (int) $storeId : '') . ' ORDER BY j.id LIMIT ' . max(1, min(500, $limit)),
            );
            foreach ($rows as $row) {
                if (microtime(true) - $started > $budgetSeconds) {
                    break;
                }
                if (isset($blocked[(int) $row['batch_id']])) {
                    continue;
                }
                $outcome = $this->runTranslate($row);
                if ($outcome === 'limit') {
                    $blocked[(int) $row['batch_id']] = true;
                    $this->setStatus((int) $row['batch_id'], 'limit');
                    $result['paused_for_limit'] = true;
                } elseif ($outcome === 'done') {
                    ++$result['done'];
                } elseif ($outcome === 'failed') {
                    ++$result['failed'];
                }
            }
            $this->finishBatches($storeId);
            $result['pending'] = $this->pendingCount($storeId);

            return $result;
        } finally {
            if ($mysql) {
                $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
            }
        }
    }

    /**
     * The latest batches of the store (active ones first) with counts per language.
     *
     * @return list<array{batch:array<string,mixed>,per_locale:array<string,array{done:int,failed:int,pending:int,skipped:int}>,totals:array{done:int,failed:int,pending:int,skipped:int},failures:list<array<string,mixed>>}>
     */
    public function progress(int $storeId, int $limit = 10): array
    {
        $batches = $this->db->fetchAllAssociative(
            "SELECT id,public_id,kind,options_json,status,created_by,created_at,updated_at FROM mc_ai_batch WHERE store_id=? ORDER BY CASE WHEN status IN ('running','paused','limit') THEN 0 ELSE 1 END, id DESC LIMIT " . max(1, min(50, $limit)),
            [$storeId],
        );
        $out = [];
        foreach ($batches as $b) {
            $options = json_decode((string) $b['options_json'], true) ?: [];
            $perLocale = [];
            foreach ((array) ($options['locales'] ?? []) as $locale) {
                $perLocale[(string) $locale] = ['done' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];
            }
            $totals = ['done' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];
            foreach ($this->db->fetchAllAssociative('SELECT locale,status,COUNT(*) AS n FROM mc_ai_batch_job WHERE batch_id=? GROUP BY locale,status', [(int) $b['id']]) as $c) {
                $key = match ((string) $c['status']) {
                    'pending', 'failed', 'skipped' => (string) $c['status'],
                    'rejected' => 'skipped',
                    default => 'done',
                };
                $perLocale[(string) $c['locale']] ??= ['done' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];
                $perLocale[(string) $c['locale']][$key] += (int) $c['n'];
                $totals[$key] += (int) $c['n'];
            }
            $failures = [];
            foreach ($this->db->fetchAllAssociative("SELECT entity_type,entity_id,locale,error FROM mc_ai_batch_job WHERE batch_id=? AND status='failed' ORDER BY id DESC LIMIT 5", [(int) $b['id']]) as $f) {
                $failures[] = ['entity_type' => (string) $f['entity_type'], 'entity_id' => (int) $f['entity_id'], 'locale' => (string) $f['locale'], 'error' => (string) $f['error'], 'name' => $this->entityName($storeId, (string) $f['entity_type'], (int) $f['entity_id'])];
            }
            $out[] = [
                'batch' => ['public_id' => (string) $b['public_id'], 'kind' => (string) $b['kind'], 'status' => (string) $b['status'], 'options' => $options, 'created_by' => (string) $b['created_by'], 'created_at' => (string) $b['created_at'], 'updated_at' => (string) $b['updated_at']],
                'per_locale' => $perLocale,
                'totals' => $totals,
                'failures' => $failures,
            ];
        }

        return $out;
    }

    public function pause(string $publicId, ?int $storeId = null): void
    {
        $this->transition($publicId, $storeId, ['running', 'limit'], 'paused');
    }

    /** A batch paused by hand or by the limit goes on; if the limit is still used up, the next round puts it back to "limit". */
    public function resume(string $publicId, ?int $storeId = null): void
    {
        $this->transition($publicId, $storeId, ['paused', 'limit'], 'running');
    }

    /** Stops the batch for good: its waiting jobs are skipped; what was translated stays. */
    public function cancel(string $publicId, ?int $storeId = null): void
    {
        $id = $this->transition($publicId, $storeId, self::ACTIVE, 'cancelled');
        if ($id !== null) {
            $this->db->executeStatement("UPDATE mc_ai_batch_job SET status='skipped' WHERE batch_id=? AND status='pending'", [$id]);
        }
    }

    /** Puts the failed jobs back in the line and the batch back to work. Returns how many. */
    public function retryFailed(string $publicId, ?int $storeId = null): int
    {
        $id = $this->batchId($publicId, $storeId);
        if ($id === null) {
            return 0;
        }
        $count = $this->db->executeStatement("UPDATE mc_ai_batch_job SET status='pending',attempts=0,error=NULL WHERE batch_id=? AND status='failed'", [$id]);
        if ($count > 0) {
            $this->db->executeStatement("UPDATE mc_ai_batch SET status='running',updated_at=? WHERE id=? AND status IN ('done','limit')", [gmdate('Y-m-d H:i:s.u'), $id]);
        }

        return (int) $count;
    }

    /** @param array<string,mixed> $row a pending job joined with its batch @return 'done'|'failed'|'pending'|'skipped'|'limit' */
    private function runTranslate(array $row): string
    {
        $jobId = (int) $row['id'];
        $storeId = (int) $row['store_id'];
        $type = (string) $row['entity_type'];
        $entityId = (int) $row['entity_id'];
        $locale = (string) $row['locale'];
        $options = json_decode((string) $row['options_json'], true) ?: [];
        try {
            $default = $this->catalog->defaultLocale($storeId);
            $source = $this->texts($storeId, $type, $entityId, $default);
            $target = $this->texts($storeId, $type, $entityId, $locale);
            $send = $source === null ? [] : $this->fieldsToSend($type, $source, $target, ($options['mode'] ?? 'missing') === 'overwrite');
            if ($send === []) {
                $this->db->update('mc_ai_batch_job', ['status' => 'skipped', 'processed_at' => gmdate('Y-m-d H:i:s.u')], ['id' => $jobId]);

                return 'skipped';
            }
            $translated = $this->tasks->translateFields($storeId, (string) $row['created_by'], (string) ($options['provider'] ?? ''), $send, $locale, $default)['fields'];
            $merged = [];
            foreach (array_keys(self::fields($type)) as $field) {
                $merged[$field] = $translated[$field] ?? (string) ($target[$field] ?? '');
            }
            match ($type) {
                'product' => $this->catalog->saveProduct($storeId, $entityId, $locale, $merged),
                'category' => $this->catalog->saveCategory($storeId, $entityId, $locale, $merged),
                default => $this->saveBrand($storeId, $entityId, $locale, $merged),
            };
            $this->db->update('mc_ai_batch_job', ['status' => 'done', 'error' => null, 'processed_at' => gmdate('Y-m-d H:i:s.u')], ['id' => $jobId]);

            return 'done';
        } catch (AiLimitException $e) {
            if ($e->limit <= 0 || $e->needed <= $e->limit) {
                return 'limit'; // fits tomorrow (or once the owner turns the limit back on): the job waits as it is
            }

            return $this->fail($jobId, self::MAX_ATTEMPTS, CanonicalUiText::get('admin.ai.batch.too_long', ['needed' => $e->needed, 'limit' => $e->limit]));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($jobId, self::MAX_ATTEMPTS, $e->getMessage()); // a missing name or a switched-off language does not fix itself
        } catch (\Throwable $e) {
            return $this->fail($jobId, (int) $row['attempts'] + 1, $e->getMessage());
        }
    }

    /** @return 'failed'|'pending' */
    private function fail(int $jobId, int $attempts, string $error): string
    {
        $failed = $attempts >= self::MAX_ATTEMPTS;
        $this->db->update('mc_ai_batch_job', ['status' => $failed ? 'failed' : 'pending', 'attempts' => $attempts, 'error' => mb_substr($error, 0, 250), 'processed_at' => gmdate('Y-m-d H:i:s.u')], ['id' => $jobId]);

        return $failed ? 'failed' : 'pending';
    }

    /**
     * What to translate: every source text when overwriting or when the language has no real translation yet; otherwise only
     * the fields that are empty or still a copy of the main language.
     *
     * @param array<string,string> $source
     * @param array<string,string>|null $target
     * @return array<string,string>
     */
    private function fieldsToSend(string $type, array $source, ?array $target, bool $overwrite): array
    {
        $all = $overwrite || $target === null || (int) ($target['is_fallback'] ?? 0) === 1;
        $send = [];
        foreach (array_keys(self::fields($type)) as $field) {
            $text = trim((string) ($source[$field] ?? ''));
            if ($text === '') {
                continue;
            }
            $current = trim((string) ($target[$field] ?? ''));
            if ($all || $current === '' || $current === $text) {
                $send[$field] = $text;
            }
        }

        return $send;
    }

    /** @return array<string,string>|null the texts of one language, or null without a row */
    private function texts(int $storeId, string $type, int $entityId, string $locale): ?array
    {
        [, , $table, $key] = self::TABLES[$type];
        $row = $this->db->fetchAssociative('SELECT ' . implode(',', array_keys(self::fields($type))) . ",is_fallback FROM $table WHERE $key=? AND store_id=? AND locale=?", [$entityId, $storeId, $locale]);

        return is_array($row) ? array_map(static fn ($v): string => (string) ($v ?? ''), $row) : null;
    }

    /**
     * Ids of entities whose language $locale needs a translation: no row, a fallback copy, or a field that is empty or equal to
     * the main language while the main language has it. With $mode 'overwrite', every entity that has main-language texts.
     *
     * @param list<int>|null $only
     * @return list<int>
     */
    private function untranslated(int $storeId, string $type, string $default, string $locale, string $mode, ?array $only): array
    {
        [$link, $linkKey, $table, $key] = self::TABLES[$type];
        $where = '';
        if ($mode !== 'overwrite') {
            $checks = ['t.id IS NULL', 't.is_fallback=1'];
            foreach (array_keys(self::fields($type)) as $f) {
                $checks[] = "(COALESCE(s.$f,'')<>'' AND (COALESCE(t.$f,'')='' OR t.$f=s.$f))";
            }
            $where = ' AND (' . implode(' OR ', $checks) . ')';
        }
        if ($only !== null) {
            if ($only === []) {
                return [];
            }
            $where .= " AND l.$linkKey IN (" . implode(',', array_map('intval', $only)) . ')';
        }

        return array_map('intval', $this->db->fetchFirstColumn(
            "SELECT l.$linkKey FROM $link l JOIN $table s ON s.$key=l.$linkKey AND s.store_id=l.store_id AND s.locale=?
             LEFT JOIN $table t ON t.$key=l.$linkKey AND t.store_id=l.store_id AND t.locale=?
             WHERE l.store_id=?$where ORDER BY l.$linkKey",
            [$default, $locale, $storeId],
        ));
    }

    /** @param list<mixed> $publicIds @return list<int> */
    private function productIds(int $storeId, array $publicIds): array
    {
        $ids = [];
        foreach (array_slice($publicIds, 0, 500) as $publicId) {
            try {
                $binary = Uuid::fromString((string) $publicId)->toBinary();
            } catch (\Throwable) {
                continue;
            }
            $id = $this->db->fetchOne('SELECT p.id FROM mc_product p JOIN mc_store_product s ON s.product_id=p.id AND s.store_id=? WHERE p.public_id=?', [$storeId, $binary]);
            if ($id !== false) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Brands have one name for all languages; their translated texts are the description and the SEO tags. */
    private function saveBrand(int $storeId, int $brandId, string $locale, array $texts): void
    {
        $data = [];
        foreach (self::BRAND_FIELDS as $field => $max) {
            $value = trim(mb_substr((string) ($texts[$field] ?? ''), 0, $max));
            $data[$field] = $value === '' ? null : ($field === 'description' ? $value : strip_tags($value));
        }
        $brand = $this->db->fetchAssociative('SELECT public_id,name FROM mc_brand WHERE id=?', [$brandId]);
        if (!is_array($brand)) {
            throw new \InvalidArgumentException('not_found');
        }
        $route = $this->seo->ensureForCreatedEntity($storeId, $locale, SeoEntityType::Brand, Uuid::fromBinary((string) $brand['public_id'])->toRfc4122(), (string) $brand['name']);
        $exists = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_brand_translation WHERE brand_id=? AND store_id=? AND locale=?', [$brandId, $storeId, $locale]) > 0;
        if ($exists) {
            $this->db->update('mc_brand_translation', $data + ['is_fallback' => 0], ['brand_id' => $brandId, 'store_id' => $storeId, 'locale' => $locale]);
        } else {
            $this->db->insert('mc_brand_translation', $data + ['brand_id' => $brandId, 'store_id' => $storeId, 'locale' => $locale, 'slug' => $route->slug, 'is_fallback' => 0]);
        }
    }

    private function entityName(int $storeId, string $type, int $id): string
    {
        if ($type === 'brand') {
            return (string) ($this->db->fetchOne('SELECT name FROM mc_brand WHERE id=?', [$id]) ?: '#' . $id);
        }
        [, , $table, $key] = self::TABLES[$type] ?? self::TABLES['product'];

        return (string) ($this->db->fetchOne("SELECT name FROM $table WHERE $key=? AND store_id=? AND locale=?", [$id, $storeId, $this->catalog->defaultLocale($storeId)]) ?: '#' . $id);
    }

    /** A batch waiting for the limit goes back to work once the store has requests left today (after midnight UTC). */
    private function wakeUpLimited(?int $storeId): void
    {
        $rows = $this->db->fetchAllAssociative("SELECT id,store_id FROM mc_ai_batch WHERE status='limit'" . ($storeId !== null ? ' AND store_id=' . (int) $storeId : ''));
        foreach ($rows as $row) {
            $store = (int) $row['store_id'];
            if ($this->settings->usedToday($store) < $this->settings->dailyLimit($store)) {
                $this->setStatus((int) $row['id'], 'running');
            }
        }
    }

    private function finishBatches(?int $storeId): void
    {
        $this->db->executeStatement(
            "UPDATE mc_ai_batch SET status='done',updated_at=? WHERE status='running' AND kind='translate'" . ($storeId !== null ? ' AND store_id=' . (int) $storeId : '') . " AND NOT EXISTS (SELECT 1 FROM mc_ai_batch_job j WHERE j.batch_id=mc_ai_batch.id AND j.status='pending')",
            [gmdate('Y-m-d H:i:s.u')],
        );
    }

    private function pendingCount(?int $storeId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM mc_ai_batch_job j JOIN mc_ai_batch b ON b.id=j.batch_id WHERE j.status='pending' AND b.status IN ('running','limit')" . ($storeId !== null ? ' AND j.store_id=' . (int) $storeId : ''),
        );
    }

    private function setStatus(int $batchId, string $status): void
    {
        $this->db->update('mc_ai_batch', ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s.u')], ['id' => $batchId]);
    }

    /** @param list<string> $from */
    private function transition(string $publicId, ?int $storeId, array $from, string $to): ?int
    {
        $id = $this->batchId($publicId, $storeId);
        if ($id === null) {
            return null;
        }
        $changed = $this->db->executeStatement(
            'UPDATE mc_ai_batch SET status=?,updated_at=? WHERE id=? AND status IN (' . implode(',', array_fill(0, count($from), '?')) . ')',
            array_merge([$to, gmdate('Y-m-d H:i:s.u'), $id], $from),
        );

        return $changed > 0 ? $id : null;
    }

    private function batchId(string $publicId, ?int $storeId): ?int
    {
        $id = $this->db->fetchOne('SELECT id FROM mc_ai_batch WHERE public_id=?' . ($storeId !== null ? ' AND store_id=' . (int) $storeId : ''), [$publicId]);

        return $id === false ? null : (int) $id;
    }
}
