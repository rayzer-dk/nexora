<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Domain;

enum SeoEntityType: string
{
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
    case CmsPage = 'cms_page';
    case BlogArticle = 'blog_article';
    case LandingPage = 'landing_page';
}
