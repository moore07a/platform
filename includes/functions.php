<?php require_once(__DIR__ . '/../init.php'); ?>
<?php
// includes/functions.php (updated compatibility)

// If $pdo is not set but $conn (mysqli) is present, create a PDO wrapper.
if (!isset($pdo) && isset($conn) && $conn instanceof mysqli) {
    $mysqli = $conn;
    $dsn = 'mysql:host='.$mysqli->host_info.';dbname='.$mysqli->database.';charset=utf8mb4';
    // The above may not give correct host/db - prefer config.php to create $pdo. Skip automatic conversion.
}

if (!function_exists('ensureInventoryActiveColumn')) {
function ensureInventoryActiveColumn($pdo) {
    // Ensure the stock_items table has an is_active flag for soft deletes
    $checkStmt = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'is_active'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE stock_items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER farm_type");
    }
}
}

if (!function_exists('ensureInventoryUnitCostColumn')) {
function ensureInventoryUnitCostColumn($pdo) {
    // Ensure the stock_items table can store a unit cost for accurate stock valuation
    $checkStmt = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'unit_cost'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE stock_items ADD COLUMN unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER current_stock");
    }
}
}

if (!function_exists('ensureExpenseUnitColumn')) {
function ensureExpenseUnitColumn($pdo) {
    // Ensure the farm_expenses table can store a unit quantity for line-item totals
    $checkStmt = $pdo->query("SHOW COLUMNS FROM farm_expenses LIKE 'unit'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE farm_expenses ADD COLUMN unit DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER amount");
    }
}
}

if (!function_exists('ensureUserLastLoginColumn')) {
function ensureUserLastLoginColumn($pdo) {
    // Track the last time each user logged in to avoid shared login timestamps
    $checkStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL AFTER full_name");
    }
}
}

if (!function_exists('ensurePoultryCategoryColumn')) {
function ensurePoultryCategoryColumn($pdo) {
    // Ensure the farm_expenses table can distinguish poultry expenses (broiler vs layer)
    $checkStmt = $pdo->query("SHOW COLUMNS FROM farm_expenses LIKE 'poultry_category'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE farm_expenses ADD COLUMN poultry_category ENUM('broiler','layer') NULL DEFAULT NULL AFTER farm_type");
    }
}
}

if (!function_exists('ensurePermissionsTable')) {
function ensurePermissionsTable($pdo) {
    // Ensure permissions table exists before attempting permission reads/writes
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'permissions'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec(
            "CREATE TABLE permissions (" .
            "id INT AUTO_INCREMENT PRIMARY KEY," .
            // Use VARCHAR instead of ENUM to avoid insert errors when roles change
            "role VARCHAR(100) NOT NULL," .
            "module VARCHAR(100) NOT NULL," .
            "allowed TINYINT(1) NOT NULL DEFAULT 0," .
            "UNIQUE KEY unique_role_module (role, module)" .
            ")"
        );
    } else {
        // If the table exists from an older schema (ENUM), relax the column to VARCHAR
        $columnStmt = $pdo->query("SHOW COLUMNS FROM permissions LIKE 'role'");
        $column = $columnStmt->fetch(PDO::FETCH_ASSOC);
        if ($column && stripos($column['Type'], 'enum(') === 0) {
            $pdo->exec("ALTER TABLE permissions MODIFY COLUMN role VARCHAR(100) NOT NULL");
        }
    }
}
}

if (!function_exists('runSchemaMigrations')) {
function runSchemaMigrations($pdo, array $targets = []) {
    static $completed = [];

    $map = [
        'inventory_active' => 'ensureInventoryActiveColumn',
        'inventory_unit_cost' => 'ensureInventoryUnitCostColumn',
        'expense_unit' => 'ensureExpenseUnitColumn',
        'user_last_login' => 'ensureUserLastLoginColumn',
        'poultry_category' => 'ensurePoultryCategoryColumn',
        'permissions_table' => 'ensurePermissionsTable'
    ];

    $selectedTargets = empty($targets) ? array_keys($map) : $targets;
    sort($selectedTargets);
    $signature = implode('|', $selectedTargets);

    if (isset($completed[$signature])) {
        return;
    }

    foreach ($selectedTargets as $target) {
        if (!isset($map[$target])) {
            continue;
        }

        $functionName = $map[$target];
        if (function_exists($functionName)) {
            $functionName($pdo);
        }
    }

    $completed[$signature] = true;
}
}

if (!function_exists('hasPermission')) {
function hasPermission($role, $module) {
    global $pdo, $conn;
    if (!$role) return false;
    if (function_exists('isPlatformOwner') && isPlatformOwner()) return true;
    if (function_exists('farmHasModule')) {
        if (str_starts_with($module, 'poultry') && !farmHasModule('poultry')) return false;
        if (str_starts_with($module, 'ruminant') && !farmHasModule('ruminant')) return false;
        if (in_array($module, ['sales', 'expenses'], true) && !farmHasModule('sales')) return false;
    }
    if (function_exists('hasRole')) {
        if (hasRole('farm_admin')) return true;
        // Viewers never receive write-module permissions. They may only open
        // the shared reporting workspace for the farm in their session.
        if (hasRole('viewer')) return in_array($module, ['management', 'reports'], true);
        $role = $role ?: (currentUserRoles()[0] ?? '');
        if (str_starts_with($module, 'poultry') && !hasRole('poultry_manager')) return false;
        if (str_starts_with($module, 'ruminant') && !hasRole('ruminant_manager')) return false;
        if ($module === 'sales' && !hasRole('sales_rep')) return false;
    } elseif ($role === 'farm_admin') return true;
    if (isset($pdo)) {
        $sql = "SELECT allowed FROM permissions WHERE role = ? AND module = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$role,$module])) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return ($row['allowed'] == 1);
        }
        return false;
    } elseif (isset($conn)) {
        $sql = "SELECT allowed FROM permissions WHERE role = ? AND module = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss',$role,$module);
        $stmt->execute();
        $stmt->bind_result($allowed);
        if ($stmt->fetch()) { $stmt->close(); return ($allowed==1);} 
        $stmt->close();
        return false;
    }
    return false;
}
}

if (!function_exists('syncDailyFeedConsumption')) {
function normalizeStockUnit(string $unit): string {
    $unit = strtolower(trim($unit));
    if (in_array($unit, ['bag', 'bags'], true)) return 'bags';
    if (in_array($unit, ['kg', 'kgs', 'kilogram', 'kilograms'], true)) return 'kg';
    return $unit;
}

function recalculateStockTransactionBalances(PDO $pdo, int $farmId, int $itemId, ?string $fromDate = null, int $fromId = 0): void {
    $itemStmt = $pdo->prepare('SELECT current_stock FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE');
    $itemStmt->execute([$itemId, $farmId]);
    $currentStock = $itemStmt->fetchColumn();
    if ($currentStock === false) return;

    $openingStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'received' THEN quantity ELSE -quantity END), 0) FROM stock_transactions WHERE stock_item_id = ? AND farm_id = ?");
    $openingStmt->execute([$itemId, $farmId]);
    $balance = (float)$currentStock - (float)$openingStmt->fetchColumn();

    $where = '';
    $params = [$itemId, $farmId];
    if ($fromDate !== null) {
        $prefixStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'received' THEN quantity ELSE -quantity END), 0) FROM stock_transactions WHERE stock_item_id = ? AND farm_id = ? AND (transaction_date < ? OR (transaction_date = ? AND id < ?))");
        $prefixStmt->execute([$itemId, $farmId, $fromDate, $fromDate, $fromId]);
        $balance += (float)$prefixStmt->fetchColumn();
        $where = ' AND (transaction_date > ? OR (transaction_date = ? AND id >= ?))';
        array_push($params, $fromDate, $fromDate, $fromId);
    }
    $transactions = $pdo->prepare('SELECT id, transaction_type, quantity, previous_stock, new_stock FROM stock_transactions WHERE stock_item_id = ? AND farm_id = ?' . $where . ' ORDER BY transaction_date, id FOR UPDATE');
    $transactions->execute($params);
    $rows = $transactions->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare('UPDATE stock_transactions SET previous_stock = ?, new_stock = ? WHERE id = ? AND farm_id = ?');
    foreach ($rows as $row) {
        $previous = $balance;
        $balance += $row['transaction_type'] === 'received' ? (float)$row['quantity'] : -(float)$row['quantity'];
        if ($balance < -0.000001) {
            throw new RuntimeException('This backdated transaction would make stock negative on its transaction date.');
        }
        if (abs((float)$row['previous_stock'] - $previous) > 0.000001 || abs((float)$row['new_stock'] - $balance) > 0.000001) {
            $update->execute([$previous, $balance, $row['id'], $farmId]);
        }
    }
}

function stockTransactionHasDailyFeedLinks(PDO $pdo, int $farmId, int $transactionId): bool {
    foreach (['layer_daily_records', 'broiler_daily_records', 'ruminant_daily_records'] as $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE farm_id = ? AND feed_stock_transaction_id = ? LIMIT 1");
        $stmt->execute([$farmId, $transactionId]);
        if ($stmt->fetchColumn()) return true;
    }
    return false;
}

function inventoryItemHasDailyFeedLinks(PDO $pdo, int $farmId, int $itemId): bool {
    foreach (['layer_daily_records', 'broiler_daily_records', 'ruminant_daily_records'] as $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE farm_id = ? AND (feed_item_id = ? OR feed_stock_transaction_id IN (SELECT id FROM stock_transactions WHERE farm_id = ? AND stock_item_id = ?)) LIMIT 1");
        $stmt->execute([$farmId, $itemId, $farmId, $itemId]);
        if ($stmt->fetchColumn()) return true;
    }
    return false;
}

function detachDailyFeedTransaction(PDO $pdo, int $farmId, int $transactionId): void {
    foreach (['layer_daily_records', 'broiler_daily_records', 'ruminant_daily_records'] as $table) {
        $stmt = $pdo->prepare("UPDATE {$table} SET feed_stock_transaction_id = NULL WHERE farm_id = ? AND feed_stock_transaction_id = ?");
        $stmt->execute([$farmId, $transactionId]);
    }
}

/**
 * Replace the stock movement generated by a daily livestock record.
 * The caller must own the surrounding database transaction.
 */
function syncDailyFeedConsumption(PDO $pdo, int $farmId, ?int $oldTransactionId, int $feedItemId, float $quantity, string $date, string $feedCategory, string $expectedUnit, ?int $userId): ?int {
    if (!is_finite($quantity) || $quantity < 0) {
        throw new RuntimeException('Feed consumption must be a non-negative number.');
    }
    $affectedItems = [];
    $oldTransaction = null;
    if ($oldTransactionId) {
        // Discover the old item without taking a movement lock, then lock every
        // involved stock row in numeric order. All feed-sync paths consequently
        // acquire item locks before movement locks and cannot form a lock cycle.
        $oldStmt = $pdo->prepare("SELECT * FROM stock_transactions WHERE id = ? AND farm_id = ? AND transaction_type = 'used'");
        $oldStmt->execute([$oldTransactionId, $farmId]);
        $oldTransaction = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldTransaction) {
            throw new RuntimeException('The previous feed stock movement could not be found.');
        }
    }

    $itemIds = [];
    if ($oldTransaction) $itemIds[] = (int)$oldTransaction['stock_item_id'];
    if ($quantity > 0 && $feedItemId > 0) $itemIds[] = $feedItemId;
    $itemIds = array_values(array_unique($itemIds));
    sort($itemIds, SORT_NUMERIC);
    $lockedItems = [];
    $lockItemStmt = $pdo->prepare('SELECT * FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE');
    foreach ($itemIds as $itemId) {
        $lockItemStmt->execute([$itemId, $farmId]);
        $lockedItem = $lockItemStmt->fetch(PDO::FETCH_ASSOC);
        if ($lockedItem) $lockedItems[$itemId] = $lockedItem;
    }

    if ($oldTransaction) {
        $oldStmt = $pdo->prepare("SELECT * FROM stock_transactions WHERE id = ? AND farm_id = ? AND transaction_type = 'used' FOR UPDATE");
        $oldStmt->execute([$oldTransactionId, $farmId]);
        $lockedOldTransaction = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedOldTransaction || (int)$lockedOldTransaction['stock_item_id'] !== (int)$oldTransaction['stock_item_id']) {
            throw new RuntimeException('The previous feed stock movement could not be found.');
        }
        $oldTransaction = $lockedOldTransaction;
        $oldItemId = (int)$oldTransaction['stock_item_id'];
        if (!isset($lockedItems[$oldItemId])) {
            throw new RuntimeException('The previously selected feed item could not be found.');
        }
        $oldStock = (float)$lockedItems[$oldItemId]['current_stock'];
        $pdo->prepare('UPDATE stock_items SET current_stock = ? WHERE id = ? AND farm_id = ?')
            ->execute([$oldStock + (float)$oldTransaction['quantity'], $oldItemId, $farmId]);
        $lockedItems[$oldItemId]['current_stock'] = $oldStock + (float)$oldTransaction['quantity'];
        // The daily row owns this generated movement through a restrictive foreign
        // key. Detach it inside the caller's transaction before replacing/deleting it.
        detachDailyFeedTransaction($pdo, $farmId, $oldTransactionId);
        $pdo->prepare('DELETE FROM stock_transactions WHERE id = ? AND farm_id = ?')->execute([$oldTransactionId, $farmId]);
        $affectedItems[(int)$oldTransaction['stock_item_id']] = [
            'date' => $oldTransaction['transaction_date'],
            'id' => (int)$oldTransaction['id'],
        ];
    }

    if ($quantity <= 0) {
        foreach ($affectedItems as $affectedItemId => $start) {
            recalculateStockTransactionBalances($pdo, $farmId, $affectedItemId, $start['date'], $start['id']);
        }
        return null;
    }
    if ($feedItemId <= 0) {
        throw new RuntimeException('Select the feed item used for this consumption.');
    }

    $item = $lockedItems[$feedItemId] ?? null;
    if (!$item || $item['feed_category'] !== $feedCategory) {
        throw new RuntimeException('The selected feed item is not available for this record type.');
    }
    $reusingInactiveItem = $oldTransactionId && isset($oldTransaction) && (int)$oldTransaction['stock_item_id'] === $feedItemId;
    if (!(int)$item['is_active'] && !$reusingInactiveItem) {
        throw new RuntimeException('The selected feed item is inactive.');
    }
    if (normalizeStockUnit((string)$item['unit']) !== normalizeStockUnit($expectedUnit)) {
        throw new RuntimeException(sprintf('The selected feed item must be stocked in %s.', $expectedUnit));
    }
    if ($quantity > (float)$item['current_stock']) {
        throw new RuntimeException(sprintf('Insufficient feed stock. Available: %s %s.', $item['current_stock'], $item['unit']));
    }

    $newStock = (float)$item['current_stock'] - $quantity;
    $pdo->prepare('UPDATE stock_items SET current_stock = ? WHERE id = ? AND farm_id = ?')->execute([$newStock, $feedItemId, $farmId]);
    $movement = $pdo->prepare("INSERT INTO stock_transactions (farm_id, stock_item_id, transaction_type, quantity, previous_stock, new_stock, transaction_date, remarks, user_id, farm_type) VALUES (?, ?, 'used', ?, ?, ?, ?, 'Automatically deducted from daily feed consumption', ?, ?)");
    $movement->execute([$farmId, $feedItemId, $quantity, $item['current_stock'], $newStock, $date, $userId, $item['farm_type']]);
    $movementId = (int)$pdo->lastInsertId();
    $newStart = ['date' => $date, 'id' => $movementId];
    $existingStart = $affectedItems[$feedItemId] ?? null;
    if (!$existingStart || $date < $existingStart['date'] || ($date === $existingStart['date'] && $movementId < $existingStart['id'])) {
        $affectedItems[$feedItemId] = $newStart;
    }
    foreach ($affectedItems as $affectedItemId => $start) {
        recalculateStockTransactionBalances($pdo, $farmId, $affectedItemId, $start['date'], $start['id']);
    }
    return $movementId;
}
}

if (!function_exists('reverseDailyFeedConsumption')) {
function reverseDailyFeedConsumption(PDO $pdo, int $farmId, ?int $transactionId): void {
    if (!$transactionId) return;
    syncDailyFeedConsumption($pdo, $farmId, $transactionId, 0, 0, date('Y-m-d'), 'general', '', null);
}
}

if (!function_exists('ensureAllowed')) {
function ensureAllowed($module) {
    if (!isset($_SESSION['user_type'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit();
    }
    if (!hasPermission($_SESSION['user_type'], $module)) {
        header('Location: ' . BASE_URL . '/no_access.php');
        exit();
    }
}
}
?>
