<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Authorization;

/**
 * Decides which sales-transfer actions an admin may perform.
 *
 * The parent resource grants both actions. Each child resource grants only itself,
 * so a role can export without being allowed to import.
 */
final class Access
{
    public static function canOpen(bool $transfer, bool $export, bool $import): bool
    {
        return $transfer || $export || $import;
    }

    public static function canExport(bool $transfer, bool $export): bool
    {
        return $transfer || $export;
    }

    public static function canImport(bool $transfer, bool $import): bool
    {
        return $transfer || $import;
    }
}
