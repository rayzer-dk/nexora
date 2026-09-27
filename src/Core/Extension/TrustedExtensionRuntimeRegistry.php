<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Commerce\Core\Event\StoredDomainEvent;

final class TrustedExtensionRuntimeRegistry
{
    /** @var array<string,callable> */
    private array $routeHandlers = [];
    /** @var array<string,list<callable>> */
    private array $eventHandlers = [];

    public function registerRoute(string $extensionCode, string $routeName, callable $handler): void
    {
        if (!str_starts_with($routeName, 'extension.' . str_replace(['.', '-'], '_', $extensionCode) . '.')) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.9f1d508677b0'));
        }
        if (isset($this->routeHandlers[$routeName])) {
            throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.b02aaff0af0a') . $routeName);
        }
        $this->routeHandlers[$routeName] = $handler;
    }

    public function registerEvent(string $extensionCode, string $eventName, callable $handler): void
    {
        if ($eventName === '' || strlen($eventName) > 190) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.c0ea847cc6b3'));
        }
        $this->eventHandlers[$eventName][] = $handler;
    }

    public function routeHandler(string $routeName): ?callable
    {
        return $this->routeHandlers[$routeName] ?? null;
    }

    public function dispatchEvent(StoredDomainEvent $event): void
    {
        foreach ($this->eventHandlers[$event->eventName] ?? [] as $handler) {
            $handler($event);
        }
    }
}
