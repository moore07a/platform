<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['type'], $_GET['date'])) {
    echo json_encode(['closing_stock' => null, 'error' => 'Missing parameters']);
    exit;
}

$type = $_GET['type'];
$date = $_GET['date'];
$cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$animalType = isset($_GET['animal_type']) ? strtolower(trim($_GET['animal_type'])) : '';

$tableMap = [
    'layer' => ['table' => 'layer_daily_records', 'animal' => false],
    'broiler' => ['table' => 'broiler_daily_records', 'animal' => false],
    'ruminant' => ['table' => 'ruminant_daily_records', 'animal' => true],
];

if (!isset($tableMap[$type]) || ($tableMap[$type]['animal'] && $animalType === '')) {
    echo json_encode(['closing_stock' => null, 'error' => 'Invalid parameters']);
    exit;
}

$sql = "SELECT * FROM {$tableMap[$type]['table']} WHERE record_date < ? AND farm_id = ?";
$params = [$date, requireCurrentFarmId()];
if ($cycleId > 0) {
    $sql .= " AND cycle_id = ?";
    $params[] = $cycleId;
}
if ($tableMap[$type]['animal']) {
    $sql .= " AND LOWER(animal_type) = ?";
    $params[] = $animalType;
}
$sql .= " ORDER BY record_date DESC, id DESC LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if ($record) {
    $closingStock = max(0, (float)$record['opening_stock'] - (float)$record['mortality']);
    echo json_encode(['closing_stock' => $closingStock, 'previous_record' => $record]);
} else {
    echo json_encode(['closing_stock' => null, 'message' => 'No earlier record found']);
}
?>
