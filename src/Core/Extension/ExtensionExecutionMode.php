<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

enum ExtensionExecutionMode: string
{
    case Declarative = 'declarative';
    case RemoteApp = 'remote_app';
    case TrustedRelease = 'trusted_release';
}
