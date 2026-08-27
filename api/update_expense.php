<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/api_helpers.php');
requireLogin();
require_once(__DIR__ . '/../includes/functions.php');
require_http_method('POST');
require_csrf_token();
require_rate_limit('update_expense', 60, 60);

if (!isPlatformOwner() && !hasRole('farm_admin')) {
    send_json(['success' => false, 'error' => 'Unauthorized: Only owners can edit expenses.'], 403);
}

$requiredFields = ['expense_id', 'expense_date', 'farm_type', 'category', 'amount', 'unit'];

foreach ($requiredFields as $field) {
    if (empty($_POST[$field])) {
        send_json(['success' => false, 'error' => 'Missing required field: ' . $field], 400);
    }
}

$expenseId = $_POST['expense_id'];
$expenseDate = $_POST['expense_date'];
$farmType = $_POST['farm_type'];
if (!in_array($farmType, allowedFarmTypes(), true)) {
    send_json(['success' => false, 'error' => 'That farm type is not enabled for this farm.'], 422);
}
$poultryCategory = $_POST['poultry_category'] ?? null;
$category = $_POST['category'];
$amount = $_POST['amount'];
$unit = $_POST['unit'];
$description = $_POST['description'] ?? '';

try {
    $stmt = $pdo->prepare("UPDATE farm_expenses
                           SET expense_date = ?, farm_type = ?, poultry_category = ?, category = ?, amount = ?, unit = ?, description = ?
                           WHERE id = ? AND farm_id = ?");
    $stmt->execute([$expenseDate, $farmType, $poultryCategory, $category, $amount, $unit, $description, $expenseId, requireCurrentFarmId()]);

    send_json(['success' => true, 'message' => 'Expense updated successfully']);
} catch (Exception $e) {
    log_app_error('update_expense_failed', ['error' => $e->getMessage(), 'expense_id' => $expenseId ?? null]);
    send_json(['success' => false, 'error' => $e->getMessage()], 500);
}
?>
