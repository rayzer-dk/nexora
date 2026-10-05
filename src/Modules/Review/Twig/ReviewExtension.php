<?php

declare(strict_types=1);

namespace Commerce\Modules\Review\Twig;

use Commerce\Modules\Review\Application\ReviewSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ReviewExtension extends AbstractExtension
{
    public function __construct(private readonly ReviewSettings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('reviews_allow_guests', fn (): bool => $this->settings->guestsAllowed())];
    }
}
