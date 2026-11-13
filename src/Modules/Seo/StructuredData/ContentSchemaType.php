<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\StructuredData;

use InvalidArgumentException;

/** Explicit content-type selection is limited to information pages and articles. */
final class ContentSchemaType
{
    public const PAGE = ['auto', 'WebPage', 'AboutPage', 'ContactPage', 'FAQPage', 'CollectionPage', 'Article', 'none'];
    public const ARTICLE = ['auto', 'BlogPosting', 'Article', 'NewsArticle', 'none'];

    public static function validate(string $value, bool $article = false): string
    {
        if (!in_array($value, $article ? self::ARTICLE : self::PAGE, true)) {
            throw new InvalidArgumentException('Unknown Schema.org content type.');
        }
        return $value;
    }

    public static function page(string $selection, ?string $systemKey = null): ?string
    {
        if ($selection === 'none') {
            return null;
        }
        if ($selection !== 'auto') {
            return self::validate($selection);
        }
        return match ($systemKey) {
            'about' => 'AboutPage',
            'contacts' => 'ContactPage',
            'faq' => 'FAQPage',
            default => 'WebPage',
        };
    }

    public static function article(string $selection): ?string
    {
        return $selection === 'none' ? null : ($selection === 'auto' ? 'BlogPosting' : self::validate($selection, true));
    }

    /** @return array<string, mixed>|null */
    public static function pageNode(string $selection, ?string $systemKey, string $title, string $url, string $locale, string $description = '', string $publisherId = ''): ?array
    {
        $type = self::page($selection, $systemKey);
        if ($type === null) {
            return null;
        }
        $node = [
            '@type' => $type,
            '@id' => $url . ($type === 'Article' ? '#article' : '#webpage'),
            'url' => $url,
            'name' => $title,
            'inLanguage' => $locale,
        ];
        if ($description !== '') {
            $node['description'] = $description;
        }
        if (in_array($type, ['AboutPage', 'ContactPage'], true) && $publisherId !== '') {
            $node['about'] = ['@id' => $publisherId];
        }
        if ($type === 'Article') {
            $node['headline'] = $title;
            $node['mainEntityOfPage'] = ['@id' => $url . '#webpage'];
            if ($publisherId !== '') {
                $node['publisher'] = ['@id' => $publisherId];
            }
        }
        return $node;
    }
}
