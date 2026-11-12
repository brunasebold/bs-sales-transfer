<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

/**
 * Turns a Magento increment id into the numeric counter stored in the sequence table.
 */
final class SequenceNumber
{
    public static function fromIncrementId(string $incrementId, ?string $prefix, ?string $suffix): int
    {
        $value = $incrementId;
        $prefix = (string) $prefix;
        $suffix = (string) $suffix;

        if ($prefix !== '' && str_starts_with($value, $prefix)) {
            $value = substr($value, strlen($prefix));
        }
        if ($suffix !== '' && str_ends_with($value, $suffix)) {
            $value = substr($value, 0, -strlen($suffix));
        }
        if (!preg_match('/^\d+$/', $value)) {
            return 0;
        }

        return (int) $value;
    }
}
