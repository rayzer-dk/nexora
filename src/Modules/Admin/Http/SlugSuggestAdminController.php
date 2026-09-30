<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Seo\Application\SlugGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Suggests an SEO URL slug (transliterated, ASCII) from a name; used by the generate icon next to every slug field. */
final class SlugSuggestAdminController extends AbstractController
{
    public function __construct(private readonly SlugGenerator $slugs)
    {
    }

    #[Route('/admin/api/slug', name: 'admin_api_slug', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $text = mb_substr(strip_tags((string) $request->query->get('text', '')), 0, 300, 'UTF-8');
        $locale = preg_match('/^[a-z]{2,3}(?:-[A-Za-z]{2})?$/D', (string) $request->query->get('locale', '')) === 1 ? (string) $request->query->get('locale') : 'uk-UA';
        try {
            return $this->json(['slug' => $this->slugs->generate($text, $locale)], 200, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException) {
            return $this->json(['slug' => ''], 200, ['Cache-Control' => 'no-store']);
        }
    }
}
