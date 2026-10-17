<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Application;

use Commerce\Core\I18n\CanonicalUiText;

/** The requests a task needs do not fit into what is left of today's AI limit. Thrown before any request is sent. */
final class AiLimitException extends \DomainException
{
    public function __construct(public readonly int $needed, public readonly int $limit, public readonly int $used)
    {
        parent::__construct(CanonicalUiText::get('admin.ai.error_limit'));
    }
}
