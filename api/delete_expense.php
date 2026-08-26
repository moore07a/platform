<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/api_helpers.php');
requireLogin();
require_http_method('POST');
require_csrf_token();
require_rate_limit('delete_expense', 30, 60);

$userType = getUserType();

if (!isPlatformOwner() && !hasRole('farm_admin')) {
    send_json(['success' => false, 'error' => 'Unauthorized: Only owners can delete expenses.'], 403);
}

$expenseId = $_POST['id'] ?? null;
if (!$expenseId) {
    send_json(['success' => false, 'error' => 'Expense ID is required.'], 400);
}

try {
    $stmt = $pdo->prepare("DELETE FROM farm_expenses WHERE id = ? AND farm_id = ?");
    $stmt->execute([$expenseId, requireCurrentFarmId()]);
    
    send_json(['success' => true, 'message' => 'Expense record deleted successfully']);
} catch (Exception $e) {
    log_app_error('delete_expense_failed', ['error' => $e->getMessage(), 'expense_id' => $expenseId]);
    send_json(['success' => false, 'error' => $e->getMessage()], 400);
}
?>
