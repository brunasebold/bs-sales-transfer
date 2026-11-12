<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Test\Unit\Model\Validation;

use BS\SalesTransfer\Model\Transfer\SalesDocument;
use BS\SalesTransfer\Model\Validation\DateRange;
use BS\SalesTransfer\Model\Validation\DocumentHeader;
use BS\SalesTransfer\Model\Validation\ImportBehavior;
use BS\SalesTransfer\Model\Validation\IncrementId;
use BS\SalesTransfer\Model\Validation\StoreCode;
use BS\SalesTransfer\Model\Validation\UploadLimit;
use PHPUnit\Framework\TestCase;

class RulesTest extends TestCase
{
    public function testImportBehaviorAcceptsOnlyTheTwoSupportedModes(): void
    {
        $this->assertTrue(ImportBehavior::isValid(SalesDocument::BEHAVIOR_SKIP));
        $this->assertTrue(ImportBehavior::isValid(SalesDocument::BEHAVIOR_REPLACE));
        $this->assertFalse(ImportBehavior::isValid('delete'));
        $this->assertFalse(ImportBehavior::isValid(''));
    }

    public function testDocumentHeaderRequiresFormatAndVersion(): void
    {
        $this->assertTrue(DocumentHeader::isValid([
            'format' => SalesDocument::FORMAT,
            'version' => SalesDocument::VERSION,
        ]));
        $this->assertFalse(DocumentHeader::isValid([
            'format' => SalesDocument::FORMAT,
            'version' => 2,
        ]));
        $this->assertFalse(DocumentHeader::isValid([]));
    }

    public function testDateRangeRejectsImpossibleDaysAndReversedBounds(): void
    {
        $this->assertTrue(DateRange::isIsoDate('2024-02-29'));
        $this->assertFalse(DateRange::isIsoDate('2023-02-29'));
        $this->assertFalse(DateRange::isIsoDate('12/01/2024'));
        $this->assertTrue(DateRange::isOrdered('2024-01-01', '2024-01-01'));
        $this->assertFalse(DateRange::isOrdered('2024-02-01', '2024-01-01'));
        $this->assertTrue(DateRange::isOrdered(null, '2024-01-01'));
    }

    public function testStoreCodeAndIncrementIdStayInsideTheDatabaseLimits(): void
    {
        $this->assertTrue(StoreCode::isValid('default'));
        $this->assertFalse(StoreCode::isValid('default-store'));
        $this->assertFalse(StoreCode::isValid(''));

        $this->assertTrue(IncrementId::isValid('100000123'));
        $this->assertFalse(IncrementId::isValid(str_repeat('1', 51)));
        $this->assertFalse(IncrementId::isValid("100\n000"));
        $this->assertFalse(IncrementId::isValid(''));
    }

    public function testUploadLimitRejectsEmptyAndOversizedFiles(): void
    {
        $this->assertFalse(UploadLimit::isAllowedSize(0));
        $this->assertTrue(UploadLimit::isAllowedSize(1024));
        $this->assertTrue(UploadLimit::isAllowedSize(UploadLimit::MAX_BYTES));
        $this->assertFalse(UploadLimit::isAllowedSize(UploadLimit::MAX_BYTES + 1));
    }
}
