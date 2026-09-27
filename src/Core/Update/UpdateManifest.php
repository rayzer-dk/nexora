<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use InvalidArgumentException;

final readonly class UpdateManifest
{
    public function __construct(
        public string $version,
        public string $channel,
        public string $minPhp,
        public string $maxPhpExclusive,
        public string $extensionApi,
        public string $packageUrl,
        public string $sha256,
        public string $signature,
        public array $requiredExtensions = [],
        public ?string $minDatabaseVersion = null,
    ) {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.92f110d42de0'));
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2688fb86647d'));
        }

        if (version_compare($minPhp, $maxPhpExclusive, '>=')) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fa4596f9c6d4'));
        }
    }

    public function signedPayload(): string
    {
        return implode("\n", [
            $this->version,
            $this->channel,
            $this->minPhp,
            $this->maxPhpExclusive,
            $this->extensionApi,
            $this->packageUrl,
            strtolower($this->sha256),
            implode(',', $this->requiredExtensions),
            $this->minDatabaseVersion ?? '',
        ]);
    }
}
