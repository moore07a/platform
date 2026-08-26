<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $itemId = $_GET['id'];
    
    $stmt = $pdo->prepare("SELECT * FROM stock_items WHERE id = ? AND farm_id = ?");
    $stmt->execute([$itemId, requireCurrentFarmId()]);
    $item = $stmt->fetch();
    
    if ($item) {
        echo json_encode($item);
    } else {
        echo json_encode(['error' => 'Item not found']);
    }
} else {
    echo json_encode(['error' => 'Item ID required']);
}
?>
