<?php require_once(__DIR__ . '/../init.php'); ?>
<?php
// permissions_save.php - save permission changes
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

ensurePermissionsTable($pdo);

if (!isPlatformOwner() && !hasRole('farm_admin')) {
    header('Location: /no_access.php'); exit();
}

$roles = isPlatformOwner() ? ['poultry_manager','ruminant_manager','sales_rep'] : validTenantRoleCodes(['poultry_manager','ruminant_manager','sales_rep']);
$modules = [
  'poultry_overview','poultry_daily_layer','poultry_daily_broiler','poultry_feeds','poultry_health','poultry_expenses',
  'ruminant_overview','ruminant_daily','ruminant_feeds','update_stock','ruminant_expenses',
  'inventory','inventory_add_new_item','reports','users','management','settings','sales','expenses','permissions'
];

$incoming = isset($_POST['perm']) ? $_POST['perm'] : [];

try {
    $pdo->beginTransaction();

    foreach ($roles as $role) {
        foreach ($modules as $module) {
            $allowed = (isset($incoming[$role][$module]) && $incoming[$role][$module]==1) ? 1 : 0;
            $stmt = $pdo->prepare("INSERT INTO permissions (role,module,allowed) VALUES (?,?,?) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)");
            $stmt->execute([$role, $module, $allowed]);
        }
    }

    $pdo->commit();
    header('Location: permissions.php?updated=1');
    exit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = 'Permission save failed: ' . $e->getMessage();
    error_log($message);

    // Keep a user-visible hint about what went wrong and log to a dedicated file
    $_SESSION['permission_error_detail'] = $e->getMessage();

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logEntry = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    @file_put_contents($logDir . '/permissions_error.log', $logEntry, FILE_APPEND);
    header('Location: permissions.php?error=1');
    exit();
}
