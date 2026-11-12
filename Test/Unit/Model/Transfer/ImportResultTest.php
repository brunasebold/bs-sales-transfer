<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Test\Unit\Model\Transfer;

use BS\SalesTransfer\Model\Transfer\ImportResult;
use PHPUnit\Framework\TestCase;

class ImportResultTest extends TestCase
{
    public function testCountsEveryFailureAndKeepsTheFirstMessages(): void
    {
        $result = new ImportResult();
        for ($i = 1; $i <= 40; $i++) {
            $result->addError('error ' . $i);
        }

        $this->assertSame(40, $result->failed);
        $this->assertCount(30, $result->errors);
        $this->assertSame('error 1', $result->errors[0]);
    }

    public function testWarningsDoNotIncreaseTheFailureCount(): void
    {
        $result = new ImportResult();
        $result->addWarning('grid');

        $this->assertSame(0, $result->failed);
        $this->assertSame(['grid'], $result->warnings);
    }
}
