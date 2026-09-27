<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Domain;

final readonly class MigrationDryRunReport
{
    /** @param array<string, int> $counts @param list<MigrationIssue> $issues */
    public function __construct(public array $counts, public array $issues)
    {
    }

    public function hasBlockingIssues(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === 'error') {
                return true;
            }
        }
        return false;
    }
}
