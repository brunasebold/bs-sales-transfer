<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Controller\Adminhtml\Transfer;

use BS\SalesTransfer\Controller\Adminhtml\Transfer;
use BS\SalesTransfer\Model\Authorization\Access;
use BS\SalesTransfer\Model\Authorization\Acl;
use BS\SalesTransfer\Model\Transfer\ImportProgress;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

class Progress extends Transfer implements HttpGetActionInterface
{
    public function __construct(Context $context, private readonly ImportProgress $progress)
    {
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
        $token = (string) $this->getRequest()->getParam('token');
        $state = ImportProgress::isValidToken($token) ? $this->progress->read($token) : null;

        return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData(
            $state ?? ['status' => 'waiting', 'processed' => 0, 'total' => 0, 'label' => '']
        );
    }
}
