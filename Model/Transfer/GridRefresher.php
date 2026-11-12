<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

use Magento\Sales\Model\ResourceModel\Grid;

/**
 * Rebuilds the admin grids from the rows just inserted.
 */
class GridRefresher
{
    public function __construct(
        private readonly Grid $orderGrid,
        private readonly Grid $invoiceGrid,
        private readonly Grid $shipmentGrid,
        private readonly Grid $creditmemoGrid
    ) {
    }

    public function order(int $orderId): void
    {
        $this->refresh($this->orderGrid, $orderId);
    }

    public function invoice(int $invoiceId): void
    {
        $this->refresh($this->invoiceGrid, $invoiceId);
    }

    public function shipment(int $shipmentId): void
    {
        $this->refresh($this->shipmentGrid, $shipmentId);
    }

    public function creditmemo(int $creditmemoId): void
    {
        $this->refresh($this->creditmemoGrid, $creditmemoId);
    }

    private function refresh(Grid $grid, int $entityId): void
    {
        if ($entityId > 0) {
            $grid->refresh($entityId);
        }
    }
}
