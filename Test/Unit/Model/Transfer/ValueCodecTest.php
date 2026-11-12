<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Test\Unit\Model\Transfer;

use BS\SalesTransfer\Model\Transfer\ValueCodec;
use PHPUnit\Framework\TestCase;

class ValueCodecTest extends TestCase
{
    public function testLeavesUtf8TextUntouched(): void
    {
        $this->assertSame('Azul', ValueCodec::encode('Azul'));
        $this->assertSame('Azul', ValueCodec::decode('Azul'));
    }

    public function testRoundTripsBinaryColumnValues(): void
    {
        $binary = "token\xFF\xFE";
        $encoded = ValueCodec::encodeRow(['additional_information' => $binary, 'method' => 'pix']);

        $this->assertSame($binary, ValueCodec::decodeRow($encoded)['additional_information']);
        $this->assertSame('pix', $encoded['method']);
    }

    public function testIgnoresArraysThatAreNotABinaryWrapper(): void
    {
        $this->assertSame(['a' => 1], ValueCodec::decode(['a' => 1]));
    }
}
