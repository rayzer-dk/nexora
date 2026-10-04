<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Update\PendingMigrations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** What is installed, whether the database has caught up with the files, and how to update or restore. */
final class AdminSystemUpdateController extends AbstractController
{
    public function __construct(private readonly PendingMigrations $migrations)
    {
    }

    #[Route('/admin/system/update', name: 'admin_system_update', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $files = $this->migrations->files();
        $applied = $this->migrations->applied();
        $pending = array_values(array_diff($files, $applied));

        return $this->render('@storefront/admin/system/update.html.twig', [
            'version' => PlatformVersion::VERSION,
            'channel' => PlatformVersion::CHANNEL,
            'schema' => PlatformVersion::DATABASE_SCHEMA,
            'extension_api' => PlatformVersion::EXTENSION_API,
            'php' => PHP_VERSION,
            'php_min' => PlatformVersion::MIN_PHP,
            'files' => count($files),
            'applied' => count(array_intersect($files, $applied)),
            'pending' => $pending,
            'upgrade_url' => $request->getSchemeAndHttpHost() . '/upgrade.php',
        ]);
    }
}
