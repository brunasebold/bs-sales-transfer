<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Block\Adminhtml;

use BS\SalesTransfer\Model\Authorization\Access;
use BS\SalesTransfer\Model\Authorization\Acl;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Store\Model\StoreManagerInterface;

class Transfer extends Template
{
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public function getStores(): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            $stores[] = [
                'id' => (int) $store->getId(),
                'code' => (string) $store->getCode(),
                'name' => (string) $store->getName(),
            ];
        }

        return $stores;
    }

    public function canExport(): bool
    {
        return Access::canExport(
            $this->authorization->isAllowed(Acl::TRANSFER),
            $this->authorization->isAllowed(Acl::EXPORT)
        );
    }

    public function canImport(): bool
    {
        return Access::canImport(
            $this->authorization->isAllowed(Acl::TRANSFER),
            $this->authorization->isAllowed(Acl::IMPORT)
        );
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('bs_salestransfer/transfer/export');
    }

    public function getImportUrl(): string
    {
        return $this->getUrl('bs_salestransfer/transfer/import');
    }

    public function getProgressUrl(): string
    {
        return $this->getUrl('bs_salestransfer/transfer/progress');
    }

    public function getImportInitJson(): string
    {
        return (string) json_encode([
            '#bs-sales-import-form' => [
                'BS_SalesTransfer/js/import-progress' => [
                    'progressUrl' => $this->getProgressUrl(),
                    'preparing' => (string) __('Preparing the import...'),
                    'running' => (string) __('Importing order %1. %2 of %3.'),
                    'counting' => (string) __('Importing order %1. %2 processed.'),
                    'failed' => (string) __('The import failed.'),
                ],
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
