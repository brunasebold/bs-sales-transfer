<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Moves the sales sequence forward so the next real order does not reuse an imported number.
 */
class SequenceSynchronizer
{
    /**
     * @var array<string, int>
     */
    private array $maxValues = [];

    /**
     * @var array<string, array{table: string, prefix: string, suffix: string}>|null
     */
    private ?array $profiles = null;

    public function __construct(private readonly AdapterInterface $connection)
    {
    }

    public function track(string $entityType, int $storeId, ?string $incrementId): void
    {
        if ($storeId <= 0 || $incrementId === null || $incrementId === '') {
            return;
        }
        $key = $entityType . ':' . $storeId;
        $this->maxValues[$key] = max($this->maxValues[$key] ?? 0, $this->numericValue($entityType, $storeId, $incrementId));
    }

    public function sync(): void
    {
        if (!$this->maxValues) {
            return;
        }

        $profiles = $this->profiles();
        foreach ($this->maxValues as $key => $value) {
            if ($value < 1 || !isset($profiles[$key])) {
                continue;
            }
            $this->raise($profiles[$key], $value + 1);
        }
        $this->maxValues = [];
    }

    private function numericValue(string $entityType, int $storeId, string $incrementId): int
    {
        $profiles = $this->profiles();
        $key = $entityType . ':' . $storeId;
        $prefix = $profiles[$key]['prefix'] ?? '';
        $suffix = $profiles[$key]['suffix'] ?? '';

        return SequenceNumber::fromIncrementId($incrementId, $prefix, $suffix);
    }

    /**
     * @return array<string, array{table: string, prefix: string, suffix: string}>
     */
    private function profiles(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        $profiles = [];
        $meta = $this->connection->getTableName('sales_sequence_meta');
        $profile = $this->connection->getTableName('sales_sequence_profile');
        if (!$this->connection->isTableExists($meta) || !$this->connection->isTableExists($profile)) {
            return $this->profiles = $profiles;
        }

        $select = $this->connection->select()
            ->from(['m' => $meta], ['entity_type', 'store_id', 'sequence_table'])
            ->join(['p' => $profile], 'p.meta_id = m.meta_id', ['prefix', 'suffix'])
            ->where('p.is_active = ?', 1);

        foreach ($this->connection->fetchAll($select) as $row) {
            $key = $row['entity_type'] . ':' . (int) $row['store_id'];
            $profiles[$key] = [
                'table' => (string) $row['sequence_table'],
                'prefix' => (string) ($row['prefix'] ?? ''),
                'suffix' => (string) ($row['suffix'] ?? ''),
            ];
        }

        return $this->profiles = $profiles;
    }

    private function raise(array $profile, int $nextValue): void
    {
        $table = $this->sequenceTable($profile['table']);
        if ($table === null) {
            return;
        }

        $current = $this->connection->fetchOne(
            'SELECT `AUTO_INCREMENT` FROM `information_schema`.`TABLES`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
            [$table]
        );
        if ($current !== null && $current !== false && (int) $current >= $nextValue) {
            return;
        }

        $this->connection->query(sprintf(
            'ALTER TABLE %s AUTO_INCREMENT = %d',
            $this->connection->quoteIdentifier($table),
            $nextValue
        ));
    }

    private function sequenceTable(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return null;
        }
        if ($this->connection->isTableExists($name)) {
            return $name;
        }
        $prefixed = $this->connection->getTableName($name);
        if ($prefixed !== $name && $this->connection->isTableExists($prefixed)) {
            return $prefixed;
        }

        return null;
    }
}
