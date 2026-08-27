<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();
requireBusinessReportAccess();
$tenantFarmId = requireCurrentFarmId();

$userType = getUserType();
$userFarmType = getUserFarmType();
$canChooseFarmType = isPlatformOwner() || hasRole('farm_admin', 'sales_rep');

$reportMode = $_GET['report_mode'] ?? 'monthly';
$month = $_GET['month'] ?? date('Y-m');
$year = $_GET['year'] ?? date('Y');
$requestedFarmType = $canChooseFarmType ? ($_GET['farm_type'] ?? null) : $userFarmType;
// getUserFarmType() represents dual-module viewer access as "both", while
// reports represent that same authorized combined scope as "all".
if (!$canChooseFarmType && $requestedFarmType === 'both' && count(accessibleFarmTypes()) === 2) {
    $requestedFarmType = 'all';
}
$farmType = normalizeFarmType($requestedFarmType, true, false, $canChooseFarmType);
$visibleFarmTypes = $farmType === 'all' ? accessibleFarmTypes() : [$farmType];

if ($reportMode === 'yearly') {
    $year = date('Y', strtotime($year . '-01-01'));
    $startDate = $year . '-01-01';
    $endDate = $year . '-12-31';
    $periodLabel = $year;
} else {
    $month = date('Y-m', strtotime($month . '-01'));
    $monthFilterDate = date('Y-m-d', strtotime($month . '-' . min((int)date('d'), (int)date('t', strtotime($month . '-01')))));
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
    $periodLabel = date('F Y', strtotime($startDate));
}

$farmFilterSql = $farmType === '' ? ' AND 1 = 0' : '';
$farmParams = [];
if ($farmType === 'poultry') {
    $farmFilterSql = " AND farm_type IN ('poultry', 'general')";
} elseif ($farmType === 'ruminant') {
    $farmFilterSql = " AND farm_type IN ('ruminant', 'general')";
}

$salesStmt = $pdo->prepare("SELECT farm_type, SUM(total_amount) AS total_sales
                           FROM sales_records
                           WHERE farm_id = ? AND sale_date BETWEEN ? AND ? {$farmFilterSql}
                           GROUP BY farm_type");
$salesStmt->execute([$tenantFarmId, $startDate, $endDate]);
$salesSummary = $salesStmt->fetchAll(PDO::FETCH_KEY_PAIR);
if ($farmType !== 'all' && isset($salesSummary['general'])) {
    $salesSummary[$farmType] = (float)($salesSummary[$farmType] ?? 0) + (float)$salesSummary['general'];
    unset($salesSummary['general']);
}

$expenseStmt = $pdo->prepare("SELECT farm_type, SUM(amount * unit) AS total_expenses
                              FROM farm_expenses
                              WHERE farm_id = ? AND expense_date BETWEEN ? AND ?" .
                              ($farmType === '' ? ' AND 1 = 0' : ($farmType === 'all' ? '' : " AND (farm_type = ? OR farm_type = 'both')")) .
                              " GROUP BY farm_type");
$expenseParams = [$tenantFarmId, $startDate, $endDate];
if ($farmType !== '' && $farmType !== 'all') {
    $expenseParams[] = $farmType;
}
$expenseStmt->execute($expenseParams);
$expenseRows = $expenseStmt->fetchAll();
$expenseSummary = ['poultry' => 0, 'ruminant' => 0];
foreach ($expenseRows as $row) {
    if ($row['farm_type'] === 'both') {
        if ($farmType === 'all') {
            $expenseSummary['poultry'] += (float)$row['total_expenses'];
            $expenseSummary['ruminant'] += (float)$row['total_expenses'];
        } else {
            $expenseSummary[$farmType] += (float)$row['total_expenses'];
        }
    } else {
        $expenseSummary[$row['farm_type']] = (float)$row['total_expenses'];
    }
}

$layerStartStmt = $pdo->prepare("SELECT opening_stock FROM layer_daily_records
                                WHERE farm_id = ? AND record_date BETWEEN ? AND ?
                                ORDER BY record_date ASC
                                LIMIT 1");
$layerStartStmt->execute([$tenantFarmId, $startDate, $endDate]);
$layerOpeningStock = (int)($layerStartStmt->fetchColumn() ?: 0);

$layerStatStmt = $pdo->prepare("SELECT COUNT(*) AS days_count, SUM(mortality) AS mortality,
                                SUM(feed_consumption_bags) AS feed, SUM(egg_production) AS eggs
                                FROM layer_daily_records WHERE farm_id = ? AND record_date BETWEEN ? AND ?");
$layerStatStmt->execute([$tenantFarmId, $startDate, $endDate]);
$layer = $layerStatStmt->fetch();
$layerMortality = (int)($layer['mortality'] ?? 0);
$layerClosingStock = max(0, $layerOpeningStock - $layerMortality);

$broilerStartStmt = $pdo->prepare("SELECT opening_stock FROM broiler_daily_records
                                  WHERE farm_id = ? AND record_date BETWEEN ? AND ?
                                  ORDER BY record_date ASC
                                  LIMIT 1");
$broilerStartStmt->execute([$tenantFarmId, $startDate, $endDate]);
$broilerOpeningStock = (int)($broilerStartStmt->fetchColumn() ?: 0);

$broilerStatStmt = $pdo->prepare("SELECT COUNT(*) AS days_count, SUM(mortality) AS mortality,
                                  SUM(feed_consumption_bags) AS feed
                                  FROM broiler_daily_records WHERE farm_id = ? AND record_date BETWEEN ? AND ?");
$broilerStatStmt->execute([$tenantFarmId, $startDate, $endDate]);
$broiler = $broilerStatStmt->fetch();
$broilerMortality = (int)($broiler['mortality'] ?? 0);
$broilerClosingStock = max(0, $broilerOpeningStock - $broilerMortality);

$ruminantStartDateStmt = $pdo->prepare("SELECT MIN(record_date) FROM ruminant_daily_records WHERE farm_id = ? AND record_date BETWEEN ? AND ?");
$ruminantStartDateStmt->execute([$tenantFarmId, $startDate, $endDate]);
$ruminantFirstDate = $ruminantStartDateStmt->fetchColumn();

$ruminantOpeningStock = 0;
if ($ruminantFirstDate) {
    $ruminantOpeningStmt = $pdo->prepare("SELECT SUM(opening_stock) FROM ruminant_daily_records WHERE farm_id = ? AND record_date = ?");
    $ruminantOpeningStmt->execute([$tenantFarmId, $ruminantFirstDate]);
    $ruminantOpeningStock = (int)($ruminantOpeningStmt->fetchColumn() ?: 0);
}

$ruminantStatStmt = $pdo->prepare("SELECT COUNT(*) AS entries_count, SUM(mortality) AS mortality,
                                   SUM(feed_consumption_kg) AS feed
                                   FROM ruminant_daily_records WHERE farm_id = ? AND record_date BETWEEN ? AND ?");
$ruminantStatStmt->execute([$tenantFarmId, $startDate, $endDate]);
$ruminant = $ruminantStatStmt->fetch();
$ruminantMortality = (int)($ruminant['mortality'] ?? 0);
$ruminantClosingStock = max(0, $ruminantOpeningStock - $ruminantMortality);

$stockStmt = $pdo->query("SELECT farm_type, COUNT(*) AS items, SUM(current_stock * unit_cost) AS stock_value
                          FROM stock_items WHERE farm_id = $tenantFarmId AND is_active = 1 GROUP BY farm_type");
$stockRows = $stockStmt->fetchAll();
$stockSummary = ['poultry' => ['items' => 0, 'stock_value' => 0], 'ruminant' => ['items' => 0, 'stock_value' => 0]];
foreach ($stockRows as $row) {
    if ($row['farm_type'] === 'both') {
        $stockSummary['poultry']['items'] += (int)$row['items'];
        $stockSummary['ruminant']['items'] += (int)$row['items'];
        $stockSummary['poultry']['stock_value'] += (float)$row['stock_value'];
        $stockSummary['ruminant']['stock_value'] += (float)$row['stock_value'];
    } else {
        $stockSummary[$row['farm_type']] = ['items' => (int)$row['items'], 'stock_value' => (float)$row['stock_value']];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include(__DIR__ . '/../navbar_head.php'); ?>
    <title>Poultry & Ruminant Report - Renee Farms</title>
</head>
<body>
<?php include(__DIR__ . '/../navbar.php'); ?>
<div class="container-fluid mt-4">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4><i class="bi bi-clipboard-data"></i> Poultry & Ruminant Report - <?php echo htmlspecialchars($periodLabel); ?></h4>
            <div class="d-flex gap-2 report-controls">
                <select class="form-select" id="farmTypeFilter" style="width: 150px;">
                    <?php if ($canChooseFarmType): ?>
                        <?php if (count(accessibleFarmTypes()) === 2): ?><option value="all" <?php echo $farmType === 'all' ? 'selected' : ''; ?>>All Farms</option><?php endif; ?>
                        <?php foreach (accessibleFarmTypes() as $type): ?><option value="<?php echo $type; ?>" <?php echo $farmType === $type ? 'selected' : ''; ?>><?php echo ucfirst($type); ?></option><?php endforeach; ?>
                    <?php else: ?>
                        <option value="<?php echo $farmType; ?>" selected><?php echo ucfirst($farmType); ?></option>
                    <?php endif; ?>
                </select>
                <select class="form-select" id="reportMode" style="width: 140px;">
                    <option value="monthly" <?php echo $reportMode === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="yearly" <?php echo $reportMode === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                </select>
                <input type="date" class="form-control js-calendar-input" id="monthFilter" value="<?php echo $monthFilterDate ?? date('Y-m-d'); ?>" style="width:170px;<?php echo $reportMode === 'yearly' ? 'display:none;' : ''; ?>">
                <select class="form-select" id="yearFilter" style="width:130px;<?php echo $reportMode === 'monthly' ? 'display:none;' : ''; ?>">
                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo (string)$y === (string)$year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <button class="btn btn-primary" id="printBtn"><i class="bi bi-printer"></i> Print Report</button>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <?php if (in_array('poultry', $visibleFarmTypes, true)): ?>
                <div class="col-md-6"><div class="card border-info"><div class="card-body"><h6>Poultry Sales</h6><h3>₦<?php echo number_format($salesSummary['poultry'] ?? 0, 2); ?></h3></div></div></div>
                <div class="col-md-6"><div class="card border-danger"><div class="card-body"><h6>Poultry Expenses</h6><h3>₦<?php echo number_format($expenseSummary['poultry'] ?? 0, 2); ?></h3></div></div></div>
                <?php endif; ?>
                <?php if (in_array('ruminant', $visibleFarmTypes, true)): ?>
                <div class="col-md-6"><div class="card border-warning"><div class="card-body"><h6>Ruminant Sales</h6><h3>₦<?php echo number_format($salesSummary['ruminant'] ?? 0, 2); ?></h3></div></div></div>
                <div class="col-md-6"><div class="card border-danger"><div class="card-body"><h6>Ruminant Expenses</h6><h3>₦<?php echo number_format($expenseSummary['ruminant'] ?? 0, 2); ?></h3></div></div></div>
                <?php endif; ?>
                <?php if ($farmType === 'all' && isset($salesSummary['general'])): ?>
                <div class="col-md-6"><div class="card border-success"><div class="card-body"><h6>General Sales</h6><h3>₦<?php echo number_format($salesSummary['general'], 2); ?></h3></div></div></div>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-dark"><tr><th>Section</th><th>Opening Stock</th><th>Mortality</th><th>Closing Stock</th><th>Feeds Consumed</th><th>Eggs Laid</th><th>Stock Items</th><th>Stock Value</th></tr></thead>
                    <tbody>
                        <?php if (in_array('poultry', $visibleFarmTypes, true)): ?><tr>
                            <td>Layers</td><td><?php echo $layerOpeningStock; ?></td><td><?php echo $layerMortality; ?></td><td><?php echo $layerClosingStock; ?></td><td><?php echo number_format((float)($layer['feed'] ?? 0), 2); ?> bags</td><td><?php echo (int)($layer['eggs'] ?? 0); ?></td><td><?php echo $stockSummary['poultry']['items']; ?></td><td>₦<?php echo number_format($stockSummary['poultry']['stock_value'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>Broilers</td><td><?php echo $broilerOpeningStock; ?></td><td><?php echo $broilerMortality; ?></td><td><?php echo $broilerClosingStock; ?></td><td><?php echo number_format((float)($broiler['feed'] ?? 0), 2); ?> bags</td><td>N/A</td><td><?php echo $stockSummary['poultry']['items']; ?></td><td>₦<?php echo number_format($stockSummary['poultry']['stock_value'], 2); ?></td>
                        </tr><?php endif; ?>
                        <?php if (in_array('ruminant', $visibleFarmTypes, true)): ?><tr>
                            <td>Ruminants</td><td><?php echo $ruminantOpeningStock; ?></td><td><?php echo $ruminantMortality; ?></td><td><?php echo $ruminantClosingStock; ?></td><td><?php echo number_format((float)($ruminant['feed'] ?? 0), 2); ?> kg</td><td>N/A</td><td><?php echo $stockSummary['ruminant']['items']; ?></td><td>₦<?php echo number_format($stockSummary['ruminant']['stock_value'], 2); ?></td>
                        </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/jquery/jquery.min.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/js/main.js'); ?>"></script>
<script>
function applyFilters() {
    const farmType = $('#farmTypeFilter').val();
    const reportMode = $('#reportMode').val();
    const monthValue = $('#monthFilter').val();
    const month = monthValue ? monthValue.substring(0, 7) : '';
    const year = $('#yearFilter').val();
    window.location.href = `poultry_ruminant_report.php?report_mode=${reportMode}&month=${month}&year=${year}&farm_type=${farmType}`;
}
$('#farmTypeFilter, #reportMode, #monthFilter, #yearFilter').on('change', function() {
    const mode = $('#reportMode').val();
    $('#monthFilter').toggle(mode === 'monthly');
    $('#yearFilter').toggle(mode === 'yearly');
    applyFilters();
});
$('#printBtn').on('click', () => window.print());
</script>
</body>
</html>
