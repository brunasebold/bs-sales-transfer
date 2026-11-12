<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Controller\Adminhtml\Transfer;

use BS\SalesTransfer\Controller\Adminhtml\Transfer;
use BS\SalesTransfer\Model\Authorization\Access;
use BS\SalesTransfer\Model\Authorization\Acl;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

class Index extends Transfer implements HttpGetActionInterface
{
    public function execute()
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu(Acl::TRANSFER);
        $resultPage->getConfig()->getTitle()->prepend(__('Sales Transfer'));

        return $resultPage;
    }

    protected function _isAllowed()
    {
        return Access::canOpen(
            $this->_authorization->isAllowed(Acl::TRANSFER),
            $this->_authorization->isAllowed(Acl::EXPORT),
            $this->_authorization->isAllowed(Acl::IMPORT)
        );
    }
}
