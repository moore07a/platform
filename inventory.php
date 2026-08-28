<?php require_once(__DIR__ . '/init.php'); ?>
<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/includes/functions.php');
requireLogin();

// Check access
$userType = getUserType();
$isOwnerOrAdmin = isPlatformOwner() || hasRole('farm_admin');
if (!$isOwnerOrAdmin && !checkAccess('poultry') && !checkAccess('ruminant')) {
    header('Location: dashboard.php');
    exit();
}

// Inventory access should align with farm assignments so managers are not blocked
$hasFarmAccess = checkAccess('poultry') || checkAccess('ruminant');
$canAccessInventory = $isOwnerOrAdmin || hasPermission($userType, 'inventory') || $hasFarmAccess;
$canManageInventory = $isOwnerOrAdmin;
$canAddNewItem = $canManageInventory || hasPermission($userType, 'inventory_add_new_item');
$canUpdateStock = $canManageInventory || hasPermission($userType, 'update_stock');

if (!$canAccessInventory) {
    header('Location: no_access.php');
    exit();
}
$farmType = $isOwnerOrAdmin ? getUserFarmType() : (hasRole('poultry_manager') ? 'poultry' : 'ruminant');
$currentFarmId = requireCurrentFarmId();

// Get inventory items
if ($farmType === 'all') {
    $query = "SELECT si.*, ic.category_name,
              CASE
                WHEN si.current_stock <= si.min_stock_level THEN 'danger'
                WHEN si.current_stock <= si.min_stock_level * 2 THEN 'warning'
                ELSE 'success'
              END as status_class
              FROM stock_items si
              JOIN inventory_categories ic ON si.category_id = ic.id AND ic.farm_id = si.farm_id
              WHERE si.farm_id = ? AND si.is_active = 1
              ORDER BY si.current_stock ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$currentFarmId]);
} else {
    $query = "SELECT si.*, ic.category_name,
              CASE
                WHEN si.current_stock <= si.min_stock_level THEN 'danger'
                WHEN si.current_stock <= si.min_stock_level * 2 THEN 'warning'
                ELSE 'success'
              END as status_class
              FROM stock_items si
              JOIN inventory_categories ic ON si.category_id = ic.id AND ic.farm_id = si.farm_id
              WHERE si.farm_id = ? AND si.farm_type IN (?, 'both')
              AND si.is_active = 1
              ORDER BY si.current_stock ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$currentFarmId, $farmType]);
}

$inventoryItems = $stmt->fetchAll();

// Get deactivated items so owners can restore or permanently delete them after cleaning history
$inactiveQuery = "SELECT si.*, ic.category_name FROM stock_items si
                  JOIN inventory_categories ic ON si.category_id = ic.id AND ic.farm_id = si.farm_id
                  WHERE si.farm_id = ? AND si.is_active = 0
                  ORDER BY si.item_name ASC";
$inactiveItemsStmt = $pdo->prepare($inactiveQuery);
$inactiveItemsStmt->execute([$currentFarmId]);
$inactiveItems = $inactiveItemsStmt->fetchAll();

$totalItems = count($inventoryItems);
$lowStockItems = 0;
$moderateStockItems = 0;
$goodStockItems = 0;
$totalInventoryValue = 0;
$poultryItems = 0;
$ruminantItems = 0;

foreach ($inventoryItems as $item) {
    $currentStock = (float) ($item['current_stock'] ?? 0);
    $minStockLevel = (float) ($item['min_stock_level'] ?? 0);
    $unitCost = (float) ($item['unit_cost'] ?? 0);

    $totalInventoryValue += $currentStock * $unitCost;

    if (($item['farm_type'] ?? '') === 'poultry') {
        $poultryItems++;
    } elseif (($item['farm_type'] ?? '') === 'ruminant') {
        $ruminantItems++;
    }

    if ($currentStock <= $minStockLevel) {
        $lowStockItems++;
    } elseif ($currentStock <= ($minStockLevel * 2)) {
        $moderateStockItems++;
    } else {
        $goodStockItems++;
    }
}

$criticalInventoryItems = array_values(array_filter($inventoryItems, function ($item) {
    return (float) ($item['current_stock'] ?? 0) <= (float) ($item['min_stock_level'] ?? 0);
}));

usort($criticalInventoryItems, function ($a, $b) {
    $ratioA = ((float) ($a['current_stock'] ?? 0)) / max(1, (float) ($a['min_stock_level'] ?? 0));
    $ratioB = ((float) ($b['current_stock'] ?? 0)) / max(1, (float) ($b['min_stock_level'] ?? 0));

    return $ratioA <=> $ratioB;
});

$topCriticalInventoryItems = array_slice($criticalInventoryItems, 0, 3);
$inventoryHealthScore = $totalItems > 0 ? (int) round(($goodStockItems / $totalItems) * 100) : 100;
$inventoryHealthLabel = $inventoryHealthScore >= 80 ? 'Healthy' : ($inventoryHealthScore >= 55 ? 'Watch list' : 'Needs urgent restock');
$inventoryHealthClass = $inventoryHealthScore >= 80 ? 'success' : ($inventoryHealthScore >= 55 ? 'warning' : 'danger');
$inventoryAutomationMessage = $lowStockItems > 0
    ? sprintf('%d item%s need reorder review now.', $lowStockItems, $lowStockItems === 1 ? '' : 's')
    : 'No item is below minimum stock level.';

// Get categories for dropdown
$categoriesStmt = $pdo->prepare("SELECT ic.*, COUNT(si.id) AS item_count FROM inventory_categories ic LEFT JOIN stock_items si ON si.category_id = ic.id AND si.farm_id = ic.farm_id WHERE ic.farm_id = ? GROUP BY ic.id ORDER BY ic.category_name");
$categoriesStmt->execute([$currentFarmId]);
$categories = $categoriesStmt->fetchAll();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (isset($_POST['add_category'])) {
        if (!$canManageInventory) {
            $_SESSION['error'] = "Only owners can add categories.";
            header('Location: inventory.php');
            exit();
        }

        $name = trim($_POST['category_name'] ?? '');
        $farmType = $_POST['category_farm_type'] ?? 'both';
        $unit = trim($_POST['category_unit'] ?? '');

        if ($name === '') {
            $_SESSION['error'] = "Category name is required.";
            header('Location: inventory.php');
            exit();
        }

        if (!in_array($farmType, allowedFarmTypes(), true)) {
            $farmType = allowedFarmTypes()[0] ?? '';
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO inventory_categories (farm_id, category_name, farm_type, unit) VALUES (?, ?, ?, ?)");
            $stmt->execute([$currentFarmId, $name, $farmType, $unit]);
            $_SESSION['success'] = "Category added successfully.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Could not add category. It may already exist.";
        }

        header('Location: inventory.php');
        exit();
    }

    if (isset($_POST['delete_category'])) {
        if (!$canManageInventory) {
            $_SESSION['error'] = "Only owners can delete categories.";
            header('Location: inventory.php');
            exit();
        }

        $categoryId = (int) ($_POST['category_id'] ?? 0);
        if ($categoryId <= 0) {
            $_SESSION['error'] = "Please select a category to delete.";
            header('Location: inventory.php');
            exit();
        }

        try {
            $pdo->beginTransaction();

            $itemStmt = $pdo->prepare('SELECT id, item_name FROM stock_items WHERE category_id = ? AND farm_id = ?');
            $itemStmt->execute([$categoryId, $currentFarmId]);
            $items = $itemStmt->fetchAll();

            if (!empty($items)) {
                $deleteTrans = $pdo->prepare('DELETE FROM stock_transactions WHERE stock_item_id = ? AND farm_id = ?');
                $deleteItem = $pdo->prepare('DELETE FROM stock_items WHERE id = ? AND farm_id = ?');

                foreach ($items as $itemRow) {
                    $deleteTrans->execute([$itemRow['id'], $currentFarmId]);
                    $deleteItem->execute([$itemRow['id'], $currentFarmId]);
                }
            }

            $deleteCategoryStmt = $pdo->prepare('DELETE FROM inventory_categories WHERE id = ? AND farm_id = ?');
            $deleteCategoryStmt->execute([$categoryId, $currentFarmId]);

            $pdo->commit();

            $_SESSION['success'] = 'Category deleted successfully.';
            if (!empty($items)) {
                $_SESSION['success'] .= ' Related stock items were also removed.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = 'Could not delete category. Please try again.';
        }

        header('Location: inventory.php');
        exit();
    }
    if (isset($_POST['add_item'])) {
        if (!$canAddNewItem) {
            $_SESSION['error'] = "You do not have permission to add new items.";
            header('Location: inventory.php');
            exit();
        }

        $feedCategory = $_POST['feed_category'] ?? 'general';
        $farmType = $_POST['farm_type'];
        $initialStockDate = trim($_POST['initial_stock_date'] ?? '');

        $parsedInitialStockDate = DateTimeImmutable::createFromFormat('!Y-m-d', $initialStockDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$parsedInitialStockDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $_SESSION['error'] = "Please select a valid initial stock date.";
            header('Location: inventory.php');
            exit();
        }

        if (!in_array($feedCategory, allowedFeedCategories(), true)) {
            $_SESSION['error'] = "That feed type is not enabled for this farm.";
            header('Location: inventory.php');
            exit();
        }

        if ($feedCategory === 'ruminant') {
            $farmType = 'ruminant';
        } elseif (in_array($feedCategory, ['layer', 'broiler'], true)) {
            $farmType = 'poultry';
        }

        if (!in_array($farmType, allowedFarmTypes(), true)) {
            $_SESSION['error'] = "That farm type is not enabled for this farm.";
            header('Location: inventory.php');
            exit();
        }

        $categoryId = (int)($_POST['category_id'] ?? 0);
        $categoryStmt = $pdo->prepare('SELECT id FROM inventory_categories WHERE id = ? AND farm_id = ?');
        $categoryStmt->execute([$categoryId, $currentFarmId]);
        if (!$categoryStmt->fetchColumn()) {
            $_SESSION['error'] = 'The selected category does not belong to this farm.';
            header('Location: inventory.php');
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO stock_items
            (farm_id, item_name, category_id, current_stock, min_stock_level, unit, farm_type, feed_category, unit_cost)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $currentFarmId, $_POST['item_name'],
            $categoryId,
            $_POST['initial_stock'],
            $_POST['min_stock'],
            $_POST['unit'],
            $farmType,
            $feedCategory,
            isset($_POST['unit_cost']) ? max(0, (float) $_POST['unit_cost']) : 0
        ]);

        $itemId = $pdo->lastInsertId();

        $transStmt = $pdo->prepare("INSERT INTO stock_transactions (farm_id, stock_item_id, transaction_type, quantity, previous_stock, new_stock, transaction_date, remarks, user_id, farm_type) VALUES (?, ?, 'received', ?, 0, ?, ?, 'Initial stock entry', ?, ?)");
        $transStmt->execute([
            $currentFarmId, $itemId,
            $_POST['initial_stock'],
            $_POST['initial_stock'],
            $parsedInitialStockDate->format('Y-m-d'),
            $_SESSION['user_id'] ?? null,
            $farmType
        ]);

        $_SESSION['success'] = "Item added successfully!";
        header('Location: inventory.php');
        exit();
    }

    if (isset($_POST['update_stock'])) {
        if (!$canUpdateStock) {
            $_SESSION['error'] = "You do not have permission to update stock.";
            header('Location: inventory.php');
            exit();
        }

        $itemId = $_POST['item_id'];
        $type = $_POST['transaction_type'];
        $quantity = $_POST['quantity'];
        
        // Get current stock and valuation
        $itemStmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ? AND farm_id = ?");
        $itemStmt->execute([$itemId, $currentFarmId]);
        $item = $itemStmt->fetch();
        
        if ($item) {
            $previousStock = $item['current_stock'];
            
            $newUnitCost = $item['unit_cost'] ?? 0;

            if ($type == 'received') {
                $newStock = $previousStock + $quantity;

                $incomingUnitCost = isset($_POST['unit_cost']) ? (float) $_POST['unit_cost'] : 0;
                if ($incomingUnitCost > 0) {
                    $currentValue = $previousStock * $newUnitCost;
                    $incomingValue = $quantity * $incomingUnitCost;
                    $newUnitCost = $newStock > 0 ? ($currentValue + $incomingValue) / $newStock : $newUnitCost;
                }
            } else {
                if ($quantity > $previousStock) {
                    $_SESSION['error'] = "Insufficient stock. Available: {$previousStock}";
                    header('Location: inventory.php');
                    exit();
                }
                $newStock = $previousStock - $quantity;
            }

            // Update stock
            $updateStmt = $pdo->prepare("UPDATE stock_items SET current_stock = ?, unit_cost = ? WHERE id = ? AND farm_id = ?");
            $updateStmt->execute([$newStock, $newUnitCost, $itemId, $currentFarmId]);
            
            // Record transaction
            $transStmt = $pdo->prepare("INSERT INTO stock_transactions 
                (stock_item_id, transaction_type, quantity, previous_stock, new_stock, 
                 transaction_date, remarks, user_id, farm_type, farm_id)
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)");
            $transStmt->execute([
                $itemId,
                $type,
                $quantity,
                $previousStock,
                $newStock,
                $_POST['remarks'],
                $_SESSION['user_id'],
                $item['farm_type'], $currentFarmId
            ]);
            
            $_SESSION['success'] = "Stock updated successfully!";
            header('Location: inventory.php');
            exit();
        }
    }
    
    if (isset($_POST['delete_item'])) {
        if (!$canManageInventory) {
            $_SESSION['error'] = "Only owners can delete items.";
            header('Location: inventory.php');
            exit();
        }

        $itemId = $_POST['item_id'];
        
        // Check if item has transactions
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_transactions WHERE stock_item_id = ? AND farm_id = ?");
        $checkStmt->execute([$itemId, $currentFarmId]);
        $hasTransactions = $checkStmt->fetchColumn() > 0;

        if ($hasTransactions) {
            $stmt = $pdo->prepare("UPDATE stock_items SET is_active = 0 WHERE id = ? AND farm_id = ?");
            $stmt->execute([$itemId, $currentFarmId]);
            $_SESSION['success'] = "Item deactivated successfully. Transaction history preserved.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM stock_items WHERE id = ? AND farm_id = ?");
            $stmt->execute([$itemId, $currentFarmId]);
            $_SESSION['success'] = "Item deleted successfully!";
        }
        
        header('Location: inventory.php');
        exit();
    }

    if (isset($_POST['restore_item'])) {
        if (!$canManageInventory) {
            $_SESSION['error'] = "Only owners can restore items.";
            header('Location: inventory.php');
            exit();
        }

        $itemId = $_POST['item_id'];

        $stmt = $pdo->prepare("UPDATE stock_items SET is_active = 1 WHERE id = ? AND farm_id = ?");
        $stmt->execute([$itemId, $currentFarmId]);

        $_SESSION['success'] = "Item restored successfully.";
        header('Location: inventory.php');
        exit();
    }

    if (isset($_POST['purge_item'])) {
        if (!$canManageInventory) {
            $_SESSION['error'] = "Only owners can permanently delete items.";
            header('Location: inventory.php');
            exit();
        }

        $itemId = $_POST['item_id'];

        try {
            $pdo->beginTransaction();

            // Always clear related transactions so the item can be removed cleanly
            $deleteTransactions = $pdo->prepare("DELETE FROM stock_transactions WHERE stock_item_id = ? AND farm_id = ?");
            $deleteTransactions->execute([$itemId, $currentFarmId]);

            $deleteItem = $pdo->prepare("DELETE FROM stock_items WHERE id = ? AND farm_id = ?");
            $deleteItem->execute([$itemId, $currentFarmId]);

            $pdo->commit();

            $_SESSION['success'] = "Item and its history permanently deleted.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Failed to delete item permanently. Please try again.";
        }

        header('Location: inventory.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - Renee Farms</title>
    
    <!-- Include CSS -->
    <?php include(__DIR__ . '/navbar_head.php'); ?>
    
    <!-- DataTables CSS (local copies to avoid CDN issues) -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/css/jquery.dataTables.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/css/dataTables.bootstrap5.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables-responsive/css/responsive.dataTables.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables-responsive/css/responsive.bootstrap.min.css'); ?>">
    
    <style>
        .inventory-shell {
            background: radial-gradient(circle at 0% 0%, rgba(13, 110, 253, 0.12), transparent 35%),
                        radial-gradient(circle at 100% 0%, rgba(25, 135, 84, 0.12), transparent 32%);
            border: 1px solid rgba(13, 110, 253, 0.08);
            border-radius: 1.25rem;
            padding: 1.25rem;
        }

        .inventory-hero {
            border: 0;
            border-radius: 1rem;
            color: #fff;
            background: linear-gradient(125deg, #0d6efd, #198754);
            box-shadow: 0 1rem 2rem rgba(13, 110, 253, .22);
        }

        .metric-card {
            border: 0;
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, 0.06);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 .9rem 1.4rem rgba(0, 0, 0, 0.1);
        }

        .metric-icon {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.05rem;
        }

        .stock-status {
            font-size: 0.8rem;
            padding: 3px 10px;
            border-radius: 10px;
        }

        .stock-status-low {
            background-color: #f8d7da;
            color: #721c24;
        }

        .stock-status-warning {
            background-color: #fff3cd;
            color: #856404;
        }

        .stock-status-good {
            background-color: #d4edda;
            color: #155724;
        }

        .table-wrap {
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 1rem;
            overflow-x: auto;
            overflow-y: visible;
        }

        .stock-progress {
            height: 18px;
            border-radius: 10px;
        }

        .inventory-command-card {
            border: 0;
            border-radius: 1.15rem;
            background: linear-gradient(145deg, #ffffff 0%, #f7fbff 100%);
            box-shadow: 0 .75rem 1.75rem rgba(16, 24, 40, 0.08);
        }

        .inventory-score-ring {
            --score-color: #198754;
            width: 104px;
            height: 104px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at center, #fff 0 58%, transparent 59%),
                conic-gradient(var(--score-color) calc(var(--score) * 1%), #e9eef6 0);
        }

        .inventory-score-ring.score-warning { --score-color: #ffc107; }
        .inventory-score-ring.score-danger { --score-color: #dc3545; }

        .inventory-priority-list {
            display: grid;
            gap: .75rem;
        }

        .inventory-priority-item {
            border: 1px solid #edf2f7;
            border-radius: .9rem;
            background: #fff;
            padding: .8rem;
        }

        .inventory-table-card {
            border: 0;
            border-radius: 1.1rem;
            box-shadow: 0 .75rem 1.5rem rgba(16, 24, 40, 0.08);
            overflow: hidden;
        }

        .inventory-table-card .card-header {
            background: #fff;
            border-bottom: 1px solid #eef2f7;
        }

        .inventory-table thead th {
            background: #f8fafc;
            color: #667085;
            border-bottom: 1px solid #e4e7ec;
            font-size: .74rem;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .inventory-table tbody td {
            vertical-align: middle;
        }

        .inventory-table > tbody > tr:not(.child) > td:last-child,
        .inventory-table > thead > tr > th:last-child {
            min-width: 9.5rem;
        }

        @media (min-width: 768px) {
            .inventory-table > tbody > tr:not(.child) > td:last-child,
            .inventory-table > thead > tr > th:last-child {
                background: #fff;
                position: sticky;
                right: 0;
                z-index: 2;
            }

            .inventory-table > thead > tr > th:last-child {
                background: #f8fafc;
                z-index: 3;
            }
        }

        @media (max-width: 767.98px) {
            .inventory-table > tbody > tr:not(.child) > td:first-child {
                cursor: pointer;
            }
        }

        .inventory-action-group {
            justify-content: flex-start;
            min-width: max-content;
        }

        .inventory-action-group .btn {
            border-radius: 999px;
        }

        .bg-primary-subtle { background-color: rgba(13, 110, 253, 0.12) !important; }
        .bg-success-subtle { background-color: rgba(25, 135, 84, 0.12) !important; }
        .bg-warning-subtle { background-color: rgba(255, 193, 7, 0.18) !important; }
        .bg-danger-subtle { background-color: rgba(220, 53, 69, 0.12) !important; }
    </style>
</head>
<body>
    <?php include(__DIR__ . '/navbar.php'); ?>
    
    <div class="container-fluid mt-4">
        <div class="inventory-shell">
            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <div class="card inventory-hero h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div>
                                    <p class="mb-2 text-uppercase small fw-semibold">Stock Intelligence</p>
                                    <h3 class="mb-2"><i class="bi bi-box-seam-fill me-2"></i>Inventory Management</h3>
                                    <p class="mb-0 opacity-75">Track stock health, manage categories, and update movement in one place.</p>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ($canManageInventory): ?>
                                        <button class="btn btn-light text-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                            <i class="bi bi-folder-plus me-1"></i> Manage Categories
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canAddNewItem): ?>
                                        <button class="btn btn-light text-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addItemModal">
                                            <i class="bi bi-plus-circle me-1"></i> Add Item
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canUpdateStock): ?>
                                        <button class="btn btn-warning fw-semibold" data-bs-toggle="modal" data-bs-target="#updateStockModal">
                                            <i class="bi bi-arrow-up-down me-1"></i> Update Stock
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card metric-card h-100">
                        <div class="card-body">
                            <p class="text-muted mb-2">Inventory Value</p>
                            <h3 class="fw-bold mb-2">₦<?php echo number_format($totalInventoryValue, 2); ?></h3>
                            <div class="small text-muted d-flex gap-3">
                                <span><i class="bi bi-feather me-1"></i>Poultry: <?php echo $poultryItems; ?></span>
                                <span><i class="bi bi-tree me-1"></i>Ruminant: <?php echo $ruminantItems; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6 col-xl-3">
                    <div class="card metric-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <p class="text-muted mb-0">Total Items</p>
                                <span class="metric-icon bg-primary-subtle text-primary"><i class="bi bi-boxes"></i></span>
                            </div>
                            <h4 class="fw-bold mb-1"><?php echo $totalItems; ?></h4>
                            <small class="text-muted">Tracked stock records</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card metric-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <p class="text-muted mb-0">Low Stock</p>
                                <span class="metric-icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-triangle"></i></span>
                            </div>
                            <h4 class="fw-bold mb-1"><?php echo $lowStockItems; ?></h4>
                            <small class="text-muted">Need immediate reorder</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card metric-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <p class="text-muted mb-0">Moderate</p>
                                <span class="metric-icon bg-warning-subtle text-warning"><i class="bi bi-activity"></i></span>
                            </div>
                            <h4 class="fw-bold mb-1"><?php echo $moderateStockItems; ?></h4>
                            <small class="text-muted">Monitor closely</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="card metric-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <p class="text-muted mb-0">Good Stock</p>
                                <span class="metric-icon bg-success-subtle text-success"><i class="bi bi-check-circle"></i></span>
                            </div>
                            <h4 class="fw-bold mb-1"><?php echo $goodStockItems; ?></h4>
                            <small class="text-muted">Healthy supply</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card inventory-command-card mb-3">
                <div class="card-body p-4">
                    <div class="row g-4 align-items-center">
                        <div class="col-lg-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="inventory-score-ring score-<?php echo htmlspecialchars($inventoryHealthClass); ?>" style="--score: <?php echo (int) $inventoryHealthScore; ?>;">
                                    <strong class="fs-4"><?php echo (int) $inventoryHealthScore; ?>%</strong>
                                </div>
                                <div>
                                    <span class="badge bg-<?php echo htmlspecialchars($inventoryHealthClass); ?>-subtle text-<?php echo htmlspecialchars($inventoryHealthClass); ?> mb-2">Smart inventory health</span>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($inventoryHealthLabel); ?></h5>
                                    <p class="text-muted mb-0 small"><?php echo htmlspecialchars($inventoryAutomationMessage); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div>
                                    <h6 class="mb-0">Priority restock plan</h6>
                                    <small class="text-muted">Automatically ranks stock below minimum level by urgency.</small>
                                </div>
                                <?php if ($canUpdateStock): ?>
                                    <button class="btn btn-sm btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#updateStockModal">
                                        <i class="bi bi-arrow-up-down me-1"></i> Update priority stock
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="inventory-priority-list">
                                <?php if (empty($topCriticalInventoryItems)): ?>
                                    <div class="inventory-priority-item d-flex align-items-center gap-3">
                                        <span class="metric-icon bg-success-subtle text-success"><i class="bi bi-check2-circle"></i></span>
                                        <div>
                                            <strong>No urgent restock item</strong>
                                            <div class="small text-muted">Keep minimum levels updated so the system can alert you early.</div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($topCriticalInventoryItems as $criticalItem): ?>
                                        <?php
                                            $criticalCurrent = (float) ($criticalItem['current_stock'] ?? 0);
                                            $criticalMin = (float) ($criticalItem['min_stock_level'] ?? 0);
                                            $suggestedQuantity = max(0, ($criticalMin * 2) - $criticalCurrent);
                                        ?>
                                        <div class="inventory-priority-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <div>
                                                <strong><?php echo htmlspecialchars($criticalItem['item_name']); ?></strong>
                                                <div class="small text-muted">
                                                    Current <?php echo number_format($criticalCurrent, 2); ?> / Min <?php echo number_format($criticalMin, 2); ?> <?php echo htmlspecialchars($criticalItem['unit']); ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-danger-subtle text-danger">
                                                Reorder <?php echo number_format($suggestedQuantity, 2); ?> <?php echo htmlspecialchars($criticalItem['unit']); ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($canManageInventory && !empty($inactiveItems)): ?>
                <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong>Inactive items found.</strong> Restore them to continue using them or permanently delete after clearing their history.
                    </div>
                    <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#inactiveItems" aria-expanded="false" aria-controls="inactiveItems">
                        View inactive items
                    </button>
                </div>

                <div class="collapse mb-4" id="inactiveItems">
                    <div class="card border-warning">
                        <div class="card-header bg-warning text-dark">
                            <i class="bi bi-archive"></i> Inactive Items
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th>Current Stock</th>
                                            <th>Min Level</th>
                                            <th>Unit</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($inactiveItems as $inactive): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($inactive['item_name']); ?></td>
                                            <td><?php echo htmlspecialchars($inactive['category_name']); ?></td>
                                            <td><?php echo number_format((float) $inactive['current_stock'], 2); ?></td>
                                            <td><?php echo number_format((float) $inactive['min_stock_level'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($inactive['unit']); ?></td>
                                            <td class="d-flex gap-2">
                                                <form method="POST" class="mb-0">
                                                    <input type="hidden" name="item_id" value="<?php echo $inactive['id']; ?>">
                                                    <button type="submit" name="restore_item" class="btn btn-sm btn-outline-success">
                                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                                    </button>
                                                </form>
                                                <form method="POST" class="mb-0" onsubmit="return confirm('This will permanently delete the item after its history is removed. Continue?');">
                                                    <input type="hidden" name="item_id" value="<?php echo $inactive['id']; ?>">
                                                    <button type="submit" name="purge_item" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i> Delete permanently
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card inventory-table-card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 p-3">
                    <div>
                        <span class="text-uppercase small text-muted fw-semibold">Inventory ledger</span>
                        <h5 class="mb-0">All Stock Items</h5>
                    </div>
                    <span class="badge bg-primary-subtle text-primary"><?php echo number_format($totalItems); ?> active items</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-wrap table-responsive border-0 rounded-0">
                        <table id="inventoryTable" class="table table-hover mb-0 inventory-table align-middle">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Current Stock</th>
                                    <th>Min Level</th>
                                    <th>Unit</th>
                                    <th>Unit Cost (₦)</th>
                                    <th>Stock Value (₦)</th>
                                    <th>Farm Type</th>
                                    <th>Status</th>
                                    <th>Stock Level</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($inventoryItems) && is_array($inventoryItems)): ?>
                            <?php foreach ($inventoryItems as $item): 
                                $currentStock = $item['current_stock'] ?? 0;
                                $minStockLevel = max(1, ($item['min_stock_level'] ?? 0));
                                $stockPercentage = ($currentStock / ($minStockLevel * 3)) * 100;

                                $statusClass = $item['status_class'] ?? 'success';

                                $statusTextMap = [
                                    'danger' => 'Low Stock',
                                    'warning' => 'Moderate',
                                    'success' => 'Good'
                                ];
                                $statusText = $statusTextMap[$statusClass] ?? 'Unknown';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['item_name'] ?? '-') ?></strong><div class="small text-muted"><?= htmlspecialchars($item['feed_category'] ?? 'general') ?></div></td>
                                <td><?= htmlspecialchars($item['category_name'] ?? '-') ?></td>

                                <td>
                                    <span class="fw-bold text-<?= $statusClass ?>">
                                        <?= $currentStock ?>
                                    </span>
                                </td>

                                <td><?= $minStockLevel ?></td>
                                <td><?= htmlspecialchars($item['unit'] ?? '-') ?></td>

                                <td>₦<?= number_format($item['unit_cost'] ?? 0, 2) ?></td>
                                <td>₦<?= number_format($currentStock * ($item['unit_cost'] ?? 0), 2) ?></td>

                                <td>
                                    <span class="badge bg-<?= ($item['farm_type'] ?? '') === 'poultry' ? 'info' : 'warning' ?>">
                                        <?= ucfirst($item['farm_type'] ?? '-') ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="stock-status stock-status-<?= $statusClass ?>">
                                        <?= $statusText ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="progress stock-progress">
                                        <div class="progress-bar bg-<?= $statusClass ?>"
                                            style="width: <?= min($stockPercentage, 100) ?>%">
                                            <?= round($stockPercentage, 1) ?>%
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="inventory-action-group d-flex gap-1 flex-wrap">
                                        <?php if ($canUpdateStock): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary js-quick-update"
                                                data-item-id="<?= (int)$item['id'] ?>"
                                                data-item-name="<?= htmlspecialchars($item['item_name'] ?? '') ?>">
                                                <i class="bi bi-arrow-up-down me-1"></i> Update
                                            </button>
                                        <?php endif; ?>

                                        <button class="btn btn-sm btn-outline-info"
                                            onclick="viewHistory(<?= (int)$item['id'] ?>)">
                                            <i class="bi bi-clock-history"></i>
                                        </button>

                                        <?php if ($canManageInventory): ?>
                                            <button class="btn btn-sm btn-outline-danger"
                                                onclick="deleteItem(<?= (int)$item['id'] ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <?php if ($canManageInventory): ?>
        <div class="modal fade" id="addCategoryModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Manage Inventory Categories</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <h6 class="mb-3">Add Category</h6>
                                <form method="POST">
                                    <div class="mb-3">
                                        <label>Category Name</label>
                                        <input type="text" name="category_name" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label>Farm Type</label>
                                        <select name="category_farm_type" class="form-select" required>
                                            <?php foreach (allowedFarmTypes() as $type): ?><option value="<?php echo $type; ?>" <?php echo $type === 'both' ? 'selected' : ''; ?>><?php echo ucfirst($type); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label>Unit (optional)</label>
                                        <input type="text" name="category_unit" class="form-control" placeholder="kg, bags, liters...">
                                    </div>
                                    <button type="submit" name="add_category" class="btn btn-primary w-100">Save Category</button>
                                </form>
                            </div>
                            <div class="col-md-7">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0">Category List</h6>
                                    <small class="text-muted">Delete only if category is no longer needed.</small>
                                </div>
                                <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                                    <table class="table table-sm table-striped align-middle">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Farm</th>
                                                <th>Unit</th>
                                                <th>Items</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($categories as $category): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                                <td><?php echo htmlspecialchars(ucfirst($category['farm_type'])); ?></td>
                                                <td><?php echo htmlspecialchars($category['unit'] ?? '-'); ?></td>
                                                <td><?php echo (int) $category['item_count']; ?></td>
                                                <td>
                                                    <form method="POST" onsubmit="return confirm('Delete this category and its related stock records?');" class="mb-0">
                                                        <input type="hidden" name="category_id" value="<?php echo (int) $category['id']; ?>">
                                                        <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <?php if ($canAddNewItem): ?>
        <!-- Add Item Modal -->
        <div class="modal fade" id="addItemModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">Add New Inventory Item</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label>Item Name</label>
                                <input type="text" name="item_name" class="form-control" required>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Category</label>
                                    <select name="category_id" class="form-select" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Farm Type</label>
                                    <select name="farm_type" class="form-select" required>
                                        <?php foreach (allowedFarmTypes() as $type): ?><option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label>Feed Type</label>
                                    <select name="feed_category" class="form-select">
                                        <?php
                                        $feedCategoryLabels = [
                                            'general' => 'General / Not a feed',
                                            'layer' => 'Layer Feeds',
                                            'broiler' => 'Broiler Feeds',
                                            'ruminant' => 'Ruminant Feeds',
                                        ];
                                        foreach (allowedFeedCategories() as $category):
                                        ?>
                                            <option value="<?php echo $category; ?>"><?php echo $feedCategoryLabels[$category]; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="initialStockDate">Initial Stock Date</label>
                                    <input type="date" name="initial_stock_date" id="initialStockDate" class="form-control"
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                    <small class="text-muted">Choose the date this opening stock was received.</small>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Initial Stock</label>
                                    <input type="number" name="initial_stock" class="form-control"
                                           step="0.01" min="0" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Minimum Stock Level</label>
                                    <input type="number" name="min_stock" class="form-control"
                                           step="0.01" min="0" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Unit</label>
                                    <input type="text" name="unit" class="form-control"
                                           placeholder="bags, kg, vials, etc." required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Unit Cost (₦)</label>
                                    <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" placeholder="0.00">
                                    <small class="text-muted">Used for valuing stock on dashboards.</small>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="add_item" class="btn btn-primary">Add Item</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canUpdateStock): ?>
        <!-- Update Stock Modal -->
        <div class="modal fade" id="updateStockModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" id="updateStockForm">
                        <div class="modal-header">
                            <h5 class="modal-title">Update Stock</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="item_id" id="updateItemId">

                            <div class="mb-3">
                                <label>Item</label>
                                <select class="form-select" id="updateItemSelect" required
                                        onchange="updateItemInfo(this.value)">
                                    <option value="">Select Item</option>
                                    <?php foreach ($inventoryItems as $item): ?>
                                    <option value="<?php echo $item['id']; ?>"
                                            data-stock="<?php echo $item['current_stock']; ?>"
                                            data-unit="<?php echo $item['unit']; ?>">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                        (Current: <?php echo $item['current_stock']; ?> <?php echo $item['unit']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="itemInfo" class="mt-2 small text-muted"></div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Transaction Type</label>
                                    <select name="transaction_type" class="form-select" required
                                            onchange="updateQuantityLabel()">
                                        <option value="received">Received Stock (+)</option>
                                        <option value="used">Used Stock (-)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label id="quantityLabel">Quantity</label>
                                    <input type="number" name="quantity" class="form-control"
                                           step="0.01" min="0.01" required>
                                </div>
                            </div>

                            <div class="mb-3" id="unitCostWrapper">
                                <label>Unit Cost (₦) for Received Stock</label>
                                <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" placeholder="Leave blank to keep current cost">
                                <small class="text-muted">Provided value is blended with existing stock to keep averages accurate.</small>
                            </div>

                            <div class="mb-3">
                                <label>Remarks</label>
                                <input type="text" name="remarks" class="form-control"
                                       placeholder="Optional remarks">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="update_stock" class="btn btn-primary">Update Stock</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canManageInventory): ?>
        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Deletion</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this item?</p>
                        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
                        <form method="POST" id="deleteForm">
                            <input type="hidden" name="item_id" id="deleteItemId">
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" form="deleteForm" name="delete_item" class="btn btn-danger">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- JavaScript -->
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/jquery/jquery.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/jquery.dataTables.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/dataTables.bootstrap5.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables-responsive/js/dataTables.responsive.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/js/main.js'); ?>"></script>

 <script src="<?php echo BASE_URL; ?>/assets/js/edit-modal.js"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#inventoryTable').DataTable({
            pageLength: 25,
            order: [[2, 'asc']],
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            columnDefs: [
                { className: 'dtr-control', targets: 0 },
                { responsivePriority: 1, targets: 0 },
                { responsivePriority: 2, targets: -1 },
                { responsivePriority: 3, targets: 1 },
                { orderable: false, targets: -1 }
            ]
        });
        
        // Show messages
        <?php if (isset($_SESSION['success'])): ?>
        showAlert('success', '<?php echo addslashes($_SESSION['success']); ?>');
        <?php unset($_SESSION['success']); endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        showAlert('danger', '<?php echo addslashes($_SESSION['error']); ?>');
        <?php unset($_SESSION['error']); endif; ?>

        // Prevent bootstrap modal errors if the target modal is missing
        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('[data-bs-toggle="modal"]');
            if (!trigger) return;

            const targetSelector = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href');
            if (!targetSelector) return;

            const modalEl = document.querySelector(targetSelector);
            if (!modalEl) {
                console.warn('Modal target not found for button:', targetSelector);
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    });
    
    // Update item info when select changes
    function updateItemInfo(itemId) {
        const selectedOption = document.querySelector(`#updateItemSelect option[value="${itemId}"]`);
        if (selectedOption) {
            const currentStock = selectedOption.dataset.stock;
            const unit = selectedOption.dataset.unit;
            document.getElementById('updateItemId').value = itemId;
            document.getElementById('itemInfo').innerHTML = `
                Current stock: <strong>${currentStock} ${unit}</strong><br>
                Selected item: <strong>${selectedOption.textContent.split(' (Current:')[0]}</strong>
            `;
        }
    }
    
    // Update quantity label based on transaction type
    function updateQuantityLabel() {
        const typeSelect = document.querySelector('select[name="transaction_type"]');
        const label = document.getElementById('quantityLabel');
        const input = document.querySelector('input[name="quantity"]');
        const costWrapper = document.getElementById('unitCostWrapper');

        if (!typeSelect || !label || !input) return;

        if (typeSelect.value === 'used') {
            label.innerHTML = 'Quantity <small class="text-danger">(will be subtracted)</small>';
            input.min = 0.01;
            if (costWrapper) {
                costWrapper.style.display = 'none';
            }
        } else {
            label.innerHTML = 'Quantity <small class="text-success">(will be added)</small>';
            input.min = 0.01;
            if (costWrapper) {
                costWrapper.style.display = 'block';
            }
        }
    }
    
    // Quick update stock from row actions (uses event delegation for DataTables support)
    document.addEventListener('click', function(event) {
        const button = event.target.closest('.js-quick-update');
        if (!button) return;

        const itemId = button.dataset.itemId;

        const itemIdInput = document.getElementById('updateItemId');
        const itemSelect = document.getElementById('updateItemSelect');
        if (!itemIdInput || !itemSelect) return;

        itemIdInput.value = itemId;
        itemSelect.value = itemId;
        updateItemInfo(itemId);

        const modalEl = document.getElementById('updateStockModal');
        if (!modalEl) return;

        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    });
    
    // View stock history
    function viewHistory(itemId) {
        window.location.href = `<?php echo BASE_URL; ?>/api/stock_history.php?item_id=${itemId}`;
    }
    
    // Delete item confirmation
    function deleteItem(itemId) {
        document.getElementById('deleteItemId').value = itemId;
        const modalEl = document.getElementById('deleteConfirmModal');
        if (!modalEl) return;

        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
    
    // Initialize quantity label
    document.addEventListener('DOMContentLoaded', updateQuantityLabel);
    </script>
</body>
</html>
