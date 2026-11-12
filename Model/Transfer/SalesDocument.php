<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

/**
 * Identifies the portable file written by the exporter and read by the importer.
 */
final class SalesDocument
{
    public const FORMAT = 'bs_salestransfer_transfer';

    public const VERSION = 1;

    public const BEHAVIOR_SKIP = 'skip';

    public const BEHAVIOR_REPLACE = 'replace';
}
