<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Data-driven option lists for product forms and storefront labels (units, custom document types). */
final class CatalogOptionsTwigExtension extends AbstractExtension
{
    private const BUILTIN_DOCUMENT_TYPES = ['document', 'manual', 'certificate', 'sds', 'datasheet', 'warranty'];

    public function __construct(private readonly MeasurementUnitService $units, private readonly RequestStack $requests, private readonly Connection $db)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('measurement_units', fn (): array => $this->units->all($this->locale())),
            new TwigFunction('measurement_unit_label', fn (string $code): string => $this->units->shortLabel($code, $this->locale())),
            new TwigFunction('custom_document_types', $this->customDocumentTypes(...)),
        ];
    }

    /** @return list<string> */
    public function customDocumentTypes(): array
    {
        $rows = $this->db->fetchFirstColumn('SELECT DISTINCT document_type FROM mc_product_document ORDER BY document_type LIMIT 50');

        return array_values(array_filter(array_map('strval', $rows), static fn (string $t): bool => $t !== '' && !in_array($t, self::BUILTIN_DOCUMENT_TYPES, true)));
    }

    private function locale(): string
    {
        return (string) ($this->requests->getCurrentRequest()?->getLocale() ?: 'uk-UA');
    }
}
