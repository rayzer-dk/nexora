<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Exception\InvalidArgumentException as InvalidUidException;

final class MalformedIdentifierSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if ($throwable instanceof InvalidUidException) {
            $event->setThrowable(new NotFoundHttpException('Not Found', $throwable));
        }
    }
}
