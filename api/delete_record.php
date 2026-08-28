<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/api_helpers.php');
requireLogin();
require_http_method('POST');
require_csrf_token();
require_rate_limit('delete_record', 30, 60);

$type = $_POST['type'] ?? null;
$id = $_POST['id'] ?? null;
if (!$type || !$id) {
    send_json(['success' => false, 'error' => 'type and id are required'], 400);
}

if (($type === 'layer' || $type === 'broiler') && !checkAccess('poultry')) {
    send_json(['success' => false, 'error' => 'Unauthorized for poultry records'], 403);
}

try {
    $pdo->beginTransaction();
    if ($type === 'layer') {
        $table = 'layer_daily_records';
    } elseif ($type === 'broiler') {
        $table = 'broiler_daily_records';
    } else {
        send_json(['success' => false, 'error' => 'Unsupported record type'], 400);
    }
    
    $farmId = requireCurrentFarmId();
    $recordStmt = $pdo->prepare("SELECT feed_stock_transaction_id FROM {$table} WHERE id = ? AND farm_id = ? FOR UPDATE");
    $recordStmt->execute([$id, $farmId]);
    $record = $recordStmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) throw new RuntimeException('Record not found.');
    reverseDailyFeedConsumption($pdo, $farmId, $record['feed_stock_transaction_id'] ? (int)$record['feed_stock_transaction_id'] : null);
    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = ? AND farm_id = ?");
    $stmt->execute([$id, $farmId]);
    $pdo->commit();
    
    send_json(['success' => true, 'message' => 'Record deleted successfully']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    log_app_error('delete_record_failed', ['error' => $e->getMessage(), 'type' => $type, 'id' => $id]);
    send_json(['success' => false, 'error' => $e->getMessage()], 400);
}
?>
