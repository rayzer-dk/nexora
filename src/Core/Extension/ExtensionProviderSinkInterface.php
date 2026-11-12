<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

/** Lets a module accept the providers an extension contributes through TrustedExtensionContext, without Core knowing the module. */
interface ExtensionProviderSinkInterface
{
    /** Capability this sink serves, e.g. `provider.payment`. */
    public function capability(): string;

    public function accept(object $provider): void;
}
