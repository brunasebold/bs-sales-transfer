<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

/**
 * One non-header line from a sales transfer file.
 */
final class JsonlRecord
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public readonly int $lineNumber,
        public readonly ?array $payload = null,
        public readonly ?string $error = null
    ) {
    }
}
