<?php

declare(strict_types=1);

namespace Commerce\Modules\Localization\Application;

use Commerce\Modules\Localization\Domain\LocaleNormalizer;

final readonly class LocaleFallbackChain
{
    public function __construct(private LocaleNormalizer $normalizer = new LocaleNormalizer())
    {
    }

    /** @param list<string> $available @return list<string> */
    public function resolve(string $requested, string $storeDefault, array $available): array
    {
        $normalizedAvailable = [];
        foreach ($available as $locale) {
            $normalizedAvailable[$this->normalizer->normalize($locale)] = true;
        }
        $requested = $this->normalizer->normalize($requested);
        $storeDefault = $this->normalizer->normalize($storeDefault);
        $languageOnly = strtolower(strtok($requested, '-') ?: $requested);
        $chain = [];
        foreach ([$requested, $languageOnly, $storeDefault] as $candidate) {
            if ($candidate !== '' && isset($normalizedAvailable[$candidate]) && !in_array($candidate, $chain, true)) {
                $chain[] = $candidate;
            }
        }
        foreach (array_keys($normalizedAvailable) as $candidate) {
            if (!in_array($candidate, $chain, true)) {
                $chain[] = $candidate;
            }
        }
        return $chain;
    }
}
