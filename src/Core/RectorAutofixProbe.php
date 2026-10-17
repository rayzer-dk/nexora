<?php

declare(strict_types=1);

namespace Commerce\Core;

final class RectorAutofixProbe
{
    public function isEmpty(array $items): bool
    {
        if (!count($items)) {
            return true;
        }

        return false;
    }
}
