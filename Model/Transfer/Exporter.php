<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Writes orders, invoices, shipments and credit memos to a JSON Lines file.
 *
 * Rows are copied as stored. The file keeps the admin-scope values already saved
 * on the order, so store-view translation is not applied again.
 */
class Exporter
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    private AdapterInterface $sales;

    private AdapterInterface $default;

    public function __construct(
        ResourceConnection $resource,
        private readonly ProductMetadataInterface $productMetadata
    ) {
        $this->sales = $resource->getConnection('sales');
        $this->default = $resource->getConnection();
    }

    /**
     * @param callable(int, string): void|null $onOrder
     */
    public function export(string $absolutePath, ExportFilter $filter, ?callable $onOrder = null): int
    {
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create the directory %s.', $directory));
        }

        $handle = fopen($absolutePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not create the file %s.', $absolutePath));
        }

        $storeCodes = $this->storeCodes();
        $count = 0;
        try {
            $this->write($handle, [
                'format' => SalesDocument::FORMAT,
                'version' => SalesDocument::VERSION,
                'exported_at' => gmdate('c'),
                'magento' => $this->productMetadata->getVersion(),
            ]);

            $lastId = 0;
            while (true) {
                $rows = $this->sales->fetchAll($this->orderSelect($filter, $lastId));
                if (!$rows) {
                    break;
                }
                foreach ($rows as $order) {
                    $lastId = (int) $order['entity_id'];
                    $payload = $this->payload($order, $storeCodes);
                    $this->write($handle, $payload);
                    $count++;
                    if ($onOrder) {
                        $onOrder($count, (string) ($order['increment_id'] ?? ''));
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $data
     */
    private function write($handle, array $data): void
    {
        $written = fwrite($handle, json_encode($data, self::JSON_FLAGS) . "\n");
        if ($written === false) {
            throw new \RuntimeException('Could not write the export file.');
        }
    }

    private function orderSelect(ExportFilter $filter, int $lastId): \Magento\Framework\DB\Select
    {
        $select = $this->sales->select()
            ->from($this->table('sales_order'))
            ->where('entity_id > ?', $lastId)
            ->order('entity_id ASC')
            ->limit(100);

        if ($filter->storeIds) {
            $select->where('store_id IN (?)', $filter->storeIds);
        }
        if ($filter->createdFrom) {
            $select->where('created_at >= ?', $filter->createdFrom);
        }
        if ($filter->createdTo) {
            $select->where('created_at <= ?', $filter->createdTo);
        }

        return $select;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, string> $storeCodes
     * @return array<string, mixed>
     */
    private function payload(array $order, array $storeCodes): array
    {
        $orderId = (int) $order['entity_id'];
        $storeId = (int) ($order['store_id'] ?? 0);
        $items = $this->fetch('sales_order_item', 'order_id', $orderId);

        return [
            'store_code' => $storeCodes[$storeId] ?? null,
            'order' => $this->encode($order),
            'items' => $this->encodeRows($items),
            'addresses' => $this->encodeRows($this->fetch('sales_order_address', 'parent_id', $orderId)),
            'payments' => $this->encodeRows($this->fetch('sales_order_payment', 'parent_id', $orderId)),
            'status_histories' => $this->encodeRows($this->fetch('sales_order_status_history', 'parent_id', $orderId)),
            'status_changes' => $this->encodeRows(
                $this->fetch('sales_order_status_change_history', 'order_id', $orderId)
            ),
            'taxes' => $this->taxes($orderId),
            'invoices' => $this->documents('sales_invoice', 'sales_invoice_item', 'sales_invoice_comment', $orderId),
            'shipments' => $this->shipments($orderId),
            'creditmemos' => $this->documents(
                'sales_creditmemo',
                'sales_creditmemo_item',
                'sales_creditmemo_comment',
                $orderId
            ),
            'transactions' => $this->encodeRows($this->fetch('sales_payment_transaction', 'order_id', $orderId)),
            'gift_messages' => $this->encodeRows($this->giftMessages($order, $items)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taxes(int $orderId): array
    {
        $taxes = [];
        foreach ($this->fetch('sales_order_tax', 'order_id', $orderId) as $tax) {
            $taxes[] = [
                'row' => $this->encode($tax),
                'items' => $this->encodeRows($this->fetch('sales_order_tax_item', 'tax_id', (int) $tax['tax_id'])),
            ];
        }

        return $taxes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(string $header, string $items, string $comments, int $orderId): array
    {
        $documents = [];
        foreach ($this->fetch($header, 'order_id', $orderId) as $row) {
            $parentId = (int) $row['entity_id'];
            $documents[] = [
                'row' => $this->encode($row),
                'items' => $this->encodeRows($this->fetch($items, 'parent_id', $parentId)),
                'comments' => $this->encodeRows($this->fetch($comments, 'parent_id', $parentId)),
            ];
        }

        return $documents;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shipments(int $orderId): array
    {
        $documents = [];
        foreach ($this->fetch('sales_shipment', 'order_id', $orderId) as $row) {
            $parentId = (int) $row['entity_id'];
            $documents[] = [
                'row' => $this->encode($row),
                'items' => $this->encodeRows($this->fetch('sales_shipment_item', 'parent_id', $parentId)),
                'tracks' => $this->encodeRows($this->fetch('sales_shipment_track', 'parent_id', $parentId)),
                'comments' => $this->encodeRows($this->fetch('sales_shipment_comment', 'parent_id', $parentId)),
            ];
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function giftMessages(array $order, array $items): array
    {
        $table = $this->table('gift_message');
        if (!$this->sales->isTableExists($table)) {
            return [];
        }

        $ids = [];
        if (!empty($order['gift_message_id'])) {
            $ids[] = (int) $order['gift_message_id'];
        }
        foreach ($items as $item) {
            if (!empty($item['gift_message_id'])) {
                $ids[] = (int) $item['gift_message_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }

        return $this->sales->fetchAll(
            $this->sales->select()->from($table)->where('gift_message_id IN (?)', $ids)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $table, string $column, int $value): array
    {
        $tableName = $this->table($table);
        if (!$this->sales->isTableExists($tableName)) {
            return [];
        }

        return $this->sales->fetchAll(
            $this->sales->select()->from($tableName)->where($column . ' = ?', $value)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function encode(array $row): array
    {
        return ValueCodec::encodeRow($row);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function encodeRows(array $rows): array
    {
        return array_map([$this, 'encode'], $rows);
    }

    /**
     * @return array<int, string>
     */
    private function storeCodes(): array
    {
        $pairs = $this->default->fetchPairs(
            $this->default->select()->from($this->default->getTableName('store'), ['store_id', 'code'])
        );
        $codes = [];
        foreach ($pairs as $storeId => $code) {
            $codes[(int) $storeId] = (string) $code;
        }

        return $codes;
    }

    private function table(string $name): string
    {
        return $this->sales->getTableName($name);
    }
}
