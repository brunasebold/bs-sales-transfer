<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Validation;

final class IncrementId
{
    public const MAX_LENGTH = 50;

    public static function isValid(string $incrementId): bool
    {
        if ($incrementId === '' || strlen($incrementId) > self::MAX_LENGTH) {
            return false;
        }

        return !preg_match('/[\x00-\x1F\x7F]/', $incrementId);
    }
}
