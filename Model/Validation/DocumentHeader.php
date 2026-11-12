<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Validation;

use BS\SalesTransfer\Model\Transfer\SalesDocument;

final class DocumentHeader
{
    /**
     * @param array<string, mixed> $data
     */
    public static function isValid(array $data): bool
    {
        return ($data['format'] ?? null) === SalesDocument::FORMAT
            && (int) ($data['version'] ?? 0) === SalesDocument::VERSION;
    }
}
