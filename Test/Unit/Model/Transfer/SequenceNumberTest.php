<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Test\Unit\Model\Transfer;

use BS\SalesTransfer\Model\Transfer\SequenceNumber;
use PHPUnit\Framework\TestCase;

class SequenceNumberTest extends TestCase
{
    public function testReadsTheNumericCounterWithoutAPrefix(): void
    {
        $this->assertSame(123, SequenceNumber::fromIncrementId('000000123', '', ''));
    }

    public function testStripsTheStorePrefixAndSuffix(): void
    {
        $this->assertSame(123, SequenceNumber::fromIncrementId('100000123', '1', ''));
        $this->assertSame(123, SequenceNumber::fromIncrementId('000000123A', '', 'A'));
    }

    public function testReturnsZeroWhenTheRemainderIsNotNumeric(): void
    {
        $this->assertSame(0, SequenceNumber::fromIncrementId('ABC', null, null));
    }
}
