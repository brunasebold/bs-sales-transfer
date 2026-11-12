<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

final class ImportResult
{
    public int $imported = 0;

    public int $skipped = 0;

    public int $failed = 0;

    /**
     * @var string[]
     */
    public array $errors = [];

    /**
     * @var string[]
     */
    public array $warnings = [];

    public function addError(string $message): void
    {
        $this->failed++;
        if (count($this->errors) < 30) {
            $this->errors[] = $message;
        }
    }

    public function addWarning(string $message): void
    {
        if (count($this->warnings) < 10) {
            $this->warnings[] = $message;
        }
    }
}
