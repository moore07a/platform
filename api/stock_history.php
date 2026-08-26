<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(dirname(__DIR__) . '/config.php');
require_once(dirname(__DIR__) . '/includes/functions.php');
requireLogin();

$itemId = $_GET['item_id'] ?? null;

if (!$itemId) {
    $_SESSION['error'] = 'No inventory item selected.';
    header('Location: ' . BASE_URL . '/inventory.php');
    exit();
}

$itemStmt = $pdo->prepare("SELECT si.*, ic.category_name FROM stock_items si JOIN inventory_categories ic ON si.category_id = ic.id AND ic.farm_id = si.farm_id WHERE si.id = ? AND si.farm_id = ?");
$itemStmt->execute([$itemId, requireCurrentFarmId()]);
$item = $itemStmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    $_SESSION['error'] = 'Inventory item not found.';
    header('Location: ' . BASE_URL . '/inventory.php');
    exit();
}

$pageTitle = 'Stock History - ' . htmlspecialchars($item['item_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <?php include(dirname(__DIR__) . '/navbar_head.php'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
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
    <style>
        .summary-card {
            border: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .summary-value {
            font-size: 1.5rem;
            font-weight: 700;
        }
        .history-table td, .history-table th {
            vertical-align: middle;
        }
    </style>
</head>
<body>
<?php include(dirname(__DIR__) . '/navbar.php'); ?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0">Stock History</h3>
            <p class="text-muted mb-0">Tracking history for <strong><?php echo htmlspecialchars($item['item_name']); ?></strong> (<?php echo htmlspecialchars($item['unit']); ?>)</p>
        </div>
        <div>
            <a href="<?php echo BASE_URL; ?>/inventory.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Inventory</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card summary-card p-3">
                <small class="text-muted">Current Stock</small>
                <div id="currentStock" class="summary-value">--</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card p-3">
                <small class="text-muted">Total Received</small>
                <div id="totalReceived" class="summary-value text-success">--</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card p-3">
                <small class="text-muted">Total Used</small>
                <div id="totalUsed" class="summary-value text-danger">--</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card summary-card p-3">
                <small class="text-muted">Transactions</small>
                <div id="transactionCount" class="summary-value">--</div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="card-title mb-0">Stock Trend</h5>
                <select id="daysFilter" class="form-select form-select-sm" style="width:auto;">
                    <option value="30">Last 30 days</option>
                    <option value="60">Last 60 days</option>
                    <option value="90">Last 90 days</option>
                    <option value="180">Last 180 days</option>
                    <option value="365">Last 365 days</option>
                </select>
            </div>
            <canvas id="stockChart" height="120"></canvas>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="card-title mb-0">Transaction History</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-striped history-table mb-0" id="historyTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Previous Stock</th>
                            <th class="text-end">New Stock</th>
                            <th>Remarks</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="7" class="text-center text-muted">Loading history...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const itemId = <?php echo json_encode($itemId); ?>;
let chartInstance = null;

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric' });
}

function renderChart(chartData) {
    const ctx = document.getElementById('stockChart').getContext('2d');
    if (chartInstance) {
        chartInstance.destroy();
    }
    chartInstance = new Chart(ctx, {
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
}

function renderTable(transactions) {
    const tbody = document.querySelector('#historyTable tbody');
    tbody.innerHTML = '';

    if (!transactions || transactions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No history available for the selected period.</td></tr>';
        return;
    }

    transactions.forEach(tx => {
        const row = document.createElement('tr');
        const typeBadge = tx.transaction_type === 'received' ?
            '<span class="badge bg-success">Received</span>' :
            '<span class="badge bg-danger">Used</span>';

        row.innerHTML = `
            <td>${formatDate(tx.transaction_date)}</td>
            <td>${typeBadge}</td>
            <td class="text-end">${Number(tx.quantity).toLocaleString()}</td>
            <td class="text-end">${Number(tx.previous_stock).toLocaleString()}</td>
            <td class="text-end">${Number(tx.new_stock).toLocaleString()}</td>
            <td>${tx.remarks ? tx.remarks : ''}</td>
            <td>${tx.full_name ? tx.full_name : 'N/A'}</td>
        `;
        tbody.appendChild(row);
    });
}

function updateSummary(summary, currentStock) {
    document.getElementById('currentStock').textContent = Number(currentStock ?? 0).toLocaleString();
    document.getElementById('totalReceived').textContent = Number(summary.total_received ?? 0).toLocaleString();
    document.getElementById('totalUsed').textContent = Number(summary.total_used ?? 0).toLocaleString();
    document.getElementById('transactionCount').textContent = summary.transaction_count ?? 0;
}

async function loadHistory(days = 30) {
    const response = await fetch(`<?php echo BASE_URL; ?>/api/get_stock_history.php?item_id=${itemId}&days=${days}`);
    const data = await response.json();

    if (data.error) {
        document.querySelector('#historyTable tbody').innerHTML = `<tr><td colspan="7" class="text-danger text-center">${data.error}</td></tr>`;
        return;
    }

    renderChart(data.chart_data);
    renderTable(data.transactions);
    updateSummary(data.summary, data.current_stock);
}

document.getElementById('daysFilter').addEventListener('change', (event) => {
    loadHistory(event.target.value);
});

loadHistory();
</script>
</body>
</html>
