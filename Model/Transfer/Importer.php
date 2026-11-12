<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Model\Transfer;

use BS\SalesTransfer\Model\Validation\ImportBehavior;
use BS\SalesTransfer\Model\Validation\IncrementId;
use BS\SalesTransfer\Model\Validation\StoreCode;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads a sales export into this Magento.
 *
 * Rows are inserted with SQL on purpose. Saving through the order models would
 * send e-mail, talk to the payment gateway and change stock.
 */
class Importer
{
    private AdapterInterface $sales;

    private AdapterInterface $default;

    private RowInserter $rows;

    private SequenceSynchronizer $sequences;

    /**
     * @var array<string, array{store_id: int, website_id: int}>|null
     */
    private ?array $storesByCode = null;

    /**
     * @var array<string, int|null>
     */
    private array $customerCache = [];

    /**
     * @var array<string, int|null>
     */
    private array $productCache = [];

    /**
     * @var array<int, bool>
     */
    private array $groupCache = [];

    /**
     * @var array<int, int>
     */
    private array $orderIdMap = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $relations = [];

    private readonly JsonlReader $reader;

    public function __construct(
        ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly GridRefresher $grids,
        private readonly LoggerInterface $logger,
        ?JsonlReader $reader = null
    ) {
        $this->sales = $resource->getConnection('sales');
        $this->default = $resource->getConnection();
        $this->rows = new RowInserter($this->sales);
        $this->sequences = new SequenceSynchronizer($this->sales);
        $this->reader = $reader ?? new JsonlReader();
    }

    /**
     * @param callable|null $onOrder Reports each order as it is saved, skipped or rejected.
     */
    public function import(
        string $absolutePath,
        string $behavior = SalesDocument::BEHAVIOR_SKIP,
        ?callable $onOrder = null
    ): ImportResult {
        if (!ImportBehavior::isValid($behavior)) {
            throw new LocalizedException(__('Invalid import behavior.'));
        }

        $this->reset();
        $result = new ImportResult();

        try {
            foreach ($this->reader->read($absolutePath) as $record) {
                $label = 'line ' . $record->lineNumber;
                if ($record->error !== null || $record->payload === null) {
                    $message = $record->error === JsonlReader::LINE_INVALID_RECORD
                        ? __('Line %1: the record is not an order.', $record->lineNumber)
                        : __('Line %1: invalid JSON.', $record->lineNumber);
                    $result->addError((string) $message);
                } else {
                    $order = $record->payload['order'] ?? null;
                    $incrementId = is_array($order) ? (string) ($order['increment_id'] ?? '') : '';
                    if (IncrementId::isValid($incrementId)) {
                        $label = $incrementId;
                    }
                    $this->importPayload($record->payload, $behavior, $result, $record->lineNumber);
                }
                if ($onOrder !== null) {
                    $onOrder($result, $label);
                }
            }
        } catch (\InvalidArgumentException $exception) {
            throw new LocalizedException($this->fileError($exception->getMessage()));
        }

        try {
            $this->linkRelations();
            $this->sequences->sync();
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Orders were imported, but the sequence or the link between edited orders was not updated: '
                . $exception->getMessage(),
                ['exception' => $exception]
            );
            $result->addWarning((string) __(
                'Orders were imported, but the sequence or the link between edited orders was not updated.'
            ));
        }

        return $result;
    }

    public function count(string $absolutePath): int
    {
        try {
            return $this->reader->countRecords($absolutePath);
        } catch (\InvalidArgumentException $exception) {
            throw new LocalizedException($this->fileError($exception->getMessage()));
        }
    }

    private function fileError(string $code): Phrase
    {
        return match ($code) {
            JsonlReader::ERROR_UNREADABLE => __('The import file could not be read.'),
            JsonlReader::ERROR_EMPTY => __('The file is empty.'),
            JsonlReader::ERROR_HEADER_JSON => __('The first line of the file is not valid JSON.'),
            default => __('This file is not a sales export from this module.'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function importPayload(array $payload, string $behavior, ImportResult $result, int $lineNumber): void
    {
        $order = $payload['order'] ?? null;
        $incrementId = is_array($order) ? (string) ($order['increment_id'] ?? '') : '';
        $label = IncrementId::isValid($incrementId) ? $incrementId : ('line ' . $lineNumber);

        try {
            if (!is_array($order) || !IncrementId::isValid($incrementId)) {
                throw new LocalizedException(__('The order number is missing or invalid.'));
            }
            $storeCode = (string) ($payload['store_code'] ?? '');
            if (!StoreCode::isValid($storeCode)) {
                throw new LocalizedException(__('The store code is missing or invalid.'));
            }
            $store = $this->storesByCode()[$storeCode] ?? null;
            if ($store === null) {
                throw new LocalizedException(__('Store "%1" does not exist on this Magento.', $storeCode));
            }

            $existingId = $this->existingOrderId($incrementId, $store['store_id']);
            if ($existingId && $behavior === SalesDocument::BEHAVIOR_SKIP) {
                $result->skipped++;
                return;
            }

            $this->sales->beginTransaction();
            try {
                if ($existingId && $behavior === SalesDocument::BEHAVIOR_REPLACE) {
                    $this->deleteOrder($existingId);
                }
                $created = $this->insertOrder($payload, $order, $store['store_id'], $store['website_id']);
                $this->sales->commit();
            } catch (\Throwable $exception) {
                try {
                    $this->sales->rollBack();
                } catch (\Throwable $rollbackException) {
                    $this->logger->error(
                        'Failed to roll back the import of order ' . $label . ': ' . $rollbackException->getMessage()
                    );
                }
                throw $exception;
            }

            $this->refreshGrids($created, $result);
            $result->imported++;
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('Failed to import order %s: %s', $label, $exception->getMessage()),
                ['exception' => $exception]
            );
            $result->addError(sprintf('%s: %s', $label, $exception->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $order
     * @return array{order: int, invoices: int[], shipments: int[], creditmemos: int[]}
     */
    private function insertOrder(array $payload, array $order, int $storeId, int $websiteId): array
    {
        $this->ensureStatus(
            isset($order['status']) ? (string) $order['status'] : null,
            isset($order['state']) ? (string) $order['state'] : null
        );

        $customerId = null;
        if (!empty($order['customer_id'])) {
            $customerId = $this->customerId((string) ($order['customer_email'] ?? ''), $websiteId);
        }
        $giftMap = $this->importGiftMessages($this->listOf($payload, 'gift_messages'));
        $giftId = $this->mappedId($giftMap, $order['gift_message_id'] ?? null);

        $newOrderId = $this->rows->insert('sales_order', $order, [
            'store_id' => $storeId,
            'store_name' => $this->storeName($storeId),
            'customer_id' => $customerId,
            'customer_group_id' => $this->groupId($order['customer_group_id'] ?? 0, $customerId),
            'quote_id' => null,
            'billing_address_id' => null,
            'shipping_address_id' => null,
            'gift_message_id' => $giftId,
            'relation_parent_id' => null,
            'relation_parent_real_id' => null,
            'relation_child_id' => null,
            'relation_child_real_id' => null,
        ]);

        $sourceOrderId = (int) ($order['entity_id'] ?? 0);
        if ($sourceOrderId > 0) {
            $this->orderIdMap[$sourceOrderId] = $newOrderId;
        }
        $this->relations[] = [
            'id' => $newOrderId,
            'relation_parent_id' => $order['relation_parent_id'] ?? null,
            'relation_parent_real_id' => $order['relation_parent_real_id'] ?? null,
            'relation_child_id' => $order['relation_child_id'] ?? null,
            'relation_child_real_id' => $order['relation_child_real_id'] ?? null,
        ];
        $this->sequences->track('order', $storeId, (string) $order['increment_id']);

        $addressMap = $this->importAddresses($this->listOf($payload, 'addresses'), $newOrderId, $customerId);
        $this->sales->update($this->table('sales_order'), [
            'billing_address_id' => $this->mappedId($addressMap, $order['billing_address_id'] ?? null),
            'shipping_address_id' => $this->mappedId($addressMap, $order['shipping_address_id'] ?? null),
        ], ['entity_id = ?' => $newOrderId]);

        $itemMap = $this->importItems($this->listOf($payload, 'items'), $newOrderId, $storeId, $giftMap);
        $paymentMap = $this->importPayments($this->listOf($payload, 'payments'), $newOrderId);
        $this->importFlat('sales_order_status_history', $this->listOf($payload, 'status_histories'), [
            'parent_id' => $newOrderId,
        ]);
        $this->importFlat('sales_order_status_change_history', $this->listOf($payload, 'status_changes'), [
            'order_id' => $newOrderId,
        ]);
        $this->importTaxes($this->listOf($payload, 'taxes'), $newOrderId, $itemMap);

        $invoices = $this->importDocuments(
            $this->listOf($payload, 'invoices'),
            'sales_invoice',
            'sales_invoice_item',
            'sales_invoice_comment',
            $newOrderId,
            $storeId,
            $customerId,
            $addressMap,
            $itemMap,
            'invoice'
        );
        $shipments = $this->importDocuments(
            $this->listOf($payload, 'shipments'),
            'sales_shipment',
            'sales_shipment_item',
            'sales_shipment_comment',
            $newOrderId,
            $storeId,
            $customerId,
            $addressMap,
            $itemMap,
            'shipment',
            'sales_shipment_track'
        );
        $creditmemos = $this->importDocuments(
            $this->listOf($payload, 'creditmemos'),
            'sales_creditmemo',
            'sales_creditmemo_item',
            'sales_creditmemo_comment',
            $newOrderId,
            $storeId,
            $customerId,
            $addressMap,
            $itemMap,
            'creditmemo'
        );
        $this->importTransactions($this->listOf($payload, 'transactions'), $newOrderId, $paymentMap);

        return [
            'order' => $newOrderId,
            'invoices' => $invoices,
            'shipments' => $shipments,
            'creditmemos' => $creditmemos,
        ];
    }

    /**
     * @param array{order: int, invoices: int[], shipments: int[], creditmemos: int[]} $created
     */
    private function refreshGrids(array $created, ImportResult $result): void
    {
        try {
            $this->grids->order($created['order']);
            foreach ($created['invoices'] as $id) {
                $this->grids->invoice($id);
            }
            foreach ($created['shipments'] as $id) {
                $this->grids->shipment($id);
            }
            foreach ($created['creditmemos'] as $id) {
                $this->grids->creditmemo($id);
            }
        } catch (\Throwable $exception) {
            $this->logger->error(
                'The order was imported, but the admin grid was not updated: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            $result->addWarning((string) __(
                'The order was saved, but the admin grid was not updated: %1',
                $exception->getMessage()
            ));
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function importAddresses(array $rows, int $orderId, ?int $customerId): array
    {
        $map = [];
        foreach ($rows as $row) {
            $oldId = (int) ($row['entity_id'] ?? 0);
            $newId = $this->rows->insert('sales_order_address', $row, [
                'parent_id' => $orderId,
                'customer_id' => $customerId,
                'customer_address_id' => null,
                'quote_address_id' => null,
            ]);
            if ($oldId > 0) {
                $map[$oldId] = $newId;
            }
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, int> $giftMap
     * @return array<int, int>
     */
    private function importItems(array $rows, int $orderId, int $storeId, array $giftMap): array
    {
        $map = [];
        $parents = [];
        foreach ($rows as $row) {
            $oldId = (int) ($row['item_id'] ?? 0);
            $newId = $this->rows->insert('sales_order_item', $row, [
                'order_id' => $orderId,
                'store_id' => $storeId,
                'parent_item_id' => null,
                'quote_item_id' => null,
                'product_id' => $this->productId(isset($row['sku']) ? (string) $row['sku'] : null),
                'gift_message_id' => $this->mappedId($giftMap, $row['gift_message_id'] ?? null),
            ]);
            if ($oldId > 0) {
                $map[$oldId] = $newId;
            }
            if (!empty($row['parent_item_id'])) {
                $parents[$newId] = (int) $row['parent_item_id'];
            }
        }
        foreach ($parents as $newId => $oldParentId) {
            $this->sales->update($this->table('sales_order_item'), [
                'parent_item_id' => $map[$oldParentId] ?? null,
            ], ['item_id = ?' => $newId]);
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function importPayments(array $rows, int $orderId): array
    {
        $map = [];
        foreach ($rows as $row) {
            $oldId = (int) ($row['entity_id'] ?? 0);
            $newId = $this->rows->insert('sales_order_payment', $row, [
                'parent_id' => $orderId,
                'quote_payment_id' => null,
            ]);
            if ($oldId > 0) {
                $map[$oldId] = $newId;
            }
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $overrides
     */
    private function importFlat(string $table, array $rows, array $overrides): void
    {
        if (!$this->sales->isTableExists($this->table($table))) {
            return;
        }
        foreach ($rows as $row) {
            $this->rows->insert($table, $row, $overrides);
        }
    }

    /**
     * @param list<array<string, mixed>> $taxes
     * @param array<int, int> $itemMap
     */
    private function importTaxes(array $taxes, int $orderId, array $itemMap): void
    {
        foreach ($taxes as $tax) {
            if (!isset($tax['row']) || !is_array($tax['row'])) {
                continue;
            }
            $taxId = $this->rows->insert('sales_order_tax', $tax['row'], ['order_id' => $orderId]);
            foreach ($tax['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->rows->insert('sales_order_tax_item', $item, [
                    'tax_id' => $taxId,
                    'item_id' => $this->mappedId($itemMap, $item['item_id'] ?? null),
                    'associated_item_id' => $this->mappedId($itemMap, $item['associated_item_id'] ?? null),
                ]);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @param array<int, int> $addressMap
     * @param array<int, int> $itemMap
     * @return int[]
     */
    private function importDocuments(
        array $documents,
        string $headerTable,
        string $itemTable,
        string $commentTable,
        int $orderId,
        int $storeId,
        ?int $customerId,
        array $addressMap,
        array $itemMap,
        string $sequenceEntity,
        ?string $trackTable = null
    ): array {
        $ids = [];
        foreach ($documents as $document) {
            if (!isset($document['row']) || !is_array($document['row'])) {
                continue;
            }
            $row = $document['row'];
            $newId = $this->rows->insert($headerTable, $row, [
                'order_id' => $orderId,
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'billing_address_id' => $this->mappedId($addressMap, $row['billing_address_id'] ?? null),
                'shipping_address_id' => $this->mappedId($addressMap, $row['shipping_address_id'] ?? null),
            ]);
            $ids[] = $newId;
            $this->sequences->track(
                $sequenceEntity,
                $storeId,
                isset($row['increment_id']) ? (string) $row['increment_id'] : null
            );
            foreach ($document['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->rows->insert($itemTable, $item, [
                    'parent_id' => $newId,
                    'order_item_id' => $this->mappedId($itemMap, $item['order_item_id'] ?? null),
                ]);
            }
            foreach ($document['comments'] ?? [] as $comment) {
                if (!is_array($comment)) {
                    continue;
                }
                $this->rows->insert($commentTable, $comment, ['parent_id' => $newId]);
            }
            if ($trackTable) {
                foreach ($document['tracks'] ?? [] as $track) {
                    if (!is_array($track)) {
                        continue;
                    }
                    $this->rows->insert($trackTable, $track, [
                        'parent_id' => $newId,
                        'order_id' => $orderId,
                    ]);
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $transactions
     * @param array<int, int> $paymentMap
     */
    private function importTransactions(array $transactions, int $orderId, array $paymentMap): void
    {
        if (!$this->sales->isTableExists($this->table('sales_payment_transaction'))) {
            return;
        }
        $pending = array_values(array_filter($transactions, 'is_array'));
        $map = [];
        for ($guard = 0; $pending && $guard < 10; $guard++) {
            $next = [];
            foreach ($pending as $transaction) {
                $oldParent = (int) ($transaction['parent_id'] ?? 0);
                if ($oldParent > 0 && !isset($map[$oldParent])) {
                    $next[] = $transaction;
                    continue;
                }
                $this->insertTransaction($transaction, $orderId, $paymentMap, $map, $oldParent);
            }
            if (count($next) === count($pending)) {
                foreach ($next as $transaction) {
                    $this->insertTransaction($transaction, $orderId, $paymentMap, $map, 0);
                }
                break;
            }
            $pending = $next;
        }
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array<int, int> $paymentMap
     * @param array<int, int> $map
     */
    private function insertTransaction(
        array $transaction,
        int $orderId,
        array $paymentMap,
        array &$map,
        int $oldParent
    ): void {
        $paymentId = $paymentMap[(int) ($transaction['payment_id'] ?? 0)] ?? null;
        if (!$paymentId) {
            return;
        }
        $oldId = (int) ($transaction['transaction_id'] ?? 0);
        $newId = $this->rows->insert('sales_payment_transaction', $transaction, [
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'parent_id' => $oldParent > 0 ? ($map[$oldParent] ?? null) : null,
        ]);
        if ($oldId > 0) {
            $map[$oldId] = $newId;
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<int, int>
     */
    private function importGiftMessages(array $messages): array
    {
        $map = [];
        if (!$messages || !$this->sales->isTableExists($this->table('gift_message'))) {
            return $map;
        }
        foreach ($messages as $message) {
            $oldId = (int) ($message['gift_message_id'] ?? 0);
            if ($oldId < 1) {
                continue;
            }
            $map[$oldId] = $this->rows->insert('gift_message', $message, ['customer_id' => 0]);
        }

        return $map;
    }

    private function deleteOrder(int $orderId): void
    {
        $this->deleteGrid('sales_invoice_grid', $this->ids('sales_invoice', $orderId));
        $this->deleteGrid('sales_shipment_grid', $this->ids('sales_shipment', $orderId));
        $this->deleteGrid('sales_creditmemo_grid', $this->ids('sales_creditmemo', $orderId));
        $this->deleteGrid('sales_order_grid', [$orderId]);

        $taxTable = $this->table('sales_order_tax');
        if ($this->sales->isTableExists($taxTable)) {
            $taxIds = $this->sales->fetchCol(
                $this->sales->select()->from($taxTable, 'tax_id')->where('order_id = ?', $orderId)
            );
            if ($taxIds) {
                $this->sales->delete($this->table('sales_order_tax_item'), ['tax_id IN (?)' => $taxIds]);
                $this->sales->delete($taxTable, ['order_id = ?' => $orderId]);
            }
        }
        $this->sales->delete($this->table('sales_order'), ['entity_id = ?' => $orderId]);
    }

    /**
     * @return int[]
     */
    private function ids(string $table, int $orderId): array
    {
        $tableName = $this->table($table);
        if (!$this->sales->isTableExists($tableName)) {
            return [];
        }

        return array_map('intval', $this->sales->fetchCol(
            $this->sales->select()->from($tableName, 'entity_id')->where('order_id = ?', $orderId)
        ));
    }

    /**
     * @param int[] $ids
     */
    private function deleteGrid(string $table, array $ids): void
    {
        $ids = array_values(array_filter($ids));
        $tableName = $this->table($table);
        if (!$ids || !$this->sales->isTableExists($tableName)) {
            return;
        }
        $this->sales->delete($tableName, ['entity_id IN (?)' => $ids]);
    }

    private function existingOrderId(string $incrementId, int $storeId): ?int
    {
        $id = $this->sales->fetchOne(
            $this->sales->select()
                ->from($this->table('sales_order'), 'entity_id')
                ->where('increment_id = ?', $incrementId)
                ->where('store_id = ?', $storeId)
                ->limit(1)
        );

        return $id ? (int) $id : null;
    }

    private function ensureStatus(?string $status, ?string $state): void
    {
        $status = trim((string) $status);
        if ($status === '') {
            return;
        }
        $statusTable = $this->table('sales_order_status');
        $exists = $this->sales->fetchOne(
            $this->sales->select()->from($statusTable, 'status')->where('status = ?', $status)->limit(1)
        );
        if ($exists) {
            return;
        }
        $this->sales->insert($statusTable, ['status' => $status, 'label' => $status]);
        $state = trim((string) $state);
        $this->sales->insertOnDuplicate(
            $this->table('sales_order_status_state'),
            [
                'status' => $status,
                'state' => $state !== '' ? $state : 'processing',
                'is_default' => 0,
                'visible_on_front' => 1,
            ],
            ['is_default', 'visible_on_front']
        );
    }

    private function linkRelations(): void
    {
        $columns = [
            'relation_parent_id',
            'relation_parent_real_id',
            'relation_child_id',
            'relation_child_real_id',
        ];
        foreach ($this->relations as $relation) {
            $update = [];
            foreach ($columns as $column) {
                $source = $relation[$column] ?? null;
                if ($source === null || $source === '') {
                    continue;
                }
                $mapped = $this->orderIdMap[(int) $source] ?? null;
                if ($mapped) {
                    $update[$column] = (string) $mapped;
                }
            }
            if ($update) {
                $this->sales->update(
                    $this->table('sales_order'),
                    $update,
                    ['entity_id = ?' => $relation['id']]
                );
            }
        }
    }

    private function customerId(string $email, int $websiteId): ?int
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $key = $websiteId . '|' . mb_strtolower($email);
        if (!array_key_exists($key, $this->customerCache)) {
            $id = $this->default->fetchOne(
                $this->default->select()
                    ->from($this->default->getTableName('customer_entity'), 'entity_id')
                    ->where('email = ?', $email)
                    ->where('website_id = ?', $websiteId)
                    ->limit(1)
            );
            $this->customerCache[$key] = $id ? (int) $id : null;
        }

        return $this->customerCache[$key];
    }

    private function productId(?string $sku): ?int
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }
        if (!array_key_exists($sku, $this->productCache)) {
            $id = $this->default->fetchOne(
                $this->default->select()
                    ->from($this->default->getTableName('catalog_product_entity'), 'entity_id')
                    ->where('sku = ?', $sku)
                    ->limit(1)
            );
            $this->productCache[$sku] = $id ? (int) $id : null;
        }

        return $this->productCache[$sku];
    }

    private function groupId(mixed $groupId, ?int $customerId): int
    {
        $groupId = (int) $groupId;
        if ($this->groupExists($groupId)) {
            return $groupId;
        }
        if ($customerId) {
            $customerGroup = $this->default->fetchOne(
                $this->default->select()
                    ->from($this->default->getTableName('customer_entity'), 'group_id')
                    ->where('entity_id = ?', $customerId)
                    ->limit(1)
            );
            if ($customerGroup !== false && $this->groupExists((int) $customerGroup)) {
                return (int) $customerGroup;
            }
        }

        return 0;
    }

    private function groupExists(int $groupId): bool
    {
        if (!array_key_exists($groupId, $this->groupCache)) {
            $id = $this->default->fetchOne(
                $this->default->select()
                    ->from($this->default->getTableName('customer_group'), 'customer_group_id')
                    ->where('customer_group_id = ?', $groupId)
                    ->limit(1)
            );
            $this->groupCache[$groupId] = $id !== false && $id !== null;
        }

        return $this->groupCache[$groupId];
    }

    private function storeName(int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);

        return implode("\n", [
            (string) $store->getWebsite()->getName(),
            (string) $store->getGroup()->getName(),
            (string) $store->getName(),
        ]);
    }

    /**
     * @return array<string, array{store_id: int, website_id: int}>
     */
    private function storesByCode(): array
    {
        if ($this->storesByCode === null) {
            $this->storesByCode = [];
            $rows = $this->default->fetchAll(
                $this->default->select()->from(
                    $this->default->getTableName('store'),
                    ['store_id', 'code', 'website_id']
                )
            );
            foreach ($rows as $row) {
                $this->storesByCode[(string) $row['code']] = [
                    'store_id' => (int) $row['store_id'],
                    'website_id' => (int) $row['website_id'],
                ];
            }
        }

        return $this->storesByCode;
    }

    /**
     * @param array<int, int> $map
     */
    private function mappedId(array $map, mixed $sourceId): ?int
    {
        $sourceId = (int) $sourceId;
        if ($sourceId < 1) {
            return null;
        }

        return $map[$sourceId] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function listOf(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $list = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }

        return $list;
    }

    private function table(string $name): string
    {
        return $this->sales->getTableName($name);
    }

    private function reset(): void
    {
        $this->customerCache = [];
        $this->productCache = [];
        $this->groupCache = [];
        $this->orderIdMap = [];
        $this->relations = [];
        $this->storesByCode = null;
    }
}
