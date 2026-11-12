<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Test\Unit\Model\Authorization;

use BS\SalesTransfer\Model\Authorization\Access;
use PHPUnit\Framework\TestCase;

class AccessTest extends TestCase
{
    public function testParentResourceGrantsTheWholeScreen(): void
    {
        $this->assertTrue(Access::canOpen(true, false, false));
        $this->assertTrue(Access::canExport(true, false));
        $this->assertTrue(Access::canImport(true, false));
    }

    public function testExportAndImportAreIndependent(): void
    {
        $this->assertTrue(Access::canOpen(false, true, false));
        $this->assertTrue(Access::canExport(false, true));
        $this->assertFalse(Access::canImport(false, false));
        $this->assertFalse(Access::canOpen(false, false, false));
    }
}
