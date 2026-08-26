<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/api_helpers.php');
requireLogin();

if (!isset($_GET['type'], $_GET['date'])) {
    send_json(['success' => false, 'error' => 'type and date parameters are required'], 400);
}

$type = $_GET['type'];
$date = $_GET['date'];
$cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$record = null;
$isOwnerOrAdmin = isPlatformOwner() || hasRole('farm_admin');

if (!$isOwnerOrAdmin) {
    if (in_array($type, ['layer', 'broiler'], true) && !checkAccess('poultry')) {
        send_json(['success' => false, 'error' => 'Unauthorized for poultry records'], 403);
    }
    if ($type === 'ruminant' && !checkAccess('ruminant')) {
        send_json(['success' => false, 'error' => 'Unauthorized for ruminant records'], 403);
    }
}

if ($type === 'layer') {
    $sql = "SELECT * FROM layer_daily_records WHERE record_date = ? AND farm_id = ?";
    $params = [$date, requireCurrentFarmId()];
    if ($cycleId > 0) {
        $sql .= " AND cycle_id = ?";
        $params[] = $cycleId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($type === 'broiler') {
    $sql = "SELECT * FROM broiler_daily_records WHERE record_date = ? AND farm_id = ?";
    $params = [$date, requireCurrentFarmId()];
    if ($cycleId > 0) {
        $sql .= " AND cycle_id = ?";
        $params[] = $cycleId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($type === 'ruminant') {
    if (!isset($_GET['animal_type'])) {
        send_json(['success' => false, 'error' => 'animal_type parameter is required for ruminant'], 400);
    }

    $animalType = strtolower(trim($_GET['animal_type']));
    $sql = "SELECT * FROM ruminant_daily_records
                           WHERE record_date = ? AND LOWER(animal_type) = ? AND farm_id = ?";
    $params = [$date, $animalType, requireCurrentFarmId()];
    if ($cycleId > 0) {
        $sql .= " AND cycle_id = ?";
        $params[] = $cycleId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    send_json(['success' => false, 'error' => 'Unsupported record type'], 400);
}

send_json(['success' => true, 'data' => $record ?: null]);
?>
