<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Authorization;

/**
 * ACL resources for the sales transfer screens and commands.
 */
final class Acl
{
    public const TRANSFER = 'BS_SalesTransfer::transfer';

    public const EXPORT = 'BS_SalesTransfer::export';

    public const IMPORT = 'BS_SalesTransfer::import';
}
