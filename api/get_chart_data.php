<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

header('Content-Type: application/json');

$type = $_GET['type'] ?? 'profit_loss';
$period = $_GET['period'] ?? 'month';

switch ($type) {
    case 'profit_loss':
        echo json_encode(getProfitLossData($period));
        break;
    
    case 'sales':
        echo json_encode(getSalesData($period));
        break;
    
    case 'expenses':
        echo json_encode(getExpenseData($period));
        break;
    
    case 'stock':
        echo json_encode(getStockData($period));
        break;
    
    case 'production':
        echo json_encode(getProductionData($period));
        break;
    
    default:
        echo json_encode(['error' => 'Invalid chart type']);
}

function getProfitLossData($period) {
    global $pdo;
    
    $dateFormat = $period == 'year' ? '%Y' : '%Y-%m';
    $limit = $period == 'year' ? 12 : 30;
    
    $query = "SELECT 
                DATE_FORMAT(s.sale_date, ?) as period,
                SUM(s.total_amount) as total_sales,
                COALESCE(SUM(e.amount), 0) as total_expenses,
                SUM(s.total_amount) - COALESCE(SUM(e.amount), 0) as net_profit
              FROM sales_records s
              LEFT JOIN farm_expenses e ON DATE_FORMAT(s.sale_date, ?) = DATE_FORMAT(e.expense_date, ?)
              WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY DATE_FORMAT(s.sale_date, ?)
              ORDER BY period";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$dateFormat, $dateFormat, $dateFormat, $limit, $dateFormat]);
    $data = $stmt->fetchAll();
    
    $labels = [];
    $values = [];
    
    foreach ($data as $row) {
        $labels[] = $period == 'year' ? $row['period'] : date('M Y', strtotime($row['period'] . '-01'));
        $values[] = $row['net_profit'];
    }
    
    return ['labels' => $labels, 'values' => $values];
}

function getSalesData($period) {
    global $pdo;
    
    $dateFormat = $period == 'year' ? '%Y' : '%Y-%m';
    $limit = $period == 'year' ? 12 : 30;
    
    $query = "SELECT 
                DATE_FORMAT(sale_date, ?) as period,
                farm_type,
                SUM(total_amount) as total_sales
              FROM sales_records
              WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY DATE_FORMAT(sale_date, ?), farm_type
              ORDER BY period";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$dateFormat, $limit, $dateFormat]);
    $data = $stmt->fetchAll();
    
    $labels = [];
    $poultry = [];
    $ruminant = [];
    $currentPeriod = null;
    
    foreach ($data as $row) {
        if ($row['period'] != $currentPeriod) {
            $labels[] = $period == 'year' ? $row['period'] : date('M Y', strtotime($row['period'] . '-01'));
            $currentPeriod = $row['period'];
            $poultry[] = 0;
            $ruminant[] = 0;
        }
        
        $index = count($labels) - 1;
        if ($row['farm_type'] == 'poultry') {
            $poultry[$index] = $row['total_sales'];
        } else {
            $ruminant[$index] = $row['total_sales'];
        }
    }
    
    return ['labels' => $labels, 'poultry' => $poultry, 'ruminant' => $ruminant];
}

function getExpenseData($period) {
    global $pdo;
    
    $dateFormat = $period == 'year' ? '%Y' : '%Y-%m';
    $limit = $period == 'year' ? 12 : 30;
    
    $query = "SELECT
                category,
                SUM(amount * unit) as total_amount
              FROM farm_expenses
              WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY category
              ORDER BY total_amount DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$limit]);
    $data = $stmt->fetchAll();
    
    $labels = [];
    $values = [];
    
    foreach ($data as $row) {
        $labels[] = ucfirst($row['category']);
        $values[] = $row['total_amount'];
    }
    
    return ['labels' => $labels, 'values' => $values];
}

function getStockData($period) {
    global $pdo;
    
    $limit = $period == 'week' ? 7 : 30;
    
    $query = "SELECT 
                DATE(t.transaction_date) as date,
                s.item_name,
                t.new_stock
              FROM stock_transactions t
              JOIN stock_items s ON t.stock_item_id = s.id
              WHERE t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND t.id IN (
                  SELECT MAX(id) 
                  FROM stock_transactions 
                  WHERE transaction_date = DATE(t.transaction_date)
                  GROUP BY stock_item_id
              )
              ORDER BY t.transaction_date, s.item_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$limit]);
    $data = $stmt->fetchAll();
    
    // Organize data by item
    $items = [];
    $dates = [];
    $datasets = [];
    
    foreach ($data as $row) {
        if (!in_array($row['date'], $dates)) {
            $dates[] = $row['date'];
        }
        if (!isset($items[$row['item_name']])) {
            $items[$row['item_name']] = [];
        }
        $items[$row['item_name']][$row['date']] = $row['new_stock'];
    }
    
    // Format dates
    $labels = array_map(function($date) {
        return date('d M', strtotime($date));
    }, $dates);
    
    // Create datasets
    $colors = [
        'rgb(255, 99, 132)',
        'rgb(54, 162, 235)',
        'rgb(255, 205, 86)',
        'rgb(75, 192, 192)',
        'rgb(153, 102, 255)',
        'rgb(201, 203, 207)'
    ];
    
    $colorIndex = 0;
    foreach ($items as $itemName => $itemData) {
        $dataPoints = [];
        foreach ($dates as $date) {
            $dataPoints[] = $itemData[$date] ?? null;
        }
        
        $datasets[] = [
            'label' => $itemName,
            'data' => $dataPoints,
            'borderColor' => $colors[$colorIndex % count($colors)],
            'backgroundColor' => str_replace('rgb', 'rgba', $colors[$colorIndex % count($colors)]) . ', 0.2)',
            'fill' => false
        ];
        
        $colorIndex++;
    }
    
    return ['labels' => $labels, 'datasets' => $datasets];
}

function getProductionData($period) {
    global $pdo;
    
    $limit = $period == 'month' ? 30 : 7;
    
    $query = "SELECT 
                record_date,
                egg_production,
                laying_rate
              FROM layer_daily_records
              WHERE record_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              ORDER BY record_date";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$limit]);
    $data = $stmt->fetchAll();
    
    $labels = [];
    $eggs = [];
    $rates = [];
    
    foreach ($data as $row) {
        $labels[] = date('d M', strtotime($row['record_date']));
        $eggs[] = $row['egg_production'];
        $rates[] = $row['laying_rate'];
    }
    
    return ['labels' => $labels, 'eggs' => $eggs, 'rates' => $rates];
}
?>