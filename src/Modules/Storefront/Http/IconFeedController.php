<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Http;

use Commerce\Core\I18n\StorefrontUiTwigExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** The drawings of the icons an owner put into a text (rich-text editor); the page asks only for the names it shows. */
final class IconFeedController extends AbstractController
{
    #[Route('/icons.json', name: 'storefront_icons', methods: ['GET'], priority: 100)]
    public function icons(Request $request): JsonResponse
    {
        $names = array_slice(array_unique(array_filter(explode(',', (string) $request->query->get('names', '')), static fn (string $n): bool => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $n) === 1)), 0, 60);
        $library = StorefrontUiTwigExtension::loadIconLibrary();
        $icons = [];
        foreach ($names as $name) {
            if (isset($library[$name])) {
                $icons[$name] = $library[$name];
            }
        }
        $response = new JsonResponse(['icons' => $icons]);
        $response->setPublic();
        $response->setMaxAge(604800);

        return $response;
    }
}
