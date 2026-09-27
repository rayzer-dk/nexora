<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Theme;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Loader\FilesystemLoader;

final class ActiveThemeTemplateLoaderSubscriber implements EventSubscriberInterface
{
    private bool $loaded = false;

    public function __construct(private readonly Connection $connection, private readonly FilesystemLoader $twigLoader) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 2048]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->loaded) return;
        $this->loaded = true;
        try {
            $row = $this->connection->fetchAssociative("SELECT install_path,manifest_json FROM mc_extension_installation WHERE extension_type='theme' AND status='active' ORDER BY activated_at DESC,id DESC LIMIT 1");
            if (!is_array($row)) return;
            $manifest = json_decode((string) ($row['manifest_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || (string) ($manifest['execution'] ?? 'declarative') !== 'trusted_release') return;
            $root = realpath((string) $row['install_path']);
            $templates = realpath((string) $row['install_path'] . '/templates');
            if ($root === false || $templates === false || !str_starts_with($templates . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) return;
            $this->twigLoader->prependPath($templates, 'storefront');
        } catch (\Throwable) {
            // A broken optional theme must never prevent Core from booting with default templates.
        }
    }
}
