<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Crm;

use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Renders the body template of a connection. The template is written by the shop owner, so it runs in a Twig sandbox: only if/for/set,
 * a short list of filters and no functions that reach the system. The result must be JSON.
 */
final class CrmTemplateRenderer
{
    private const FILTERS = ['json_encode', 'default', 'join', 'number_format', 'upper', 'lower', 'trim', 'slice', 'length', 'replace', 'first', 'last', 'date', 'raw', 'escape', 'e'];

    /** @param array<string,mixed> $snapshot @return string the JSON body */
    public function render(string $template, array $snapshot, string $event): string
    {
        $policy = new SecurityPolicy(['if', 'for', 'set'], self::FILTERS, [], [], ['range']);
        $twig = new Environment(new ArrayLoader(['body' => $template]), ['autoescape' => false, 'strict_variables' => false, 'cache' => false]);
        $twig->addExtension(new SandboxExtension($policy, true));
        $out = trim($twig->render('body', $snapshot + ['event' => $event]));
        try {
            json_decode($out, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('The body template does not make valid JSON: ' . $e->getMessage());
        }

        return $out;
    }
}
