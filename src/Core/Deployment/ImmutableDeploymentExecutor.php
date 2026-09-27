<?php

declare(strict_types=1);

namespace Commerce\Core\Deployment;

use Closure;
use RuntimeException;
use Throwable;

final readonly class ImmutableDeploymentExecutor
{
    public function __construct(
        private ImmutableReleaseLayout $layout,
        private FailureInjector $failures = new FailureInjector(),
    ) {
    }

    /**
     * @param Closure(string):void $prepare receives release directory
     * @param Closure(string):void $migrate receives release directory
     * @param Closure(string):bool $smoke receives release directory
     */
    public function deploy(string $releaseId, Closure $prepare, Closure $migrate, Closure $smoke): void
    {
        $this->layout->ensure();
        $releaseDir = $this->layout->releaseDir($releaseId);
        $previous = $this->currentTarget();
        try {
            $this->failures->hit(FailurePoint::AfterBackup);
            $prepare($releaseDir);
            $this->failures->hit(FailurePoint::AfterReleasePrepare);
            if (!is_file($releaseDir . '/public/index.php')) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.88469b5d061e'));
            }
            $this->failures->hit(FailurePoint::BeforeMigration);
            $migrate($releaseDir);
            $this->failures->hit(FailurePoint::AfterMigration);
            $this->failures->hit(FailurePoint::BeforeSwitch);
            (new AtomicReleaseLinkSwitcher($this->layout))->switchTo($releaseId);
            $this->failures->hit(FailurePoint::AfterSwitch);
            $this->failures->hit(FailurePoint::SmokeProbe);
            if (!$smoke($releaseDir)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.295804574539'));
            }
        } catch (Throwable $e) {
            $this->restoreCurrent($previous);
            throw $e;
        }
    }

    private function currentTarget(): ?string
    {
        $current = $this->layout->currentLink();
        if (!is_link($current)) {
            return null;
        }
        $target = readlink($current);
        return is_string($target) ? $target : null;
    }

    private function restoreCurrent(?string $target): void
    {
        $current = $this->layout->currentLink();
        if ($target === null) {
            if (is_link($current)) {
                @unlink($current);
            }
            return;
        }
        $tmp = $current . '.rollback-' . bin2hex(random_bytes(4));
        if (!@symlink($target, $tmp)) {
            return;
        }
        if (!@rename($tmp, $current)) {
            @unlink($tmp);
        }
    }
}
