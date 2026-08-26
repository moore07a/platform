<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/api_helpers.php');
requireLogin();
require_http_method('POST');
require_csrf_token();
require_rate_limit('delete_sale', 30, 60);

$userType = getUserType();
if (!isPlatformOwner() && !hasRole('farm_admin')) {
    send_json(['success' => false, 'error' => 'You do not have permission to delete sales.'], 403);
}

$saleId = $_POST['id'] ?? null;
if (!$saleId) {
    send_json(['success' => false, 'error' => 'Sale ID is required.'], 400);
}

try {
    $stmt = $pdo->prepare("DELETE FROM sales_records WHERE id = ? AND farm_id = ?");
    $stmt->execute([$saleId, requireCurrentFarmId()]);

    send_json(['success' => true, 'message' => 'Sale record deleted successfully']);
} catch (Exception $e) {
    log_app_error('delete_sale_failed', ['error' => $e->getMessage(), 'sale_id' => $saleId]);
    send_json(['success' => false, 'error' => $e->getMessage()], 400);
}
?>
