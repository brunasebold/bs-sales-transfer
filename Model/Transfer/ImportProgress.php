<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * Remembers how far an admin import has got, so the page can ask while it runs.
 */
class ImportProgress
{
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public static function isValidToken(string $token): bool
    {
        return (bool) preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',
            $token
        );
    }

    /**
     * @param array{status: string, processed: int, total: int, label: string} $state
     */
    public function write(string $token, array $state): void
    {
        $path = $this->path($token);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * @return array{status: string, processed: int, total: int, label: string}|null
     */
    public function read(string $token): ?array
    {
        $path = $this->path($token);
        if (!is_file($path)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        return [
            'status' => (string) ($data['status'] ?? 'running'),
            'processed' => (int) ($data['processed'] ?? 0),
            'total' => (int) ($data['total'] ?? 0),
            'label' => (string) ($data['label'] ?? ''),
        ];
    }

    public function delete(string $token): void
    {
        $path = $this->path($token);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(string $token): string
    {
        $name = str_replace('-', '', $token) . '.json';

        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getAbsolutePath(
            'import/bs-sales-progress/' . $name
        );
    }
}
