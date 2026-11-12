<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Validation;

final class UploadLimit
{
    public const MAX_BYTES = 268435456;

    public static function isAllowedSize(int $bytes): bool
    {
        return $bytes > 0 && $bytes <= self::MAX_BYTES;
    }
}
