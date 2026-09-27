<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ExtensionSlotTwigExtension extends AbstractExtension
{
    public function __construct(private readonly ExtensionContributionRegistry $registry)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('extension_slot', [$this, 'slot'])];
    }

    /** @return list<array<string,mixed>> */
    public function slot(string $slot): array
    {
        $out = [];
        foreach ($this->registry->slotContributions($slot) as $contribution) {
            $component = (string) ($contribution['component'] ?? '');
            $definition = $this->registry->block($component);
            if ($component === '' || !is_array($definition)) {
                continue;
            }
            $out[] = [
                'slot' => $slot,
                'component' => $component,
                'extension_code' => (string) ($contribution['extension_code'] ?? ''),
                'priority' => (int) ($contribution['priority'] ?? 0),
                'title_key' => isset($contribution['title_key']) ? (string) $contribution['title_key'] : null,
                'content_key' => isset($contribution['content_key']) ? (string) $contribution['content_key'] : null,
                'definition' => $definition,
            ];
        }
        return $out;
    }
}
