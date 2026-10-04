# Blog (3.11.0)

Admin: **Content → Blog** (`/admin/content/blog`), categories at `/admin/content/blog/categories`.
Storefront: `/blog`, `/blog/category/{slug}`, `/blog/tag/{slug}`, `/blog/feed.xml`; articles live at `/blog/{slug}` (SEO routes, per language).

## Model
- An article is a `mc_content_entry` (`content_type = article`) with one `mc_content_translation` per language. Each language has its own SEO route, so the URL, title, description and body can differ per language.
- `mc_blog_article_meta` holds language-independent data: category, cover (URL + alt), author, featured flag, noindex, canonical override and reading time.
- `mc_blog_article_tag` holds tags; `mc_blog_category(+_translation)` holds categories.
- Visibility: an article is public only when `status = published` **and** `published_at <= now`. A future date is a scheduled publication; drafts and scheduled articles return 404 and are excluded from listings, RSS and the sitemap.

## SEO checklist covered by the platform
- Unique `<title>`/meta description per article with a live snippet preview and length counters in the editor.
- `BlogPosting` + `BreadcrumbList` JSON-LD (author, image, datePublished/dateModified, keywords, wordCount, articleSection), `Blog` JSON-LD on listings.
- Canonical (overridable per article), `robots` (per-article noindex; tag archives and internal search are `noindex,follow`), hreflang for every published translation, Open Graph + Twitter card.
- Slug changes keep a 301 redirect from the previous URL.
- Sitemap lists published indexable articles and active categories; RSS feed with autodiscovery `<link>`.
- Table of contents and heading anchors are generated from H2/H3; related articles and previous/next links strengthen internal linking.

## Security
- Article HTML is sanitised server-side (`commerce.rich_text`) before it is stored.
- All admin writes are CSRF-protected and covered by the destructive-action confirmation gate (`bin/admin-confirm-check.php`).
- Cover images go through the media pipeline (type/size/pixel limits, WebP/AVIF derivatives).


## Підкатегорії блогу

Категорія блогу може мати батьківську (до чотирьох рівнів): Адмінка → Контент → Блог → Категорії, поле «Батьківська категорія». Категорія на сайті показує статті всієї своєї гілки, під рядком категорій з'являється рядок підкатегорій, хлібні крихти статті містять батьківські категорії. Видалення батьківської категорії робить дочірні категорії верхнього рівня.
