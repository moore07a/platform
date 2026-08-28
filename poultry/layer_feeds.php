<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

// Check access
$isOwner = isPlatformOwner() || hasRole('farm_admin');
if (!checkAccess('poultry') && !$isOwner) {
    header('Location: dashboard.php');
    exit();
}

$tenantFarmId = requireCurrentFarmId();
$month = $_GET['month'] ?? date('Y-m');
$yearMonth = date('Y-m', strtotime($month));
$monthSelectorDate = date('Y-m-d', strtotime($yearMonth . '-' . min((int)date('d'), (int)date('t', strtotime($yearMonth . '-01')))));
$startDate = date('Y-m-01', strtotime($yearMonth));
$endDate = date('Y-m-t', strtotime($yearMonth));

// Get feed transactions for the month
$query = "SELECT t.*, s.item_name, s.unit, u.full_name, fcil.id AS consumption_link_id
          FROM stock_transactions t
          JOIN stock_items s ON t.stock_item_id = s.id
          LEFT JOIN users u ON t.user_id = u.id AND u.farm_id = t.farm_id
          LEFT JOIN feed_consumption_inventory_links fcil ON fcil.stock_transaction_id = t.id AND fcil.farm_id = t.farm_id
          WHERE t.farm_id = ? AND s.farm_id = ? AND t.transaction_date BETWEEN ? AND ?
          AND s.farm_type IN ('poultry', 'both')
          AND s.feed_category = 'layer'
          ORDER BY t.transaction_date DESC, t.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute([$tenantFarmId, $tenantFarmId, $startDate, $endDate]);
$transactions = $stmt->fetchAll();

// Get current poultry feed stock
$stockQuery = "SELECT * FROM stock_items
               WHERE farm_id = ? AND farm_type IN ('poultry', 'both')
               AND feed_category = 'layer'
               ORDER BY current_stock ASC";
$stockStmt = $pdo->prepare($stockQuery);
$stockStmt->execute([$tenantFarmId]);
$feedItems = $stockStmt->fetchAll();

// Handle new transaction (usage-only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_transaction'])) {
    $itemId = $_POST['feed_item'];
    $type = 'used';
    $quantity = $_POST['quantity'];
    $date = $_POST['transaction_date'];
    $redirectMonth = date('Y-m', strtotime($date));
    
    // Get current stock
    $itemStmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ? AND farm_id = ?");
    $itemStmt->execute([$itemId, $tenantFarmId]);
    $item = $itemStmt->fetch();
    
    if ($item) {
        $previousStock = $item['current_stock'];
        
        if ($quantity > $previousStock) {
            $_SESSION['error'] = "Insufficient stock. Available: {$previousStock} {$item['unit']}";
            header("Location: layer_feeds.php?month={$redirectMonth}");
            exit();
        }
        $newStock = $previousStock - $quantity;
        
        // Update stock
        $updateStmt = $pdo->prepare("UPDATE stock_items SET current_stock = ? WHERE id = ? AND farm_id = ?");
        $updateStmt->execute([$newStock, $itemId, $tenantFarmId]);
        
        // Record transaction
        $transStmt = $pdo->prepare("INSERT INTO stock_transactions 
            (stock_item_id, transaction_type, quantity, previous_stock, new_stock, 
             transaction_date, remarks, user_id, farm_type, farm_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'poultry', ?)");
        $transStmt->execute([
            $itemId,
            $type,
            $quantity,
            $previousStock,
            $newStock,
            $date,
            $_POST['remarks'],
            $_SESSION['user_id'],
            $tenantFarmId
        ]);
        
        $_SESSION['success'] = "Feed transaction recorded successfully!";
        header("Location: layer_feeds.php?month={$redirectMonth}");
        exit();
    }
}

// Handle transaction deletion (owner only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_transaction']) && $isOwner) {
    $transactionId = $_POST['transaction_id'];

    try {
        $pdo->beginTransaction();

        $transStmt = $pdo->prepare("SELECT * FROM stock_transactions WHERE id = ? AND farm_id = ? AND farm_type = 'poultry'");
        $transStmt->execute([$transactionId, $tenantFarmId]);
        $transaction = $transStmt->fetch();

        if (!$transaction) {
            throw new Exception('Transaction not found.');
        }

        $itemStmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE");
        $itemStmt->execute([$transaction['stock_item_id'], $tenantFarmId]);
        $item = $itemStmt->fetch();

        if (!$item) {
            throw new Exception('Related feed item not found.');
        }

        $adjustedStock = $item['current_stock'];
        $adjustedStock += $transaction['transaction_type'] === 'received'
            ? -$transaction['quantity']
            : $transaction['quantity'];

        if ($adjustedStock < 0) {
            throw new Exception('Deleting this record would create negative stock.');
        }

        $pdo->prepare("UPDATE stock_items SET current_stock = ? WHERE id = ? AND farm_id = ?")
            ->execute([$adjustedStock, $item['id'], $tenantFarmId]);

        $pdo->prepare("DELETE FROM stock_transactions WHERE id = ? AND farm_id = ?")
            ->execute([$transactionId, $tenantFarmId]);

        $pdo->commit();
        $_SESSION['success'] = 'Transaction deleted successfully.';
        $redirectMonth = date('Y-m', strtotime($transaction['transaction_date']));
        header("Location: layer_feeds.php?month={$redirectMonth}");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
}

// Handle transaction edit (owner only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_transaction']) && $isOwner) {
    $transactionId = $_POST['transaction_id'];
    $newItemId = $_POST['feed_item'];
    $newType = $_POST['transaction_type'];
    $newQuantity = (float)$_POST['quantity'];
    $newDate = $_POST['transaction_date'];
    $newRemarks = $_POST['remarks'];

    try {
        $pdo->beginTransaction();

        $transStmt = $pdo->prepare("SELECT * FROM stock_transactions WHERE id = ? AND farm_id = ? AND farm_type = 'poultry'");
        $transStmt->execute([$transactionId, $tenantFarmId]);
        $existing = $transStmt->fetch();

        if (!$existing) {
            throw new Exception('Transaction not found.');
        }

        $oldItemStmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE");
        $oldItemStmt->execute([$existing['stock_item_id'], $tenantFarmId]);
        $oldItem = $oldItemStmt->fetch();

        if (!$oldItem) {
            throw new Exception('Original feed item not found.');
        }

        $revertedStock = $oldItem['current_stock'];
        $revertedStock += $existing['transaction_type'] === 'received'
            ? -$existing['quantity']
            : $existing['quantity'];

        if ($revertedStock < 0) {
            throw new Exception('Cannot edit because reverting the previous transaction would create negative stock.');
        }

        $pdo->prepare("UPDATE stock_items SET current_stock = ? WHERE id = ? AND farm_id = ?")
            ->execute([$revertedStock, $oldItem['id'], $tenantFarmId]);

        $newItemStmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE");
        $newItemStmt->execute([$newItemId, $tenantFarmId]);
        $newItem = $newItemStmt->fetch();

        if (!$newItem) {
            throw new Exception('Selected feed item not found.');
        }

        $baseStock = $newItemId == $oldItem['id'] ? $revertedStock : $newItem['current_stock'];

        if ($newType === 'received') {
            $calculatedNewStock = $baseStock + $newQuantity;
        } else {
            if ($newQuantity > $baseStock) {
                throw new Exception("Insufficient stock. Available: {$baseStock} {$newItem['unit']}");
            }
            $calculatedNewStock = $baseStock - $newQuantity;
        }

        $pdo->prepare("UPDATE stock_items SET current_stock = ? WHERE id = ? AND farm_id = ?")
            ->execute([$calculatedNewStock, $newItemId, $tenantFarmId]);

        $updateTrans = $pdo->prepare("UPDATE stock_transactions
            SET stock_item_id = ?, transaction_type = ?, quantity = ?, previous_stock = ?, new_stock = ?, transaction_date = ?, remarks = ?
            WHERE id = ? AND farm_id = ?");
        $updateTrans->execute([
            $newItemId,
            $newType,
            $newQuantity,
            $baseStock,
            $calculatedNewStock,
            $newDate,
            $newRemarks,
            $transactionId,
            $tenantFarmId
        ]);

        $pdo->commit();
        $_SESSION['success'] = 'Transaction updated successfully.';
        $redirectMonth = date('Y-m', strtotime($newDate));
        header("Location: layer_feeds.php?month={$redirectMonth}");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include(__DIR__ . '/../navbar_head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layer Feeds Record - Renee Farms</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.2.9/css/responsive.bootstrap5.min.css">
    <style>
        #feedsTable thead th {
            background-color: #198754;
            color: #ffffff;
        }

        #feedsTable_wrapper .dataTables_filter label {
            color: #ffffff;
        }
    </style>
</head>
<body class="poultry-page">
    <?php include(__DIR__ . '/../navbar.php'); ?>

    <div class="container-fluid mt-4 poultry-shell">
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card poultry-panel">
                    <div class="card-header poultry-hero d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h4 class="mb-0">
                            <i class="bi bi-bucket"></i> 
                            Layer Feeds Record - <?php echo date('F Y', strtotime($yearMonth)); ?>
                        </h4>
                        <div class="d-flex flex-wrap gap-2">
                            <input type="date" class="form-control js-calendar-input" id="monthSelector" 
                                   value="<?php echo $monthSelectorDate; ?>" style="width: 200px;">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                                <i class="bi bi-plus-circle"></i> New Transaction
                            </button>
                        </div>
                    </div>
                    
                    <!-- Current Stock Summary -->
                    <div class="card-body">
                        <div class="smart-poultry-note p-3 mb-4 d-flex gap-3 align-items-start">
                            <i class="bi bi-stars fs-4"></i>
                            <div>
                                <div class="fw-bold">Feed stock intelligence</div>
                                <div class="small">These stock cards can power reorder alerts, usage forecasting and low-stock WhatsApp/SMS notifications.</div>
                            </div>
                        </div>
                        <h5 class="mb-3">Current Feed Stock</h5>
                        <div class="row mb-4">
                            <?php foreach ($feedItems as $item):
                                $stockPercent = ($item['current_stock'] / ($item['min_stock_level'] * 2)) * 100;
                                $cardClass = $item['current_stock'] <= $item['min_stock_level'] ? 'border-danger' :
                                            ($stockPercent <= 50 ? 'border-warning' : 'border-success');
                            ?>
                            <div class="col-md-3 mb-3 d-flex">
                                <div class="card stock-card <?php echo $cardClass; ?> h-100 w-100">
                                    <div class="card-body text-center">
                                        <h6 class="card-title"><?php echo $item['item_name']; ?></h6>
                                        <div class="mb-2">
                                            <span class="display-6 fw-bold <?php echo $item['current_stock'] <= $item['min_stock_level'] ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo $item['current_stock']; ?>
                                            </span>
                                            <small class="text-muted d-block"><?php echo $item['unit']; ?></small>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar <?php echo $item['current_stock'] <= $item['min_stock_level'] ? 'bg-danger' : 'bg-success'; ?>"
                                                 style="width: <?php echo min($stockPercent, 100); ?>%"></div>
                                        </div>
                                        <small class="text-muted">Min: <?php echo $item['min_stock_level']; ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Monthly Summary -->
                        <?php
                        $monthlySummary = [
                            'received' => 0,
                            'used' => 0,
                            'balance' => 0
                        ];

                        foreach ($transactions as $trans) {
                            if ($trans['transaction_type'] == 'received') {
                                $monthlySummary['received'] += $trans['quantity'];
                            } else {
                                $monthlySummary['used'] += $trans['quantity'];
                            }
                        }
                        $monthlySummary['balance'] = $monthlySummary['received'] - $monthlySummary['used'];
                        ?>

                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card bg-success text-white">
                                    <div class="card-body text-center">
                                        <h6>Received This Month</h6>
                                        <h3>+<?php echo number_format($monthlySummary['received'], 2); ?></h3>
                                        <small>Bags</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-danger text-white">
                                    <div class="card-body text-center">
                                        <h6>Used This Month</h6>
                                        <h3>-<?php echo number_format($monthlySummary['used'], 2); ?></h3>
                                        <small>Bags</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-info text-white">
                                    <div class="card-body text-center">
                                        <h6>Net Change</h6>
                                        <h3><?php echo $monthlySummary['balance'] >= 0 ? '+' : ''; ?><?php echo number_format($monthlySummary['balance'], 2); ?></h3>
                                        <small>Bags</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Transactions Table -->
                        <h5>Monthly Transactions</h5>
                        <div class="table-responsive">
                            <table id="feedsTable" class="table table-striped table-hover align-middle poultry-table">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Feed Item</th>
                                        <th>Type</th>
                                        <th>Quantity</th>
                                        <th>Previous Stock</th>
                                        <th>New Stock</th>
                                        <th>Remarks</th>
                                        <th>Recorded By</th>
                                        <?php if ($isOwner): ?>
                                            <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $trans): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($trans['transaction_date'])); ?></td>
                                        <td><?php echo $trans['item_name']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $trans['transaction_type'] == 'received' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($trans['transaction_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fw-bold <?php echo $trans['transaction_type'] == 'received' ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $trans['transaction_type'] == 'received' ? '+' : '-'; ?>
                                                <?php echo $trans['quantity']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $trans['previous_stock']; ?></td>
                                        <td class="fw-bold"><?php echo $trans['new_stock']; ?></td>
                                        <td><?php echo $trans['remarks'] ?: '--'; ?></td>
                                        <td><?php echo $trans['full_name']; ?></td>
                                        <?php if ($isOwner): ?>
                                            <td>
                                                <?php if (!empty($trans['consumption_link_id'])): ?>
                                                    <span class="badge bg-info text-dark">Managed by daily record</span>
                                                <?php else: ?>
                                                <div class="d-flex gap-2">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-primary edit-transaction"
                                                        data-id="<?php echo $trans['id']; ?>"
                                                        data-date="<?php echo date('Y-m-d', strtotime($trans['transaction_date'])); ?>"
                                                        data-item="<?php echo $trans['stock_item_id']; ?>"
                                                        data-type="<?php echo $trans['transaction_type']; ?>"
                                                        data-quantity="<?php echo $trans['quantity']; ?>"
                                                        data-remarks="<?php echo htmlspecialchars($trans['remarks'], ENT_QUOTES); ?>"
                                                    >
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('Delete this transaction?');">
                                                        <input type="hidden" name="transaction_id" value="<?php echo $trans['id']; ?>">
                                                        <button type="submit" name="delete_transaction" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Transaction Modal -->
    <div class="modal fade" id="addTransactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Record Feed Transaction</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="transaction_date" class="form-control"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Feed Item</label>
                            <select name="feed_item" class="form-select" required>
                                <option value="">Select Feed</option>
                                <?php foreach ($feedItems as $item): ?>
                                <option value="<?php echo $item['id']; ?>">
                                    <?php echo $item['item_name']; ?>
                                    (Current: <?php echo $item['current_stock']; ?> <?php echo $item['unit']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row align-items-end g-3">
                            <div class="col-md-6">
                                <label class="form-label">Transaction Type</label>
                                <select class="form-select" disabled>
                                    <option selected>⬇ Used Stock (-)</option>
                                </select>
                                <input type="hidden" name="transaction_type" value="used">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Quantity <small class="text-danger">(will be deducted)</small></label>
                                <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required>
                            </div>
                        </div>

                        <div class="mt-3">
                            <div class="alert alert-info mb-3 py-2">
                                To add stock, use the <strong>Update Stock</strong> action.
                            </div>
                            <label class="form-label">Remarks</label>
                            <input type="text" name="remarks" class="form-control"
                                   placeholder="Optional remarks">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_transaction" class="btn btn-primary">Save Transaction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Transaction Modal -->
    <div class="modal fade" id="editTransactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="transaction_id" id="editTransactionId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Feed Transaction</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Date</label>
                            <input type="date" name="transaction_date" id="editTransactionDate" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label>Feed Item</label>
                            <select name="feed_item" id="editFeedItem" class="form-select" required>
                                <option value="">Select Feed</option>
                                <?php foreach ($feedItems as $item): ?>
                                <option value="<?php echo $item['id']; ?>">
                                    <?php echo $item['item_name']; ?>
                                    (Current: <?php echo $item['current_stock']; ?> <?php echo $item['unit']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Transaction Type</label>
                                <select name="transaction_type" id="editTransactionType" class="form-select" required>
                                    <option value="received">Received Stock (+)</option>
                                    <option value="used">Used Stock (-)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Quantity</label>
                                <input type="number" name="quantity" id="editQuantity" class="form-control"
                                       step="0.01" min="0.01" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label>Remarks</label>
                            <input type="text" name="remarks" id="editRemarks" class="form-control"
                                   placeholder="Optional remarks">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_transaction" class="btn btn-primary">Update Transaction</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.2.9/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.2.9/js/responsive.bootstrap5.min.js"></script> -->

     <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/jquery/jquery.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/jquery.dataTables.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/dataTables.bootstrap5.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables-responsive/js/dataTables.responsive.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/js/main.js'); ?>"></script>
 <script src="<?php echo BASE_URL; ?>/assets/js/edit-modal.js"></script>

    <script>
    $(document).ready(function() {
        $('#feedsTable').DataTable({
            order: [[0, 'desc']],
            responsive: true,
            columnDefs: [
                { responsivePriority: 1, targets: 0 },
                { responsivePriority: 2, targets: -1 }
            ]
        });
        
        // Month selector
        $('#monthSelector').change(function() {
            window.location.href = 'layer_feeds.php?month=' + this.value.substring(0, 7);
        });

        $('.edit-transaction').on('click', function() {
            const button = $(this);
            $('#editTransactionId').val(button.data('id'));
            $('#editTransactionDate').val(button.data('date'));
            $('#editFeedItem').val(button.data('item'));
            $('#editTransactionType').val(button.data('type'));
            $('#editQuantity').val(button.data('quantity'));
            $('#editRemarks').val(button.data('remarks'));
            $('#editTransactionModal').modal('show');
        });
    });
    </script>
</body>
</html>
