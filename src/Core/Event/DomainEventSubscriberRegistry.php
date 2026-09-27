<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

final class DomainEventSubscriberRegistry
{
    /** @var array<string,list<DomainEventSubscriberInterface>> */
    private array $byEvent = [];

    /** @param iterable<DomainEventSubscriberInterface> $subscribers */
    public function __construct(iterable $subscribers)
    {
        $ids = [];
        foreach ($subscribers as $subscriber) {
            $id = trim($subscriber->subscriberId());
            if ($id === '' || strlen($id) > 190 || preg_match('/^[a-z0-9_.:-]+$/D', $id) !== 1) {
                throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.61116db04477') . $id);
            }
            if (isset($ids[$id])) {
                throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6ae9d605ea1e') . $id);
            }
            $ids[$id] = true;
            foreach (array_values(array_unique($subscriber->subscribedEvents())) as $eventName) {
                if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/D', $eventName) !== 1) {
                    throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.edbc38dd21ee') . $eventName);
                }
                $this->byEvent[$eventName][] = $subscriber;
            }
        }
    }

    /** @return list<DomainEventSubscriberInterface> */
    public function subscribersFor(string $eventName): array
    {
        return $this->byEvent[$eventName] ?? [];
    }
}
