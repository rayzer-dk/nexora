<?php

declare(strict_types=1);

namespace Commerce\Core\Deployment;

use RuntimeException;

final readonly class AtomicReleaseLinkSwitcher
{
    public function __construct(private ImmutableReleaseLayout $layout)
    {
    }

    public function switchTo(string $releaseId): ?string
    {
        $this->layout->ensure();
        $release = $this->layout->releaseDir($releaseId);
        if (!is_dir($release) || !is_file($release . '/public/index.php')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9e133ec6c2d6'));
        }
        $current = $this->layout->currentLink();
        $previous = is_link($current) ? readlink($current) : null;
        $tmp = $current . '.next-' . bin2hex(random_bytes(4));
        if (!@symlink($release, $tmp)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b733cebc02f9'));
        }
        if (!@rename($tmp, $current)) {
            @unlink($tmp);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.956219ef0641'));
        }
        return is_string($previous) ? $previous : null;
    }
}
