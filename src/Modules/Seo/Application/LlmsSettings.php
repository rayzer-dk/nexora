<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Core\Configuration\SystemSettingStore;

/** What the shop tells AI assistants about itself in /llms.txt: a short profile, own instructions, extra links and how much of the catalogue is listed. */
final class LlmsSettings
{
    private const KEY = 'ai.llms';

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    /** @return array{enabled:bool,description:string,instructions:string,links:string,categories:int,products:int,articles:int,prices:bool} */
    public function all(): array
    {
        $raw = $this->store->getArray(self::KEY) ?? [];

        return [
            'enabled' => (bool) ($raw['enabled'] ?? true),
            'description' => (string) ($raw['description'] ?? ''),
            'instructions' => (string) ($raw['instructions'] ?? ''),
            'links' => (string) ($raw['links'] ?? ''),
            'categories' => max(0, min(500, (int) ($raw['categories'] ?? 50))),
            'products' => max(0, min(2000, (int) ($raw['products'] ?? 100))),
            'articles' => max(0, min(200, (int) ($raw['articles'] ?? 20))),
            'prices' => (bool) ($raw['prices'] ?? true),
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $text = static fn (mixed $v, int $max): string => trim(mb_substr(str_replace("\0", '', strip_tags((string) $v)), 0, $max, 'UTF-8'));
        $this->store->setArray(self::KEY, [
            'enabled' => !empty($input['enabled']),
            'description' => $text($input['description'] ?? '', 1500),
            'instructions' => $text($input['instructions'] ?? '', 2000),
            'links' => $text($input['links'] ?? '', 4000),
            'categories' => max(0, min(500, (int) ($input['categories'] ?? 50))),
            'products' => max(0, min(2000, (int) ($input['products'] ?? 100))),
            'articles' => max(0, min(200, (int) ($input['articles'] ?? 20))),
            'prices' => !empty($input['prices']),
        ]);
    }
}
