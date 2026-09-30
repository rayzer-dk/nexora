<?php

declare(strict_types=1);

namespace Commerce\Modules\CustomField\Application;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Merchant-defined extra product fields (Shopify "metafields"): definitions per store, values per product. */
final class CustomFieldService
{
    public const TYPES = ['text', 'number', 'url', 'date'];
    public const MAX_DEFINITIONS = 40;

    /** @var array<int,list<array{label:string,value:string,type:string}>> */
    private array $publicCache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function definitions(int $storeId): array
    {
        return $this->db->fetchAllAssociative("SELECT id,code,label,field_type,show_on_storefront,sort_order FROM mc_custom_field_definition WHERE store_id=? AND owner_type='product' ORDER BY sort_order,id", [$storeId]);
    }

    public function saveDefinition(int $storeId, string $code, string $label, string $type, bool $show, int $sort): void
    {
        $code = strtolower(trim($code));
        $label = trim($label);
        if (!preg_match('/^[a-z][a-z0-9_]{1,47}$/', $code)) {
            throw new \InvalidArgumentException('code');
        }
        if ($label === '' || mb_strlen($label) > 160 || !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('label');
        }
        if ((int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_custom_field_definition WHERE store_id=? AND owner_type='product'", [$storeId]) >= self::MAX_DEFINITIONS) {
            throw new \DomainException('limit');
        }
        $this->db->executeStatement(
            "INSERT INTO mc_custom_field_definition (store_id,owner_type,code,label,field_type,show_on_storefront,sort_order,created_at) VALUES (?,'product',?,?,?,?,?,UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE label=VALUES(label),field_type=VALUES(field_type),show_on_storefront=VALUES(show_on_storefront),sort_order=VALUES(sort_order)",
            [$storeId, $code, $label, $type, $show ? 1 : 0, max(-1000, min(1000, $sort))],
        );
    }

    public function deleteDefinition(int $storeId, int $id): void
    {
        $this->db->executeStatement('DELETE FROM mc_custom_field_definition WHERE id=? AND store_id=?', [$id, $storeId]);
    }

    /** @return array<int,string> definition id => value */
    public function values(int $storeId, int $productId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT v.definition_id,v.value FROM mc_custom_field_value v JOIN mc_custom_field_definition d ON d.id=v.definition_id WHERE d.store_id=? AND v.owner_id=?', [$storeId, $productId]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['definition_id']] = (string) $row['value'];
        }

        return $out;
    }

    /** @param array<int|string,mixed> $input definition id => raw value; an empty value removes the row */
    public function saveValues(int $storeId, int $productId, array $input): void
    {
        $defs = [];
        foreach ($this->definitions($storeId) as $d) {
            $defs[(int) $d['id']] = $d;
        }
        $this->db->transactional(function () use ($defs, $input, $productId): void {
            foreach ($defs as $id => $def) {
                $raw = trim((string) ($input[$id] ?? ''));
                $raw = mb_substr($raw, 0, 2000);
                if ($raw === '' || !$this->valid((string) $def['field_type'], $raw)) {
                    $this->db->executeStatement('DELETE FROM mc_custom_field_value WHERE definition_id=? AND owner_id=?', [$id, $productId]);
                    continue;
                }
                $this->db->executeStatement(
                    'INSERT INTO mc_custom_field_value (definition_id,owner_id,value,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)',
                    [$id, $productId, $raw],
                );
            }
        });
        unset($this->publicCache[$productId]);
    }

    /** @return list<array{label:string,value:string,type:string}> */
    public function publicFields(int $storeId, int $productId): array
    {
        if (isset($this->publicCache[$productId])) {
            return $this->publicCache[$productId];
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT d.label,d.field_type,v.value FROM mc_custom_field_value v JOIN mc_custom_field_definition d ON d.id=v.definition_id
             WHERE d.store_id=? AND d.owner_type='product' AND d.show_on_storefront=1 AND v.owner_id=? ORDER BY d.sort_order,d.id",
            [$storeId, $productId],
        );

        return $this->publicCache[$productId] = array_map(static fn (array $r): array => ['label' => (string) $r['label'], 'value' => (string) $r['value'], 'type' => (string) $r['field_type']], $rows);
    }

    private function valid(string $type, string $value): bool
    {
        return match ($type) {
            'number' => is_numeric(str_replace(',', '.', $value)),
            'url' => (bool) preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
            default => true,
        };
    }
}
