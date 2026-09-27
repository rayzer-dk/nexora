<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Application;

use Commerce\Modules\GoogleCommerce\Infrastructure\GoogleMerchantApiClient;
use Doctrine\DBAL\Connection;

final readonly class GoogleMerchantSyncHandler
{
    public function __construct(private Connection $db, private GoogleMerchantProductProjection $projection, private GoogleMerchantApiClient $api, private string $accountId) {}

    /** @param array<string,mixed> $job */
    public function handle(array $job): void
    {
        $payload = json_decode((string)$job['payload'], true, 512, JSON_THROW_ON_ERROR);
        $projected = $this->projection->project((string)$job['aggregate_id'], isset($payload['store_id']) ? (int)$payload['store_id'] : null);
        $canonical = $projected['canonical'];
        $now = gmdate('Y-m-d H:i:s.u');
        $hash = hash('sha256', json_encode($projected['input'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        if ($projected['status'] !== 'published') {
            try { $this->api->deleteProduct((string)$canonical['content_language'], (string)$canonical['feed_label'], (string)$canonical['offer_id']); } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), '404')) throw $e;
            }
            $this->saveState($projected, 'deleted', [], null, null, $hash, $now);
            return;
        }
        $response = $this->api->upsertProduct($projected['input']);
        $productName = (string)($response['product'] ?? '');
        $issues = [];
        if ($productName !== '') {
            try {
                $processed = $this->api->getProcessedProduct($productName);
                $issues = array_values((array)($processed['productStatus']['itemLevelIssues'] ?? $processed['productStatus']['destinationStatuses'] ?? []));
            } catch (\Throwable) {
                // Google processing can lag behind insertion; worker retry is not needed for this diagnostic fetch.
            }
        }
        $this->saveState($projected, $issues === [] ? 'synced' : 'issues', $issues, (string)($response['name'] ?? ''), $productName, $hash, $now);
    }

    /** @param array<string,mixed> $projected @param array<int,mixed> $issues */
    private function saveState(array $projected, string $status, array $issues, ?string $inputName, ?string $productName, string $hash, string $now): void
    {
        $c=$projected['canonical'];
        $key=['store_id'=>$projected['store_id'],'product_id'=>$projected['product_id'],'merchant_account'=>$this->accountId,'content_language'=>$c['content_language'],'feed_label'=>$c['feed_label'],'offer_id'=>$c['offer_id']];
        $data=['product_input_name'=>$inputName,'processed_product_name'=>$productName,'sync_status'=>$status,'issues_json'=>json_encode($issues,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'last_payload_hash'=>$hash,'last_synced_at'=>$now,'updated_at'=>$now];
        $exists=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_google_merchant_product_state WHERE store_id=? AND product_id=? AND merchant_account=? AND content_language=? AND feed_label=? AND offer_id=?',array_values($key));
        $exists ? $this->db->update('mc_google_merchant_product_state',$data,$key) : $this->db->insert('mc_google_merchant_product_state',array_merge($key,$data));
    }
}
