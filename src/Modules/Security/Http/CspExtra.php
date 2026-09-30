<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Http;

use Symfony\Component\HttpFoundation\Request;

/** Lets several storefront components (chat, captcha, ...) add hosts to the page CSP without overwriting each other. */
final class CspExtra
{
    /** @param array<string,list<string>> $extra directive (script|style|font|connect|frame) => hosts */
    public static function merge(?Request $request, array $extra): void
    {
        if ($request === null) {
            return;
        }
        $current = $request->attributes->get('_csp_extra');
        $current = is_array($current) ? $current : [];
        foreach ($extra as $directive => $hosts) {
            $current[$directive] = array_values(array_unique([...(array) ($current[$directive] ?? []), ...$hosts]));
        }
        $request->attributes->set('_csp_extra', $current);
    }
}
