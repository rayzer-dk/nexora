<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Domain;

final readonly class SeoRouteResolution
{
    private function __construct(
        public ?SeoRoute $route,
        public ?int $redirectStatus,
    ) {
    }

    public static function canonical(SeoRoute $route): self
    {
        return new self($route, null);
    }

    public static function redirect(SeoRoute $route, int $status = 301): self
    {
        return new self($route, $status);
    }

    public static function notFound(): self
    {
        return new self(null, null);
    }

    public function isRedirect(): bool
    {
        return $this->route !== null && $this->redirectStatus !== null;
    }
}
