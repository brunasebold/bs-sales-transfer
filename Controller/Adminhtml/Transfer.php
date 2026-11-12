<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Controller\Adminhtml;

use BS\SalesTransfer\Model\Authorization\Acl;
use Magento\Backend\App\Action;

abstract class Transfer extends Action
{
    public const ADMIN_RESOURCE = Acl::TRANSFER;
}
