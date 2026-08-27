<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

header('Content-Type: application/json');

if (isset($_GET['item_id'])) {
    $itemId = $_GET['item_id'];
    $days = $_GET['days'] ?? 30;
    
    $dateLimit = date('Y-m-d', strtotime("-$days days"));
    
    $query = "SELECT t.*, s.item_name, s.unit, u.full_name 
              FROM stock_transactions t
              JOIN stock_items s ON t.stock_item_id = s.id
              LEFT JOIN users u ON t.user_id = u.id AND u.farm_id = t.farm_id
              WHERE t.stock_item_id = ? AND t.farm_id = ? AND s.farm_id = ?
              AND t.transaction_date >= ?
              ORDER BY t.transaction_date DESC, t.id DESC";
    
    $stmt = $pdo->prepare($query);
    $farmId = requireCurrentFarmId();
    $stmt->execute([$itemId, $farmId, $farmId, $dateLimit]);
    $transactions = $stmt->fetchAll();

    // Ensure transactions are consistently ordered (newest first)
    usort($transactions, function($a, $b) {
        $dateCompare = strtotime($b['transaction_date']) <=> strtotime($a['transaction_date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
    });
    
    // Format data for chart
    $chartData = [];
    $currentStock = null;
    
    // Get current stock
    $stockStmt = $pdo->prepare("SELECT current_stock FROM stock_items WHERE id = ? AND farm_id = ?");
    $stockStmt->execute([$itemId, $farmId]);
    $currentStock = $stockStmt->fetchColumn();
    
    // Prepare data for response
    $response = [
        'transactions' => $transactions,
        'current_stock' => $currentStock,
        'chart_data' => prepareChartData($transactions),
        'summary' => prepareSummary($transactions)
    ];
    
    echo json_encode($response);
} else {
    echo json_encode(['error' => 'Item ID required']);
}

function prepareChartData($transactions) {
    $dates = [];
    $stocks = [];

    // Sort by date ascending for chart
    usort($transactions, function($a, $b) {
        $dateCompare = strtotime($a['transaction_date']) <=> strtotime($b['transaction_date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
    });
    
    $runningTotal = isset($transactions[0]) ? (float)$transactions[0]['previous_stock'] : 0;
    foreach ($transactions as $trans) {
        $dates[] = date('d M', strtotime($trans['transaction_date']));

        if ($trans['transaction_type'] == 'received') {
            $runningTotal += $trans['quantity'];
        } else {
            $runningTotal -= $trans['quantity'];
        }
        
        $stocks[] = $runningTotal;
    }
    
    return [
        'labels' => $dates,
        'datasets' => [[
            'label' => 'Stock Level',
            'data' => $stocks,
            'borderColor' => 'rgb(75, 192, 192)',
            'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
            'fill' => true
        ]]
    ];
}

function prepareSummary($transactions) {
    $summary = [
        'total_received' => 0,
        'total_used' => 0,
        'transaction_count' => count($transactions),
        'last_transaction' => null
    ];
    
    if (!empty($transactions)) {
        $summary['last_transaction'] = $transactions[0];
    }
    
    foreach ($transactions as $trans) {
        if ($trans['transaction_type'] == 'received') {
            $summary['total_received'] += $trans['quantity'];
        } else {
            $summary['total_used'] += $trans['quantity'];
        }
    }
    
    return $summary;
}
?>
