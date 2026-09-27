<?php

declare(strict_types=1);
namespace Commerce\Modules\Review\Domain;
final readonly class ProductReview
{
    public function __construct(
        public string $publicId,
        public string $productPublicId,
        public string $authorName,
        public int $rating,
        public string $body,
        public bool $verifiedPurchase,
        public ReviewStatus $status = ReviewStatus::Pending,
    ) {
        if ($rating < 1 || $rating > 5 || trim($authorName)==='' || trim($body)==='') throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8554c983e339'));
    }
}
