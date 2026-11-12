<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

final class ExportFilter
{
    /**
     * @param int[] $storeIds
     */
    public function __construct(
        public readonly array $storeIds = [],
        public readonly ?string $createdFrom = null,
        public readonly ?string $createdTo = null
    ) {
    }
}
