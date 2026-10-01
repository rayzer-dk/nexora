<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pseudo-cron: on hosting without a server cron the due tasks run opportunistically after a normal
 * response has been sent. Throttled by a stamp file (one check per minute, no database hit otherwise),
 * serialised by the runner's advisory lock and skipped while a real cron (server or web cron) is healthy.
 */
final class PseudoCronSubscriber implements EventSubscriberInterface
{
    private const TICK_SECONDS = 60;
    private const REAL_CRON_FRESH_SECONDS = 600;
    private const BUDGET_SECONDS = 20.0;

    public function __construct(
        private readonly CronRunner $runner,
        private readonly CronSettings $settings,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -256]];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest() || PHP_SAPI === 'cli' || $this->environment === 'test') {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || str_starts_with($route, 'cron_') || str_starts_with($route, '_') || $event->getResponse()->getStatusCode() >= 500) {
            return;
        }
        $stamp = $this->projectDir . '/var/cron/pseudo.stamp';
        $modified = @filemtime($stamp);
        if ($modified !== false && (time() - $modified) < self::TICK_SECONDS) {
            return;
        }
        if (!is_dir(dirname($stamp)) && !@mkdir(dirname($stamp), 0775, true) && !is_dir(dirname($stamp))) {
            return;
        }
        if (@touch($stamp) === false) {
            return;
        }
        try {
            if (!$this->settings->pseudoEnabled()) {
                return;
            }
            $last = $this->runner->lastTick();
            if (in_array($last['source'], ['cli', 'web'], true) && ($last['age_seconds'] ?? PHP_INT_MAX) < self::REAL_CRON_FRESH_SECONDS) {
                return;
            }
            @ignore_user_abort(true);
            @set_time_limit(60);
            $this->runner->run('pseudo', null, false, self::BUDGET_SECONDS);
        } catch (\Throwable) {
            // Never let background work affect the request that already completed.
        }
    }
}
