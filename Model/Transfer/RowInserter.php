<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Inserts a source row into a destination table, keeping only columns that exist there.
 */
class RowInserter
{
    /**
     * @var array<string, string[]>
     */
    private array $columns = [];

    public function __construct(private readonly AdapterInterface $connection)
    {
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @param array<string, mixed> $overrides
     */
    public function insert(string $table, array $sourceRow, array $overrides = []): int
    {
        $table = $this->connection->getTableName($table);
        $sourceRow = ValueCodec::decodeRow($sourceRow);
        $data = [];
        foreach ($this->writableColumns($table) as $column) {
            if (array_key_exists($column, $overrides)) {
                $data[$column] = $overrides[$column];
            } elseif (array_key_exists($column, $sourceRow)) {
                $data[$column] = $sourceRow[$column];
            }
        }

        $this->connection->insert($table, $data);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return string[]
     */
    private function writableColumns(string $table): array
    {
        if (!isset($this->columns[$table])) {
            $names = [];
            foreach ($this->connection->describeTable($table) as $name => $meta) {
                if (!empty($meta['IDENTITY'])) {
                    continue;
                }
                $names[] = (string) $name;
            }
            $this->columns[$table] = $names;
        }

        return $this->columns[$table];
    }
}
