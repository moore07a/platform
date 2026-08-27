<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();
requireBusinessReportAccess();
$tenantFarmId = requireCurrentFarmId();

$userType = getUserType();
$userFarmType = getUserFarmType();
$canChooseFarmType = isPlatformOwner() || hasRole('farm_admin', 'sales_rep');

$year = $_GET['year'] ?? date('Y');
$requestedFarmType = $canChooseFarmType ? ($_GET['farm_type'] ?? null) : $userFarmType;
// User access represents a dual-module assignment as "both", while report
// filters represent the same combined read scope as "all".
if ($requestedFarmType === 'both' && count(accessibleFarmTypes()) === 2) {
    $requestedFarmType = 'all';
}

// Sales-only farms have no livestock scope to normalize, but their neutral
// general sales must remain available to the analytics dashboard and export.
$salesOnlyScope = enabledFarmTypes() === []
    && farmHasModule('sales')
    && (isPlatformOwner() || hasRole('farm_admin', 'sales_rep', 'viewer'));
$farmType = $salesOnlyScope
    ? 'general'
    : normalizeFarmType($requestedFarmType, true, false, $canChooseFarmType);
$startDate = $year . '-01-01';
$endDate = $year . '-12-31';

// Get profit/loss data aggregated from sales and expenses
$salesQuery = "SELECT DATE_FORMAT(sale_date, '%Y-%m') AS month, farm_type, SUM(total_amount) AS total_sales
               FROM sales_records
               WHERE farm_id = ? AND sale_date BETWEEN ? AND ?";
$salesParams = [$tenantFarmId, $startDate, $endDate];

if ($farmType === '') {
    $salesQuery .= " AND 1 = 0";
} elseif ($farmType !== 'all') {
    $salesQuery .= " AND (farm_type = ? OR farm_type = 'general')";
    $salesParams[] = $farmType;
}

$salesQuery .= " GROUP BY month, farm_type";
$salesStmt = $pdo->prepare($salesQuery);
$salesStmt->execute($salesParams);
$salesData = $salesStmt->fetchAll();

$expenseQuery = "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month, farm_type, category, SUM(amount * unit) AS total_amount
                 FROM farm_expenses
                 WHERE farm_id = ? AND expense_date BETWEEN ? AND ?";
$expenseParams = [$tenantFarmId, $startDate, $endDate];

if ($farmType === '' || $salesOnlyScope) {
    $expenseQuery .= " AND 1 = 0";
} elseif ($farmType !== 'all') {
    $expenseQuery .= " AND (farm_type = ? OR farm_type = 'both')";
    $expenseParams[] = $farmType;
}

$expenseQuery .= " GROUP BY month, farm_type, category";
$expenseStmt = $pdo->prepare($expenseQuery);
$expenseStmt->execute($expenseParams);
$expenseData = $expenseStmt->fetchAll();

// Merge sales and expenses into profit data keyed by month and farm type
$profitData = [];

$createDefaultRow = function (string $month, string $farmType): array {
    return [
        'month' => $month,
        'farm_type' => $farmType,
        'total_sales' => 0,
        'feeds_expenses' => 0,
        'medication_expenses' => 0,
        'salary_expenses' => 0,
        'logistic_expenses' => 0,
        'fuel_expenses' => 0,
        'misc_expenses' => 0,
        'net_profit' => 0
    ];
};

foreach ($salesData as $sale) {
    $saleFarmType = $sale['farm_type'] === 'general' && $farmType !== 'all' ? $farmType : $sale['farm_type'];
    $key = $sale['month'] . '_' . $saleFarmType;
    if (!isset($profitData[$key])) {
        $profitData[$key] = $createDefaultRow($sale['month'], $saleFarmType);
    }

    $profitData[$key]['total_sales'] += (float)$sale['total_sales'];
}

foreach ($expenseData as $expense) {
    // Expenses tagged as "both" should be reflected on the selected farm type, or both when viewing all
    if ($expense['farm_type'] === 'both') {
        $targetFarmTypes = ($farmType === 'all') ? ['poultry', 'ruminant'] : [$farmType];
    } else {
        $targetFarmTypes = [$expense['farm_type']];
    }

    foreach ($targetFarmTypes as $targetFarmType) {
        $key = $expense['month'] . '_' . $targetFarmType;
        if (!isset($profitData[$key])) {
            $profitData[$key] = $createDefaultRow($expense['month'], $targetFarmType);
        }

        $categoryField = $expense['category'] . '_expenses';
        if (array_key_exists($categoryField, $profitData[$key])) {
            $profitData[$key][$categoryField] += (float)$expense['total_amount'];
        }
    }
}

foreach ($profitData as $key => $data) {
    $totalExpenses = $data['feeds_expenses'] +
                    $data['medication_expenses'] +
                    $data['salary_expenses'] +
                    $data['logistic_expenses'] +
                    $data['fuel_expenses'] +
                    $data['misc_expenses'];
    $profitData[$key]['net_profit'] = $data['total_sales'] - $totalExpenses;
}

// Re-index for easier looping sorted by month
uasort($profitData, function ($a, $b) {
    return strcmp($a['month'], $b['month']);
});
$profitData = array_values($profitData);

// Get top selling products
$topProductsQuery = "SELECT product_type, SUM(quantity) as total_quantity,
                     SUM(total_amount) as total_revenue
                     FROM sales_records
                     WHERE farm_id = ? AND sale_date BETWEEN ? AND ?";
$topProductParams = [$tenantFarmId, $startDate, $endDate];

if ($farmType !== 'all') {
    $topProductsQuery .= " AND farm_type = ?";
    $topProductParams[] = $farmType;
}

$topProductsQuery .= " GROUP BY product_type
                     ORDER BY total_revenue DESC
                     LIMIT 10";
$topProductsStmt = $pdo->prepare($topProductsQuery);
$topProductsStmt->execute($topProductParams);
$topProducts = $topProductsStmt->fetchAll();

// Get expense breakdown
$expenseQuery = "SELECT category, SUM(amount * unit) as total_amount
                 FROM farm_expenses
                 WHERE farm_id = ? AND expense_date BETWEEN ? AND ?";
$expenseParams = [$tenantFarmId, $startDate, $endDate];

if ($farmType === '' || $salesOnlyScope) {
    $expenseQuery .= " AND 1 = 0";
} elseif ($farmType !== 'all') {
    $expenseQuery .= " AND (farm_type = ? OR farm_type = 'both')";
    $expenseParams[] = $farmType;
}

$expenseQuery .= " GROUP BY category
                 ORDER BY total_amount DESC";
$expenseStmt = $pdo->prepare($expenseQuery);
$expenseStmt->execute($expenseParams);
$expenses = $expenseStmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $fileFarmType = preg_replace('/[^a-z_]/i', '', (string) $farmType);
    $fileYear = preg_replace('/[^0-9]/', '', (string) $year);
    $filename = 'farm_reports_' . ($fileFarmType ?: 'all') . '_' . ($fileYear ?: date('Y')) . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        exit();
    }

    fputcsv($output, ['Farm Reports & Analytics']);
    fputcsv($output, ['Year', $year]);
    fputcsv($output, ['Farm Type', ucfirst($farmType)]);
    fputcsv($output, []);

    fputcsv($output, [
        'Month',
        'Farm Type',
        'Sales',
        'Feeds Expenses',
        'Medication',
        'Salary',
        'Logistic',
        'Fuel',
        'Misc',
        'Total Expenses',
        'Net Profit'
    ]);

    foreach ($profitData as $data) {
        $totalExpenses = $data['feeds_expenses']
            + $data['medication_expenses']
            + $data['salary_expenses']
            + $data['logistic_expenses']
            + $data['fuel_expenses']
            + $data['misc_expenses'];

        fputcsv($output, [
            date('M Y', strtotime($data['month'] . '-01')),
            ucfirst($data['farm_type']),
            number_format((float) $data['total_sales'], 2, '.', ''),
            number_format((float) $data['feeds_expenses'], 2, '.', ''),
            number_format((float) $data['medication_expenses'], 2, '.', ''),
            number_format((float) $data['salary_expenses'], 2, '.', ''),
            number_format((float) $data['logistic_expenses'], 2, '.', ''),
            number_format((float) $data['fuel_expenses'], 2, '.', ''),
            number_format((float) $data['misc_expenses'], 2, '.', ''),
            number_format((float) $totalExpenses, 2, '.', ''),
            number_format((float) $data['net_profit'], 2, '.', ''),
        ]);
    }

    if (!empty($topProducts)) {
        fputcsv($output, []);
        fputcsv($output, ['Top Selling Products']);
        fputcsv($output, ['Product Type', 'Total Quantity', 'Total Revenue']);
        foreach ($topProducts as $product) {
            fputcsv($output, [
                $product['product_type'],
                number_format((float) $product['total_quantity'], 2, '.', ''),
                number_format((float) $product['total_revenue'], 2, '.', ''),
            ]);
        }
    }

    if (!empty($expenses)) {
        fputcsv($output, []);
        fputcsv($output, ['Expense Breakdown']);
        fputcsv($output, ['Category', 'Total Amount']);
        foreach ($expenses as $expense) {
            fputcsv($output, [
                ucfirst($expense['category']),
                number_format((float) $expense['total_amount'], 2, '.', ''),
            ]);
        }
    }

    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include(__DIR__ . '/../navbar_head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Farm Management System</title>
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
</head>
<body>
    <?php include(__DIR__ . '/../navbar.php'); ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="bi bi-graph-up-arrow"></i> Farm Reports & Analytics</h4>
                        <div class="d-flex gap-2 mt-2 report-controls">
                            <select class="form-select" id="yearFilter" style="width: 150px;">
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                            <select class="form-select" id="farmTypeFilter" style="width: 200px;">
                                <?php if ($canChooseFarmType): ?>
                                <?php if (count(accessibleFarmTypes()) === 2): ?><option value="all" <?php echo $farmType == 'all' ? 'selected' : ''; ?>>All Farms</option><?php endif; ?>
                                <?php foreach (accessibleFarmTypes() as $type): ?><option value="<?php echo $type; ?>" <?php echo $farmType === $type ? 'selected' : ''; ?>><?php echo ucfirst($type); ?> Only</option><?php endforeach; ?>
                                <?php else: ?>
                                <option value="<?php echo $farmType; ?>" selected><?php echo ucfirst($farmType); ?> Only</option>
                                <?php endif; ?>
                            </select>
                            <button class="btn btn-primary" onclick="printReport()">
                                <i class="bi bi-printer"></i> Print Report
                            </button>
                            <button class="btn btn-success" onclick="exportToExcel()">
                                <i class="bi bi-file-earmark-excel"></i> Export Excel
                            </button>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <!-- Profit/Loss Chart -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Monthly Profit/Loss Analysis</h5>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="profitChart" height="100"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Yearly Summary</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        $yearlyTotals = [
                                            'sales' => 0,
                                            'expenses' => 0,
                                            'profit' => 0
                                        ];
                                        
                                        foreach ($profitData as $data) {
                                            $yearlyTotals['sales'] += $data['total_sales'];
                                            $yearlyTotals['expenses'] += ($data['feeds_expenses'] +
                                                                          $data['medication_expenses'] +
                                                                          $data['salary_expenses'] +
                                                                          $data['logistic_expenses'] +
                                                                          $data['fuel_expenses'] +
                                                                          $data['misc_expenses']);
                                            $yearlyTotals['profit'] += $data['net_profit'];
                                        }
                                        ?>
                                        <div class="mb-3">
                                            <h6>Total Sales</h6>
                                            <h3 class="text-success">₦<?php echo number_format($yearlyTotals['sales'], 2); ?></h3>
                                        </div>
                                        <div class="mb-3">
                                            <h6>Total Expenses</h6>
                                            <h3 class="text-danger">₦<?php echo number_format($yearlyTotals['expenses'], 2); ?></h3>
                                        </div>
                                        <div class="mb-3">
                                            <h6>Net Profit</h6>
                                            <h3 class="<?php echo $yearlyTotals['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                ₦<?php echo number_format($yearlyTotals['profit'], 2); ?>
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Detailed Profit/Loss Table -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5>Detailed Profit/Loss Statement</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Month</th>
                                                <th>Farm Type</th>
                                                <th>Sales</th>
                                                <th>Feeds Expenses</th>
                                                <th>Medication</th>
                                                <th>Salary</th>
                                                <th>Logistic</th>
                                                <th>Fuel</th>
                                                <th>Misc</th>
                                                <th>Total Expenses</th>
                                                <th>Net Profit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($profitData as $data):
                                                $totalExpenses = $data['feeds_expenses'] +
                                                                $data['medication_expenses'] +
                                                                $data['salary_expenses'] +
                                                                $data['logistic_expenses'] +
                                                                $data['fuel_expenses'] +
                                                                $data['misc_expenses'];
                                            ?>
                                            <tr>
                                                <td><?php echo date('M Y', strtotime($data['month'] . '-01')); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $data['farm_type'] == 'poultry' ? 'info' : 'warning'; ?>">
                                                        <?php echo ucfirst($data['farm_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-success">₦<?php echo number_format($data['total_sales'], 2); ?></td>
                                                <td>₦<?php echo number_format($data['feeds_expenses'], 2); ?></td>
                                                <td>₦<?php echo number_format($data['medication_expenses'], 2); ?></td>
                                                <td>₦<?php echo number_format($data['salary_expenses'], 2); ?></td>
                                                <td>₦<?php echo number_format($data['logistic_expenses'], 2); ?></td>
                                                <td>₦<?php echo number_format($data['fuel_expenses'], 2); ?></td>
                                                <td>₦<?php echo number_format($data['misc_expenses'], 2); ?></td>
                                                <td class="text-danger">₦<?php echo number_format($totalExpenses, 2); ?></td>
                                                <td class="fw-bold <?php echo $data['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    ₦<?php echo number_format($data['net_profit'], 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Charts -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Top Selling Products</h5>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="productsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Expense Breakdown</h5>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="expensesChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
 <script src="<?php echo BASE_URL; ?>/assets/js/edit-modal.js"></script>
    <script>
    // Filter change
    document.getElementById('yearFilter').addEventListener('change', function() {
        updateReport();
    });
    
    document.getElementById('farmTypeFilter').addEventListener('change', function() {
        updateReport();
    });
    
    function updateReport() {
        const year = document.getElementById('yearFilter').value;
        const farmType = document.getElementById('farmTypeFilter').value;
        window.location.href = `reports.php?year=${year}&farm_type=${farmType}`;
    }
    
    function printReport() {
        window.print();
    }
    
    function exportToExcel() {
        const year = document.getElementById('yearFilter').value;
        const farmType = document.getElementById('farmTypeFilter').value;
        window.location.href = `reports.php?year=${year}&farm_type=${farmType}&export=excel`;
    }
    
    // Initialize charts when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Profit/Loss Chart
        const profitCtx = document.getElementById('profitChart').getContext('2d');
        const profitChart = new Chart(profitCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($d) { 
                    return date('M', strtotime($d['month'] . '-01')); 
                }, $profitData)); ?>,
                datasets: [{
                    label: 'Net Profit (₦)',
                    data: <?php echo json_encode(array_column($profitData, 'net_profit')); ?>,
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₦' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        // Top Products Chart
        const productsCtx = document.getElementById('productsChart').getContext('2d');
        const productsChart = new Chart(productsCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($topProducts, 'product_type')); ?>,
                datasets: [{
                    label: 'Revenue (₦)',
                    data: <?php echo json_encode(array_column($topProducts, 'total_revenue')); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₦' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        // Expenses Chart
        const expensesCtx = document.getElementById('expensesChart').getContext('2d');
        const expensesChart = new Chart(expensesCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($expenses, 'category')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($expenses, 'total_amount')); ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.7)',
                        'rgba(54, 162, 235, 0.7)',
                        'rgba(255, 206, 86, 0.7)',
                        'rgba(75, 192, 192, 0.7)',
                        'rgba(153, 102, 255, 0.7)',
                        'rgba(255, 159, 64, 0.7)'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '₦' + context.parsed.toLocaleString();
                                return label;
                            }
                        }
                    }
                }
            }
        });
    });
    </script>
</body>
</html>
