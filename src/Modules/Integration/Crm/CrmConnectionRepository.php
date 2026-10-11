<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Crm;

use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;

/** Connections that send orders to another system. The headers (they often carry an API key) are stored encrypted. */
final readonly class CrmConnectionRepository
{
    private const CONTEXT = 'crm_connection_headers';
    public const MASK = '********';

    public function __construct(private Connection $db, private SecretVault $vault)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $storeId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM mc_crm_connection WHERE store_id=? ORDER BY id', [$storeId]);

        return array_map([$this, 'hydrate'], $rows);
    }

    /** @return list<array<string,mixed>> enabled connections that want this event */
    public function forEvent(int $storeId, string $event): array
    {
        return array_values(array_filter($this->all($storeId), static fn (array $c): bool => $c['enabled'] && in_array($event, $c['events'], true)));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $storeId = 0): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM mc_crm_connection WHERE id=?' . ($storeId > 0 ? ' AND store_id=?' : ''), $storeId > 0 ? [$id, $storeId] : [$id]);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array{name:string,preset:string,url:string,method:string,headers_text:string,body_template:string,events:list<string>,enabled:bool} $in
     */
    public function save(int $storeId, ?int $id, array $in): int
    {
        $existing = $id !== null ? $this->find($id, $storeId) : null;
        $headers = $this->parseHeaders($in['headers_text'], $existing['headers'] ?? []);
        $now = gmdate('Y-m-d H:i:s.u');
        $data = [
            'name' => mb_substr(trim($in['name']), 0, 120), 'preset' => mb_substr($in['preset'], 0, 32), 'url' => mb_substr(trim($in['url']), 0, 1000),
            'method' => $in['method'] === 'PUT' ? 'PUT' : 'POST', 'headers_enc' => $headers === [] ? null : $this->vault->encrypt(json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), self::CONTEXT),
            'body_template' => $in['body_template'], 'events' => json_encode(array_values($in['events']), JSON_THROW_ON_ERROR), 'enabled' => $in['enabled'] ? 1 : 0, 'updated_at' => $now,
        ];
        if ($existing !== null) {
            $this->db->update('mc_crm_connection', $data, ['id' => $id, 'store_id' => $storeId]);

            return (int) $id;
        }
        $this->db->insert('mc_crm_connection', $data + ['store_id' => $storeId, 'signing_secret' => bin2hex(random_bytes(24)), 'created_at' => $now]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id, int $storeId): void
    {
        $this->db->delete('mc_crm_connection', ['id' => $id, 'store_id' => $storeId]);
    }

    public function setEnabled(int $id, int $storeId, bool $enabled): void
    {
        $this->db->update('mc_crm_connection', ['enabled' => $enabled ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s.u')], ['id' => $id, 'store_id' => $storeId]);
    }

    public function log(int $connectionId, ?string $orderNumber, string $event, string $status, ?int $http, string $excerpt): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_crm_delivery', ['connection_id' => $connectionId, 'order_number' => $orderNumber, 'event' => $event, 'status' => $status, 'http_status' => $http, 'response_excerpt' => mb_substr($excerpt, 0, 500), 'created_at' => $now]);
        $this->db->update('mc_crm_connection', ['last_status' => $status, 'last_at' => $now], ['id' => $connectionId]);
        $this->db->executeStatement('DELETE FROM mc_crm_delivery WHERE connection_id=? AND id <= (SELECT id FROM (SELECT id FROM mc_crm_delivery WHERE connection_id=? ORDER BY id DESC LIMIT 1 OFFSET 200) x)', [$connectionId, $connectionId]);
    }

    /** @return list<array<string,mixed>> */
    public function deliveries(int $storeId, int $limit = 60): array
    {
        return $this->db->fetchAllAssociative('SELECT d.*,c.name connection_name FROM mc_crm_delivery d JOIN mc_crm_connection c ON c.id=d.connection_id WHERE c.store_id=? ORDER BY d.id DESC LIMIT ' . max(1, min(200, $limit)), [$storeId]);
    }

    /** The headers as lines "Name: value", with the secret-looking values hidden. */
    public static function headersText(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . (preg_match('/auth|token|key|secret|pass/i', (string) $name) === 1 ? self::MASK : $value);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,string> $keep the stored headers: a line whose value is the mask keeps the stored value of that name
     * @return array<string,string>
     */
    private function parseHeaders(string $text, array $keep): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            if (preg_match('/^[A-Za-z0-9-]{1,60}$/D', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1 || strlen($value) > 1000) {
                continue;
            }
            if (in_array(strtolower($name), ['host', 'content-length', 'transfer-encoding', 'connection', 'content-type'], true)) {
                continue;
            }
            $out[$name] = $value === self::MASK ? (string) ($keep[$name] ?? '') : $value;
        }

        return array_filter($out, static fn (string $v): bool => $v !== '');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $headers = [];
        if (is_string($row['headers_enc'] ?? null) && $row['headers_enc'] !== '') {
            try {
                $decoded = json_decode($this->vault->decrypt((string) $row['headers_enc'], self::CONTEXT), true);
                $headers = is_array($decoded) ? array_map('strval', $decoded) : [];
            } catch (\Throwable) {
                $headers = [];
            }
        }
        $events = json_decode((string) $row['events'], true);

        return [
            'id' => (int) $row['id'], 'store_id' => (int) $row['store_id'], 'name' => (string) $row['name'], 'preset' => (string) $row['preset'], 'url' => (string) $row['url'], 'method' => (string) $row['method'],
            'headers' => $headers, 'signing_secret' => (string) $row['signing_secret'], 'body_template' => (string) $row['body_template'], 'events' => is_array($events) ? array_values(array_map('strval', $events)) : [],
            'enabled' => (bool) $row['enabled'], 'last_status' => $row['last_status'] !== null ? (string) $row['last_status'] : null, 'last_at' => $row['last_at'] !== null ? (string) $row['last_at'] : null,
        ];
    }
}
