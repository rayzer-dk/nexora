<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Rich text blocks of a category: an introduction shown above the product grid and an SEO text
 * shown below it. Both are sanitised on save with the same policy as product descriptions.
 */
final readonly class CategoryTextService
{
    public function __construct(
        private Connection $db,
        private \Commerce\Modules\Localization\Application\TranslationFallbackFiller $fallbackTexts,
        #[Autowire(service: 'html_sanitizer.sanitizer.commerce.rich_text')] private HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function save(int $categoryId, int $storeId, string $locale, string $top, string $bottom): void
    {
        $this->db->executeStatement(
            // is_fallback is assigned first: MySQL evaluates SET left to right, so it still sees the old texts and a copy
            // that is sent back unchanged stays a copy.
            'UPDATE mc_category_translation SET is_fallback=IF(is_fallback=1 AND description<=>? AND description_bottom<=>?,1,0),description=?,description_bottom=? WHERE category_id=? AND store_id=? AND locale=?',
            [$top = $this->clean($top), $bottom = $this->clean($bottom), $top, $bottom, $categoryId, $storeId, $locale],
        );
        $this->fallbackTexts->fillCategory($this->db, $storeId, $categoryId);
    }

    /** H1, meta title and meta description of a category in one language; empty values fall back to the name and the SEO templates. */
    public function saveSeo(int $categoryId, int $storeId, string $locale, string $h1, string $metaTitle, string $metaDescription): void
    {
        $cut = static fn (string $v, int $n): ?string => ($v = trim(strip_tags($v))) === '' ? null : mb_substr($v, 0, $n, 'UTF-8');
        $this->db->executeStatement(
            'UPDATE mc_category_translation SET is_fallback=IF(is_fallback=1 AND h1<=>? AND meta_title<=>? AND meta_description<=>?,1,0),h1=?,meta_title=?,meta_description=? WHERE category_id=? AND store_id=? AND locale=?',
            [$h1 = $cut($h1, 255), $metaTitle = $cut($metaTitle, 255), $metaDescription = $cut($metaDescription, 500), $h1, $metaTitle, $metaDescription, $categoryId, $storeId, $locale],
        );
    }

    private function clean(string $html): ?string
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }
        $clean = trim($this->sanitizer->sanitize(mb_substr($html, 0, 100000)));

        return $clean === '' ? null : $clean;
    }
}
