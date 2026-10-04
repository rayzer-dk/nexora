<?php

declare(strict_types=1);

namespace Commerce\Modules\Search\Infrastructure;

use Commerce\Core\Extension\ExtensionServiceRegistry;
use Commerce\Modules\Search\Contract\SearchCandidateProviderInterface;
use Commerce\Modules\Search\Domain\SearchCandidateResult;
use Commerce\Modules\Storefront\Domain\StorefrontContext;

/**
 * Asks the search engines of signed modules (`provider.search`) first and falls back to the built-in engine. An engine
 * that returns null or throws is skipped, so the storefront always reaches its canonical SQL search.
 */
final class ExtensionAwareSearchCandidateProvider implements SearchCandidateProviderInterface
{
    public function __construct(private readonly SearchCandidateProviderInterface $builtIn, private readonly ExtensionServiceRegistry $extensions)
    {
    }

    public function candidates(StorefrontContext $context, string $query, int $limit = 5000): ?SearchCandidateResult
    {
        foreach ($this->extensions->all('provider.search') as $engine) {
            if (!$engine instanceof SearchCandidateProviderInterface) {
                continue;
            }
            try {
                $result = $engine->candidates($context, $query, $limit);
            } catch (\Throwable) {
                continue;
            }
            if ($result !== null) {
                return $result;
            }
        }

        return $this->builtIn->candidates($context, $query, $limit);
    }
}
