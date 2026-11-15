<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\AdminInterfaceLocale;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminInterfaceLocaleTwigExtension extends AbstractExtension
{
    public function __construct(private readonly AdminInterfaceLocale $locales, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('admin_interface_languages', $this->languages(...))];
    }

    /** @return array{current:string,default:string,available:array<string,string>} */
    public function languages(): array
    {
        $current = (string) ($this->requests->getCurrentRequest()?->attributes->get(AdminInterfaceLocale::REQUEST_ATTRIBUTE) ?? AdminInterfaceLocale::DEFAULT);

        $available = $this->locales->choices();

        return ['current' => $current, 'default' => (string) array_key_first($available), 'available' => $available];
    }
}
