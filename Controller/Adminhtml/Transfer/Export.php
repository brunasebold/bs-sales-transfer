<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Controller\Adminhtml\Transfer;

use BS\SalesTransfer\Controller\Adminhtml\Transfer;
use BS\SalesTransfer\Model\Authorization\Access;
use BS\SalesTransfer\Model\Authorization\Acl;
use BS\SalesTransfer\Model\Transfer\ExportFilter;
use BS\SalesTransfer\Model\Transfer\Exporter;
use BS\SalesTransfer\Model\Validation\DateRange;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class Export extends Transfer implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Exporter $exporter,
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem,
        private readonly TimezoneInterface $timezone
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed()
    {
        return Access::canExport(
            $this->_authorization->isAllowed(Acl::TRANSFER),
            $this->_authorization->isAllowed(Acl::EXPORT)
        );
    }

    public function execute()
    {
        $relative = 'export/bs-sales-' . date('Ymd-His') . '.jsonl';
        $absolute = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getAbsolutePath($relative);

        try {
            $from = $this->rawDate('created_from');
            $to = $this->rawDate('created_to');
            if (!DateRange::isOrdered($from, $to)) {
                throw new LocalizedException(__('The start date must be on or before the end date.'));
            }
            $count = $this->exporter->export($absolute, new ExportFilter(
                $this->storeIds(),
                $this->utcBoundary($from, false),
                $this->utcBoundary($to, true)
            ));
        } catch (\Throwable $exception) {
            if (is_file($absolute)) {
                unlink($absolute);
            }
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->resultRedirectFactory->create()->setPath('bs_salestransfer/transfer/index');
        }

        if ($count === 0) {
            if (is_file($absolute)) {
                unlink($absolute);
            }
            $this->messageManager->addWarningMessage(__('No orders found for the selected filters.'));
            return $this->resultRedirectFactory->create()->setPath('bs_salestransfer/transfer/index');
        }

        return $this->fileFactory->create(
            basename($relative),
            [
                'type' => 'filename',
                'value' => $relative,
                'rm' => true,
            ],
            DirectoryList::VAR_DIR,
            'application/octet-stream'
        );
    }

    /**
     * @return int[]
     */
    private function storeIds(): array
    {
        $storeIds = array_map('intval', (array) $this->getRequest()->getParam('store_ids', []));

        return array_values(array_filter($storeIds));
    }

    private function rawDate(string $param): ?string
    {
        $date = trim((string) $this->getRequest()->getParam($param));

        return $date === '' ? null : $date;
    }

    private function utcBoundary(mixed $date, bool $endOfDay): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        if (!DateRange::isIsoDate($date)) {
            throw new LocalizedException(__('Use the date format YYYY-MM-DD.'));
        }
        $time = $endOfDay ? '23:59:59' : '00:00:00';

        return $this->timezone->convertConfigTimeToUtc($date . ' ' . $time);
    }
}
