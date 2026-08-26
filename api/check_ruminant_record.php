<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

header('Content-Type: application/json');

if (isset($_GET['date']) && isset($_GET['animal_type'])) {
    $date = $_GET['date'];
    $animalType = strtolower(trim($_GET['animal_type']));
    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    
    $sql = "SELECT id FROM ruminant_daily_records
                           WHERE record_date = ? AND LOWER(animal_type) = ? AND farm_id = ?";
    $params = [$date, $animalType, requireCurrentFarmId()];
    if ($cycleId > 0) {
        $sql .= " AND cycle_id = ?";
        $params[] = $cycleId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode(['exists' => $stmt->fetch() ? true : false]);
} else {
    echo json_encode(['error' => 'Missing parameters']);
}
?>
