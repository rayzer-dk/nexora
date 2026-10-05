<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Commerce\Core\Configuration\SystemSettingStore;

/**
 * When a member's post goes live without a moderator: never ("moderate"), once the member has enough approved posts ("trusted"),
 * or at once for every signed-in member ("authorized"). Topics and replies have their own mode; posts with many links always wait.
 */
final class ForumSettings
{
    public const MODES = ['moderate', 'trusted', 'authorized'];
    private const KEY = 'forum.settings';

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    /** @return array{topics_mode:string,replies_mode:string,trusted_after:int,max_links:int} */
    public function all(): array
    {
        $raw = $this->store->getArray(self::KEY) ?? [];

        return [
            'topics_mode' => $this->mode($raw['topics_mode'] ?? null),
            'replies_mode' => $this->mode($raw['replies_mode'] ?? null),
            'trusted_after' => max(1, min(100, (int) ($raw['trusted_after'] ?? 3))),
            'max_links' => max(0, min(20, (int) ($raw['max_links'] ?? 2))),
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $this->store->setArray(self::KEY, [
            'topics_mode' => $this->mode($input['topics_mode'] ?? null),
            'replies_mode' => $this->mode($input['replies_mode'] ?? null),
            'trusted_after' => max(1, min(100, (int) ($input['trusted_after'] ?? 3))),
            'max_links' => max(0, min(20, (int) ($input['max_links'] ?? 2))),
        ]);
    }

    private function mode(mixed $value): string
    {
        return is_string($value) && in_array($value, self::MODES, true) ? $value : 'moderate';
    }
}
