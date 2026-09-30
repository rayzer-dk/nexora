<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Twig;

use Commerce\Modules\Security\Captcha\CaptchaService;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CaptchaExtension extends AbstractExtension
{
    public function __construct(private readonly CaptchaService $captcha, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('captcha_widget', [$this, 'widget'])];
    }

    /** @return array<string,string>|null */
    public function widget(string $form): ?array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return null;
        }
        try {
            return $this->captcha->widget($request, $form);
        } catch (\Throwable) {
            return null;
        }
    }
}
