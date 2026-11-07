<?php

declare(strict_types=1);

namespace Commerce\Modules\Review\Http;

use Commerce\Modules\Review\Application\GoogleProductReviewsFeed;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReviewsFeedController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly GoogleProductReviewsFeed $feed)
    {
    }

    #[Route('/feeds/{storeCode}/google-reviews', name: 'public_google_reviews_feed', methods: ['GET'], requirements: ['storeCode' => '[A-Za-z0-9_-]+'])]
    public function __invoke(string $storeCode): Response
    {
        $store = $this->db->fetchAssociative("SELECT id,name FROM mc_store WHERE code=? AND status='active' LIMIT 1", [$storeCode]);
        if (!is_array($store)) {
            throw $this->createNotFoundException();
        }
        $response = new Response($this->feed->render((int) $store['id'], (string) $store['name']), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
