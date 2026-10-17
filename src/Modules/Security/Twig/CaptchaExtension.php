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

    /** @return array{provider:string,site_key:string,form:string,token:string,mode:string,question:string,options:list<array{id:string,icon:string}>,key:string}|null */
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
