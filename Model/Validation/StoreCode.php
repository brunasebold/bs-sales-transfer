<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Validation;

final class StoreCode
{
    public static function isValid(string $code): bool
    {
        return $code !== '' && (bool) preg_match('/^[A-Za-z0-9_]+$/', $code);
    }
}
