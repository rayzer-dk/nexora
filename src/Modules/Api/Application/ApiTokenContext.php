<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Application;

final readonly class ApiTokenContext
{
    /** @param list<string> $scopes */
    public function __construct(
        public int $id,
        public ?int $storeId,
        public array $scopes,
        public int $rateLimitPerMinute,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }
}
