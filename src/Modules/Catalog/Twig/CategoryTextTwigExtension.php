<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Category texts may come from imports with raw HTML, so they are sanitised again on output. */
final class CategoryTextTwigExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.commerce.rich_text')] private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('category_html', $this->clean(...), ['is_safe' => ['html']])];
    }

    public function clean(mixed $html): string
    {
        return $this->sanitizer->sanitize(mb_substr((string) $html, 0, 100000));
    }
}
