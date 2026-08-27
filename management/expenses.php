<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../includes/functions.php');
requireLogin();
requireBusinessReportAccess();
$tenantFarmId = requireCurrentFarmId();

$userType = getUserType();
$userFarmType = getUserFarmType();
$canManageExpenses = isPlatformOwner() || hasRole('farm_admin');
$canChooseFarmType = isPlatformOwner() || hasRole('farm_admin', 'sales_rep');

$reportMode = $_GET['report_mode'] ?? 'monthly';
$month = $_GET['month'] ?? date('Y-m');
$year = $_GET['year'] ?? date('Y');

if ($reportMode === 'yearly') {
    $year = date('Y', strtotime($year . '-01-01'));
    $startDate = $year . '-01-01';
    $endDate = $year . '-12-31';
    $periodLabel = $year;
} else {
    $selectedMonth = date('Y-m', strtotime($month . '-01'));
    $monthFilterDate = date('Y-m-d', strtotime($selectedMonth . '-' . min((int)date('d'), (int)date('t', strtotime($selectedMonth . '-01')))));
    $startDate = date('Y-m-01', strtotime($selectedMonth . '-01'));
    $endDate = date('Y-m-t', strtotime($selectedMonth . '-01'));
    $periodLabel = date('F Y', strtotime($selectedMonth));
}

$farmType = normalizeFarmType($canChooseFarmType ? ($_GET['farm_type'] ?? null) : $userFarmType, true, false, $canChooseFarmType);
$category = $_GET['category'] ?? 'all';

// Build query based on filters
$whereClause = "WHERE e.farm_id = ? AND e.expense_date BETWEEN ? AND ?";
$params = [$tenantFarmId, $startDate, $endDate];

if ($farmType === '') {
    $whereClause .= " AND 1 = 0";
} elseif ($farmType !== 'all') {
    // Shared expenses continue to apply when only one livestock module remains enabled.
    $whereClause .= " AND (e.farm_type = ? OR e.farm_type = 'both')";
    $params[] = $farmType;
}

if ($category !== 'all') {
    $whereClause .= " AND e.category = ?";
    $params[] = $category;
}

$query = "SELECT e.*, u.full_name 
          FROM farm_expenses e
          LEFT JOIN users u ON e.user_id = u.id AND u.farm_id = e.farm_id
          {$whereClause}
          ORDER BY e.expense_date DESC";
          
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Calculate totals
$totalExpenses = 0;
$categoryTotals = [];
$farmTypeTotals = [];

foreach ($expenses as $expense) {
    $lineTotal = (float)($expense['amount'] ?? 0) * (float)($expense['unit'] ?? 1);
    $totalExpenses += $lineTotal;
    $categoryTotals[$expense['category']] = ($categoryTotals[$expense['category']] ?? 0) + $lineTotal;
    $farmTypeTotals[$expense['farm_type']] = ($farmTypeTotals[$expense['farm_type']] ?? 0) + $lineTotal;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include(__DIR__ . '/../navbar_head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Report - Renee Farms</title>
</head>
<body>
    <?php include(__DIR__ . '/../navbar.php'); ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4>
                            <i class="bi bi-cash-stack"></i> 
                            Expense Report - <?php echo htmlspecialchars($periodLabel); ?>
                        </h4>
                        <div class="d-flex gap-2 report-controls">
                            <select class="form-select" id="farmTypeFilter" style="width: 150px;">
                                <?php if ($canChooseFarmType): ?>
                                <?php if (count(enabledFarmTypes()) === 2): ?><option value="all" <?php echo $farmType == 'all' ? 'selected' : ''; ?>>All Farms</option><?php endif; ?>
                                <?php foreach (enabledFarmTypes() as $type): ?><option value="<?php echo $type; ?>" <?php echo $farmType === $type ? 'selected' : ''; ?>><?php echo ucfirst($type); ?></option><?php endforeach; ?>
                                <?php else: ?>
                                <option value="<?php echo $farmType; ?>" selected><?php echo ucfirst($farmType); ?></option>
                                <?php endif; ?>
                            </select>
                            <select class="form-select" id="categoryFilter" style="width: 150px;">
                                <option value="all" <?php echo $category == 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <option value="feeds" <?php echo $category == 'feeds' ? 'selected' : ''; ?>>Feeds</option>
                                <option value="medication" <?php echo $category == 'medication' ? 'selected' : ''; ?>>Medication</option>
                                <option value="salary" <?php echo $category == 'salary' ? 'selected' : ''; ?>>Salary</option>
                                <option value="logistic" <?php echo $category == 'logistic' ? 'selected' : ''; ?>>Logistic</option>
                                <option value="fuel" <?php echo $category == 'fuel' ? 'selected' : ''; ?>>Fuel</option>
                                <option value="misc" <?php echo $category == 'misc' ? 'selected' : ''; ?>>Misc</option>
                            </select>
                            <select class="form-select" id="reportMode" style="width: 140px;">
                                <option value="monthly" <?php echo $reportMode === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="yearly" <?php echo $reportMode === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                            </select>
                            <input type="date" class="form-control js-calendar-input" id="monthFilter"
                                   value="<?php echo $monthFilterDate ?? date('Y-m-d'); ?>" style="width: 170px; <?php echo $reportMode === 'yearly' ? 'display:none;' : ''; ?>">
                            <select class="form-select" id="yearFilter" style="width: 130px; <?php echo $reportMode === 'monthly' ? 'display:none;' : ''; ?>">
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo (string)$y === (string)$year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                            <button class="btn btn-primary" id="printMonthlyBtn" <?php echo $reportMode === 'yearly' ? 'style="display:none;"' : ''; ?>><i class="bi bi-printer"></i> Print Monthly</button>
                            <button class="btn btn-primary" id="printYearlyBtn" <?php echo $reportMode === 'monthly' ? 'style="display:none;"' : ''; ?>><i class="bi bi-printer"></i> Print Yearly</button>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="card-body bg-light">
                        <!-- Total Expenses -->
                        <div class="card bg-danger text-white mb-4">
                            <div class="card-body text-center">
                                <h1>TOTAL EXPENSES: ₦<?php echo number_format($totalExpenses, 2); ?></h1>
                                <h5>For <?php echo htmlspecialchars($periodLabel); ?></h5>
                            </div>
                        </div>
                        
                        <!-- Breakdown -->
                        <div class="row mb-4">
                            <!-- Farm Type Breakdown -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6>By Farm Type</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($farmTypeTotals as $type => $total): 
                                            $percentage = $totalExpenses > 0 ? ($total / $totalExpenses * 100) : 0;
                                        ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>
                                                    <span class="badge bg-<?php 
                                                        echo $type == 'poultry' ? 'info' : 
                                                             ($type == 'ruminant' ? 'warning' : 'secondary'); 
                                                    ?>">
                                                        <?php echo ucfirst($type); ?>
                                                    </span>
                                                </span>
                                                <span>₦<?php echo number_format($total, 2); ?></span>
                                            </div>
                                            <div class="progress" style="height: 10px;">
                                                <div class="progress-bar bg-<?php 
                                                    echo $type == 'poultry' ? 'info' : 
                                                         ($type == 'ruminant' ? 'warning' : 'secondary'); 
                                                ?>" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo number_format($percentage, 1); ?>%</small>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Category Breakdown -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6>By Category</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($categoryTotals as $cat => $total): 
                                            $percentage = $totalExpenses > 0 ? ($total / $totalExpenses * 100) : 0;
                                        ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>
                                                    <span class="badge bg-<?php 
                                                        switch($cat) {
                                                            case 'feeds': echo 'primary'; break;
                                                            case 'medication': echo 'success'; break;
                                                            case 'salary': echo 'warning'; break;
                                                            case 'logistic': echo 'info'; break;
                                                            case 'fuel': echo 'secondary'; break;
                                                            default: echo 'dark';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst($cat); ?>
                                                    </span>
                                                </span>
                                                <span>₦<?php echo number_format($total, 2); ?></span>
                                            </div>
                                            <div class="progress" style="height: 10px;">
                                                <div class="progress-bar bg-<?php 
                                                    switch($cat) {
                                                        case 'feeds': echo 'primary'; break;
                                                        case 'medication': echo 'success'; break;
                                                        case 'salary': echo 'warning'; break;
                                                        case 'logistic': echo 'info'; break;
                                                        case 'fuel': echo 'secondary'; break;
                                                        default: echo 'dark';
                                                    }
                                                ?>" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?php echo number_format($percentage, 1); ?>%</small>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Detailed Expenses Table -->
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Farm Type</th>
                                        <th>Category</th>
                                        <th>Unit</th>
                                        <th>Amount</th>
                                        <th>Total</th>
                                        <th>Description</th>
                                        <th>Recorded By</th>
                                        <?php if ($canManageExpenses): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($expenses)): ?>
                                    <tr>
                                        <td colspan="<?php echo $canManageExpenses ? '9' : '8'; ?>" class="text-center text-muted py-4">
                                            <i class="bi bi-receipt display-4 d-block mb-2"></i>
                                            No expenses recorded for this period
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($expenses as $expense): 
                                            $lineTotal = (float)($expense['amount'] ?? 0) * (float)($expense['unit'] ?? 1);
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo date('d/m/Y', strtotime($expense['expense_date'])); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $expense['farm_type'] == 'poultry' ? 'info' : 
                                                         ($expense['farm_type'] == 'ruminant' ? 'warning' : 'secondary'); 
                                                ?>">
                                                    <?php echo ucfirst($expense['farm_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    switch($expense['category']) {
                                                        case 'feeds': echo 'primary'; break;
                                                        case 'medication': echo 'success'; break;
                                                        case 'salary': echo 'warning'; break;
                                                        case 'logistic': echo 'info'; break;
                                                        case 'fuel': echo 'secondary'; break;
                                                        default: echo 'dark';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($expense['category']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo number_format($expense['unit'] ?? 1, 2); ?>
                                            </td>
                                            <td class="text-danger fw-bold">
                                                ₦<?php echo number_format($expense['amount'], 2); ?>
                                            </td>
                                            <td class="text-danger fw-bold">
                                                ₦<?php echo number_format($lineTotal, 2); ?>
                                            </td>
                                            <td>
                                                <?php echo $expense['description'] ?: '--'; ?>
                                            </td>
                                            <td>
                                                <small><?php echo $expense['full_name']; ?></small>
                                            </td>
                                            <?php if ($canManageExpenses): ?>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary edit-expense-btn"
                                                        data-id="<?php echo $expense['id']; ?>"
                                                        data-date="<?php echo $expense['expense_date']; ?>"
                                                        data-farm-type="<?php echo $expense['farm_type']; ?>"
                                                        data-category="<?php echo $expense['category']; ?>"
                                                        data-amount="<?php echo $expense['amount']; ?>"
                                                        data-unit="<?php echo $expense['unit'] ?? 1; ?>"
                                                        data-description="<?php echo htmlspecialchars($expense['description'] ?? '', ENT_QUOTES); ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                                <button class="btn btn-sm btn-outline-danger"
                                                        onclick="deleteExpense(<?php echo $expense['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canManageExpenses): ?>
    <!-- Edit Expense Modal -->
    <div class="modal fade" id="editExpenseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="editExpenseForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Expense</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="expense_id" id="editExpenseId">
                        <div class="mb-3">
                            <label>Date</label>
                            <input type="date" name="expense_date" id="editExpenseDate" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Farm Type</label>
                            <select name="farm_type" id="editFarmType" class="form-select" required>
                                <?php foreach (allowedFarmTypes() as $type): ?><option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Category</label>
                            <select name="category" id="editCategory" class="form-select" required>
                                <option value="feeds">Feeds</option>
                                <option value="medication">Medication</option>
                                <option value="salary">Salary</option>
                                <option value="logistic">Logistic</option>
                                <option value="fuel">Fuel</option>
                                <option value="misc">Misc</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Unit</label>
                            <input type="number" name="unit" id="editUnit" class="form-control" step="0.01" min="0.01" required>
                            <small class="text-muted">Multiplier used for total (Amount × Unit).</small>
                        </div>
                        <div class="mb-3">
                            <label>Amount (₦)</label>
                            <input type="number" name="amount" id="editAmount" class="form-control" step="0.01" min="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label>Description</label>
                            <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

   <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/jquery/jquery.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/jquery.dataTables.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/dataTables.bootstrap5.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables-responsive/js/dataTables.responsive.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/js/main.js'); ?>"></script>
 <script src="<?php echo BASE_URL; ?>/assets/js/edit-modal.js"></script>
    <script>
    // Filter change
    function applyFilters() {
        const farmType = $('#farmTypeFilter').val();
        const category = $('#categoryFilter').val();
        const monthValue = $('#monthFilter').val();
        const month = monthValue ? monthValue.substring(0, 7) : '';
        const reportMode = $('#reportMode').val();
        const year = $('#yearFilter').val();
        window.location.href = `expenses.php?report_mode=${reportMode}&month=${month}&year=${year}&farm_type=${farmType}&category=${category}`;
    }

    $('#farmTypeFilter, #categoryFilter, #monthFilter, #reportMode, #yearFilter').change(function() {
        const mode = $('#reportMode').val();
        $('#monthFilter').toggle(mode === 'monthly');
        $('#yearFilter').toggle(mode === 'yearly');
        $('#printMonthlyBtn').toggle(mode === 'monthly');
        $('#printYearlyBtn').toggle(mode === 'yearly');
        applyFilters();
    });

    $('#printMonthlyBtn, #printYearlyBtn').on('click', function() {
        window.print();
    });

    <?php if ($canManageExpenses): ?>
    attachEditModal({
        buttonSelector: '.edit-expense-btn',
        modalSelector: '#editExpenseModal',
            fieldMap: {
                id: '#editExpenseId',
                date: '#editExpenseDate',
                farmType: '#editFarmType',
                category: '#editCategory',
                amount: '#editAmount',
                description: '#editDescription',
                unit: '#editUnit'
            }
        });

    document.getElementById('editExpenseForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');

        try {
            const response = await fetch('../api/update_expense.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + (result.error || result.message || 'Unable to update expense'));
            }
        } catch (error) {
            alert('Network error: ' + error.message);
        }
    });
    <?php endif; ?>
    
    function deleteExpense(expenseId) {
        if (confirm('Are you sure you want to delete this expense record?')) {
            const params = new URLSearchParams({ id: expenseId, csrf_token: '<?php echo csrf_token(); ?>' });
            fetch('../api/delete_expense.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || data.message || 'Unable to delete expense'));
                    }
                });
        }
    }
    </script>
</body>
</html>
