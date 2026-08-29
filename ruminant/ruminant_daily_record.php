<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

// Check access
if (!checkAccess('ruminant')) {
    header('Location: dashboard.php');
    exit();
}

$month = $_GET['month'] ?? date('Y-m');
$yearMonth = date('Y-m', strtotime($month));
$selectedCycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$monthSelectorDate = date('Y-m-d', strtotime($yearMonth . '-' . min((int)date('d'), (int)date('t', strtotime($yearMonth . '-01')))));
$canDeleteRecords = isPlatformOwner() || hasRole('farm_admin');
$canEditRecords = $canDeleteRecords || hasRole('ruminant_manager');
$managedTypes = ['cattle', 'goat', 'sheep', 'other'];
$tenantFarmId = requireCurrentFarmId();
$feedItemsStmt = $pdo->prepare("SELECT id, item_name, current_stock, unit, is_active FROM stock_items WHERE farm_id = ? AND feed_category = 'ruminant' AND (is_active = 1 OR id IN (SELECT feed_item_id FROM ruminant_daily_records WHERE farm_id = ? AND feed_stock_transaction_id IS NOT NULL)) ORDER BY is_active DESC, item_name");
$feedItemsStmt->execute([$tenantFarmId, $tenantFarmId]);
$feedItems = $feedItemsStmt->fetchAll(PDO::FETCH_ASSOC);
$cycleEnabled = ($pdo->query("SHOW TABLES LIKE 'production_cycles'")->rowCount() > 0);
$activeCycles = [];
$selectedCycle = null;
if ($cycleEnabled) {
    $cycleStmt = $pdo->prepare("SELECT id, cycle_code, production_type FROM production_cycles WHERE farm_id = ? AND farm_type = 'ruminant' AND status = 'active' ORDER BY start_date DESC");
    $cycleStmt->execute([$tenantFarmId]);
    $activeCycles = $cycleStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($selectedCycleId > 0) {
        $activeCycleIds = array_map(static function ($cycle) {
            return (int)$cycle['id'];
        }, $activeCycles);
        if (!in_array($selectedCycleId, $activeCycleIds, true)) {
            $selectedCycleId = 0;
        }
    }

    foreach ($activeCycles as $cycle) {
        if ((int)$cycle['id'] === $selectedCycleId) {
            $selectedCycle = $cycle;
            break;
        }
    }
}

// Handle delete request (admin/owner only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record']) && $canDeleteRecords) {
    $recordId = (int)($_POST['record_id'] ?? 0);

    if ($recordId > 0) {
        try {
            $pdo->beginTransaction();
            $recordCheck = $pdo->prepare("SELECT animal_type, feed_stock_transaction_id FROM ruminant_daily_records WHERE id = ? AND farm_id = ? FOR UPDATE");
            $recordCheck->execute([$recordId, $tenantFarmId]);
            $record = $recordCheck->fetch();

            if ($record && in_array(strtolower($record['animal_type']), $managedTypes, true)) {
                reverseDailyFeedConsumption($pdo, $tenantFarmId, $record['feed_stock_transaction_id'] ? (int)$record['feed_stock_transaction_id'] : null);
                $deleteStmt = $pdo->prepare("DELETE FROM ruminant_daily_records WHERE id = ? AND farm_id = ?");
                $deleteStmt->execute([$recordId, $tenantFarmId]);
                $pdo->commit();
                $_SESSION['success'] = "Ruminant daily record deleted successfully.";
            } else {
                $pdo->rollBack();
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Ruminant daily record deletion failed: ' . $e->getMessage());
            $_SESSION['error'] = 'The daily record could not be deleted. Please try again.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = $e->getMessage();
        }
    }

    header("Location: ruminant_daily_record.php?month=" . $month . "&cycle_id=" . (int)$selectedCycleId);
    exit();
}

// Get all records for the month
$query = "SELECT * FROM ruminant_daily_records WHERE farm_id = ? AND DATE_FORMAT(record_date, '%Y-%m') = ?";
$params = [$tenantFarmId, $yearMonth];
if ($cycleEnabled && $selectedCycleId > 0) {
    $query .= " AND cycle_id = ?";
    $params[] = $selectedCycleId;
}
$query .= " ORDER BY record_date, animal_type";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Group by animal type
$cycleMetaById = [];
foreach ($activeCycles as $cycle) {
    $cycleMetaById[(int)$cycle['id']] = $cycle;
}

$animalTypeTabs = [];
foreach ($records as $record) {
    $animalType = (string)$record['animal_type'];
    $cycleId = (int)($record['cycle_id'] ?? 0);
    $tabKey = strtolower($animalType);
    $tabLabel = $animalType;

    if ($cycleEnabled && $selectedCycleId === 0 && $cycleId > 0) {
        $cycleCode = $cycleMetaById[$cycleId]['cycle_code'] ?? ('Cycle #' . $cycleId);
        $tabKey = strtolower($animalType) . '-cycle-' . $cycleId;
        $tabLabel = $animalType . ' (' . $cycleCode . ')';
    }

    if (!isset($animalTypeTabs[$tabKey])) {
        $animalTypeTabs[$tabKey] = [
            'label' => $tabLabel,
            'animal_type' => $animalType,
            'records' => []
        ];
    }
    $animalTypeTabs[$tabKey]['records'][] = $record;
}

$recordsByCycle = [];
if ($cycleEnabled) {
    foreach ($records as $record) {
        $cycleId = (int)($record['cycle_id'] ?? 0);
        if ($cycleId <= 0) {
            continue;
        }
        if (!isset($recordsByCycle[$cycleId])) {
            $recordsByCycle[$cycleId] = [];
        }
        $recordsByCycle[$cycleId][] = $record;
    }
}

$recordsByDate = [];
foreach ($records as $record) {
    $recordDateKey = date('Y-m-d', strtotime($record['record_date']));
    if (!isset($recordsByDate[$recordDateKey])) {
        $recordsByDate[$recordDateKey] = [];
    }
    $recordsByDate[$recordDateKey][] = $record;
}

$showCycleTabs = false;
$showAnimalTypeTabs = true;

// Calculate totals
$monthlyTotals = [
    'opening_stock' => 0,
    'mortality' => 0,
    'feed_consumption' => 0,
    'water_consumption' => 0
];

foreach ($records as $record) {
    $monthlyTotals['opening_stock'] += $record['opening_stock'];
    $monthlyTotals['mortality'] += $record['mortality'];
    $monthlyTotals['feed_consumption'] += $record['feed_consumption_kg'];
    $monthlyTotals['water_consumption'] += $record['water_consumption_liters'];
}

$summaryTotals = $monthlyTotals;
if ($cycleEnabled && $selectedCycleId === 0) {
    $summaryStmt = $pdo->query("
        SELECT
            COALESCE(SUM(opening_stock), 0) AS opening_stock,
            COALESCE(SUM(mortality), 0) AS mortality,
            COALESCE(SUM(feed_consumption_kg), 0) AS feed_consumption,
            COALESCE(SUM(water_consumption_liters), 0) AS water_consumption
        FROM ruminant_daily_records
        WHERE farm_id = $tenantFarmId
    ");
    $summaryTotals = array_merge($summaryTotals, $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

$normalizeAnimalType = static function ($value) {
    $normalized = strtolower(trim((string)$value));
    $aliases = [
        'cattles' => 'cattle',
        'goats' => 'goat',
        'sheeps' => 'sheep',
        'rabbits' => 'other',
        'cats' => 'other',
        'dogs' => 'other'
    ];
    return $aliases[$normalized] ?? $normalized;
};

$latestClosingStock = '--';
if ($cycleEnabled && $selectedCycleId === 0) {
    $latestRuminantStmt = $pdo->query("
        SELECT t1.cycle_id, t1.opening_stock, t1.mortality
        FROM ruminant_daily_records t1
        INNER JOIN (
            SELECT cycle_id, MAX(record_date) AS max_date
            FROM ruminant_daily_records
            WHERE farm_id = $tenantFarmId AND cycle_id IS NOT NULL AND cycle_id > 0
            GROUP BY cycle_id
        ) t2 ON t1.cycle_id = t2.cycle_id AND t1.record_date = t2.max_date
        INNER JOIN production_cycles pc ON pc.id = t1.cycle_id
        WHERE t1.farm_id = $tenantFarmId AND pc.farm_id = $tenantFarmId AND pc.farm_type = 'ruminant' AND pc.status = 'active'
    ");
    $cycleClosingTotal = 0;
    foreach ($latestRuminantStmt->fetchAll(PDO::FETCH_ASSOC) as $cycleRecord) {
        $cycleClosingTotal += max(0, (float)$cycleRecord['opening_stock'] - (float)$cycleRecord['mortality']);
    }
    $latestClosingStock = $cycleClosingTotal;
} elseif ($cycleEnabled && $selectedCycleId > 0) {
    $latestRuminantStmt = $pdo->prepare("
        SELECT opening_stock, mortality
        FROM ruminant_daily_records
        WHERE cycle_id = ? AND farm_id = ?
        ORDER BY record_date DESC
        LIMIT 1
    ");
    $latestRuminantStmt->execute([$selectedCycleId, $tenantFarmId]);
    $latestRecord = $latestRuminantStmt->fetch();
    if ($latestRecord) {
        $latestClosingStock = max(0, (float)$latestRecord['opening_stock'] - (float)$latestRecord['mortality']);
    }
} elseif (!$cycleEnabled) {
    $latestRuminantStmt = $pdo->query("
        SELECT opening_stock, mortality
        FROM ruminant_daily_records
        WHERE farm_id = $tenantFarmId
        ORDER BY record_date DESC
        LIMIT 1
    ");
    $latestRecord = $latestRuminantStmt->fetch();
    if ($latestRecord) {
        $latestClosingStock = max(0, (float)$latestRecord['opening_stock'] - (float)$latestRecord['mortality']);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_record'])) {
    $parseNumeric = static function ($value) {
        return str_replace(',', '', trim((string)$value));
    };
    $recordDate = $_POST['record_date'];
    $animalType = strtolower(trim($_POST['animal_type']));
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
        // Lock the record before reading its linked movement so concurrent edits serialize.
        $checkSql = "SELECT id, feed_item_id, feed_consumption_kg, feed_stock_transaction_id FROM ruminant_daily_records WHERE farm_id = ?";
        $checkParams = [$tenantFarmId];
        if ($recordId > 0) {
            $checkSql .= " AND id = ?";
            $checkParams[] = $recordId;
        } else {
            $checkSql .= " AND record_date = ? AND LOWER(animal_type) = ?";
            $checkParams[] = $recordDate;
            $checkParams[] = $animalType;
            if ($cycleEnabled && $selectedCycleId > 0) {
                $checkSql .= " AND cycle_id = ?";
                $checkParams[] = $selectedCycleId;
            }
        }
        $checkSql .= " FOR UPDATE";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute($checkParams);
        $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($recordId > 0 && !$existingRecord) {
            throw new RuntimeException('The selected daily record could not be found.');
        }

        $movementId = ($existingRecord && !$existingRecord['feed_stock_transaction_id'] && !$existingRecord['feed_item_id'] && (float)$existingRecord['feed_consumption_kg'] === $feedQuantity && $feedItemId === 0)
            ? null
            : syncDailyFeedConsumption($pdo, $tenantFarmId, $existingRecord ? (int)$existingRecord['feed_stock_transaction_id'] : null, $feedItemId, $feedQuantity, $recordDate, 'ruminant', '', $_SESSION['user_id'] ?? null);
        $linkedFeedItemId = $movementId ? $feedItemId : null;
    if ($existingRecord) {
        // Update existing record
        $stmt = $pdo->prepare("UPDATE ruminant_daily_records SET
            opening_stock = ?, mortality = ?, feed_consumption_kg = ?, feed_item_id = ?, feed_stock_transaction_id = ?,
            water_consumption_liters = ?, other_details = ?, tag_no = ?,
            medications = ?, reproduction_details = ?, remarks = ?
            WHERE id = ? AND farm_id = ?");
        $updateParams = [
            $parseNumeric($_POST['opening_stock']),
            $parseNumeric($_POST['mortality']),
            $feedQuantity, $linkedFeedItemId, $movementId,
            $parseNumeric($_POST['water_consumption']),
            $_POST['other_details'],
            $_POST['tag_no'],
            $_POST['medications'],
            $_POST['reproduction_details'],
            $_POST['remarks'],
            $existingRecord['id'],
            $tenantFarmId
        ];
        $stmt->execute($updateParams);
    } else {
        // Insert new record
        $stmt = $pdo->prepare("INSERT INTO ruminant_daily_records
            (farm_id, cycle_id, record_date, animal_type, opening_stock, mortality,
             feed_consumption_kg, feed_item_id, feed_stock_transaction_id, water_consumption_liters, other_details,
             tag_no, medications, reproduction_details, remarks, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $tenantFarmId,
            ($cycleEnabled && $selectedCycleId > 0) ? $selectedCycleId : null,
            $recordDate,
            $animalType,
            $parseNumeric($_POST['opening_stock']),
            $parseNumeric($_POST['mortality']),
            $feedQuantity, $linkedFeedItemId, $movementId,
            $parseNumeric($_POST['water_consumption']),
            $_POST['other_details'],
            $_POST['tag_no'],
            $_POST['medications'],
            $_POST['reproduction_details'],
            $_POST['remarks'],
            $_SESSION['user_id']
        ]);
    }

        $pdo->commit();
        $_SESSION['success'] = "Ruminant daily record saved successfully!";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Unable to save ruminant daily record: ' . $e->getMessage());
        $_SESSION['error'] = 'Unable to save the daily record. Please try again.';
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Unable to save ruminant daily record: ' . $e->getMessage());
        $_SESSION['error'] = 'Unable to save the daily record. Please try again.';
    }
    header("Location: ruminant_daily_record.php?month=" . $month . "&cycle_id=" . (int)$selectedCycleId);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include(__DIR__ . '/../navbar_head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ruminant Daily Record - Renee Farms</title>
</head>
<body class="ruminant-page">
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
                            <i class="bi bi-shield-plus"></i>
                            Ruminant Daily Record - <?php echo date('F Y', strtotime($yearMonth)); ?>
                        </h4>
                        <div class="d-flex flex-wrap gap-2">
                            <input type="date" class="form-control js-calendar-input" id="monthSelector"
                                   value="<?php echo $monthSelectorDate; ?>" style="width: 200px;">
                            <button class="btn btn-primary" onclick="openRecordModal()">
                                <i class="bi bi-plus-circle"></i> Add Today's Record
                            </button>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="smart-poultry-note p-3 mb-4 d-flex gap-3 align-items-start">
                            <i class="bi bi-stars fs-4"></i>
                            <div>
                                <div class="fw-bold">Ruminant herd intelligence</div>
                                <div class="small">Track herd closing stock, mortality, feed and water trends across active cycles so health and grazing alerts can be automated next.</div>
                            </div>
                        </div>
                        <!-- Monthly Summary Cards -->
                        <div class="row mb-4">
                            <div class="col-6 col-md-3">
                                <div class="card text-white bg-primary">
                                    <div class="card-body text-center">
                                        <h6>Current Stock</h6>
                                        <h3><?php echo is_numeric($latestClosingStock) ? number_format($latestClosingStock) : $latestClosingStock; ?></h3>
                                        <small>Latest Closing</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card text-white bg-danger">
                                    <div class="card-body text-center">
                                        <h6>Mortality</h6>
                                        <h3><?php echo number_format($summaryTotals['mortality']); ?></h3>
                                        <small>Losses</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card text-white bg-warning">
                                    <div class="card-body text-center">
                                        <h6>Feed Used</h6>
                                        <h3><?php echo number_format($summaryTotals['feed_consumption'], 1); ?></h3>
                                        <small>Kg</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card text-white bg-info">
                                    <div class="card-body text-center">
                                        <h6>Water Used</h6>
                                        <h3><?php echo number_format($summaryTotals['water_consumption']); ?></h3>
                                        <small>Liters</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($cycleEnabled): ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Active Ruminant Cycle</label>
                                <select class="form-select" id="activeCycleSelector">
                                    <option value="0" <?php echo ($selectedCycleId === 0) ? 'selected' : ''; ?>>All / Legacy records</option>
                                    <?php foreach ($activeCycles as $cycle): ?>
                                    <option value="<?php echo (int)$cycle['id']; ?>" <?php echo ((int)$selectedCycleId === (int)$cycle['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cycle['cycle_code'] . ' (' . $cycle['production_type'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Monthly Calendar View -->
                        <div class="card mb-4">
                            <div class="card-header d-flex align-items-center justify-content-between">
                                <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Monthly Calendar View</h5>
                                <?php if (!($cycleEnabled && $selectedCycleId === 0)): ?>
                                <span class="badge text-bg-light border">Tap a day to add or edit</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body <?php echo ($cycleEnabled && $selectedCycleId === 0) ? 'calendar-legacy' : ''; ?>">
                                <div class="row">
                                    <?php
                                    $firstDay = date('N', strtotime($yearMonth . '-01'));
                                    $daysInMonth = date('t', strtotime($yearMonth));
                                    $currentDay = 1;
                                    $weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                    ?>
                                    <?php foreach ($weekDays as $day): ?>
                                    <div class="col text-center fw-bold text-muted mb-2 calendar-weekday"><?php echo $day; ?></div>
                                    <?php endforeach; ?>

                                    <?php for ($i = 1; $i < $firstDay; $i++): ?>
                                    <div class="col"></div>
                                    <?php endfor; ?>

                                    <?php while ($currentDay <= $daysInMonth): ?>
                                        <?php if (($currentDay + $firstDay - 2) % 7 === 0 && $currentDay > 1): ?>
                                            </div><div class="row mb-2">
                                        <?php endif; ?>
                                        <?php
                                        $currentDate = $yearMonth . '-' . sprintf('%02d', $currentDay);
                                        $dayRecords = $recordsByDate[$currentDate] ?? [];
                                        $hasRecord = !empty($dayRecords);
                                        $hasMortality = false;
                                        $dayOpeningStock = 0;
                                        $dayMortality = 0;
                                        $dayFeedConsumption = 0;
                                        foreach ($dayRecords as $dayRecord) {
                                            $dayOpeningStock += (float)$dayRecord['opening_stock'];
                                            $dayMortality += (float)$dayRecord['mortality'];
                                            $dayFeedConsumption += (float)$dayRecord['feed_consumption_kg'];
                                            if ((int)$dayRecord['mortality'] > 0) {
                                                $hasMortality = true;
                                            }
                                        }
                                        $isCalendarEditable = !($cycleEnabled && $selectedCycleId === 0);
                                        $dayClasses = 'calendar-day border rounded p-2 text-center w-100';
                                        if ($isCalendarEditable) {
                                            $dayClasses .= ' add-record-btn';
                                        } else {
                                            $dayClasses .= ' no-action';
                                        }
                                        if ($hasRecord) $dayClasses .= ' has-record';
                                        if ($hasMortality) $dayClasses .= ' has-mortality';
                                        ?>
                                        <div class="col p-1">
                                            <button type="button"
                                                    class="<?php echo $dayClasses; ?>"
                                                    data-record-date="<?php echo htmlspecialchars($currentDate); ?>"
                                                    data-cycle-animal-type="<?php echo htmlspecialchars($normalizeAnimalType($selectedCycle['production_type'] ?? ($dayRecords[0]['animal_type'] ?? ''))); ?>">
                                                <div class="calendar-date"><?php echo $currentDay; ?></div>
                                                <?php if ($hasRecord): ?>
                                                    <div class="calendar-meta"><?php echo count($dayRecords); ?> record(s)</div>
                                                    <?php if (!($cycleEnabled && $selectedCycleId === 0)): ?>
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
                        <!-- Animal Type Tabs -->
                        <ul class="nav nav-tabs mb-4" id="animalTabs">
                            <?php if ($showCycleTabs): ?>
                                <?php foreach ($activeCycles as $cycleIndex => $cycle): ?>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo ($cycleIndex === 0 && !$showAnimalTypeTabs) ? 'active' : ''; ?>" data-bs-toggle="tab" href="#cycle-<?php echo (int)$cycle['id']; ?>">
                                        <?php echo htmlspecialchars($cycle['cycle_code']); ?>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($showAnimalTypeTabs): ?>
                            <?php $animalTabIndex = 0; foreach ($animalTypeTabs as $tabKey => $animalTab): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo ($animalTabIndex === 0) ? 'active' : ''; ?>" data-bs-toggle="tab" href="#<?php echo htmlspecialchars(strtolower(str_replace(' ', '', $tabKey))); ?>">
                                    <?php echo htmlspecialchars($animalTab['label']); ?>
                                </a>
                            </li>
                            <?php $animalTabIndex++; ?>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content">
                            <?php if ($showCycleTabs): ?>
                                <?php foreach ($activeCycles as $cycleIndex => $cycle): ?>
                                <div class="tab-pane fade <?php echo ($cycleIndex === 0 && !$showAnimalTypeTabs) ? 'show active' : ''; ?>" id="cycle-<?php echo (int)$cycle['id']; ?>">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover poultry-table">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Animal Type</th>
                                                    <th>Opening Stock</th>
                                                    <th>Mortality</th>
                                                    <th>Feed (kg)</th>
                                                    <th>Water (L)</th>
                                                    <th>Tag No</th>
                                                    <th>Reproduction</th>
                                                    <th>Remarks</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $cycleRecords = $recordsByCycle[(int)$cycle['id']] ?? []; ?>
                                                <?php if (empty($cycleRecords)): ?>
                                                <tr>
                                                    <td colspan="9" class="text-center text-muted py-4">
                                                        No records found for <?php echo htmlspecialchars($cycle['cycle_code']); ?> in this month
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                    <?php foreach ($cycleRecords as $record): ?>
                                                    <tr>
                                                        <td><strong><?php echo date('d/m/Y', strtotime($record['record_date'])); ?></strong></td>
                                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($record['animal_type']); ?></span></td>
                                                        <td><?php echo (int)$record['opening_stock']; ?></td>
                                                        <td class="text-danger fw-bold"><?php echo (int)$record['mortality']; ?></td>
                                                        <td><?php echo htmlspecialchars($record['feed_consumption_kg']); ?></td>
                                                        <td><?php echo number_format((float)$record['water_consumption_liters']); ?></td>
                                                        <td><?php echo htmlspecialchars($record['tag_no'] ?: '--'); ?></td>
                                                        <td><?php echo htmlspecialchars($record['reproduction_details'] ? substr($record['reproduction_details'], 0, 20) . '...' : '--'); ?></td>
                                                        <td><?php echo htmlspecialchars($record['remarks'] ? substr($record['remarks'], 0, 20) . '...' : '--'); ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Individual Animal Type Tabs -->
                            <?php if ($showAnimalTypeTabs): ?>
                            <?php $animalPaneIndex = 0; foreach ($animalTypeTabs as $tabKey => $animalTab):
                                $animalType = $animalTab['animal_type'];
                                $typeRecords = $animalTab['records'];
                                $typeKey = strtolower($animalType);
                                $isEditableType = in_array($typeKey, $managedTypes, true);
                                $typeTotals = [
                                    'closing_stock' => 0,
                                    'mortality' => 0,
                                    'feed_consumption' => 0,
                                    'water_consumption' => 0
                                ];

                                foreach ($typeRecords as $record) {
                                    $closingStock = max(0, $record['opening_stock'] - $record['mortality']);
                                    $typeTotals['closing_stock'] = $closingStock;
                                    $typeTotals['mortality'] += $record['mortality'];
                                    $typeTotals['feed_consumption'] += $record['feed_consumption_kg'];
                                    $typeTotals['water_consumption'] += $record['water_consumption_liters'];
                                }
                            ?>
                            <div class="tab-pane fade <?php echo ($animalPaneIndex === 0) ? 'show active' : ''; ?>" id="<?php echo htmlspecialchars(strtolower(str_replace(' ', '', $tabKey))); ?>">
                                <!-- Type Records Table -->
                                <div class="table-responsive">
                                    <table class="table table-striped poultry-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Opening Stock</th>
                                                <th>Mortality</th>
                                                <th>Feed (kg)</th>
                                                <th>Water (L)</th>
                                                <th>Tag No</th>
                                                <th>Medications</th>
                                                <th>Reproduction</th>
                                                <th>Other Details</th>
                                                <th>Remarks</th>
                                                <?php if ($canEditRecords && $isEditableType): ?>
                                                <th>Actions</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($typeRecords as $record):
                                                $closingStock = $record['opening_stock'] - $record['mortality'];
                                            ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($record['record_date'])); ?></td>
                                                <td><?php echo $record['opening_stock']; ?></td>
                                                <td class="text-danger fw-bold">
                                                    <?php echo $record['mortality']; ?>
                                                    <small class="d-block text-muted">
                                                        Closing: <?php echo $closingStock; ?>
                                                    </small>
                                                </td>
                                                <td><?php echo $record['feed_consumption_kg']; ?></td>
                                                <td><?php echo number_format($record['water_consumption_liters']); ?></td>
                                                <td><?php echo $record['tag_no'] ?: '--'; ?></td>
                                                <td>
                                                    <?php if ($record['medications']): ?>
                                                    <small><?php echo substr($record['medications'], 0, 15); ?>...</small>
                                                    <?php else: ?>
                                                    <span class="text-muted">--</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($record['reproduction_details']): ?>
                                                    <small><?php echo substr($record['reproduction_details'], 0, 15); ?>...</small>
                                                    <?php else: ?>
                                                    <span class="text-muted">--</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($record['other_details']): ?>
                                                    <small><?php echo substr($record['other_details'], 0, 15); ?>...</small>
                                                    <?php else: ?>
                                                    <span class="text-muted">--</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($record['remarks']): ?>
                                                    <small class="text-muted"><?php echo substr($record['remarks'], 0, 15); ?>...</small>
                                                    <?php else: ?>
                                                    <span class="text-muted">--</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($canEditRecords && $isEditableType): ?>
                                                <td>
                                                      <div class="d-flex flex-wrap gap-2">
                                                          <button class="btn btn-sm btn-outline-primary edit-record-btn"
                                                                  title="Edit Record"
                                                                  data-record-id="<?php echo (int)$record['id']; ?>"
                                                                  data-record-date="<?php echo htmlspecialchars($record['record_date']); ?>"
                                                                  data-selected-date="<?php echo htmlspecialchars($record['record_date']); ?>"
                                                                  data-animal-type="<?php echo htmlspecialchars($record['animal_type']); ?>"
                                                                  data-opening-stock="<?php echo htmlspecialchars($record['opening_stock']); ?>"
                                                                  data-mortality="<?php echo htmlspecialchars($record['mortality']); ?>"
                                                                  data-feed-consumption="<?php echo htmlspecialchars($record['feed_consumption_kg']); ?>"
                                                                  data-feed-item-id="<?php echo htmlspecialchars($record['feed_item_id'] ?? ''); ?>"
                                                                  data-water-consumption="<?php echo htmlspecialchars($record['water_consumption_liters']); ?>"
                                                                  data-tag-no="<?php echo htmlspecialchars($record['tag_no']); ?>"
                                                                  data-medications="<?php echo htmlspecialchars($record['medications']); ?>"
                                                                  data-reproduction-details="<?php echo htmlspecialchars($record['reproduction_details']); ?>"
                                                                  data-other-details="<?php echo htmlspecialchars($record['other_details']); ?>"
                                                                  data-remarks="<?php echo htmlspecialchars($record['remarks']); ?>">
                                                              <i class="bi bi-pencil"></i>
                                                          </button>
                                                        <?php if ($canDeleteRecords): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this record?');">
                                                            <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                                            <button type="submit" name="delete_record" class="btn btn-sm btn-outline-danger">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-secondary">
                                            <tr>
                                                <td><strong>TOTAL</strong></td>
                                                <td>--</td>
                                                <td class="text-danger fw-bold"><?php echo $typeTotals['mortality']; ?></td>
                                                <td class="fw-bold"><?php echo number_format($typeTotals['feed_consumption'], 2); ?></td>
                                                <td class="fw-bold"><?php echo number_format($typeTotals['water_consumption']); ?></td>
                                                <td colspan="<?php echo ($canEditRecords && $isEditableType) ? '6' : '5'; ?>">Monthly Summary</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <?php $animalPaneIndex++; ?>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Modal -->
    <div class="modal fade" id="recordModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="recordForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Ruminant Daily Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="record_date" id="recordDate">
                        <input type="hidden" name="record_id" id="recordId" value="0">
                        <input type="hidden" name="animal_type" id="animalTypeHidden">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Date</label>
                                <input type="date" class="form-control" id="selectedDate" max="<?php echo date('Y-m-d'); ?>"
                                       onchange="checkExistingRecord()" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Animal Type</label>
                                <select class="form-control" id="animalType" required>
                                    <option value="">Select Animal Type</option>
                                    <option value="cattle">Cattle</option>
                                    <option value="goat">Goat</option>
                                    <option value="sheep">Sheep</option>
                                    <option value="other">Other</option>
                                </select>
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
                                <label for="feedItemId">Ruminant Feed Item</label>
                                <select name="feed_item_id" id="feedItemId" class="form-select mb-2">
                                    <option value="">Select feed from current stock</option>
                                    <?php foreach ($feedItems as $feedItem): ?>
                                        <option value="<?php echo (int)$feedItem['id']; ?>"><?php echo htmlspecialchars($feedItem['item_name']); ?> — <?php echo htmlspecialchars($feedItem['current_stock']); ?> <?php echo htmlspecialchars($feedItem['unit']); ?> available<?php echo !(int)$feedItem['is_active'] ? ' (inactive; linked records only)' : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label>Feed Consumption (in the selected item's unit)</label>
                                <input type="number" name="feed_consumption" class="form-control"
                                       id="feedConsumption" oninput="document.getElementById('feedItemId').required = parseFloat(this.value) > 0" step="0.1" min="0" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Water Consumption (liters)</label>
                                <input type="number" name="water_consumption" class="form-control"
                                       id="waterConsumption" step="0.1" min="0" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Tag Number</label>
                                <input type="text" name="tag_no" class="form-control"
                                       id="tagNo" placeholder="Optional tag number">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Medications</label>
                                <input type="text" name="medications" class="form-control"
                                       id="medications" placeholder="Medications given">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label>Reproduction Details</label>
                            <textarea name="reproduction_details" class="form-control"
                                     id="reproductionDetails" rows="2"
                                     placeholder="Births, pregnancies, etc."></textarea>
                        </div>

                        <div class="mb-3">
                            <label>Other Details</label>
                            <textarea name="other_details" class="form-control"
                                     id="otherDetails" rows="2"></textarea>
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

<!--
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    -->
    <script>
    // Month selector
    document.getElementById('monthSelector').addEventListener('blur', function() {
        window.location.href = 'ruminant_daily_record.php?month=' + this.value.substring(0, 7) + '&cycle_id=<?php echo (int)$selectedCycleId; ?>';
    });
    const cycleSelector = document.getElementById('activeCycleSelector');
    if (cycleSelector) {
        cycleSelector.addEventListener('change', function () {
            window.location.href = 'ruminant_daily_record.php?month=<?php echo $yearMonth; ?>&cycle_id=' + this.value;
        });
    }

    const selectedCycleId = <?php echo (int)$selectedCycleId; ?>;
    const selectedCycleAnimalType = <?php echo json_encode($normalizeAnimalType($selectedCycle['production_type'] ?? '')); ?>;
    const validRuminantTypes = ['cattle', 'goat', 'sheep', 'other'];

    function getSelectedCycleAnimalType() {
        const animalType = String(selectedCycleAnimalType || '').toLowerCase();
        return selectedCycleId > 0 && validRuminantTypes.includes(animalType) ? animalType : null;
    }

    function parseNumericInput(value) {
        if (value === null || value === undefined) return '';
        return String(value).replace(/,/g, '').trim();
    }

    // Open modal for new record
    function openRecordModal(date = null, animalType = null, hasRecord = false) {
        const modalElement = document.getElementById('recordModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const today = new Date().toISOString().split('T')[0];
        const selectedDate = date || today;
        const defaultAnimalType = animalType || getSelectedCycleAnimalType();

        resetForm();
        document.getElementById('animalType').disabled = false;

        document.getElementById('selectedDate').value = selectedDate;
        document.getElementById('recordDate').value = selectedDate;

        if (defaultAnimalType) {
            document.getElementById('animalType').value = defaultAnimalType;
            document.getElementById('animalTypeHidden').value = defaultAnimalType;
        }

        if (hasRecord) {
            document.getElementById('modalTitle').textContent = 'Edit Record';
        } else {
            document.getElementById('modalTitle').textContent = "Add Today's Record";
            if (defaultAnimalType) {
                loadExistingRecordOrPreviousStock(selectedDate, defaultAnimalType);
            }
        }

        modal.show();
    }

    let dailyRecordLookupVersion = 0;

    function loadExistingRecordOrPreviousStock(date, animalType) {
        if (selectedCycleId <= 0 || !date || !animalType) {
            document.getElementById('modalTitle').textContent = "Add Today's Record";
            return;
        }

        const params = new URLSearchParams({
            date: date,
            animal_type: animalType
        });

        const cycleId = selectedCycleId;
        const lookupVersion = ++dailyRecordLookupVersion;
        fetch(`../api/check_ruminant_record.php?cycle_id=${cycleId}&${params}`)
            .then(response => response.json())
            .then(data => {
                if (!ruminantLookupIsCurrent(date, animalType, cycleId, lookupVersion)) return;
                if (data.exists) {
                    document.getElementById('modalTitle').textContent = 'Edit Record';
                    document.getElementById('animalType').disabled = true;
                    fetchRecordData(date, animalType, lookupVersion, cycleId);
                    return;
                }

                document.getElementById('modalTitle').textContent = "Add Today's Record";
                document.getElementById('animalType').disabled = false;
                fetchPreviousStock(date, animalType);
            })
            .catch(error => console.error(error));
    }

    const canEditRetrievedOpeningStock = <?php echo (isPlatformOwner() || hasRole('farm_admin')) ? 'true' : 'false'; ?>;
    function lockRetrievedOpeningStock() {
        if (!canEditRetrievedOpeningStock) {
            document.getElementById('openingStock').readOnly = true;
        }
    }
    function unlockOpeningStock() {
        document.getElementById('openingStock').readOnly = false;
    }

    // Fetch record data
    function ruminantLookupIsCurrent(date, animalType, cycleId, lookupVersion) {
        return lookupVersion === dailyRecordLookupVersion &&
            document.getElementById('selectedDate').value === date &&
            document.getElementById('animalType').value === animalType &&
            selectedCycleId === cycleId;
    }

    function fetchRecordData(date, animalType, lookupVersion = dailyRecordLookupVersion, cycleId = selectedCycleId) {
        const params = new URLSearchParams({
            date: date,
            animal_type: animalType
        });

        fetch(`../api/get_record.php?type=ruminant&cycle_id=${cycleId}&${params}`)
            .then(response => response.json())
            .then(payload => {
                if (payload && payload.success === false) {
                    throw new Error(payload.error || 'Failed to fetch record');
                }
                const data = payload ? payload.data : null;
                if (!ruminantLookupIsCurrent(date, animalType, cycleId, lookupVersion)) return;
                if (data) {
                    document.getElementById('recordId').value = data.id || 0;
                    document.getElementById('openingStock').value = parseNumericInput(data.opening_stock || '');
                    document.getElementById('mortality').value = parseNumericInput(data.mortality || 0);
                    document.getElementById('feedConsumption').value = parseNumericInput(data.feed_consumption_kg || '');
                    document.getElementById('feedItemId').value = data.feed_item_id || '';
                    document.getElementById('waterConsumption').value = parseNumericInput(data.water_consumption_liters || '');
                    document.getElementById('tagNo').value = data.tag_no || '';
                    document.getElementById('medications').value = data.medications || '';
                    document.getElementById('reproductionDetails').value = data.reproduction_details || '';
                    document.getElementById('otherDetails').value = data.other_details || '';
                    document.getElementById('remarks').value = data.remarks || '';
                }
            })
            .catch(error => {
                console.error(error);
            });
    }


    function fetchPreviousStock(date, animalType) {
        const params = new URLSearchParams({
            type: 'ruminant',
            date: date,
            animal_type: animalType,
            cycle_id: selectedCycleId
        });

        fetch(`../api/get_previous_stock.php?${params}`)
            .then(response => response.json())
            .then(payload => {
                if (payload && payload.closing_stock !== null && payload.closing_stock !== undefined) {
                    document.getElementById('openingStock').value = payload.closing_stock > 0 ? payload.closing_stock : '';
                    lockRetrievedOpeningStock();
                }
            })
            .catch(error => console.error(error));
    }

    // Check existing record
    function checkExistingRecord() {
        const date = document.getElementById('selectedDate').value;
        const animalType = document.getElementById('animalType').value;
        const cycleId = selectedCycleId;
        const lookupVersion = ++dailyRecordLookupVersion;

        document.getElementById('recordId').value = 0;
        document.getElementById('recordDate').value = date;
        document.getElementById('animalTypeHidden').value = animalType;

        if (!date || !animalType) return;
        if (selectedCycleId <= 0) {
            resetForm();
            document.getElementById('selectedDate').value = date;
            document.getElementById('recordDate').value = date;
            document.getElementById('animalType').value = animalType;
            document.getElementById('animalTypeHidden').value = animalType;
            return;
        }

        const params = new URLSearchParams({
            date: date,
            animal_type: animalType
        });

        fetch(`../api/check_ruminant_record.php?cycle_id=${cycleId}&${params}`)
            .then(response => response.json())
            .then(data => {
                if (!ruminantLookupIsCurrent(date, animalType, cycleId, lookupVersion)) return;
                if (data.exists) {
                    document.getElementById('modalTitle').textContent = 'Edit Record';
                    document.getElementById('animalType').disabled = true;
                    fetchRecordData(date, animalType, lookupVersion, cycleId);
                } else {
                    document.getElementById('modalTitle').textContent = "Add Today's Record";
                    document.getElementById('animalType').disabled = false;
                    const currentDate = date;
                    const currentAnimalType = animalType;
                    resetForm();
                    document.getElementById('selectedDate').value = currentDate;
                    document.getElementById('recordDate').value = currentDate;
                    document.getElementById('animalType').value = currentAnimalType;
                    document.getElementById('animalTypeHidden').value = currentAnimalType;
                    fetchPreviousStock(currentDate, currentAnimalType);
                }
            });
    }

    document.getElementById('animalType').addEventListener('change', () => {
        document.getElementById('animalTypeHidden').value = document.getElementById('animalType').value;
        checkExistingRecord();
    });

    // Reset form
    function resetForm() {
        unlockOpeningStock();
        document.getElementById('recordForm').reset();
        document.getElementById('mortality').value = 0;
        document.getElementById('feedItemId').required = parseFloat(document.getElementById('feedConsumption').value) > 0;
        document.getElementById('recordDate').value = document.getElementById('selectedDate').value;
        document.getElementById('animalTypeHidden').value = document.getElementById('animalType').value;
    }

    document.getElementById('recordForm').addEventListener('submit', () => {
        ['openingStock', 'mortality', 'feedConsumption', 'waterConsumption'].forEach((fieldId) => {
            const input = document.getElementById(fieldId);
            if (input) {
                input.value = parseNumericInput(input.value);
            }
        });
    });

    attachEditModal({
        buttonSelector: '.edit-record-btn',
        modalSelector: '#recordModal',
        fieldMap: {
            recordId: '#recordId',
            recordDate: '#recordDate',
            selectedDate: '#selectedDate',
            animalType: '#animalType',
            openingStock: '#openingStock',
            mortality: '#mortality',
            feedConsumption: '#feedConsumption',
            feedItemId: '#feedItemId',
            waterConsumption: '#waterConsumption',
            tagNo: '#tagNo',
            medications: '#medications',
            reproductionDetails: '#reproductionDetails',
            otherDetails: '#otherDetails',
            remarks: '#remarks'
        },
        onShow: ({ modalElement, data }) => {
            modalElement.querySelector('#modalTitle').textContent = 'Edit Record';
            modalElement.querySelector('#feedItemId').required = parseFloat(modalElement.querySelector('#feedConsumption').value) > 0;
            modalElement.querySelector('#animalType').disabled = true;
            modalElement.querySelector('#animalTypeHidden').value = data.animalType || '';
        }
    });

    document.querySelectorAll('.add-record-btn').forEach(button => {
        button.addEventListener('click', () => {
            const cycleAnimalType = (button.dataset.cycleAnimalType || '').toLowerCase();
            const animalType = validRuminantTypes.includes(cycleAnimalType) ? cycleAnimalType : null;
            openRecordModal(button.dataset.recordDate, animalType, false);
        });
    });

    // Show messages
    </script>
</body>
</html>
