<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/api_helpers.php');
requireLogin();
require_http_method('POST');
require_csrf_token();
require_rate_limit('update_stock', 80, 60);

$data = json_input();

if (!isset($data['item_id'], $data['type'], $data['quantity'])) {
    send_json(['success' => false, 'error' => 'Missing required fields'], 400);
}

try {
    $pdo->beginTransaction();
    
    // Get current stock
    $farmId = requireCurrentFarmId();
    $stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE");
    $stmt->execute([$data['item_id'], $farmId]);
    $item = $stmt->fetch();
    
    if (!$item) {
        throw new Exception('Item not found');
    }

    $itemFarmType = $item['farm_type'];
    $canUpdateItem = isPlatformOwner()
        || ($itemFarmType === 'poultry' && checkAccess('poultry'))
        || ($itemFarmType === 'ruminant' && checkAccess('ruminant'))
        || ($itemFarmType === 'both' && (checkAccess('poultry') || checkAccess('ruminant')));
    if (!$canUpdateItem) {
        $pdo->rollBack();
        send_json(['success' => false, 'error' => 'Unauthorized for this inventory item'], 403);
    }
    
    // Calculate new stock
    $previous_stock = (float) $item['current_stock'];
    $quantity = (float) $data['quantity'];
    
    if ($data['type'] === 'received') {
        $new_stock = $previous_stock + $quantity;
    } elseif ($data['type'] === 'used') {
        if ($quantity > $previous_stock) {
            throw new Exception('Insufficient stock. Available: ' . $previous_stock);
        }
        $new_stock = $previous_stock - $quantity;
    } else {
        throw new Exception('Invalid transaction type');
    }
    
    // Update stock item
    $updateStmt = $pdo->prepare("UPDATE stock_items SET current_stock = ? WHERE id = ? AND farm_id = ?");
    $updateStmt->execute([$new_stock, $data['item_id'], $farmId]);
    
    // Record transaction
    $transStmt = $pdo->prepare("INSERT INTO stock_transactions 
        (stock_item_id, transaction_type, quantity, previous_stock, new_stock, 
         transaction_date, remarks, user_id, farm_type, farm_id)
        VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)");
    $transStmt->execute([
        $data['item_id'],
        $data['type'],
        $quantity,
        $previous_stock,
        $new_stock,
        $data['remarks'] ?? null,
        $_SESSION['user_id'],
        $itemFarmType,
        $farmId
    ]);
    
    $pdo->commit();
    
    send_json([
        'success' => true,
        'message' => 'Stock updated successfully',
        'new_stock' => $new_stock
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_app_error('update_stock_failed', ['error' => $e->getMessage(), 'payload' => $data]);
    send_json([
        'success' => false,
        'error' => $e->getMessage()
    ], 400);
}
?>
