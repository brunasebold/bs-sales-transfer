<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

use BS\SalesTransfer\Model\Validation\DocumentHeader;

/**
 * Reads the JSON Lines file produced by the exporter.
 */
class JsonlReader
{
    public const ERROR_UNREADABLE = 'unreadable';

    public const ERROR_EMPTY = 'empty';

    public const ERROR_HEADER_JSON = 'header_json';

    public const ERROR_HEADER_FORMAT = 'header_format';

    public const LINE_INVALID_JSON = 'invalid_json';

    public const LINE_INVALID_RECORD = 'invalid_record';

    /**
     * @return \Generator<int, JsonlRecord>
     */
    public function read(string $absolutePath): \Generator
    {
        if (!is_file($absolutePath)) {
            throw new \InvalidArgumentException(self::ERROR_UNREADABLE);
        }
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException(self::ERROR_UNREADABLE);
        }

        $expectHeader = true;
        $lineNumber = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if ($lineNumber === 1) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                }
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                try {
                    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    if ($expectHeader) {
                        throw new \InvalidArgumentException(self::ERROR_HEADER_JSON);
                    }
                    yield new JsonlRecord($lineNumber, null, self::LINE_INVALID_JSON);
                    continue;
                }

                if (!is_array($data)) {
                    if ($expectHeader) {
                        throw new \InvalidArgumentException(self::ERROR_HEADER_JSON);
                    }
                    yield new JsonlRecord($lineNumber, null, self::LINE_INVALID_RECORD);
                    continue;
                }

                if ($expectHeader) {
                    if (!DocumentHeader::isValid($data)) {
                        throw new \InvalidArgumentException(self::ERROR_HEADER_FORMAT);
                    }
                    $expectHeader = false;
                    continue;
                }

                yield new JsonlRecord($lineNumber, $data);
            }
        } finally {
            fclose($handle);
        }

        if ($expectHeader) {
            throw new \InvalidArgumentException(self::ERROR_EMPTY);
        }
    }

    public function countRecords(string $absolutePath): int
    {
        if (!is_file($absolutePath)) {
            throw new \InvalidArgumentException(self::ERROR_UNREADABLE);
        }
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException(self::ERROR_UNREADABLE);
        }

        $count = 0;
        $headerSeen = false;
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                if (!$headerSeen) {
                    $headerSeen = true;
                    continue;
                }
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }
}
