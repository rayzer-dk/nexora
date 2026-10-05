<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Twig;

use Commerce\Modules\Forum\Application\ForumFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class ForumTwigExtension extends AbstractExtension
{
    public function __construct(private readonly ForumFormatter $formatter)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('forum_format', $this->format(...), ['is_safe' => ['html']])];
    }

    /** @param array<string,string> $mentions */
    public function format(?string $text, array $mentions = []): string
    {
        return $this->formatter->toHtml((string) $text, $mentions);
    }
}
