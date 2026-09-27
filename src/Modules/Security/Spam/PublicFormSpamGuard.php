<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Spam;

final class PublicFormSpamGuard
{
    public function check(string $honeypot, int $renderedAtUnix, int $nowUnix, int $payloadBytes): SpamCheckResult
    {
        if (trim($honeypot) !== '') {
            return new SpamCheckResult(false, 'honeypot');
        }

        $elapsed = $nowUnix - $renderedAtUnix;
        if ($elapsed < 1) {
            return new SpamCheckResult(false, 'submitted_too_fast', true);
        }

        if ($elapsed > 7200) {
            return new SpamCheckResult(false, 'form_expired');
        }

        if ($payloadBytes > 262144) {
            return new SpamCheckResult(false, 'payload_too_large');
        }

        return new SpamCheckResult(true);
    }
}
