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
    $sql = "SELECT dr.*, fcil.stock_item_id AS feed_stock_item_id FROM layer_daily_records dr
            LEFT JOIN feed_consumption_inventory_links fcil
              ON fcil.farm_id = dr.farm_id AND fcil.record_type = 'layer' AND fcil.record_id = dr.id
            WHERE dr.record_date = ? AND dr.farm_id = ?";
    $params = [$date, requireCurrentFarmId()];
    if ($cycleId > 0) {
        $sql .= " AND dr.cycle_id = ?";
        $params[] = $cycleId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($type === 'broiler') {
    $sql = "SELECT dr.*, fcil.stock_item_id AS feed_stock_item_id FROM broiler_daily_records dr
            LEFT JOIN feed_consumption_inventory_links fcil
              ON fcil.farm_id = dr.farm_id AND fcil.record_type = 'broiler' AND fcil.record_id = dr.id
            WHERE dr.record_date = ? AND dr.farm_id = ?";
    $params = [$date, requireCurrentFarmId()];
    if ($cycleId > 0) {
        $sql .= " AND dr.cycle_id = ?";
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
    $sql = "SELECT dr.*, fcil.stock_item_id AS feed_stock_item_id FROM ruminant_daily_records dr
            LEFT JOIN feed_consumption_inventory_links fcil
              ON fcil.farm_id = dr.farm_id AND fcil.record_type = 'ruminant' AND fcil.record_id = dr.id
            WHERE dr.record_date = ? AND LOWER(dr.animal_type) = ? AND dr.farm_id = ?";
    $params = [$date, $animalType, requireCurrentFarmId()];
    if ($cycleId > 0) {
        $sql .= " AND dr.cycle_id = ?";
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
