<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Test\Unit\Model\Transfer;

use BS\SalesTransfer\Model\Transfer\JsonlReader;
use BS\SalesTransfer\Model\Transfer\SalesDocument;
use PHPUnit\Framework\TestCase;

class JsonlReaderTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testReadsAHeaderAndOrderRecords(): void
    {
        $this->write($this->header() . "\n" . '{"order":{"increment_id":"100"}}' . "\n");
        $records = iterator_to_array((new JsonlReader())->read($this->path));

        $this->assertCount(1, $records);
        $this->assertSame('100', $records[0]->payload['order']['increment_id']);
        $this->assertNull($records[0]->error);
    }

    public function testAcceptsAByteOrderMark(): void
    {
        $this->write("\xEF\xBB\xBF" . $this->header() . "\n");
        $this->assertSame([], iterator_to_array((new JsonlReader())->read($this->path)));
    }

    public function testRejectsAnEmptyFile(): void
    {
        $this->write(" \n");
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(JsonlReader::ERROR_EMPTY);
        iterator_to_array((new JsonlReader())->read($this->path));
    }

    public function testRejectsAForeignFile(): void
    {
        $this->write('{"format":"other","version":1}' . "\n");
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(JsonlReader::ERROR_HEADER_FORMAT);
        iterator_to_array((new JsonlReader())->read($this->path));
    }

    public function testReportsABrokenOrderLineAndKeepsReading(): void
    {
        $this->write($this->header() . "\n{broken\n" . '{"order":{"increment_id":"2"}}' . "\n");
        $records = iterator_to_array((new JsonlReader())->read($this->path));

        $this->assertSame(JsonlReader::LINE_INVALID_JSON, $records[0]->error);
        $this->assertSame(2, $records[0]->lineNumber);
        $this->assertSame('2', $records[1]->payload['order']['increment_id']);
    }

    public function testReportsALineThatIsNotAnOrder(): void
    {
        $this->write($this->header() . "\n1\n");
        $records = iterator_to_array((new JsonlReader())->read($this->path));

        $this->assertSame(JsonlReader::LINE_INVALID_RECORD, $records[0]->error);
    }

    public function testCountsTheOrdersAfterTheHeader(): void
    {
        $this->write($this->header() . "\n\n" . '{"order":{"increment_id":"1"}}' . "\n" . '{"order":{"increment_id":"2"}}' . "\n");

        $this->assertSame(2, (new JsonlReader())->countRecords($this->path));
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(JsonlReader::ERROR_UNREADABLE);
        iterator_to_array((new JsonlReader())->read(sys_get_temp_dir() . '/bs-sales-missing-' . uniqid('', true)));
    }

    private function header(): string
    {
        return json_encode([
            'format' => SalesDocument::FORMAT,
            'version' => SalesDocument::VERSION,
        ]);
    }

    private function write(string $contents): void
    {
        $this->path = $this->path();
        file_put_contents($this->path, $contents);
    }

    private function path(): string
    {
        return tempnam(sys_get_temp_dir(), 'bs-sales-');
    }
}
