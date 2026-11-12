<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Controller\Adminhtml\Transfer;

use BS\SalesTransfer\Controller\Adminhtml\Transfer;
use BS\SalesTransfer\Model\Authorization\Access;
use BS\SalesTransfer\Model\Authorization\Acl;
use BS\SalesTransfer\Model\Transfer\Importer;
use BS\SalesTransfer\Model\Transfer\ImportProgress;
use BS\SalesTransfer\Model\Transfer\ImportResult;
use BS\SalesTransfer\Model\Transfer\SalesDocument;
use BS\SalesTransfer\Model\Validation\ImportBehavior;
use BS\SalesTransfer\Model\Validation\UploadLimit;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;

class Import extends Transfer implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Importer $importer,
        private readonly ImportProgress $progress,
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed()
    {
        return Access::canImport(
            $this->_authorization->isAllowed(Acl::TRANSFER),
            $this->_authorization->isAllowed(Acl::IMPORT)
        );
    }

    public function execute()
    {
        $token = (string) $this->getRequest()->getParam('progress_token');
        if ($token !== '' && $this->getRequest()->isAjax()) {
            return $this->executeWithProgress($token);
        }

        return $this->executeAndRedirect();
    }

    private function executeWithProgress(string $token): ResultInterface
    {
        if (!ImportProgress::isValidToken($token)) {
            return $this->json(['ok' => false, 'message' => (string) __('The import failed.')], 400);
        }

        $path = null;
        try {
            $path = $this->receiveFile();
            $behavior = $this->behavior();
            $this->_session->writeClose();
            $total = $this->importer->count($path);
            $this->progress->write($token, $this->state(0, $total, ''));
            $result = $this->importer->import(
                $path,
                $behavior,
                function (ImportResult $result, string $label) use ($token, $total): void {
                    $done = $result->imported + $result->skipped + $result->failed;
                    $this->progress->write($token, $this->state($done, $total, $label));
                }
            );

            return $this->json([
                'ok' => true,
                'message' => (string) __(
                    'Import finished. Imported: %1. Already present: %2. Failed: %3.',
                    $result->imported,
                    $result->skipped,
                    $result->failed
                ),
                'imported' => $result->imported,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
                'errors' => $result->errors,
                'warnings' => $result->warnings,
            ]);
        } catch (\Throwable $exception) {
            return $this->json([
                'ok' => false,
                'message' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : (string) __('The import failed.'),
                'errors' => [],
                'warnings' => [],
                'failed' => 1,
            ], 400);
        } finally {
            $this->progress->delete($token);
            if ($path && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function executeAndRedirect(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('bs_salestransfer/transfer/index');
        $path = null;

        try {
            $path = $this->receiveFile();
            $result = $this->importer->import($path, $this->behavior());
            $this->messageManager->addSuccessMessage(__(
                'Import finished. Imported: %1. Already present: %2. Failed: %3.',
                $result->imported,
                $result->skipped,
                $result->failed
            ));
            foreach ($result->errors as $error) {
                $this->messageManager->addErrorMessage($error);
            }
            foreach ($result->warnings as $warning) {
                $this->messageManager->addWarningMessage($warning);
            }
        } catch (\Throwable $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } finally {
            if ($path && is_file($path)) {
                unlink($path);
            }
        }

        return $redirect;
    }

    private function receiveFile(): string
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getAbsolutePath('import');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new LocalizedException(__('Could not prepare the import directory.'));
        }

        if (!UploadLimit::isAllowedSize($this->uploadSize())) {
            throw new LocalizedException(__('Select a .jsonl file up to 256 MB exported by this module.'));
        }

        $uploader = $this->uploaderFactory->create(['fileId' => 'import_file']);
        $uploader->setAllowedExtensions(['jsonl']);
        $uploader->setAllowRenameFiles(true);
        $uploader->setFilesDispersion(false);
        $saved = $uploader->save($directory);
        if (empty($saved['file'])) {
            throw new LocalizedException(__('Select a .jsonl file up to 256 MB exported by this module.'));
        }

        return rtrim((string) $saved['path'], '/') . '/' . $saved['file'];
    }

    private function behavior(): string
    {
        $behavior = (string) $this->getRequest()->getParam('behavior', SalesDocument::BEHAVIOR_SKIP);
        if (!ImportBehavior::isValid($behavior)) {
            throw new LocalizedException(__('Invalid import behavior.'));
        }

        return $behavior;
    }

    /**
     * @return array{status: string, processed: int, total: int, label: string}
     */
    private function state(int $processed, int $total, string $label): array
    {
        return [
            'status' => 'running',
            'processed' => $processed,
            'total' => $total,
            'label' => $label,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $code = 200): ResultInterface
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode($code);
        $result->setData($data);

        return $result;
    }

    private function uploadSize(): int
    {
        $upload = $this->getRequest()->getFiles('import_file');
        if (is_array($upload) || $upload instanceof \ArrayAccess) {
            return (int) ($upload['size'] ?? 0);
        }

        return 0;
    }
}
