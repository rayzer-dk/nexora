<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;

/** Per-store AI configuration: provider switches, model names, encrypted API keys, a daily request cap and the usage log. */
final class AiSettings
{
    public const PROVIDERS = ['openai' => 'OpenAI', 'gemini' => 'Gemini', 'anthropic' => 'Claude'];
    private const DEFAULT_MODEL = ['openai' => 'gpt-5', 'gemini' => 'gemini-2.5-flash', 'anthropic' => 'claude-sonnet-5-5'];

    /** @param array<string,array{enabled:bool,key:string,model:string}> $env deployment defaults from the environment */
    public function __construct(private readonly Connection $db, private readonly SecretVault $vault, private readonly array $env = [])
    {
    }

    /** @return array<string,array{enabled:bool,model:string,key:string,key_set:bool,source:string}> */
    public function providers(int $storeId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT provider,enabled,model,api_key_enc FROM mc_ai_provider_config WHERE store_id=?', [$storeId]);
        $byCode = [];
        foreach ($rows as $r) {
            $byCode[(string) $r['provider']] = $r;
        }
        $out = [];
        foreach (self::PROVIDERS as $code => $label) {
            $env = $this->env[$code] ?? ['enabled' => false, 'key' => '', 'model' => ''];
            if (isset($byCode[$code])) {
                $r = $byCode[$code];
                $key = '';
                if (is_string($r['api_key_enc']) && $r['api_key_enc'] !== '') {
                    try {
                        $key = $this->vault->decrypt($r['api_key_enc'], 'ai.key');
                    } catch (\Throwable) {
                        $key = '';
                    }
                }
                $out[$code] = ['enabled' => (bool) $r['enabled'], 'model' => (string) $r['model'] !== '' ? (string) $r['model'] : self::DEFAULT_MODEL[$code], 'key' => $key, 'key_set' => $key !== '', 'source' => 'admin'];
            } else {
                $out[$code] = ['enabled' => $env['enabled'], 'model' => $env['model'] !== '' ? $env['model'] : self::DEFAULT_MODEL[$code], 'key' => $env['key'], 'key_set' => $env['key'] !== '', 'source' => 'env'];
            }
        }

        return $out;
    }

    /** An empty $apiKey keeps the stored key. */
    public function saveProvider(int $storeId, string $code, bool $enabled, string $model, string $apiKey): void
    {
        if (!isset(self::PROVIDERS[$code])) {
            throw new \InvalidArgumentException('ai_unknown_provider');
        }
        $model = trim($model);
        if ($model !== '' && preg_match('/^[A-Za-z0-9._:\-\/]{2,80}$/', $model) !== 1) {
            throw new \InvalidArgumentException('ai_invalid_model');
        }
        $current = $this->db->fetchOne('SELECT api_key_enc FROM mc_ai_provider_config WHERE store_id=? AND provider=?', [$storeId, $code]);
        $apiKey = trim($apiKey);
        if ($apiKey !== '' && (strlen($apiKey) < 12 || strlen($apiKey) > 300 || preg_match('/\s/', $apiKey) === 1)) {
            throw new \InvalidArgumentException('ai_invalid_key');
        }
        $enc = $apiKey !== '' ? $this->vault->encrypt($apiKey, 'ai.key') : (is_string($current) ? $current : null);
        $this->db->executeStatement(
            'INSERT INTO mc_ai_provider_config (store_id,provider,enabled,model,api_key_enc,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),model=VALUES(model),api_key_enc=VALUES(api_key_enc),updated_at=VALUES(updated_at)',
            [$storeId, $code, $enabled ? 1 : 0, $model, $enc],
        );
    }

    public function removeKey(int $storeId, string $code): void
    {
        $this->db->executeStatement('UPDATE mc_ai_provider_config SET api_key_enc=NULL,enabled=0,updated_at=UTC_TIMESTAMP(6) WHERE store_id=? AND provider=?', [$storeId, $code]);
    }

    public function dailyLimit(int $storeId): int
    {
        $v = $this->db->fetchOne('SELECT daily_limit FROM mc_ai_settings WHERE store_id=?', [$storeId]);

        return $v === false ? 200 : (int) $v;
    }

    public function saveDailyLimit(int $storeId, int $limit): void
    {
        $this->db->executeStatement(
            'INSERT INTO mc_ai_settings (store_id,daily_limit,updated_at) VALUES (?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE daily_limit=VALUES(daily_limit),updated_at=VALUES(updated_at)',
            [$storeId, max(0, min(100000, $limit))],
        );
    }

    public function usedToday(int $storeId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_ai_usage WHERE store_id=? AND created_at>=?', [$storeId, gmdate('Y-m-d 00:00:00')]);
    }

    public function log(int $storeId, string $admin, string $task, string $provider, string $model, int $in, int $out, string $status): void
    {
        $this->db->insert('mc_ai_usage', [
            'store_id' => $storeId, 'admin_subject' => mb_substr($admin, 0, 120), 'task' => mb_substr($task, 0, 32), 'provider' => mb_substr($provider, 0, 16),
            'model' => mb_substr($model, 0, 80), 'input_chars' => max(0, $in), 'output_chars' => max(0, $out), 'status' => $status, 'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recentUsage(int $storeId, int $limit = 15): array
    {
        return $this->db->fetchAllAssociative('SELECT admin_subject,task,provider,model,input_chars,output_chars,status,created_at FROM mc_ai_usage WHERE store_id=? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)), [$storeId]);
    }

    /** @return list<array{code:string,label:string}> providers that can run right now */
    public function enabledProviders(int $storeId): array
    {
        $list = [];
        foreach ($this->providers($storeId) as $code => $p) {
            if ($p['enabled'] && $p['key'] !== '' && $p['model'] !== '') {
                $list[] = ['code' => $code, 'label' => self::PROVIDERS[$code]];
            }
        }

        return $list;
    }
}
