<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Plugin;

use BS\SalesTransfer\Model\Authorization\Access;
use BS\SalesTransfer\Model\Authorization\Acl;
use Magento\Backend\Model\Menu\Item;
use Magento\Framework\AuthorizationInterface;

/**
 * Shows the menu when the role has export or import, even without the parent resource.
 */
class MenuAccess
{
    public function __construct(private readonly AuthorizationInterface $authorization)
    {
    }

    public function afterIsAllowed(Item $subject, bool $result): bool
    {
        if ($subject->getId() !== Acl::TRANSFER) {
            return $result;
        }

        return Access::canOpen(
            $result,
            $this->authorization->isAllowed(Acl::EXPORT),
            $this->authorization->isAllowed(Acl::IMPORT)
        );
    }
}
