<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Doctrine\DBAL\Connection;

/**
 * Product attribute editing is isolated from the base product writer. A bad
 * characteristic value can fail independently without rolling back unrelated
 * product data or taking the product editor down.
 */
final readonly class ProductAttributeService
{
    public function __construct(private Connection $db)
    {
    }

    /** @param array<int|string,mixed> $values */
    public function saveProductValues(int $productId, string $locale, array $values): void
    {
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.nekorektna_lokal_kharakterystyk'));
        }

        $normalized = [];
        foreach ($values as $attributeIdRaw => $value) {
            $attributeId = (int) $attributeIdRaw;
            if ($attributeId < 1 || !is_scalar($value)) {
                continue;
            }
            $normalized[$attributeId] = trim((string) $value);
        }
        if (count($normalized) > 250) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.dlia_odnoho_tovaru_dozvoleno_ne_bilshe_250_kharakter'));
        }

        $this->db->transactional(function (Connection $db) use ($productId, $locale, $normalized): void {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_product WHERE id=?', [$productId]) !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.tovar_ne_znaideno'));
            }

            $definitions = $db->fetchAllAssociative(
                'SELECT id,data_type FROM mc_attribute_definition WHERE id IN (' . ($normalized === [] ? '0' : implode(',', array_fill(0, count($normalized), '?'))) . ')',
                array_keys($normalized),
            );
            $types = [];
            foreach ($definitions as $definition) {
                $types[(int) $definition['id']] = (string) $definition['data_type'];
            }

            foreach ($normalized as $attributeId => $raw) {
                if (!isset($types[$attributeId])) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.kharakterystyka_bilshe_ne_isnuie_onovit_storinku_ta_'));
                }
                $db->executeStatement(
                    'DELETE FROM mc_product_attribute_value WHERE product_id=? AND variant_id IS NULL AND attribute_id=? AND (locale=? OR locale IS NULL)',
                    [$productId, $attributeId, $locale],
                );
                if ($raw === '') {
                    continue;
                }

                $row = [
                    'product_id' => $productId,
                    'variant_id' => null,
                    'attribute_id' => $attributeId,
                    'locale' => $locale,
                    'value_text' => null,
                    'value_text_hash' => null,
                    'value_decimal' => null,
                    'value_boolean' => null,
                    'value_json' => null,
                    'sort_order' => 0,
                ];
                switch ($types[$attributeId]) {
                    case 'decimal':
                    case 'number':
                        $decimal = str_replace(',', '.', $raw);
                        if (preg_match('/^-?\d{1,19}(?:\.\d{1,10})?$/D', $decimal) !== 1) {
                            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.chyslova_kharakterystyka_maie_nekorektne_znachennia'));
                        }
                        $row['value_decimal'] = $decimal;
                        break;
                    case 'boolean':
                    case 'bool':
                        $lower = mb_strtolower($raw, 'UTF-8');
                        if (in_array($lower, ['1', 'true', 'yes', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.tak')], true)) {
                            $row['value_boolean'] = 1;
                        } elseif (in_array($lower, ['0', 'false', 'no', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.ni')], true)) {
                            $row['value_boolean'] = 0;
                        } else {
                            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.lohichna_kharakterystyka_pryimaie_lyshe_tak_abo_ni'));
                        }
                        break;
                    default:
                        if (mb_strlen($raw, 'UTF-8') > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw) === 1) {
                            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productattributeservice.tekst_kharakterystyky_perevyshchuie_bezpechnyi_limit'));
                        }
                        $row['value_text'] = $raw;
                        $row['value_text_hash'] = hash('sha256', mb_strtolower($raw, 'UTF-8'), true);
                }
                $db->insert('mc_product_attribute_value', $row);
            }
        });
    }
}
