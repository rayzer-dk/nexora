<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

/**
 * Services contributed by signed modules for the extension points of the core: exchange-rate sources, search engines,
 * notification senders and feed formats. The core consults this registry at the point of use; stores without such
 * modules see an empty registry and keep the built-in behaviour.
 */
final class ExtensionServiceRegistry
{
    /** Capability => interface the service must implement. */
    public const CONTRACTS = [
        'provider.exchange_rate' => \Commerce\Modules\Pricing\Contract\ReferenceRateSourceInterface::class,
        'provider.search' => \Commerce\Modules\Search\Contract\SearchCandidateProviderInterface::class,
        'provider.notification_sender' => \Commerce\Modules\Notification\Contract\NotificationSenderInterface::class,
        'provider.feed' => \Commerce\Modules\Feeds\Contract\FeedFormatProviderInterface::class,
    ];

    /** @var array<string,list<object>> */
    private array $services = [];

    private ?\Closure $booter = null;

    /** @param \Closure():TrustedProviderBootSubscriber $booter The boot step runs once, before the first lookup, so cron, queue and console see module services as web requests do. */
    public function setBooter(\Closure $booter): void
    {
        $this->booter = $booter;
    }

    public function add(string $capability, object $service): void
    {
        $contract = self::CONTRACTS[$capability] ?? null;
        if ($contract === null || !$service instanceof $contract) {
            throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.service_contract') . $capability . ' (' . $service::class . ')');
        }
        $this->services[$capability][] = $service;
    }

    /** @return list<object> */
    public function all(string $capability): array
    {
        if ($this->booter !== null) {
            $booter = $this->booter;
            $this->booter = null;
            try {
                $booter()->ensureBooted();
            } catch (\Throwable) {
                // A module that fails to boot is isolated by the loader; the built-in behaviour continues.
            }
        }

        return $this->services[$capability] ?? [];
    }
}
