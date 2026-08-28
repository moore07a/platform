<?php

/** Return active feed items that can be consumed by a daily-record module. */
function feedInventoryItems(PDO $pdo, int $farmId, string $recordType): array
{
    $category = $recordType === 'ruminant' ? 'ruminant' : $recordType;
    $stmt = $pdo->prepare("SELECT id, item_name, current_stock, unit
        FROM stock_items
        WHERE farm_id = ? AND feed_category = ? AND is_active = 1
        ORDER BY item_name");
    $stmt->execute([$farmId, $category]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function feedInventoryExpectedUnits(string $recordType): array
{
    return $recordType === 'ruminant'
        ? ['kg', 'kgs', 'kilogram', 'kilograms']
        : ['bag', 'bags'];
}

/**
 * Replace the inventory deduction linked to one daily record.
 * The caller must run this inside the same transaction as the daily-record save.
 */
function syncFeedConsumptionInventory(
    PDO $pdo,
    int $farmId,
    string $recordType,
    int $recordId,
    ?int $stockItemId,
    float $quantity,
    string $recordDate,
    ?int $userId
): void {
    if (!in_array($recordType, ['layer', 'broiler', 'ruminant'], true)) {
        throw new InvalidArgumentException('Unsupported daily record type.');
    }
    if ($quantity < 0) {
        throw new InvalidArgumentException('Feed consumption cannot be negative.');
    }

    $linkStmt = $pdo->prepare("SELECT * FROM feed_consumption_inventory_links
        WHERE farm_id = ? AND record_type = ? AND record_id = ? FOR UPDATE");
    $linkStmt->execute([$farmId, $recordType, $recordId]);
    $existing = $linkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $oldItemStmt = $pdo->prepare('SELECT current_stock FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE');
        $oldItemStmt->execute([(int)$existing['stock_item_id'], $farmId]);
        if ($oldItemStmt->fetchColumn() === false) {
            throw new RuntimeException('The previously linked feed item no longer exists.');
        }
        $pdo->prepare('UPDATE stock_items SET current_stock = current_stock + ? WHERE id = ? AND farm_id = ?')
            ->execute([(float)$existing['quantity'], (int)$existing['stock_item_id'], $farmId]);
        $pdo->prepare('DELETE FROM feed_consumption_inventory_links WHERE id = ? AND farm_id = ?')
            ->execute([(int)$existing['id'], $farmId]);
        $pdo->prepare('DELETE FROM stock_transactions WHERE id = ? AND farm_id = ?')
            ->execute([(int)$existing['stock_transaction_id'], $farmId]);
    }

    if ($quantity == 0.0) {
        return;
    }
    if (!$stockItemId) {
        throw new InvalidArgumentException('Select the feed item used for this consumption.');
    }

    $category = $recordType === 'ruminant' ? 'ruminant' : $recordType;
    $itemStmt = $pdo->prepare("SELECT id, item_name, current_stock, unit, farm_type
        FROM stock_items
        WHERE id = ? AND farm_id = ? AND feed_category = ? AND is_active = 1
        FOR UPDATE");
    $itemStmt->execute([$stockItemId, $farmId, $category]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new InvalidArgumentException('The selected feed item is unavailable for this daily record.');
    }

    $normalizedUnit = strtolower(trim((string)$item['unit']));
    if (!in_array($normalizedUnit, feedInventoryExpectedUnits($recordType), true)) {
        $expected = $recordType === 'ruminant' ? 'kg' : 'bags';
        throw new InvalidArgumentException("{$item['item_name']} must use {$expected} as its inventory unit.");
    }

    $previousStock = (float)$item['current_stock'];
    if ($quantity > $previousStock) {
        throw new RuntimeException("Insufficient {$item['item_name']} stock. Available: {$previousStock} {$item['unit']}.");
    }
    $newStock = $previousStock - $quantity;
    $pdo->prepare('UPDATE stock_items SET current_stock = ? WHERE id = ? AND farm_id = ?')
        ->execute([$newStock, $stockItemId, $farmId]);

    $transactionStmt = $pdo->prepare("INSERT INTO stock_transactions
        (farm_id, stock_item_id, transaction_type, quantity, previous_stock, new_stock,
         transaction_date, remarks, user_id, farm_type)
        VALUES (?, ?, 'used', ?, ?, ?, ?, ?, ?, ?)");
    $transactionStmt->execute([
        $farmId, $stockItemId, $quantity, $previousStock, $newStock, $recordDate,
        'Automatic deduction from ' . ucfirst($recordType) . ' daily record #' . $recordId,
        $userId, $item['farm_type']
    ]);
    $transactionId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO feed_consumption_inventory_links
        (farm_id, record_type, record_id, stock_item_id, stock_transaction_id, quantity, unit)
        VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$farmId, $recordType, $recordId, $stockItemId, $transactionId, $quantity, $item['unit']]);
}

/** Restore inventory before deleting a linked daily record. Caller owns the transaction. */
function reverseFeedConsumptionInventory(PDO $pdo, int $farmId, string $recordType, int $recordId): void
{
    syncFeedConsumptionInventory($pdo, $farmId, $recordType, $recordId, null, 0, date('Y-m-d'), null);
}
