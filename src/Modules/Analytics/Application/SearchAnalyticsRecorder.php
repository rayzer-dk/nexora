<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Application;

use Doctrine\DBAL\Connection;

final readonly class SearchAnalyticsRecorder
{
    public function __construct(private Connection $db) {}

    public function record(int $storeId, string $locale, string $query, int $resultCount): void
    {
        $query = trim(mb_substr($query, 0, 120, 'UTF-8'));
        if (mb_strlen($query, 'UTF-8') < 2) return;
        $display = $this->privacySafeDisplay($query);
        try {
            $this->db->insert('mc_search_query_log', [
                'store_id' => $storeId,
                'locale' => mb_substr($locale, 0, 16),
                'query_text' => $display,
                'query_hash' => hash('sha256', mb_strtolower($query, 'UTF-8'), true),
                'result_count' => max(0, $resultCount),
                'created_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
        } catch (\Throwable) {
            // Analytics must never break storefront search, including during rolling migrations.
        }
    }

    private function privacySafeDisplay(string $query): string
    {
        if (str_contains($query, '@')) return '[redacted email-like query]';
        $digits = preg_replace('/\D+/', '', $query) ?? '';
        if (strlen($digits) >= 8) return '[redacted phone-like query]';
        return $query;
    }
}
