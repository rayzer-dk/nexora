<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Doctrine\DBAL\Connection;

/**
 * Product codes by a template such as "GTR-###": the run of # characters is the counter and its length is the zero padding
 * (GTR-001, GTR-002, ...). The next number is the highest one already used with the same prefix and suffix, plus one.
 */
final class SkuGenerator
{
    public const SETTING = 'catalog.sku_template';

    public function __construct(private readonly Connection $db, private readonly SystemSettingStore $settings)
    {
    }

    public function template(): string
    {
        return $this->settings->getString(self::SETTING) ?? '';
    }

    public function saveTemplate(string $template): string
    {
        $template = self::normalize($template);
        $this->settings->setString(self::SETTING, $template);

        return $template;
    }

    public static function normalize(string $template): string
    {
        $template = trim(preg_replace('/[^\p{L}\p{N}#_\-.\/]/u', '', $template) ?? '');

        return mb_substr($template, 0, 60);
    }

    /** The next free code for the template (the saved one when $template is empty), or '' when no template is set. */
    public function next(string $template = ''): string
    {
        $template = self::normalize($template) !== '' ? self::normalize($template) : $this->template();
        if ($template === '') {
            return '';
        }
        if (preg_match('/^(.*?)(#+)(.*)$/u', $template, $m) !== 1) {
            $m = [$template, $template . '-', '###', ''];
        }
        [, $prefix, $hashes, $suffix] = $m;
        $width = mb_strlen($hashes);
        $like = addcslashes($prefix, '%_\\') . '%' . addcslashes($suffix, '%_\\');
        $max = 0;
        foreach ($this->db->fetchFirstColumn('SELECT sku FROM mc_product_variant WHERE sku LIKE ?', [$like]) as $sku) {
            $sku = (string) $sku;
            if (str_starts_with($sku, $prefix) && ($suffix === '' || str_ends_with($sku, $suffix))) {
                $middle = substr($sku, strlen($prefix), strlen($sku) - strlen($prefix) - strlen($suffix));
                if ($middle !== '' && ctype_digit($middle)) {
                    $max = max($max, (int) $middle);
                }
            }
        }

        return $prefix . str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT) . $suffix;
    }
}
