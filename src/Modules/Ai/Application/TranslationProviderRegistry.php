<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Ai\Contract\TranslationProviderInterface;

/** Translation providers contributed by signed extensions (capability `provider.translation`). */
final class TranslationProviderRegistry
{
    /** @var array<string,TranslationProviderInterface> */
    private array $providers = [];

    public function register(TranslationProviderInterface $provider): void
    {
        $code = trim($provider->code());
        if ($code === '' || isset($this->providers[$code]) || preg_match('/^[a-z][a-z0-9_]{1,39}$/D', $code) !== 1 || in_array($code, ['openai', 'gemini', 'anthropic'], true)) {
            throw new \DomainException(CanonicalUiText::get('extension.sdk.translation_provider_invalid') . $code);
        }
        $this->providers[$code] = $provider;
    }

    /** @return list<array{code:string,label:string}> */
    public function enabled(): array
    {
        $list = [];
        foreach ($this->providers as $code => $provider) {
            if ($provider->enabled()) {
                $list[] = ['code' => $code, 'label' => mb_substr(trim($provider->label()) !== '' ? $provider->label() : $code, 0, 60)];
            }
        }

        return $list;
    }

    public function has(string $code): bool
    {
        return isset($this->providers[$code]) && $this->providers[$code]->enabled();
    }

    public function require(string $code): TranslationProviderInterface
    {
        if (!$this->has($code)) {
            throw new \DomainException(CanonicalUiText::get('runtime.exception.794607124bae'));
        }

        return $this->providers[$code];
    }
}
