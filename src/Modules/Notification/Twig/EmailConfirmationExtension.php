<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Twig;

use Commerce\Modules\Notification\Application\EmailConfirmationSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class EmailConfirmationExtension extends AbstractExtension
{
    public function __construct(private readonly EmailConfirmationSettings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('email_confirmation_required', fn (string $place): bool => $this->settings->required($place))];
    }
}
