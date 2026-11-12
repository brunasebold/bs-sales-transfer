<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Validation;

use BS\SalesTransfer\Model\Transfer\SalesDocument;

final class ImportBehavior
{
    public static function isValid(string $behavior): bool
    {
        return in_array($behavior, [SalesDocument::BEHAVIOR_SKIP, SalesDocument::BEHAVIOR_REPLACE], true);
    }
}
