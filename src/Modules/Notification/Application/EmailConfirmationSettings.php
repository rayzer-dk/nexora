<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Core\Configuration\SystemSettingStore;

/**
 * Where a shopper must confirm the e-mail address by a link before it counts: the newsletter and the "tell me when it is back" request.
 * On by default (double opt-in is expected or required in many countries); each place has its own switch.
 */
final class EmailConfirmationSettings
{
    public const PLACES = ['newsletter', 'stock_notify'];
    private const KEY = 'email.confirmation';

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    public function required(string $place): bool
    {
        return (bool) (($this->store->getArray(self::KEY) ?? [])[$place] ?? true);
    }

    /** @param array<string,mixed> $input place => truthy */
    public function save(array $input): void
    {
        $out = [];
        foreach (self::PLACES as $place) {
            $out[$place] = !empty($input[$place]);
        }
        $this->store->setArray(self::KEY, $out);
    }
}
