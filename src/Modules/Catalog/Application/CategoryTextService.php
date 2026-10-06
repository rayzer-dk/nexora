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
            'UPDATE mc_category_translation SET description=?,description_bottom=?,is_fallback=0 WHERE category_id=? AND store_id=? AND locale=?',
            [$this->clean($top), $this->clean($bottom), $categoryId, $storeId, $locale],
        );
        $this->fallbackTexts->fillCategory($this->db, $storeId, $categoryId);
    }

    /** H1, meta title and meta description of a category in one language; empty values fall back to the name and the SEO templates. */
    public function saveSeo(int $categoryId, int $storeId, string $locale, string $h1, string $metaTitle, string $metaDescription): void
    {
        $cut = static fn (string $v, int $n): ?string => ($v = trim(strip_tags($v))) === '' ? null : mb_substr($v, 0, $n, 'UTF-8');
        $this->db->executeStatement(
            'UPDATE mc_category_translation SET h1=?,meta_title=?,meta_description=? WHERE category_id=? AND store_id=? AND locale=?',
            [$cut($h1, 255), $cut($metaTitle, 255), $cut($metaDescription, 500), $categoryId, $storeId, $locale],
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
