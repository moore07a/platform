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
    if ($type === 'layer') {
        $stmt = $pdo->prepare("DELETE FROM layer_daily_records WHERE id = ? AND farm_id = ?");
    } elseif ($type === 'broiler') {
        $stmt = $pdo->prepare("DELETE FROM broiler_daily_records WHERE id = ? AND farm_id = ?");
    } else {
        send_json(['success' => false, 'error' => 'Unsupported record type'], 400);
    }
    
    $stmt->execute([$id, requireCurrentFarmId()]);
    
    send_json(['success' => true, 'message' => 'Record deleted successfully']);
} catch (Exception $e) {
    log_app_error('delete_record_failed', ['error' => $e->getMessage(), 'type' => $type, 'id' => $id]);
    send_json(['success' => false, 'error' => $e->getMessage()], 400);
}
?>
