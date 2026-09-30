<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Captcha;

use Symfony\Component\HttpFoundation\Request;

interface CaptchaVerifier
{
    /** True when a captcha is configured for this form. */
    public function required(Request $request, string $form): bool;

    /** True when the form needs no captcha or the submitted answer is valid. */
    public function verify(Request $request, string $form): bool;
}
