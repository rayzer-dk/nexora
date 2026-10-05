<?php

declare(strict_types=1);

namespace Commerce\Modules\Review\Application;

use Commerce\Core\Configuration\SystemSettingStore;

/** Whether visitors who are not signed in may leave reviews and questions (they are always moderated first). */
final class ReviewSettings
{
    private const KEY = 'reviews.settings';

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    public function guestsAllowed(): bool
    {
        return (bool) (($this->store->getArray(self::KEY) ?? [])['guests'] ?? true);
    }

    public function setGuestsAllowed(bool $allowed): void
    {
        $this->store->setArray(self::KEY, ['guests' => $allowed]);
    }
}
