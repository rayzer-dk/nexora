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

    /** Whether a guest must leave an e-mail. Off by default: the shop only needs it to reply by e-mail. */
    public function emailRequired(): bool
    {
        return (bool) (($this->store->getArray(self::KEY) ?? [])['email_required'] ?? false);
    }

    public function save(bool $guestsAllowed, bool $emailRequired): void
    {
        $this->store->setArray(self::KEY, ['guests' => $guestsAllowed, 'email_required' => $emailRequired]);
    }
}
