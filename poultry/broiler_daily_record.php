<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

// Check access
$userType = getUserType();
if (!checkAccess('poultry')) {
    header('Location: dashboard.php');
    exit();
}
$canEdit = isPlatformOwner() || hasRole('farm_admin', 'poultry_manager');

$month = $_GET['month'] ?? date('Y-m');
$yearMonth = date('Y-m', strtotime($month));
$selectedCycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$monthSelectorDate = date('Y-m-d', strtotime($yearMonth . '-' . min((int)date('d'), (int)date('t', strtotime($yearMonth . '-01')))));

$tenantFarmId = requireCurrentFarmId();
$feedItemsStmt = $pdo->prepare("SELECT id, item_name, current_stock, unit, is_active FROM stock_items WHERE farm_id = ? AND feed_category = 'broiler' AND (is_active = 1 OR id IN (SELECT feed_item_id FROM broiler_daily_records WHERE farm_id = ? AND feed_stock_transaction_id IS NOT NULL)) ORDER BY is_active DESC, item_name");
$feedItemsStmt->execute([$tenantFarmId, $tenantFarmId]);
$feedItems = $feedItemsStmt->fetchAll(PDO::FETCH_ASSOC);
$cycleEnabled = ($pdo->query("SHOW TABLES LIKE 'production_cycles'")->rowCount() > 0);
$activeCycles = [];
if ($cycleEnabled) {
    $cycleStmt = $pdo->prepare("SELECT id, cycle_code FROM production_cycles WHERE farm_id = ? AND farm_type = 'poultry' AND production_type = 'broiler' AND status = 'active' ORDER BY start_date DESC");
    $cycleStmt->execute([$tenantFarmId]);
    $activeCycles = $cycleStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get records for the month
$records = [];
$query = "SELECT * FROM broiler_daily_records WHERE farm_id = ? AND DATE_FORMAT(record_date, '%Y-%m') = ?";
$params = [$tenantFarmId, $yearMonth];
if ($cycleEnabled && $selectedCycleId > 0) {
    $query .= " AND cycle_id = ?";
    $params[] = $selectedCycleId;
}
$query .= " ORDER BY record_date";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Get latest closing stock from broiler mortality table
$broilerClosingStock = null;
$latestBroilerRecord = null;
if ($cycleEnabled && $selectedCycleId === 0) {
    $latestBroilerStmt = $pdo->query("
        SELECT t1.cycle_id, t1.opening_stock, t1.mortality
        FROM broiler_daily_records t1
        INNER JOIN (
            SELECT cycle_id, MAX(record_date) AS max_date
            FROM broiler_daily_records
            WHERE farm_id = $tenantFarmId AND cycle_id IS NOT NULL AND cycle_id > 0
            GROUP BY cycle_id
        ) t2 ON t1.cycle_id = t2.cycle_id AND t1.record_date = t2.max_date
        INNER JOIN production_cycles pc ON pc.id = t1.cycle_id
        WHERE t1.farm_id = $tenantFarmId AND pc.farm_id = $tenantFarmId AND pc.farm_type = 'poultry' AND pc.status = 'active'
    ");
    $cycleRows = $latestBroilerStmt->fetchAll(PDO::FETCH_ASSOC);
    $broilerClosingStock = 0;
    foreach ($cycleRows as $row) {
        $broilerClosingStock += max(0, (float)$row['opening_stock'] - (float)$row['mortality']);
    }
} elseif ($cycleEnabled && $selectedCycleId > 0) {
    $latestBroilerStmt = $pdo->prepare("SELECT opening_stock, mortality FROM broiler_daily_records WHERE cycle_id = ? AND farm_id = ? ORDER BY record_date DESC LIMIT 1");
    $latestBroilerStmt->execute([$selectedCycleId, $tenantFarmId]);
    $latestBroilerRecord = $latestBroilerStmt->fetch();
} elseif (!$cycleEnabled) {
    $latestBroilerStmt = $pdo->query("SELECT opening_stock, mortality FROM broiler_daily_records WHERE farm_id = $tenantFarmId ORDER BY record_date DESC LIMIT 1");
    $latestBroilerRecord = $latestBroilerStmt->fetch();
}
if ($latestBroilerRecord) {
    $broilerClosingStock = $latestBroilerRecord['opening_stock'] - $latestBroilerRecord['mortality'];
}

// Calculate monthly totals
$monthlyTotals = [
    'opening_stock' => 0,
    'mortality' => 0,
    'feed_consumption' => 0,
    'water_consumption' => 0
];

foreach ($records as $record) {
    $monthlyTotals['mortality'] += $record['mortality'];
    $monthlyTotals['feed_consumption'] += $record['feed_consumption_bags'];
    $monthlyTotals['water_consumption'] += $record['water_consumption_liters'];
}

$summaryTotals = $monthlyTotals;
if ($cycleEnabled && $selectedCycleId === 0) {
    $summaryStmt = $pdo->query("
        SELECT
            COALESCE(SUM(mortality), 0) AS mortality,
            COALESCE(SUM(feed_consumption_bags), 0) AS feed_consumption,
            COALESCE(SUM(water_consumption_liters), 0) AS water_consumption
        FROM broiler_daily_records
        WHERE farm_id = $tenantFarmId
    ");
    $summaryTotals = array_merge($summaryTotals, $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_record'])) {
    $parseNumeric = static function ($value) { return str_replace(',', '', trim((string)$value)); };
    $recordDate = $_POST['record_date'];
    $feedQuantityRaw = $parseNumeric($_POST['feed_consumption'] ?? 0);
    $feedQuantity = (float)$feedQuantityRaw;
    $feedItemId = (int)($_POST['feed_item_id'] ?? 0);
    $recordId = (int)($_POST['record_id'] ?? 0);


    try {
        if (!is_numeric($feedQuantityRaw) || !is_finite($feedQuantity) || $feedQuantity < 0) {
            throw new RuntimeException('Feed consumption must be a non-negative number.');
        }
        $parsedRecordDate = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);
        $recordDateErrors = DateTimeImmutable::getLastErrors();
        if (!$parsedRecordDate || ($recordDateErrors !== false && ($recordDateErrors['warning_count'] > 0 || $recordDateErrors['error_count'] > 0))) {
            throw new RuntimeException('Please select a valid record date.');
        }
        if ($parsedRecordDate > new DateTimeImmutable('today')) {
            throw new RuntimeException('Daily records cannot be dated in the future.');
        }
        $pdo->beginTransaction();
        $checkSql = "SELECT id, feed_item_id, feed_consumption_bags, feed_stock_transaction_id FROM broiler_daily_records WHERE farm_id = ?";
        $checkParams = [$tenantFarmId];
        if ($recordId > 0) {
            $checkSql .= " AND id = ?";
            $checkParams[] = $recordId;
        } else {
            $checkSql .= " AND record_date = ?";
            $checkParams[] = $recordDate;
            if ($cycleEnabled && $selectedCycleId > 0) { $checkSql .= " AND cycle_id = ?"; $checkParams[] = $selectedCycleId; }
        }
        $checkSql .= " FOR UPDATE";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute($checkParams);
        $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($recordId > 0 && !$existingRecord) {
            throw new RuntimeException('The selected daily record could not be found.');
        }

        $movementId = ($existingRecord && !$existingRecord['feed_stock_transaction_id'] && !$existingRecord['feed_item_id'] && (float)$existingRecord['feed_consumption_bags'] === $feedQuantity && $feedItemId === 0)
            ? null
            : syncDailyFeedConsumption($pdo, $tenantFarmId, $existingRecord ? (int)$existingRecord['feed_stock_transaction_id'] : null, $feedItemId, $feedQuantity, $recordDate, 'broiler', '', $_SESSION['user_id'] ?? null);
        $linkedFeedItemId = $movementId ? $feedItemId : null;
        if ($existingRecord) {
            $stmt = $pdo->prepare("UPDATE broiler_daily_records SET opening_stock = ?, mortality = ?, feed_consumption_bags = ?, feed_item_id = ?, feed_stock_transaction_id = ?,
            water_consumption_liters = ?, medications = ?, birds_age = ?, remarks = ? WHERE id = ? AND farm_id = ?");
            $stmt->execute([$parseNumeric($_POST['opening_stock']), $parseNumeric($_POST['mortality']), $feedQuantity, $linkedFeedItemId, $movementId,
                $_POST['water_consumption'], $_POST['medications'], $_POST['birds_age'], $_POST['remarks'], $existingRecord['id'], $tenantFarmId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO broiler_daily_records (farm_id, cycle_id, record_date, opening_stock, mortality, feed_consumption_bags, feed_item_id, feed_stock_transaction_id, water_consumption_liters, medications, birds_age, remarks, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$tenantFarmId, ($cycleEnabled && $selectedCycleId > 0) ? $selectedCycleId : null, $recordDate,
                $parseNumeric($_POST['opening_stock']), $parseNumeric($_POST['mortality']), $feedQuantity, $linkedFeedItemId, $movementId,
                $_POST['water_consumption'], $_POST['medications'], $_POST['birds_age'], $_POST['remarks'], $_SESSION['user_id']]);
        }
        $pdo->commit();
        $_SESSION['success'] = "Broiler daily record saved successfully!";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Unable to save broiler daily record: ' . $e->getMessage());
        $_SESSION['error'] = 'Unable to save the daily record. Please try again.';
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Unable to save broiler daily record: ' . $e->getMessage());
        $_SESSION['error'] = 'Unable to save the daily record. Please try again.';
    }
    $redirectMonth = date('Y-m', strtotime($recordDate));
    header("Location: broiler_daily_record.php?month=" . $redirectMonth . "&cycle_id=" . (int)$selectedCycleId);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <?php include(__DIR__ . '/../navbar_head.php'); ?>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Broiler Daily Record - Renee Farms</title>
</head>
<body class="poultry-page">
    <?php include(__DIR__ . '/../navbar.php'); ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="container-fluid mt-3"><div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div></div>
    <?php endif; ?>

    <div class="container-fluid mt-4 poultry-shell">
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card poultry-panel">
                    <div class="card-header poultry-hero d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h4 class="mb-0">
                            <i class="bi bi-calendar-check"></i>
                            Broiler Daily Record - <?php echo date('F Y', strtotime($yearMonth)); ?>
                        </h4>
                        <div class="d-flex flex-wrap gap-2">
                            <input type="date" class="form-control js-calendar-input" id="monthSelector"
                                   value="<?php echo $monthSelectorDate; ?>" style="width: 200px;">
                            <button class="btn btn-primary" onclick="openRecordModal()">
                                <i class="bi bi-plus-circle"></i> Add Today's Record
                            </button>
                        </div>

                    </div>

                    <div class="card-body bg-light">
                        <div class="smart-poultry-note p-3 mb-4 d-flex gap-3 align-items-start">
                            <i class="bi bi-stars fs-4"></i>
                            <div>
                                <div class="fw-bold">Broiler growth intelligence</div>
                                <div class="small">Use daily mortality, feed and water trends as the foundation for FCR, target-weight and health warning alerts.</div>
                            </div>
                        </div>
                        <!-- Monthly Summary -->
                        <div class="row mb-4 g-3">
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card text-white" style="background-color: #7c4dff;">
                                    <div class="card-body text-center">
                                        <h6>Current Stock</h6>
                                        <h3><?php echo $broilerClosingStock !== null ? number_format($broilerClosingStock) : '--'; ?></h3>
                                        <small>Latest Closing</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card text-white bg-primary">
                                    <div class="card-body text-center">
                                        <h6>Total Mortality</h6>
                                        <h3><?php echo number_format($summaryTotals['mortality']); ?></h3>
                                        <?php if ($cycleEnabled && $selectedCycleId === 0): ?>
                                            <small>Birds Lost</small>
                                        <?php else: ?>
                                            <small>This Month</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card text-white bg-success">
                                    <div class="card-body text-center">
                                        <h6>Feed Consumed</h6>
                                        <h3><?php echo number_format($summaryTotals['feed_consumption'], 2); ?></h3>
                                        <small>Bags (25kg)</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card text-white bg-warning">
                                    <div class="card-body text-center">
                                        <h6>Water Consumed</h6>
                                        <h3><?php echo number_format($summaryTotals['water_consumption']); ?></h3>
                                        <small>Liters</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card text-white bg-info">
                                    <div class="card-body text-center">
                                        <h6>Days Recorded</h6>
                                        <h3><?php echo count($records); ?></h3>
                                        <small>Out of <?php echo date('t', strtotime($yearMonth)); ?> days</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($cycleEnabled): ?>
                        <div class="row mb-3">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label fw-bold">Active Broiler Cycle</label>
                                <select class="form-select" id="activeCycleSelector">
                                    <option value="0" <?php echo ($selectedCycleId === 0) ? 'selected' : ''; ?>>All / Legacy records</option>
                                    <?php foreach ($activeCycles as $cycle): ?>
                                    <option value="<?php echo (int)$cycle['id']; ?>" <?php echo ((int)$selectedCycleId === (int)$cycle['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cycle['cycle_code']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($selectedCycleId === 0): ?>
                            <div class="col-12 mt-2">
                                <small class="text-muted">Select a cycle code to load records into the calendar.</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Calendar View -->
                        <div class="card mb-4">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Monthly Calendar View</h5>
                                <?php if ($selectedCycleId > 0): ?>
                                <span class="badge text-bg-light border">Tap a day to add or edit</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body <?php echo $selectedCycleId === 0 ? 'calendar-legacy' : ''; ?>">
                                <div class="row">
                                    <?php
                                    // Generate calendar
                                    $firstDay = date('N', strtotime($yearMonth . '-01'));
                                    $daysInMonth = date('t', strtotime($yearMonth));
                                    $currentDay = 1;

                                    // Week days header
                                    $weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                    ?>

                                    <?php foreach ($weekDays as $day): ?>
                                    <div class="col text-center fw-bold text-muted mb-2 calendar-weekday">
                                        <?php echo $day; ?>
                                    </div>
                                    <?php endforeach; ?>

                                    <!-- Empty cells for first week -->
                                    <?php for ($i = 1; $i < $firstDay; $i++): ?>
                                    <div class="col"></div>
                                    <?php endfor; ?>

                                    <!-- Days of the month -->
                                    <?php while ($currentDay <= $daysInMonth): ?>
                                        <?php if (($currentDay + $firstDay - 2) % 7 == 0 && $currentDay > 1): ?>
                                            </div><div class="row mb-2">
                                        <?php endif; ?>

                                        <?php
                                        $currentDate = $yearMonth . '-' . sprintf('%02d', $currentDay);
                                        $record = null;
                                        $dayRecords = [];
                                        $hasRecord = false;
                                        $hasMortality = false;
                                        $dayOpeningStock = 0;
                                        $dayMortality = 0;
                                        $dayFeedConsumption = 0;

                                        foreach ($records as $rec) {
                                            if (date('Y-m-d', strtotime($rec['record_date'])) == $currentDate) {
                                                $dayRecords[] = $rec;
                                                $record = $rec;
                                                $hasRecord = true;
                                                $dayOpeningStock += (float)$rec['opening_stock'];
                                                $dayMortality += (float)$rec['mortality'];
                                                $dayFeedConsumption += (float)$rec['feed_consumption_bags'];
                                                if ($rec['mortality'] > 0) {
                                                    $hasMortality = true;
                                                }
                                            }
                                        }
                                        ?>

                                        <div class="col p-1">
                                            <?php
                                            $dayClasses = 'calendar-day border rounded p-2 text-center w-100';
                                            if (!$canEdit) {
                                                $dayClasses .= ' no-action';
                                            }
                                            if ($hasRecord) {
                                                $dayClasses .= ' has-record';
                                            }
                                            if ($hasMortality) {
                                                $dayClasses .= ' has-mortality';
                                            }

                                            if ($canEdit && $selectedCycleId > 0) {
                                                $dayClasses .= $hasRecord ? ' edit-record-btn' : ' add-record-btn';
                                            }
                                            ?>
                                            <button type="button"
                                                    class="<?php echo $dayClasses; ?>"
                                                    <?php if (!$canEdit): ?>disabled<?php endif; ?>
                                                    <?php if ($canEdit): ?>
                                                    data-record-date="<?php echo $currentDate; ?>"
                                                    data-selected-date="<?php echo $currentDate; ?>"
                                                    <?php if ($hasRecord && $record): ?>
                                                    data-opening-stock="<?php echo htmlspecialchars($record['opening_stock']); ?>"
                                                    data-mortality="<?php echo htmlspecialchars($record['mortality']); ?>"
                                                    data-feed-consumption="<?php echo htmlspecialchars($record['feed_consumption_bags']); ?>"
                                                    data-feed-item-id="<?php echo htmlspecialchars($record['feed_item_id'] ?? ''); ?>"
                                                    data-water-consumption="<?php echo htmlspecialchars($record['water_consumption_liters']); ?>"
                                                    data-medications="<?php echo htmlspecialchars($record['medications']); ?>"
                                                    data-birds-age="<?php echo htmlspecialchars($record['birds_age']); ?>"
                                                    data-remarks="<?php echo htmlspecialchars($record['remarks']); ?>"
                                                    <?php endif; ?>
                                                    <?php endif; ?>>
                                                <div class="calendar-date"><?php echo $currentDay; ?></div>
                                                <?php if ($hasRecord): ?>
                                                <div class="calendar-meta"><?php echo count($dayRecords); ?> record(s)</div>
                                                <?php if ($selectedCycleId > 0): ?>
                                                <div class="d-flex flex-wrap gap-1 justify-content-center mt-1">
                                                    <span class="badge text-bg-primary">O/S: <?php echo number_format($dayOpeningStock, 0); ?></span>
                                                    <span class="badge text-bg-danger">Mort: <?php echo number_format($dayMortality, 0); ?></span>
                                                    <span class="badge text-bg-success">Feed: <?php echo number_format($dayFeedConsumption, 1); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php else: ?>
                                                <div class="calendar-meta">No record</div>
                                                <?php endif; ?>
                                            </button>
                                        </div>

                                        <?php $currentDay++; ?>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Records Table -->
                        <div class="card">
                            <div class="card-header">
                                <h5>Detailed Daily Records</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover poultry-table">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Date</th>
                                                <th>Opening Stock</th>
                                                <th>Mortality</th>
                                                <th>Feed (bags)</th>
                                                <th>Water (L)</th>
                                                <th>Medications</th>
                                                <th>Birds Age</th>
                                                <th>Remarks</th>
                                                <?php if ($canEdit): ?>
                                                <th>Actions</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($records)): ?>
                                            <tr>
                                                <td colspan="<?php echo $canEdit ? '9' : '8'; ?>" class="text-center text-muted py-4">
                                                    <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                                    No records found for this month
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($records as $record):
                                                    $closingStock = $record['opening_stock'] - $record['mortality'];
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo date('d/m/Y', strtotime($record['record_date'])); ?></strong>
                                                    </td>
                                                    <td><?php echo $record['opening_stock']; ?></td>
                                                    <td class="text-danger fw-bold">
                                                        <?php echo $record['mortality']; ?>
                                                        <small class="d-block text-muted">
                                                            Closing: <?php echo $closingStock; ?>
                                                        </small>
                                                    </td>
                                                    <td><?php echo $record['feed_consumption_bags']; ?></td>
                                                    <td><?php echo number_format($record['water_consumption_liters']); ?></td>
                                                    <td>
                                                        <?php if ($record['medications']): ?>
                                                        <small><?php echo substr($record['medications'], 0, 20); ?>...</small>
                                                        <?php else: ?>
                                                        <span class="text-muted">--</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $record['birds_age']; ?> days
                                                    </td>
                                                    <td>
                                                        <?php if ($record['remarks']): ?>
                                                        <small class="text-muted"><?php echo substr($record['remarks'], 0, 30); ?>...</small>
                                                        <?php else: ?>
                                                        <span class="text-muted">--</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php if ($canEdit): ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary edit-record-btn"
                                                                title="Edit Record"
                                                                data-record-id="<?php echo (int)$record['id']; ?>"
                                                                data-record-date="<?php echo htmlspecialchars($record['record_date']); ?>"
                                                                data-selected-date="<?php echo htmlspecialchars($record['record_date']); ?>"
                                                                data-opening-stock="<?php echo htmlspecialchars($record['opening_stock']); ?>"
                                                                data-mortality="<?php echo htmlspecialchars($record['mortality']); ?>"
                                                                data-feed-consumption="<?php echo htmlspecialchars($record['feed_consumption_bags']); ?>"
                                                                data-feed-item-id="<?php echo htmlspecialchars($record['feed_item_id'] ?? ''); ?>"
                                                                data-water-consumption="<?php echo htmlspecialchars($record['water_consumption_liters']); ?>"
                                                                data-medications="<?php echo htmlspecialchars($record['medications']); ?>"
                                                                data-birds-age="<?php echo htmlspecialchars($record['birds_age']); ?>"
                                                                data-remarks="<?php echo htmlspecialchars($record['remarks']); ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    </td>
                                                    <?php endif; ?>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot class="table-secondary">
                                            <tr>
                                                <td><strong>TOTAL</strong></td>
                                                <td>--</td>
                                                <td class="text-danger fw-bold"><?php echo $monthlyTotals['mortality']; ?></td>
                                                <td class="fw-bold"><?php echo number_format($monthlyTotals['feed_consumption'], 2); ?></td>
                                                <td class="fw-bold"><?php echo number_format($monthlyTotals['water_consumption']); ?></td>
                                                <td colspan="<?php echo $canEdit ? '4' : '3'; ?>">Monthly Summary</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Modal -->
    <div class="modal fade" id="recordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="recordForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Broiler Daily Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="record_date" id="recordDate">
                        <input type="hidden" name="record_id" id="recordId" value="0">
                        <input type="hidden" name="cycle_id" value="<?php echo (int)$selectedCycleId; ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Date</label>
                                <input type="date" class="form-control" id="selectedDate" max="<?php echo date('Y-m-d'); ?>"
                                       onchange="checkExistingRecord()" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Birds Age (days)</label>
                                <input type="number" name="birds_age" class="form-control"
                                       id="birdsAge" min="1" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Opening Stock</label>
                                <input type="number" name="opening_stock" class="form-control"
                                       id="openingStock" min="0" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Mortality</label>
                                <input type="number" name="mortality" class="form-control"
                                       id="mortality" min="0" value="0">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="feedItemId">Broiler Feed Item</label>
                                <select name="feed_item_id" id="feedItemId" class="form-select mb-2">
                                    <option value="">Select feed from current stock</option>
                                    <?php foreach ($feedItems as $feedItem): ?>
                                        <option value="<?php echo (int)$feedItem['id']; ?>"><?php echo htmlspecialchars($feedItem['item_name']); ?> — <?php echo htmlspecialchars($feedItem['current_stock']); ?> <?php echo htmlspecialchars($feedItem['unit']); ?> available<?php echo !(int)$feedItem['is_active'] ? ' (inactive; linked records only)' : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label>Feed Consumption (in the selected item's unit)</label>
                                <input type="number" name="feed_consumption" class="form-control"
                                       id="feedConsumption" oninput="document.getElementById('feedItemId').required = parseFloat(this.value) > 0" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Water Consumption (liters)</label>
                                <input type="number" name="water_consumption" class="form-control"
                                       id="waterConsumption" step="0.1" min="0" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label>Medications</label>
                            <textarea name="medications" class="form-control"
                                     id="medications" rows="2"></textarea>
                        </div>

                        <div class="mb-3">
                            <label>Remarks / Causes of Mortality</label>
                            <textarea name="remarks" class="form-control"
                                     id="remarks" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_record" class="btn btn-primary">Save Record</button>
                    </div>
                </form>
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
    // Month selector
document.getElementById('monthSelector').addEventListener('blur', function() {
    window.location.href = 'broiler_daily_record.php?month=' + this.value.substring(0, 7) + '&cycle_id=<?php echo (int)$selectedCycleId; ?>';
});
const cycleSelector = document.getElementById('activeCycleSelector');
if (cycleSelector) {
    cycleSelector.addEventListener('change', function () {
        window.location.href = 'broiler_daily_record.php?month=<?php echo $yearMonth; ?>&cycle_id=' + this.value;
    });
}


    const selectedCycleId = <?php echo (int)$selectedCycleId; ?>;
    const canEditRetrievedOpeningStock = <?php echo (isPlatformOwner() || hasRole('farm_admin')) ? 'true' : 'false'; ?>;
    function lockRetrievedOpeningStock() {
        if (!canEditRetrievedOpeningStock) {
            document.getElementById('openingStock').readOnly = true;
        }
    }
    function unlockOpeningStock() {
        document.getElementById('openingStock').readOnly = false;
    }

    // Open modal for new record
    function openRecordModal(date = null, hasRecord = false) {
        const modalElement = document.getElementById('recordModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const today = new Date().toISOString().split('T')[0];
        const selectedDate = date || today;

        resetForm();

        document.getElementById('selectedDate').value = selectedDate;
        document.getElementById('recordDate').value = selectedDate;

        if (hasRecord) {
            document.getElementById('modalTitle').textContent = 'Edit Record';
        } else {
            document.getElementById('modalTitle').textContent = 'Add Daily Record';
            loadExistingRecordOrPreviousStock(selectedDate, 'Add Daily Record');
        }

        modal.show();
    }

    let dailyRecordLookupVersion = 0;

    function loadExistingRecordOrPreviousStock(date, addTitle) {
        if (selectedCycleId <= 0) {
            document.getElementById('modalTitle').textContent = addTitle;
            return;
        }

        const cycleId = selectedCycleId;
        const lookupVersion = ++dailyRecordLookupVersion;
        fetch(`../api/check_record.php?type=broiler&date=${date}&cycle_id=${cycleId}`)
            .then(response => response.json())
            .then(data => {
                if (lookupVersion !== dailyRecordLookupVersion ||
                    document.getElementById('selectedDate').value !== date ||
                    selectedCycleId !== cycleId) return;
                if (data.exists) {
                    document.getElementById('modalTitle').textContent = 'Edit Record';
                    fetchRecordData(date, lookupVersion, cycleId);
                    return;
                }

                document.getElementById('modalTitle').textContent = addTitle;
                fetchPreviousStock(date);
            })
            .catch(error => {
                console.error(error);
            });
    }

    // Fetch record data
    function fetchRecordData(date, lookupVersion = dailyRecordLookupVersion, cycleId = selectedCycleId) {
        fetch(`../api/get_record.php?type=broiler&date=${date}&cycle_id=${cycleId}`)
            .then(response => response.json())
            .then(payload => {
                if (payload && payload.success === false) {
                    throw new Error(payload.error || 'Failed to fetch record');
                }
                const data = payload ? payload.data : null;
                if (lookupVersion !== dailyRecordLookupVersion ||
                    document.getElementById('selectedDate').value !== date ||
                    selectedCycleId !== cycleId) return;
                if (data) {
                    document.getElementById('recordId').value = data.id || 0;
                    document.getElementById('birdsAge').value = data.birds_age || '';
                    document.getElementById('openingStock').value = data.opening_stock || '';
                    document.getElementById('mortality').value = data.mortality || 0;
                    document.getElementById('feedConsumption').value = data.feed_consumption_bags || '';
                    document.getElementById('feedItemId').value = data.feed_item_id || '';
                    document.getElementById('waterConsumption').value = data.water_consumption_liters || '';
                    document.getElementById('medications').value = data.medications || '';
                    document.getElementById('remarks').value = data.remarks || '';
                }
            })
            .catch(error => {
                console.error(error);
            });
    }

    // Fetch latest earlier closing stock
    function fetchPreviousStock(selectedDate) {
        fetch(`../api/get_previous_stock.php?type=broiler&date=${selectedDate}&cycle_id=${selectedCycleId}`)
            .then(response => response.json())
            .then(payload => {
                if (payload && payload.success === false) {
                    throw new Error(payload.error || 'Failed to fetch previous record');
                }
                if (payload && payload.closing_stock !== null && payload.closing_stock !== undefined) {
                    document.getElementById('openingStock').value = payload.closing_stock > 0 ? payload.closing_stock : '';
                    lockRetrievedOpeningStock();
                }
            })
            .catch(error => {
                console.error(error);
            });
    }

    // Check existing record
    function checkExistingRecord() {
        const date = document.getElementById('selectedDate').value;
        const cycleId = selectedCycleId;
        const lookupVersion = ++dailyRecordLookupVersion;
        document.getElementById('recordId').value = 0;
        document.getElementById('recordDate').value = date;
        if (selectedCycleId <= 0) {
            resetForm();
            document.getElementById('selectedDate').value = date;
            document.getElementById('recordDate').value = date;
            return;
        }

        fetch(`../api/check_record.php?type=broiler&date=${date}&cycle_id=${cycleId}`)
            .then(response => response.json())
            .then(data => {
                if (lookupVersion !== dailyRecordLookupVersion ||
                    document.getElementById('selectedDate').value !== date ||
                    selectedCycleId !== cycleId) return;
                if (data.exists) {
                    document.getElementById('modalTitle').textContent = 'Edit Record';
                    fetchRecordData(date, lookupVersion, cycleId);
                } else {
                    document.getElementById('modalTitle').textContent = 'Add Daily Record';
                    resetForm();

                    document.getElementById('selectedDate').value = date;
                    document.getElementById('recordDate').value = date;

                    fetchPreviousStock(date);
                }
            });
    }

    // Reset form
    function resetForm() {
        unlockOpeningStock();
        document.getElementById('recordForm').reset();
        document.getElementById('mortality').value = 0;
        document.getElementById('feedItemId').required = parseFloat(document.getElementById('feedConsumption').value) > 0;
    }

    // Attach edit modals
    attachEditModal({
        buttonSelector: '.edit-record-btn',
        modalSelector: '#recordModal',
        fieldMap: {
            recordId: '#recordId',
            recordDate: '#recordDate',
            selectedDate: '#selectedDate',
            birdsAge: '#birdsAge',
            openingStock: '#openingStock',
            mortality: '#mortality',
            feedConsumption: '#feedConsumption',
            feedItemId: '#feedItemId',
            waterConsumption: '#waterConsumption',
            medications: '#medications',
            remarks: '#remarks'
        },
        onShow: ({ modalElement }) => {
            modalElement.querySelector('#modalTitle').textContent = 'Edit Record';
            modalElement.querySelector('#feedItemId').required = parseFloat(modalElement.querySelector('#feedConsumption').value) > 0;
        }
    });

    // Add record from calendar
    document.querySelectorAll('.add-record-btn').forEach(button => {
        button.addEventListener('click', () => {
            openRecordModal(button.dataset.recordDate, false);
        });
    });

    // Show messages
    </script>
</body>
</html>
