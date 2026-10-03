<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Twig;

use Commerce\Modules\Notification\Application\NotificationChannelSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** email_design(): the colours and footer chosen in the admin for every e-mail template. */
final class EmailDesignTwigExtension extends AbstractExtension
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private readonly NotificationChannelSettings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('email_design', $this->design(...))];
    }

    /** @return array<string,string> */
    public function design(): array
    {
        return $this->cache ??= $this->settings->design();
    }
}
