<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Contract;

/** @api Public connector contract for external integration providers. */
interface CommerceConnectorInterface
{
    public function code(): string;
    public function label(): string;

    /** @return list<CommerceConnectorCapability> */
    public function capabilities(): array;

    public function health(): ConnectorHealth;
}
