<?php
$appEnv = getenv('APP_ENV') ?: 'production';
if ($appEnv === 'local' || $appEnv === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
}
?>

<?php require_once(__DIR__ . '/init.php'); ?>
<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/includes/functions.php');
requireLogin();

$userType = getUserType();
$farmAccess = getUserFarmType();
$farmAccessLabel = hasRole('sales_rep') ? 'sales' : $farmAccess;
if ($farmAccess === 'all') {
    $farmAccess = 'both';
}
$includeGeneralSales = farmHasModule('sales')
    && in_array($farmAccess, ['poultry', 'ruminant'], true);

// Get current stock levels
$tenantFarmId = requireCurrentFarmId();
if ($farmAccess === 'both') {
    $stockQuery = "SELECT * FROM stock_items 
                   WHERE farm_id = ? AND farm_type IN ('poultry', 'ruminant', 'both')
                   AND is_active = 1 
                   ORDER BY current_stock ASC";
    $stockStmt = $pdo->prepare($stockQuery);
    $stockStmt->execute([$tenantFarmId]);
} else {
    $stockQuery = "SELECT * FROM stock_items WHERE farm_id = ? AND farm_type IN (?, 'both') AND is_active = 1 ORDER BY current_stock ASC";
    $stockStmt = $pdo->prepare($stockQuery);
    $stockStmt->execute([$tenantFarmId, $farmAccess]);
}
$stockItems = $stockStmt->fetchAll();

// Get today's transactions
$today = date('Y-m-d');
if ($farmAccess === 'both') {
    $transQuery = "SELECT t.*, s.item_name, s.unit FROM stock_transactions t
                   JOIN stock_items s ON t.stock_item_id = s.id AND s.is_active = 1
                   WHERE t.farm_id = ? AND s.farm_id = ? AND t.transaction_date = ?
                   ORDER BY t.id DESC LIMIT 10";
    $transStmt = $pdo->prepare($transQuery);
    $transStmt->execute([$tenantFarmId, $tenantFarmId, $today]);
} else {
    $transQuery = "SELECT t.*, s.item_name, s.unit FROM stock_transactions t
                   JOIN stock_items s ON t.stock_item_id = s.id AND s.is_active = 1
                   WHERE t.farm_id = ? AND s.farm_id = ? AND t.farm_type = ? AND t.transaction_date = ?
                   ORDER BY t.id DESC LIMIT 10";
    $transStmt = $pdo->prepare($transQuery);
    $transStmt->execute([$tenantFarmId, $tenantFarmId, $farmAccess, $today]);
}
$todayTransactions = $transStmt->fetchAll();

// Get low stock items
if ($farmAccess === 'both') {
    $lowStockQuery = "SELECT * FROM stock_items
                      WHERE farm_id = ? AND farm_type IN ('poultry', 'ruminant', 'both')
                      AND is_active = 1
                      AND current_stock <= min_stock_level";
    $lowStockStmt = $pdo->prepare($lowStockQuery);
    $lowStockStmt->execute([$tenantFarmId]);
} else {
    $lowStockQuery = "SELECT * FROM stock_items
                      WHERE farm_id = ? AND farm_type IN (?, 'both')
                      AND is_active = 1
                      AND current_stock <= min_stock_level";
    $lowStockStmt = $pdo->prepare($lowStockQuery);
    $lowStockStmt->execute([$tenantFarmId, $farmAccess]);
}
$lowStockItems = $lowStockStmt->fetchAll();

// Get recent sales
if ($farmAccess === 'both') {
    $salesQuery = "SELECT s.*, u.full_name as seller
                   FROM sales_records s
                   LEFT JOIN users u ON s.user_id = u.id AND u.farm_id = s.farm_id
                   WHERE s.farm_id = ? ORDER BY s.sale_date DESC, s.id DESC
                   LIMIT 5";
    $salesStmt = $pdo->prepare($salesQuery);
    $salesStmt->execute([$tenantFarmId]);
} else {
    $salesFarmTypePredicate = $includeGeneralSales
        ? "(s.farm_type = ? OR s.farm_type = 'general')"
        : 's.farm_type = ?';
    $salesQuery = "SELECT s.*, u.full_name as seller
                   FROM sales_records s
                   LEFT JOIN users u ON s.user_id = u.id AND u.farm_id = s.farm_id
                   WHERE s.farm_id = ? AND {$salesFarmTypePredicate}
                   ORDER BY s.sale_date DESC, s.id DESC
                   LIMIT 5";
    $salesStmt = $pdo->prepare($salesQuery);
    $salesStmt->execute([$tenantFarmId, $farmAccess]);
}
$recentSales = $salesStmt->fetchAll();

// Get recent expenses
$expenseQuery = "SELECT e.*, u.full_name
                 FROM farm_expenses e
                 LEFT JOIN users u ON e.user_id = u.id AND u.farm_id = e.farm_id WHERE e.farm_id = ?";
$expenseParams = [$tenantFarmId];

if ($farmAccess !== 'both') {
    $expenseQuery .= " AND e.farm_type = ?";
    $expenseParams[] = $farmAccess;
}

$expenseQuery .= " ORDER BY e.expense_date DESC, e.id DESC
                 LIMIT 5";

$expenseStmt = $pdo->prepare($expenseQuery);
$expenseStmt->execute($expenseParams);
$recentExpenses = $expenseStmt->fetchAll();

// Get recent daily records
if ($farmAccess === 'poultry' || $farmAccess === 'both') {
    $layerQuery = "SELECT * FROM layer_daily_records 
                   WHERE farm_id = ?
                   ORDER BY record_date DESC, id DESC LIMIT 1";
    $layerStmt = $pdo->prepare($layerQuery);
    $layerStmt->execute([$tenantFarmId]);
    $latestLayerRecord = $layerStmt->fetch();
    
    $broilerQuery = "SELECT * FROM broiler_daily_records 
                     WHERE farm_id = ?
                     ORDER BY record_date DESC, id DESC LIMIT 1";
    $broilerStmt = $pdo->prepare($broilerQuery);
    $broilerStmt->execute([$tenantFarmId]);
    $latestBroilerRecord = $broilerStmt->fetch();
}

if ($farmAccess === 'ruminant' || $farmAccess === 'both') {
    $ruminantQuery = "SELECT * FROM ruminant_daily_records
                      WHERE farm_id = ?
                      ORDER BY record_date DESC, id DESC LIMIT 1";
    $ruminantStmt = $pdo->prepare($ruminantQuery);
    $ruminantStmt->execute([$tenantFarmId]);
    $latestRuminantRecord = $ruminantStmt->fetch();
}

// Get active-cycle livestock totals for dashboard ticker
$poultryCurrentStock = [
    'Layer' => null,
    'Broiler' => null,
];
$ruminantCurrentStock = [
    'Cattle' => null,
    'Goat' => null,
    'Sheep' => null,
    'Other' => null,
];
$cycleTablesAvailable = ($pdo->query("SHOW TABLES LIKE 'production_cycles'")->rowCount() > 0);

if ($cycleTablesAvailable) {
    if ($farmAccess === 'poultry' || $farmAccess === 'both') {
        $poultryCycleStmt = $pdo->prepare(
            "SELECT id, production_type
             FROM production_cycles
             WHERE farm_id = ? AND farm_type = 'poultry' AND status = 'active'"
        );
        $poultryCycleStmt->execute([$tenantFarmId]);
        $poultryCycles = $poultryCycleStmt->fetchAll(PDO::FETCH_ASSOC);
        $latestLayerStmt = $pdo->prepare(
            "SELECT opening_stock, mortality
             FROM layer_daily_records
             WHERE cycle_id = ? AND farm_id = ?
             ORDER BY record_date DESC, id DESC
             LIMIT 1"
        );
        $latestBroilerStmt = $pdo->prepare(
            "SELECT opening_stock, mortality
             FROM broiler_daily_records
             WHERE cycle_id = ? AND farm_id = ?
             ORDER BY record_date DESC, id DESC
             LIMIT 1"
        );

        $layerTotal = 0;
        $broilerTotal = 0;
        $layerFound = false;
        $broilerFound = false;

        foreach ($poultryCycles as $cycle) {
            $cycleType = strtolower((string)$cycle['production_type']);
            $cycleId = (int)$cycle['id'];
            if ($cycleType === 'layer') {
                $latestLayerStmt->execute([$cycleId, $tenantFarmId]);
                $row = $latestLayerStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $layerFound = true;
                    $layerTotal += max(0, (int)$row['opening_stock'] - (int)$row['mortality']);
                }
            } elseif ($cycleType === 'broiler') {
                $latestBroilerStmt->execute([$cycleId, $tenantFarmId]);
                $row = $latestBroilerStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $broilerFound = true;
                    $broilerTotal += max(0, (int)$row['opening_stock'] - (int)$row['mortality']);
                }
            }
        }

        $poultryCurrentStock['Layer'] = $layerFound ? $layerTotal : null;
        $poultryCurrentStock['Broiler'] = $broilerFound ? $broilerTotal : null;
    }

    if ($farmAccess === 'ruminant' || $farmAccess === 'both') {
        $ruminantCycleStmt = $pdo->prepare(
            "SELECT id, production_type
             FROM production_cycles
             WHERE farm_id = ? AND farm_type = 'ruminant' AND status = 'active'"
        );
        $ruminantCycleStmt->execute([$tenantFarmId]);
        $ruminantCycles = $ruminantCycleStmt->fetchAll(PDO::FETCH_ASSOC);
        $latestCycleDateStmt = $pdo->prepare(
            "SELECT MAX(record_date) FROM ruminant_daily_records WHERE cycle_id = ? AND farm_id = ?"
        );
        $sumCycleStockStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(opening_stock - mortality), 0)
             FROM ruminant_daily_records
             WHERE cycle_id = ? AND farm_id = ? AND record_date = ?"
        );
        $ruminantTotals = [
            'cattle' => 0,
            'goat' => 0,
            'sheep' => 0,
            'other' => 0,
        ];
        $ruminantFound = [
            'cattle' => false,
            'goat' => false,
            'sheep' => false,
            'other' => false,
        ];

        foreach ($ruminantCycles as $cycle) {
            $cycleType = strtolower((string)$cycle['production_type']);
            if (!array_key_exists($cycleType, $ruminantTotals)) {
                $cycleType = 'other';
            }
            $cycleId = (int)$cycle['id'];
            $latestCycleDateStmt->execute([$cycleId, $tenantFarmId]);
            $latestDate = $latestCycleDateStmt->fetchColumn();
            if (!$latestDate) {
                continue;
            }
            $sumCycleStockStmt->execute([$cycleId, $tenantFarmId, $latestDate]);
            $cycleStock = (int)$sumCycleStockStmt->fetchColumn();
            $ruminantTotals[$cycleType] += max(0, $cycleStock);
            $ruminantFound[$cycleType] = true;
        }

        $ruminantCurrentStock['Cattle'] = $ruminantFound['cattle'] ? $ruminantTotals['cattle'] : null;
        $ruminantCurrentStock['Goat'] = $ruminantFound['goat'] ? $ruminantTotals['goat'] : null;
        $ruminantCurrentStock['Sheep'] = $ruminantFound['sheep'] ? $ruminantTotals['sheep'] : null;
        $ruminantCurrentStock['Other'] = $ruminantFound['other'] ? $ruminantTotals['other'] : null;
    }
}

// Load the user's previous login time (before the current session)
$lastLoginAt = $_SESSION['last_login_at'] ?? null;
if (!$lastLoginAt && isset($_SESSION['user_id'])) {
    $lastLoginStmt = $pdo->prepare("SELECT last_login_at FROM users WHERE id = ?");
    $lastLoginStmt->execute([$_SESSION['user_id']]);
    $lastLoginAt = $lastLoginStmt->fetchColumn();
    $_SESSION['last_login_at'] = $lastLoginAt;
}
$lastLoginDisplay = $lastLoginAt ? date('M j, g:i a', strtotime($lastLoginAt)) : 'First login';
$currentHour = (int) date('G');
$greetingText = $currentHour < 12 ? 'Good morning' : ($currentHour < 18 ? 'Good afternoon' : 'Good evening');

// Get profit/loss for current month. If the summarized table has no entry, fall back to
// calculating directly from sales and expenses so that manager dashboards still show data.
$month = date('Y-m');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

if ($farmAccess === 'both') {
    $profitQuery = "SELECT
                        SUM(total_sales) as total_sales,
                        SUM(total_expenses) as total_expenses,
                        SUM(profit) as net_profit
                    FROM profit_loss_summary
                    WHERE farm_id = ? AND month = ?";
    $profitStmt = $pdo->prepare($profitQuery);
    $profitStmt->execute([$tenantFarmId, $month]);
} else {
    $profitQuery = "SELECT *, profit AS net_profit FROM profit_loss_summary WHERE farm_id = ? AND month = ? AND farm_type = ?";
    $profitStmt = $pdo->prepare($profitQuery);
    $profitStmt->execute([$tenantFarmId, $month, $farmAccess]);
}
$profitData = $profitStmt->fetch();

// Fallback calculation when no summarized data exists for the current month/farm type
if (!$profitData || $profitData['net_profit'] === null) {
    $salesSql = "SELECT COALESCE(SUM(total_amount), 0) FROM sales_records WHERE farm_id = ? AND sale_date BETWEEN ? AND ?";
    $expenseSql = "SELECT COALESCE(SUM(amount * unit), 0) FROM farm_expenses WHERE farm_id = ? AND expense_date BETWEEN ? AND ?";
    $salesParams = [$tenantFarmId, $monthStart, $monthEnd];
    $expenseParams = [$tenantFarmId, $monthStart, $monthEnd];

    if ($farmAccess !== 'both') {
        $salesSql .= $includeGeneralSales
            ? " AND (farm_type = ? OR farm_type = 'general')"
            : " AND farm_type = ?";
        $expenseSql .= " AND farm_type = ?";
        $salesParams[] = $farmAccess;
        $expenseParams[] = $farmAccess;
    }

    $salesStmt = $pdo->prepare($salesSql);
    $salesStmt->execute($salesParams);
    $fallbackSales = (float) $salesStmt->fetchColumn();

    $expenseStmt = $pdo->prepare($expenseSql);
    $expenseStmt->execute($expenseParams);
    $fallbackExpenses = (float) $expenseStmt->fetchColumn();

    $profitData = [
        'total_sales' => $fallbackSales,
        'total_expenses' => $fallbackExpenses,
        'net_profit' => $fallbackSales - $fallbackExpenses,
    ];
}

// Calculate dashboard statistics
$totalStockItems = count($stockItems);
$lowStockCount = count($lowStockItems);
$netProfit = 0;
$monthlyExpenses = 0;

if ($profitData) {
    $netProfit = $profitData['net_profit'] ?? ($profitData['profit'] ?? 0);
    $monthlyExpenses = (float) ($profitData['total_expenses'] ?? 0);
}

// Get activity count for today
if ($farmAccess === 'both') {
    $activityQuery = "SELECT COUNT(*) as activity_count FROM (
                      SELECT id FROM stock_transactions WHERE farm_id = ? AND transaction_date = ?
                      UNION ALL
                      SELECT id FROM layer_daily_records WHERE farm_id = ? AND record_date = ?
                      UNION ALL
                      SELECT id FROM broiler_daily_records WHERE farm_id = ? AND record_date = ?
                      UNION ALL
                      SELECT id FROM ruminant_daily_records WHERE farm_id = ? AND record_date = ?
                      UNION ALL
                      SELECT id FROM farm_expenses WHERE farm_id = ? AND expense_date = ?
                      UNION ALL
                      SELECT id FROM sales_records WHERE farm_id = ? AND sale_date = ?
                      ) as activities";
    $activityStmt = $pdo->prepare($activityQuery);
    $activityStmt->execute([
        $tenantFarmId, $today,
        $tenantFarmId, $today,
        $tenantFarmId, $today,
        $tenantFarmId, $today,
        $tenantFarmId, $today,
        $tenantFarmId, $today
    ]);
} else {
    $activitySalesFarmTypePredicate = $includeGeneralSales
        ? "(farm_type = ? OR farm_type = 'general')"
        : 'farm_type = ?';
    $activityQuery = "SELECT COUNT(*) as activity_count FROM (
                      SELECT id FROM stock_transactions WHERE farm_id = ? AND farm_type = ? AND transaction_date = ?
                      UNION ALL
                      SELECT id FROM layer_daily_records WHERE farm_id = ? AND record_date = ?
                      UNION ALL
                      SELECT id FROM broiler_daily_records WHERE farm_id = ? AND record_date = ?
                      UNION ALL
                      SELECT id FROM ruminant_daily_records WHERE farm_id = ? AND record_date = ?
                      UNION ALL
                      SELECT id FROM farm_expenses WHERE farm_id = ? AND farm_type = ? AND expense_date = ?
                      UNION ALL
                      SELECT id FROM sales_records WHERE farm_id = ? AND {$activitySalesFarmTypePredicate} AND sale_date = ?
                      ) as activities";
    $activityStmt = $pdo->prepare($activityQuery);
    $activityStmt->execute([
        $tenantFarmId, $farmAccess, $today,
        $tenantFarmId, $today,
        $tenantFarmId, $today,
        $tenantFarmId, $today,
        $tenantFarmId, $farmAccess, $today,
        $tenantFarmId, $farmAccess, $today
    ]);
}
$todayActivity = $activityStmt->fetchColumn();
$topLowStockItems = array_slice($lowStockItems, 0, 3);

$smartInsights = [];
$smartHealthScore = 100;
$smartHealthStatus = 'Excellent';
$smartHealthClass = 'success';

if ($lowStockCount > 0) {
    $smartHealthScore -= min(35, $lowStockCount * 7);
    $firstLowItem = $lowStockItems[0];
    $recommendedOrder = max(0, ((float) $firstLowItem['min_stock_level'] * 2) - (float) $firstLowItem['current_stock']);
    $smartInsights[] = [
        'type' => 'danger',
        'icon' => 'bi-box-seam',
        'title' => 'Restock priority',
        'message' => sprintf(
            '%s is below minimum stock. Suggested reorder: %s %s to restore a safe buffer.',
            $firstLowItem['item_name'],
            number_format($recommendedOrder, 2),
            $firstLowItem['unit']
        ),
        'action_label' => 'Open inventory',
        'action_url' => 'inventory.php'
    ];
} else {
    $smartInsights[] = [
        'type' => 'success',
        'icon' => 'bi-check2-circle',
        'title' => 'Inventory is stable',
        'message' => 'All visible stock items are above their minimum levels. Keep monitoring usage trends daily.',
        'action_label' => 'Review stock',
        'action_url' => 'inventory.php'
    ];
}

if ($todayActivity === 0) {
    $smartHealthScore -= 15;
    $smartInsights[] = [
        'type' => 'warning',
        'icon' => 'bi-calendar-check',
        'title' => 'No activity logged today',
        'message' => 'Record daily production, stock movement, sales, or expenses so reports stay accurate.',
        'action_label' => 'Add daily record',
        'action_url' => ($farmAccess === 'ruminant') ? 'ruminant/ruminant_daily_record.php' : 'poultry/layers_daily_record.php'
    ];
}

$totalSalesForInsight = (float) ($profitData['total_sales'] ?? 0);
if ($totalSalesForInsight > 0) {
    $profitMargin = ($netProfit / $totalSalesForInsight) * 100;
    if ($profitMargin < 10) {
        $smartHealthScore -= 20;
        $smartInsights[] = [
            'type' => 'warning',
            'icon' => 'bi-graph-down-arrow',
            'title' => 'Profit margin needs attention',
            'message' => sprintf('This month margin is %.1f%%. Review expenses and pricing before the month closes.', $profitMargin),
            'action_label' => 'View reports',
            'action_url' => 'management/reports.php'
        ];
    } else {
        $smartInsights[] = [
            'type' => 'success',
            'icon' => 'bi-graph-up-arrow',
            'title' => 'Positive business trend',
            'message' => sprintf('This month margin is %.1f%%. Protect this performance by keeping cost records complete.', $profitMargin),
            'action_label' => 'View reports',
            'action_url' => 'management/reports.php'
        ];
    }
} elseif ($monthlyExpenses > 0) {
    $smartHealthScore -= 10;
    $smartInsights[] = [
        'type' => 'info',
        'icon' => 'bi-receipt',
        'title' => 'Expenses recorded without sales',
        'message' => 'Sales are not yet recorded for this month. Add sales records to unlock accurate profit intelligence.',
        'action_label' => 'Record sales',
        'action_url' => 'management/sales_records.php'
    ];
}

if (($farmAccess === 'poultry' || $farmAccess === 'both') && !empty($latestLayerRecord)) {
    $layingRate = (float) $latestLayerRecord['laying_rate'];
    if ($layingRate < 70) {
        $smartHealthScore -= 15;
        $smartInsights[] = [
            'type' => 'warning',
            'icon' => 'bi-egg',
            'title' => 'Layer production watch',
            'message' => sprintf('Latest laying rate is %.1f%%. Check feed, water, lighting, health, and flock age.', $layingRate),
            'action_label' => 'Update layers',
            'action_url' => 'poultry/layers_daily_record.php'
        ];
    }
}

if (($farmAccess === 'poultry' || $farmAccess === 'both') && !empty($latestBroilerRecord)) {
    $broilerStock = max(1, (float) $latestBroilerRecord['opening_stock']);
    $broilerMortalityRate = ((float) $latestBroilerRecord['mortality'] / $broilerStock) * 100;
    if ($broilerMortalityRate > 3) {
        $smartHealthScore -= 15;
        $smartInsights[] = [
            'type' => 'danger',
            'icon' => 'bi-heart-pulse',
            'title' => 'Broiler mortality alert',
            'message' => sprintf('Latest broiler mortality is %.1f%%. Investigate housing, vaccination, feed, and water immediately.', $broilerMortalityRate),
            'action_label' => 'Update broilers',
            'action_url' => 'poultry/broiler_daily_record.php'
        ];
    }
}

if (($farmAccess === 'ruminant' || $farmAccess === 'both') && !empty($latestRuminantRecord)) {
    $ruminantStock = max(1, (float) $latestRuminantRecord['opening_stock']);
    $ruminantMortalityRate = ((float) $latestRuminantRecord['mortality'] / $ruminantStock) * 100;
    if ($ruminantMortalityRate > 2) {
        $smartHealthScore -= 15;
        $smartInsights[] = [
            'type' => 'danger',
            'icon' => 'bi-shield-exclamation',
            'title' => 'Ruminant health alert',
            'message' => sprintf('Latest ruminant mortality is %.1f%%. Review treatment, feeding, and pen condition.', $ruminantMortalityRate),
            'action_label' => 'Update ruminants',
            'action_url' => 'ruminant/ruminant_daily_record.php'
        ];
    }
}

$smartInsightPriority = [
    'danger' => 1,
    'warning' => 2,
    'info' => 3,
    'success' => 4,
];
usort($smartInsights, function ($a, $b) use ($smartInsightPriority) {
    $priorityA = $smartInsightPriority[$a['type']] ?? 99;
    $priorityB = $smartInsightPriority[$b['type']] ?? 99;

    return $priorityA <=> $priorityB;
});
$smartInsights = array_slice($smartInsights, 0, 4);
$smartHealthScore = max(0, min(100, $smartHealthScore));
if ($smartHealthScore < 50) {
    $smartHealthStatus = 'Critical';
    $smartHealthClass = 'danger';
} elseif ($smartHealthScore < 75) {
    $smartHealthStatus = 'Needs attention';
    $smartHealthClass = 'warning';
} elseif ($smartHealthScore < 90) {
    $smartHealthStatus = 'Good';
    $smartHealthClass = 'info';
}

$statCardCount = 0;
if ($farmAccess === 'poultry' || $farmAccess === 'both') {
    $statCardCount++;
}
if ($farmAccess === 'ruminant' || $farmAccess === 'both') {
    $statCardCount++;
}
$statCardCountClass = 'stats-count-' . $statCardCount;

// Set page title
$pageTitle = "Dashboard";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include(__DIR__ . '/navbar_head.php'); ?>
    <title>Dashboard - Renee Farms</title>

    <!-- Chart.js with fallback to local stub to keep page functional when CDN is blocked -->
    <script>
        function loadChartFallback() {
            if (window.fmChartFallbackLoaded) return;
            window.fmChartFallbackLoaded = true;
            var fallbackScript = document.createElement('script');
            fallbackScript.src = '<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/js/chart-fallback.js'); ?>';
            document.head.appendChild(fallbackScript);
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous" onerror="loadChartFallback()"></script>
    
    <!-- Dashboard Specific CSS -->
    <style>
        :root {
            --brand-primary: #2d6cdf;
            --brand-primary-soft: #dce7ff;
            --brand-success: #1f9d66;
            --brand-danger: #d64858;
            --brand-warning: #f5a524;
            --brand-surface: #ffffff;
            --brand-muted: #6c7786;
            --brand-bg: #f4f7fc;
        }

        body {
            background: radial-gradient(circle at top right, rgba(45, 108, 223, 0.09), transparent 55%), var(--brand-bg);
        }

        .dashboard-card {
            transition: all 0.25s ease;
            border: none;
            border-radius: 16px;
            box-shadow: 0 14px 35px rgba(20, 35, 80, 0.08);
            background: var(--brand-surface);
        }

        .dashboard-card .card-body {
            padding: 1rem 1.1rem;
        }

        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 36px rgba(20, 35, 80, 0.12);
        }

        .stat-icon {
            font-size: 1.85rem;
            opacity: 0.9;
            line-height: 1;
        }

        .stat-value {
            font-size: 1.6rem;
            line-height: 1.15;
            word-break: break-word;
        }

        .stat-value-currency {
            font-size: 1.35rem;
            line-height: 1.05;
        }

        .stat-subtext {
            display: block;
            line-height: 1.15;
            white-space: normal;
            word-break: break-word;
        }
        
        .activity-item {
            border-left: 3px solid transparent;
            padding: 10px 15px;
            margin-bottom: 10px;
            background: #f8faff;
            border-radius: 12px;
            transition: all 0.2s;
        }

        .activity-item:hover {
            background: #eef4ff;
            border-left-color: var(--brand-primary);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .stock-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        
        .stock-low {
            background-color: #dc3545;
        }
        
        .stock-moderate {
            background-color: #ffc107;
        }
        
        .stock-good {
            background-color: #28a745;
        }
        
        .quick-action-btn {
            padding: 10px 15px;
            border-radius: 14px;
            transition: all 0.2s;
            box-shadow: 0 10px 24px rgba(18, 38, 80, 0.08);
            background: linear-gradient(180deg, #fff, #f9fbff);
        }

        .quick-action-btn:hover {
            transform: translateY(-3px);
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .dashboard-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
            align-items: stretch;
        }

        .dashboard-stat-wrapper {
            flex: 1 1 100%;
        }

        @media (min-width: 768px) {
            .dashboard-stat-wrapper {
                flex: 1 1 45%;
            }
        }

        @media (min-width: 992px) {
            .dashboard-stat-wrapper {
                flex: 1 1 30%;
            }
        }

        @media (min-width: 1200px) {
            .dashboard-stats.stats-count-6 .dashboard-stat-wrapper {
                flex: 0 1 calc(25% - 0.75rem);
            }

            .dashboard-stats.stats-count-5 .dashboard-stat-wrapper {
                flex: 0 1 calc(33.333% - 0.75rem);
            }
        }

        .dashboard-stat-card {
            height: 100%;
            min-height: 130px;
            display: flex;
            flex-direction: column;
        }

        .dashboard-container {
            margin-top: 2rem;
            margin-bottom: 2.25rem;
        }

        .dashboard-hero {
            background: linear-gradient(135deg, #1f5ec9 0%, #2d6cdf 52%, #59a0ff 100%);
            color: #fff;
            border-radius: 18px;
            position: relative;
            overflow: hidden;
        }

        .dashboard-hero::after {
            content: '';
            position: absolute;
            width: 270px;
            height: 270px;
            border-radius: 50%;
            right: -80px;
            top: -130px;
            background: rgba(255, 255, 255, 0.15);
        }

        .dashboard-hero .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 999px;
            font-size: 0.75rem;
            padding: 0.35rem 0.75rem;
        }

        .hero-metric {
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 0.7rem 0.85rem;
            height: 100%;
        }

        .hero-metric span {
            display: block;
            font-size: 0.75rem;
            opacity: 0.9;
        }

        .hero-metric strong {
            font-size: 1rem;
            line-height: 1.1;
        }

        .dashboard-stat-card .card-body {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 0.45rem;
            height: 100%;
            padding: 0.85rem 0.95rem;
        }

        .dashboard-stat-card .stat-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.72rem;
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            background: #f1f5ff;
            color: #27498f;
            width: fit-content;
        }

        .dashboard-stat-card .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .dashboard-stat-card h6 {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .dashboard-stat-card .stat-icon {
            font-size: 1.55rem;
        }

        .dashboard-stat-card .stat-value {
            font-size: 1.35rem;
        }

        .dashboard-stat-card .stat-value-currency {
            font-size: 1.2rem;
        }

        .dashboard-stat-card.livestock-center-card {
            min-height: 88px;
        }

        .dashboard-stat-card.livestock-center-card .card-body {
            justify-content: flex-start;
            text-align: center;
            padding: 0.65rem 0.8rem;
            gap: 0.2rem;
            height: auto;
        }

        .dashboard-stat-card.livestock-center-card .d-flex {
            text-align: left;
        }

        .dashboard-stat-card.livestock-center-card .mb-3 {
            margin-bottom: 0.45rem !important;
        }

        .dashboard-stat-card.livestock-center-card .row.g-3 {
            --bs-gutter-y: 0.25rem;
            --bs-gutter-x: 0.55rem;
            margin-bottom: 0.1rem !important;
        }

        .livestock-ticker-section {
            display: grid;
            gap: 0.75rem;
        }

        .livestock-ticker-row {
            overflow: hidden;
            border-radius: 12px;
            border: 1px solid #e6ecf7;
            background: #fff;
            padding: 0.55rem 0.6rem;
            position: relative;
        }

        .livestock-ticker-track {
            width: max-content;
            display: inline-flex;
            align-items: center;
            gap: 0.7rem;
            white-space: nowrap;
            animation: livestock-marquee 32s linear infinite;
            will-change: transform;
            transform: translateX(-100%);
        }

        .livestock-ticker-title {
            font-size: 0.82rem;
            font-weight: 600;
            color: #5f6f8a;
            margin-right: 0.35rem;
        }

        .livestock-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            background: #f5f8ff;
            color: #243a64;
            padding: 0.2rem 0.65rem;
            font-size: 0.82rem;
            border: 1px solid #e3ebff;
        }

        @keyframes livestock-marquee {
            0% {
                transform: translateX(-100%);
            }
            100% {
                transform: translateX(100vw);
            }
        }

        .livestock-ticker-row:hover .livestock-ticker-track {
            animation-play-state: paused;
        }

        @media (prefers-reduced-motion: reduce) {
            .livestock-ticker-track {
                animation: none;
            }
        }

        body.dashboard-role-poultry_manager .dashboard-stats .dashboard-stat-wrapper,
        body.dashboard-role-ruminant_manager .dashboard-stats .dashboard-stat-wrapper {
            flex: 0 1 min(620px, 100%);
        }

        .current-month-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(155deg, #f7fbff 0%, #eef6ff 100%);
        }

        .current-month-card .floating-stat-icon {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(13, 202, 240, 0.15);
            color: #0dcaf0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .smart-command-card {
            background: linear-gradient(145deg, #ffffff 0%, #f6fbff 100%);
            border: 1px solid #e3ebff;
        }

        .smart-score-ring {
            width: 112px;
            height: 112px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at center, #fff 0 58%, transparent 59%),
                conic-gradient(var(--bs-__SMART_CLASS__, #198754) calc(var(--score) * 1%), #e8edf6 0);
            box-shadow: inset 0 0 0 1px rgba(20, 35, 80, 0.06);
        }

        .smart-score-ring.score-success { --bs-__SMART_CLASS__: #198754; }
        .smart-score-ring.score-info { --bs-__SMART_CLASS__: #0dcaf0; }
        .smart-score-ring.score-warning { --bs-__SMART_CLASS__: #ffc107; }
        .smart-score-ring.score-danger { --bs-__SMART_CLASS__: #dc3545; }

        .smart-score-value {
            font-size: 1.45rem;
            font-weight: 800;
            color: #1f2d45;
        }

        .smart-insight {
            border: 1px solid #edf1f7;
            border-radius: 14px;
            padding: 0.85rem;
            height: 100%;
            background: #fff;
        }

        .smart-insight-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .bg-primary-subtle { background-color: rgba(13, 110, 253, 0.12) !important; }
        .bg-success-subtle { background-color: rgba(25, 135, 84, 0.12) !important; }
        .bg-info-subtle { background-color: rgba(13, 202, 240, 0.14) !important; }
        .bg-warning-subtle { background-color: rgba(255, 193, 7, 0.18) !important; }
        .bg-danger-subtle { background-color: rgba(220, 53, 69, 0.12) !important; }

        .ops-card {
            border: 1px solid #e6edf8;
            overflow: hidden;
        }

        .ops-card .card-header {
            background: linear-gradient(135deg, #ffffff 0%, #f6f9ff 100%);
            color: #1f2d45;
            border-bottom: 1px solid #e6edf8;
            padding: 0.95rem 1.1rem;
        }

        .ops-card .section-eyebrow {
            color: #6f7f95;
            display: block;
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .ops-toolbar .btn,
        .ops-toolbar .badge {
            border-radius: 999px;
        }

        .stock-control-table {
            border-collapse: separate;
            border-spacing: 0 0.55rem;
        }

        .stock-control-table thead th {
            background: transparent;
            color: #718096;
            font-size: 0.73rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border: 0;
            padding: 0.35rem 0.75rem;
        }

        .stock-control-table tbody tr {
            background: #ffffff;
            box-shadow: 0 8px 22px rgba(20, 35, 80, 0.06);
        }

        .stock-control-table tbody td {
            border-top: 1px solid #edf2f7;
            border-bottom: 1px solid #edf2f7;
            padding: 0.85rem 0.75rem;
            vertical-align: middle;
        }

        .stock-control-table tbody td:first-child {
            border-left: 1px solid #edf2f7;
            border-radius: 14px 0 0 14px;
        }

        .stock-control-table tbody td:last-child {
            border-right: 1px solid #edf2f7;
            border-radius: 0 14px 14px 0;
        }

        .inventory-progress {
            height: 7px;
            border-radius: 999px;
            background: #edf2f7;
            min-width: 120px;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 1rem;
        }

        .smart-action-card {
            border: 1px solid #e7eef9;
            border-radius: 18px;
            min-height: 126px;
            background: linear-gradient(145deg, #ffffff, #f9fbff);
            box-shadow: 0 14px 30px rgba(20, 35, 80, 0.07);
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 1rem;
            transition: all 0.22s ease;
        }

        .smart-action-card:hover {
            border-color: rgba(45, 108, 223, 0.35);
            box-shadow: 0 18px 36px rgba(20, 35, 80, 0.12);
            transform: translateY(-3px);
        }

        .smart-action-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            flex: 0 0 auto;
        }

        .side-panel-card .card-body {
            padding: 1rem;
        }

        .empty-state-smart {
            min-height: 132px;
            display: grid;
            place-items: center;
            text-align: center;
            color: #667085;
        }

        .timeline-item {
            border: 1px solid #edf2f7;
            border-radius: 14px;
            padding: 0.8rem;
            background: #fff;
            margin-bottom: 0.7rem;
        }

        .production-tile {
            border: 1px solid #edf2f7;
            border-radius: 16px;
            padding: 0.85rem;
            background: linear-gradient(135deg, #ffffff, #fbfcff);
            margin-bottom: 0.75rem;
        }

        @media (max-width: 768px) {
            .dashboard-card .card-body {
                padding: 15px;
            }

            .stat-icon {
                font-size: 1.6rem;
            }

            .dashboard-stat-card {
                min-height: 118px;
            }

            .dashboard-stat-card .card-body {
                padding: 0.75rem 0.85rem;
            }

            body.dashboard-role-poultry_manager .dashboard-stats .dashboard-stat-wrapper,
            body.dashboard-role-ruminant_manager .dashboard-stats .dashboard-stat-wrapper {
                flex-basis: 100%;
            }

            .dashboard-hero::after {
                width: 200px;
                height: 200px;
            }
        }
    </style>
</head>
<body class="dashboard-role-<?php echo htmlspecialchars($userType); ?>">
    <?php include(__DIR__ . '/navbar.php'); ?>
    
    <div class="container-fluid dashboard-container">
        <!-- Welcome Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card dashboard-card dashboard-hero">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 position-relative" style="z-index:1;">
                            <div>
                                <span class="hero-pill mb-2">
                                    <i class="bi bi-stars"></i> <?php echo $greetingText; ?>
                                </span>
                                <h2 class="mb-1 fw-bold">Welcome back, <?php echo $_SESSION['full_name']; ?> 👋</h2>
                                <p class="mb-0 opacity-75">
                                    <?php echo date('l, F j, Y'); ?> • 
                                    Last login: <?php echo htmlspecialchars($lastLoginDisplay); ?>
                                </p>
                            </div>
                            <div class="text-end d-flex flex-column gap-2">
                                <span class="badge bg-light text-dark fs-6">
                                    <?php echo ucfirst(str_replace('_', ' ', $userType)); ?>
                                </span>
                                <div>
                                    <small class="opacity-75">
                                        Farm Access: 
                                         <span class="badge bg-info text-dark">
                                             <?php echo ucfirst($farmAccessLabel); ?>
                                         </span>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mt-3 position-relative" style="z-index:1;">
                            <div class="col-sm-6 col-lg-4 col-xl">
                                <div class="hero-metric">
                                    <span>Monthly Expenses</span>
                                    <strong>₦<?php echo number_format($monthlyExpenses, 2); ?></strong>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-4 col-xl">
                                <div class="hero-metric">
                                    <span>Items in Stock</span>
                                    <strong><?php echo number_format($totalStockItems); ?> Items</strong>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-4 col-xl">
                                <div class="hero-metric">
                                    <span>Today's Activities</span>
                                    <strong><?php echo number_format((int) $todayActivity); ?> Updates</strong>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-6 col-xl">
                                <div class="hero-metric">
                                    <span>Net Profit (This Month)</span>
                                    <strong class="<?php echo ($netProfit) >= 0 ? 'text-warning' : 'text-light'; ?>">
                                        ₦<?php echo number_format($netProfit, 2); ?>
                                    </strong>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-6 col-xl">
                                <div class="hero-metric">
                                    <span>Inventory Coverage</span>
                                    <strong><?php echo $lowStockCount === 0 ? 'Healthy' : 'Review Needed'; ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dashboard Statistics -->
        <?php if (in_array($farmAccess, ['poultry', 'ruminant', 'both'], true)): ?>
        <div class="livestock-ticker-section mb-4">
            <div class="livestock-ticker-row">
                <div class="livestock-ticker-track">
                    <?php if ($farmAccess === 'poultry' || $farmAccess === 'both'): ?>
                    <span class="livestock-ticker-title"><i class="bi bi-egg-fried text-primary me-1"></i>Poultry Active Cycle Stock</span>
                    <?php foreach ($poultryCurrentStock as $label => $value): ?>
                    <span class="livestock-pill">
                        <span class="text-primary">●</span>
                        <span class="fw-semibold"><?php echo $label; ?></span>
                        <span class="fw-bold"><?php echo $value !== null ? number_format($value) : 'No data'; ?></span>
                    </span>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($farmAccess === 'both'): ?>
                    <span class="livestock-pill">•</span>
                    <?php endif; ?>
                    <?php if ($farmAccess === 'ruminant' || $farmAccess === 'both'): ?>
                    <span class="livestock-ticker-title"><i class="bi bi-shield-check text-success me-1"></i>Ruminant Active Cycle Stock</span>
                    <?php foreach ($ruminantCurrentStock as $type => $value): ?>
                    <span class="livestock-pill">
                        <span class="text-success">●</span>
                        <span class="fw-semibold"><?php echo $type; ?></span>
                        <span class="fw-bold"><?php echo $value !== null ? number_format($value) : 'No data'; ?></span>
                    </span>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Smart Farm Intelligence -->
        <div class="card dashboard-card smart-command-card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-3 text-center text-lg-start">
                        <span class="badge bg-primary-subtle text-primary mb-2">
                            <i class="bi bi-cpu"></i> Smart Farm Intelligence
                        </span>
                        <div class="d-flex justify-content-center justify-content-lg-start mb-2">
                            <div class="smart-score-ring score-<?php echo htmlspecialchars($smartHealthClass); ?>" style="--score: <?php echo (int) $smartHealthScore; ?>;">
                                <span class="smart-score-value"><?php echo (int) $smartHealthScore; ?>%</span>
                            </div>
                        </div>
                        <h5 class="mb-1">Farm health: <?php echo htmlspecialchars($smartHealthStatus); ?></h5>
                        <p class="text-muted mb-0 small">Automated recommendations from your stock, production, sales, and expense records.</p>
                    </div>
                    <div class="col-lg-9">
                        <div class="row g-3">
                            <?php foreach ($smartInsights as $insight): ?>
                            <div class="col-md-6">
                                <div class="smart-insight">
                                    <div class="d-flex gap-3">
                                        <span class="smart-insight-icon bg-<?php echo htmlspecialchars($insight['type']); ?>-subtle text-<?php echo htmlspecialchars($insight['type']); ?>">
                                            <i class="bi <?php echo htmlspecialchars($insight['icon']); ?>"></i>
                                        </span>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between gap-2 align-items-start">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($insight['title']); ?></h6>
                                                <span class="badge bg-<?php echo htmlspecialchars($insight['type']); ?>"><?php echo ucfirst(htmlspecialchars($insight['type'])); ?></span>
                                            </div>
                                            <p class="small text-muted mb-2"><?php echo htmlspecialchars($insight['message']); ?></p>
                                            <a class="small fw-semibold text-decoration-none" href="<?php echo htmlspecialchars($insight['action_url']); ?>">
                                                <?php echo htmlspecialchars($insight['action_label']); ?> <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="row">
            <!-- Left Column: Stock & Quick Actions -->
            <div class="col-xl-8">
                <!-- Current Stock Levels -->
                <div class="card dashboard-card ops-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <span class="section-eyebrow">Inventory command center</span>
                            <h5 class="mb-0">
                                <i class="bi bi-box-seam text-primary"></i>
                                Smart Stock Control
                            </h5>
                        </div>
                        <div class="dropdown ops-toolbar" id="stockFilterDropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="stockFilterButton"
                                    aria-expanded="false">
                                <i class="bi bi-filter"></i> Filter
                            </button>
                            <ul class="dropdown-menu" id="stockFilterMenu">
                                <li><a class="dropdown-item" href="#" data-stock-filter="all">All Items</a></li>
                                <li><a class="dropdown-item" href="#" data-stock-filter="low">Low Stock Only</a></li>
                                <li><a class="dropdown-item" href="#" data-stock-filter="poultry">Poultry Only</a></li>
                                <li><a class="dropdown-item" href="#" data-stock-filter="ruminant">Ruminant Only</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table stock-control-table" id="stockTable">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Current Stock</th>
                                        <th>Min Level</th>
                                        <th>Unit</th>
                                        <th>Status</th>
                                        <th>Farm Type</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stockItems as $item):
                                        $minStockLevel = max(1, (float) $item['min_stock_level']);
                                        $stockPercent = ($item['current_stock'] / $minStockLevel) * 100;
                                        if ($item['current_stock'] <= $item['min_stock_level']) {
                                            $statusClass = 'danger';
                                            $statusText = 'Low Stock';
                                            $indicatorClass = 'stock-low';
                                        } elseif ($stockPercent <= 150) {
                                            $statusClass = 'warning';
                                            $statusText = 'Moderate';
                                            $indicatorClass = 'stock-moderate';
                                        } else {
                                            $statusClass = 'success';
                                            $statusText = 'Good';
                                            $indicatorClass = 'stock-good';
                                        }
                                    ?>
                                    <tr data-farm-type="<?php echo $item['farm_type']; ?>" 
                                        data-stock-status="<?php echo $statusClass; ?>">
                                        <td>
                                            <strong><?php echo $item['item_name']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="fw-bold <?php echo "text-$statusClass"; ?>">
                                                <?php echo number_format((float) $item['current_stock'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?>
                                            </div>
                                            <div class="progress inventory-progress mt-1">
                                                <div class="progress-bar bg-<?php echo $statusClass; ?>" role="progressbar" style="width: <?php echo (int) min(100, round($stockPercent)); ?>%"></div>
                                            </div>
                                        </td>
                                        <td><?php echo number_format((float) $item['min_stock_level'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                        <td>
                                            <span class="stock-indicator <?php echo $indicatorClass; ?>"></span>
                                            <span class="badge bg-<?php echo $statusClass; ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $item['farm_type'] == 'poultry' ? 'info' : 'warning'; ?>">
                                                <?php echo ucfirst($item['farm_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-primary rounded-pill px-3"
                                                    onclick="quickStockUpdate(<?php echo $item['id']; ?>)"
                                                    title="Quick Update">
                                                <i class="bi bi-arrow-up-down me-1"></i> Update
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($stockItems)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                            No stock items found
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <?php if (!hasRole('sales_rep')): ?>
                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card dashboard-card ops-card">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <span class="section-eyebrow">Daily operations</span>
                                    <h5 class="mb-0">
                                        <i class="bi bi-lightning-charge text-warning"></i>
                                        Smart Quick Actions
                                    </h5>
                                </div>
                                <span class="badge bg-warning-subtle text-warning">Fast entry</span>
                            </div>
                            <div class="card-body">
                                <div class="action-grid">
                                    <?php if ($farmAccess === 'poultry' || $farmAccess === 'both'): ?>
                                    <a href="poultry/layers_daily_record.php" class="smart-action-card text-decoration-none text-dark">
                                        <span class="smart-action-icon bg-primary-subtle text-primary"><i class="bi bi-egg-fried"></i></span>
                                        <span><strong class="d-block">Layer Daily</strong><small class="text-muted">Record eggs, mortality, feed and water.</small></span>
                                    </a>

                                    <a href="poultry/broiler_daily_record.php" class="smart-action-card text-decoration-none text-dark">
                                        <span class="smart-action-icon bg-info-subtle text-info"><i class="bi bi-basket"></i></span>
                                        <span><strong class="d-block">Broiler Daily</strong><small class="text-muted">Track age, stock, health, feed and weight.</small></span>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($farmAccess === 'ruminant' || $farmAccess === 'both'): ?>
                                    <a href="ruminant/ruminant_daily_record.php" class="smart-action-card text-decoration-none text-dark">
                                        <span class="smart-action-icon bg-warning-subtle text-warning"><i class="bi bi-shield-plus"></i></span>
                                        <span><strong class="d-block">Ruminant Daily</strong><small class="text-muted">Update livestock, treatment and mortality.</small></span>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <a href="inventory.php" class="smart-action-card text-decoration-none text-dark">
                                        <span class="smart-action-icon bg-success-subtle text-success"><i class="bi bi-box-arrow-in-down"></i></span>
                                        <span><strong class="d-block">Update Stock</strong><small class="text-muted">Receive, consume, and reconcile inventory.</small></span>
                                    </a>
                                    
                                    <?php if (isPlatformOwner() || hasRole('farm_admin')): ?>
                                    <a href="management/sales_records.php" class="smart-action-card text-decoration-none text-dark">
                                        <span class="smart-action-icon bg-danger-subtle text-danger"><i class="bi bi-cart-plus"></i></span>
                                        <span><strong class="d-block">Record Sale</strong><small class="text-muted">Capture revenue and product quantities.</small></span>
                                    </a>

                                    <a href="management/expenses.php" class="smart-action-card text-decoration-none text-dark">
                                        <span class="smart-action-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-cash-coin"></i></span>
                                        <span><strong class="d-block">Add Expense</strong><small class="text-muted">Log costs for cleaner profit reports.</small></span>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Recent Activity & Alerts -->
            <div class="col-xl-4">
                <!-- Today's Transactions -->
                <div class="card dashboard-card ops-card side-panel-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <span class="section-eyebrow">Live audit trail</span>
                            <h5 class="mb-0">
                                <i class="bi bi-clock-history text-info"></i>
                                Today's Transactions
                            </h5>
                        </div>
                        <span class="badge bg-info-subtle text-info"><?php echo count($todayTransactions); ?> today</span>
                    </div>
                    <div class="card-body" style="max-height: 340px; overflow-y: auto;">
                        <?php if (empty($todayTransactions)): ?>
                        <div class="empty-state-smart">
                            <div>
                                <i class="bi bi-check2-circle display-5 d-block mb-2 text-success"></i>
                                <strong>No transactions today</strong>
                                <div class="small">Stock movement will appear here in real time.</div>
                            </div>
                        </div>
                        <?php else: ?>
                            <?php foreach ($todayTransactions as $trans): ?>
                            <div class="timeline-item">
                                <div class="d-flex align-items-center">
                                    <div class="activity-icon bg-<?php echo $trans['transaction_type'] == 'received' ? 'success' : 'danger'; ?> text-white">
                                        <i class="bi bi-<?php echo $trans['transaction_type'] == 'received' ? 'arrow-down-left' : 'arrow-up-right'; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between">
                                            <strong><?php echo $trans['item_name']; ?></strong>
                                            <span class="fw-bold <?php echo $trans['transaction_type'] == 'received' ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $trans['transaction_type'] == 'received' ? '+' : '-'; ?>
                                                <?php echo $trans['quantity']; ?> <?php echo $trans['unit']; ?>
                                            </span>
                                        </div>
                                        <small class="text-muted">
                                            Stock: <?php echo $trans['new_stock']; ?> <?php echo $trans['unit']; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!empty($topLowStockItems)): ?>
                <div class="card dashboard-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-speedometer2 text-danger"></i>
                            Critical Inventory Snapshot
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($topLowStockItems as $item): ?>
                            <?php
                                $progress = 0;
                                if ((float) $item['min_stock_level'] > 0) {
                                    $progress = min(100, ((float) $item['current_stock'] / (float) $item['min_stock_level']) * 100);
                                }
                            ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                    <small class="text-muted"><?php echo number_format((float) $item['current_stock'], 2); ?> / <?php echo number_format((float) $item['min_stock_level'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?></small>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo (int) round($progress); ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Low Stock Alerts -->
                <?php if (!empty($lowStockItems)): ?>
                <div class="card dashboard-card border-danger mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Low Stock Alerts
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($lowStockItems as $item): ?>
                        <div class="alert alert-warning d-flex align-items-center mb-2" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <div class="flex-grow-1">
                                <strong><?php echo $item['item_name']; ?></strong><br>
                                <small>
                                    Current: <?php echo $item['current_stock']; ?> <?php echo $item['unit']; ?> • 
                                    Min: <?php echo $item['min_stock_level']; ?> <?php echo $item['unit']; ?>
                                </small>
                            </div>
                            <button class="btn btn-sm btn-outline-danger" 
                                    onclick="quickStockUpdate(<?php echo $item['id']; ?>)">
                                Reorder
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Recent Sales -->
                <?php if (!empty($recentSales)): ?>
                <div class="card dashboard-card ops-card side-panel-card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <span class="section-eyebrow">Revenue pulse</span>
                            <h5 class="mb-0">
                                <i class="bi bi-graph-up text-success"></i>
                                Recent Sales
                            </h5>
                        </div>
                        <span class="badge bg-success-subtle text-success"><?php echo count($recentSales); ?> latest</span>
                    </div>
                    <div class="card-body">
                        <?php foreach ($recentSales as $sale): ?>
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                            <div>
                                <strong><?php echo $sale['product_type']; ?></strong>
                                <div class="small text-muted">
                                    <?php echo date('M d', strtotime($sale['sale_date'])); ?> • 
                                    <?php echo $sale['quantity']; ?> units
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="fw-bold text-success">
                                    ₦<?php echo number_format($sale['total_amount'], 2); ?>
                                </span>
                                <div class="small text-muted">
                                    <?php echo $sale['seller']; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Latest Production Summary -->
                <div class="card dashboard-card ops-card side-panel-card">
                    <div class="card-header">
                        <span class="section-eyebrow">Animal performance</span>
                        <h5 class="mb-0">
                            <i class="bi bi-bar-chart text-primary"></i>
                            Latest Production
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($farmAccess === 'poultry' || $farmAccess === 'both'): ?>
                            <?php if ($latestLayerRecord): ?>
                            <div class="production-tile">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="bi bi-egg-fried text-primary me-2"></i>
                                        <strong>Layers</strong>
                                    </span>
                                    <span class="badge bg-primary">
                                        <?php echo date('M d', strtotime($latestLayerRecord['record_date'])); ?>
                                    </span>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <small class="text-muted">Eggs</small>
                                        <div class="fw-bold text-success"><?php echo $latestLayerRecord['egg_production']; ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Rate</small>
                                        <div class="fw-bold <?php echo $latestLayerRecord['laying_rate'] > 80 ? 'text-success' : ($latestLayerRecord['laying_rate'] > 60 ? 'text-warning' : 'text-danger'); ?>">
                                            <?php echo $latestLayerRecord['laying_rate']; ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($latestBroilerRecord): ?>
                            <div class="production-tile">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="bi bi-basket text-info me-2"></i>
                                        <strong>Broilers</strong>
                                    </span>
                                    <span class="badge bg-info">
                                        <?php echo date('M d', strtotime($latestBroilerRecord['record_date'])); ?>
                                    </span>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <small class="text-muted">Stock</small>
                                        <div class="fw-bold"><?php echo $latestBroilerRecord['opening_stock']; ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Age</small>
                                        <div class="fw-bold"><?php echo $latestBroilerRecord['birds_age']; ?> days</div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($farmAccess === 'ruminant' || $farmAccess === 'both'): ?>
                            <?php if ($latestRuminantRecord): ?>
                            <div class="production-tile mb-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="bi bi-shield-plus text-warning me-2"></i>
                                        <strong>Ruminant</strong>
                                    </span>
                                    <span class="badge bg-warning">
                                        <?php echo date('M d', strtotime($latestRuminantRecord['record_date'])); ?>
                                    </span>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-6">
                                        <small class="text-muted">Stock</small>
                                        <div class="fw-bold"><?php echo $latestRuminantRecord['opening_stock']; ?></div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Type</small>
                                        <div class="fw-bold"><?php echo $latestRuminantRecord['animal_type']; ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Stock Update Modal -->
        <div class="modal fade" id="quickStockModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form id="quickStockForm">
                        <div class="modal-header">
                            <h5 class="modal-title">Quick Stock Update</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="stockItemId">
                            
                            <div class="mb-3">
                                <label>Item</label>
                                <input type="text" class="form-control" id="stockItemName" readonly>
                                <small class="text-muted" id="stockItemDetails"></small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Transaction Type</label>
                                    <select class="form-select" id="transType" required>
                                        <option value="received">⬆ Received Stock (+)</option>
                                        <option value="used">⬇ Used Stock (-)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Quantity</label>
                                    <input type="number" class="form-control" id="quantity" step="0.01" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Remarks (Optional)</label>
                                <input type="text" class="form-control" id="remarks" placeholder="Enter remarks">
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <small>This will update stock in real-time and record the transaction.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Stock</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/jquery/jquery.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/jquery.dataTables.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/dataTables.bootstrap5.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables-responsive/js/dataTables.responsive.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/js/main.js'); ?>"></script>
    
    <script>
    // Initialize dashboard
    $(document).ready(function() {
        // Initialize tooltips
        $('[title]').tooltip();
        
        // Auto-refresh stock every 30 seconds
        setInterval(refreshStockData, 30000);
        
        // Check for new notifications
        checkNotifications();
    });
    
    // Filter stock table
    function filterStock(filterType) {
        const rows = document.querySelectorAll('#stockTable tbody tr');

        rows.forEach(row => {
            let showRow = true;
            const farmType = row.getAttribute('data-farm-type');
            const stockStatus = row.getAttribute('data-stock-status');

            // Keep placeholder row visible only in "all" mode
            if (!farmType && !stockStatus) {
                row.style.display = filterType === 'all' ? '' : 'none';
                return;
            }

            if (filterType === 'low') {
                showRow = stockStatus === 'danger';
            } else if (filterType === 'poultry') {
                showRow = farmType === 'poultry' || farmType === 'both';
            } else if (filterType === 'ruminant') {
                showRow = farmType === 'ruminant' || farmType === 'both';
            }

            row.style.display = showRow ? '' : 'none';
        });
    }

    document.getElementById('stockFilterMenu')?.addEventListener('click', function(event) {
        const filterLink = event.target.closest('[data-stock-filter]');
        if (!filterLink) {
            return;
        }

        event.preventDefault();
        filterStock(filterLink.getAttribute('data-stock-filter'));

        const filterMenu = document.getElementById('stockFilterMenu');
        const filterButton = document.getElementById('stockFilterButton');
        filterMenu?.classList.remove('show');
        filterButton?.setAttribute('aria-expanded', 'false');
    });

    // Custom dropdown toggle for stock filter (avoids Bootstrap Popper dependency issues).
    (function initStockFilterDropdown() {
        const dropdown = document.getElementById('stockFilterDropdown');
        const button = document.getElementById('stockFilterButton');
        const menu = document.getElementById('stockFilterMenu');

        if (!dropdown || !button || !menu) {
            return;
        }

        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();

            const isOpen = menu.classList.contains('show');
            menu.classList.toggle('show', !isOpen);
            button.setAttribute('aria-expanded', String(!isOpen));
        });

        document.addEventListener('click', function(event) {
            if (!dropdown.contains(event.target)) {
                menu.classList.remove('show');
                button.setAttribute('aria-expanded', 'false');
            }
        });
    })();
    
    // Quick stock update
    function quickStockUpdate(itemId) {
        // Fetch item details
        fetch(`api/get_item_details.php?id=${itemId}`)
            .then(response => response.json())
            .then(data => {
                if (data) {
                    document.getElementById('stockItemId').value = itemId;
                    document.getElementById('stockItemName').value = data.item_name;
                    document.getElementById('stockItemDetails').textContent = 
                        `Current stock: ${data.current_stock} ${data.unit} • Min: ${data.min_stock_level} ${data.unit}`;
                    
                    const modal = new bootstrap.Modal(document.getElementById('quickStockModal'));
                    modal.show();
                }
            })
            .catch(error => {
                showAlert('danger', 'Error loading item details: ' + error.message);
            });
    }
    
    // Handle quick stock form submission
    document.getElementById('quickStockForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = {
            item_id: document.getElementById('stockItemId').value,
            type: document.getElementById('transType').value,
            quantity: document.getElementById('quantity').value,
            remarks: document.getElementById('remarks').value,
            farm_type: '<?php echo $farmAccess; ?>'
        };
        
        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Updating...';
        submitBtn.disabled = true;
        
        // Send request
        fetch('api/update_stock.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', 'Stock updated successfully!');
                
                // Close modal
                bootstrap.Modal.getInstance(document.getElementById('quickStockModal')).hide();
                
                // Reload page after 1 second
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert('danger', 'Error: ' + data.message);
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            showAlert('danger', 'Network error: ' + error.message);
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
    
    // Refresh stock data
    function refreshStockData() {
        fetch(`api/get_stock_summary.php?farm_type=<?php echo $farmAccess; ?>`)
            .then(response => response.json())
            .then(data => {
                if (data && data.updated) {
                    // Update stock counters if changed significantly
                    const lowStockCount = data.low_stock_count || 0;
                    const currentLowCount = <?php echo $lowStockCount; ?>;
                    
                    if (Math.abs(lowStockCount - currentLowCount) > 0) {
                        // Show notification
                        if (lowStockCount > currentLowCount) {
                            showAlert('warning', `${lowStockCount - currentLowCount} new items are now low on stock!`);
                        }
                        
                        // Reload the page to show updated data
                        location.reload();
                    }
                }
            });
    }
    
    // Check for notifications
    function checkNotifications() {
        // Check for low stock notifications
        const lowStockItems = <?php echo json_encode($lowStockItems); ?>;
        if (lowStockItems.length > 0) {
            const notificationCount = lowStockItems.length;
            if (notificationCount > 0) {
                // Show persistent notification badge
                updateNotificationBadge(notificationCount);
                
                // Show initial alert if first visit
                if (!sessionStorage.getItem('stockAlertShown')) {
                    showAlert('warning', 
                        `You have ${notificationCount} item${notificationCount > 1 ? 's' : ''} with low stock. ` +
                        `Please reorder soon.`, 
                        10000);
                    sessionStorage.setItem('stockAlertShown', 'true');
                }
            }
        }
        
        // Check for pending tasks
        const today = '<?php echo date('Y-m-d'); ?>';
        fetch(`api/check_pending_tasks.php?farm_type=<?php echo $farmAccess; ?>&date=${today}`)
            .then(response => response.json())
            .then(data => {
                if (data.pending_tasks > 0) {
                    showAlert('info', 
                        `You have ${data.pending_tasks} pending task${data.pending_tasks > 1 ? 's' : ''} for today.`, 
                        8000);
                }
            });
    }
    
    // Update notification badge
    function updateNotificationBadge(count) {
        let badge = document.getElementById('notificationBadge');
        if (!badge) {
            badge = document.createElement('span');
            badge.id = 'notificationBadge';
            badge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
            badge.style.fontSize = '0.6rem';
            
            const bellIcon = document.querySelector('.bi-bell');
            if (bellIcon) {
                bellIcon.parentElement.style.position = 'relative';
                bellIcon.parentElement.appendChild(badge);
            }
        }
        
        badge.textContent = count > 9 ? '9+' : count;
        badge.style.display = count > 0 ? 'block' : 'none';
    }
    
    // Show alert message
    function showAlert(type, message, duration = 5000) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.style.cssText = 'position: fixed; top: 80px; right: 20px; z-index: 9999; max-width: 350px;';
        alertDiv.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-circle' : 'info-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alertDiv);
        
        // Auto remove after duration
        setTimeout(() => {
            if (alertDiv.parentNode) {
                const bsAlert = new bootstrap.Alert(alertDiv);
                bsAlert.close();
            }
        }, duration);
    }
    
    // Auto-update time
    function updateCurrentTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: true 
        });
        const dateString = now.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        
        const timeElement = document.querySelector('.current-time');
        if (timeElement) {
            timeElement.textContent = `${dateString} • ${timeString}`;
        }
    }
    
    // Update time every minute
    setInterval(updateCurrentTime, 60000);
    updateCurrentTime(); // Initial call
    </script>
</body>
</html>
