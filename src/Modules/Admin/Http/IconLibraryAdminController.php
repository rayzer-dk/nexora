<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\StorefrontUiTwigExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Every Lucide icon, for the icon picker: the owner chooses which icons their cards, banners and menus use. */
final class IconLibraryAdminController extends AbstractController
{
    #[Route('/admin/icons.json', name: 'admin_icon_library', methods: ['GET'])]
    public function library(): JsonResponse
    {
        $response = new JsonResponse(['icons' => StorefrontUiTwigExtension::loadIconLibrary()]);
        // The library only changes with a release, so the browser may keep it for a day.
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }
}
