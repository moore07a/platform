<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

header('Content-Type: application/json');

$farmType = $_GET['farm_type'] ?? 'both';
$tenantFarmId = requireCurrentFarmId();

// Get low stock count
if ($farmType === 'both') {
    $lowStockQuery = "SELECT COUNT(*) as low_stock_count
                      FROM stock_items
                      WHERE farm_id = ? AND farm_type IN ('poultry', 'ruminant', 'both')
                      AND current_stock <= min_stock_level";
    $lowStockStmt = $pdo->prepare($lowStockQuery);
    $lowStockStmt->execute([$tenantFarmId]);
} else {
    $lowStockQuery = "SELECT COUNT(*) as low_stock_count
                      FROM stock_items
                      WHERE farm_id = ? AND farm_type IN (?, 'both')
                      AND current_stock <= min_stock_level";
    $lowStockStmt = $pdo->prepare($lowStockQuery);
    $lowStockStmt->execute([$tenantFarmId, $farmType]);
}
$lowStockCount = $lowStockStmt->fetchColumn();

// Get total stock value
$valueQuery = "SELECT SUM(current_stock * 100) as total_value FROM stock_items 
               WHERE farm_id = ? AND farm_type IN (?, 'both')";
$valueStmt = $pdo->prepare($valueQuery);
$valueStmt->execute([$tenantFarmId, $farmType]);
$totalValue = $valueStmt->fetchColumn();

// Get recent stock changes
$changesQuery = "SELECT COUNT(*) as recent_changes FROM stock_transactions 
                 WHERE farm_id = ? AND farm_type = ? AND transaction_date = CURDATE()";
$changesStmt = $pdo->prepare($changesQuery);
$changesStmt->execute([$tenantFarmId, $farmType]);
$recentChanges = $changesStmt->fetchColumn();

echo json_encode([
    'updated' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'low_stock_count' => $lowStockCount,
    'total_stock_value' => $totalValue,
    'recent_changes' => $recentChanges
]);
?>
