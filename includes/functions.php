<?php require_once(__DIR__ . '/../init.php'); ?>
<?php
// includes/functions.php (updated compatibility)

// If $pdo is not set but $conn (mysqli) is present, create a PDO wrapper.
if (!isset($pdo) && isset($conn) && $conn instanceof mysqli) {
    $mysqli = $conn;
    $dsn = 'mysql:host='.$mysqli->host_info.';dbname='.$mysqli->database.';charset=utf8mb4';
    // The above may not give correct host/db - prefer config.php to create $pdo. Skip automatic conversion.
}

if (!function_exists('ensureInventoryActiveColumn')) {
function ensureInventoryActiveColumn($pdo) {
    // Ensure the stock_items table has an is_active flag for soft deletes
    $checkStmt = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'is_active'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE stock_items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER farm_type");
    }
}
}

if (!function_exists('ensureInventoryUnitCostColumn')) {
function ensureInventoryUnitCostColumn($pdo) {
    // Ensure the stock_items table can store a unit cost for accurate stock valuation
    $checkStmt = $pdo->query("SHOW COLUMNS FROM stock_items LIKE 'unit_cost'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE stock_items ADD COLUMN unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER current_stock");
    }
}
}

if (!function_exists('ensureExpenseUnitColumn')) {
function ensureExpenseUnitColumn($pdo) {
    // Ensure the farm_expenses table can store a unit quantity for line-item totals
    $checkStmt = $pdo->query("SHOW COLUMNS FROM farm_expenses LIKE 'unit'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE farm_expenses ADD COLUMN unit DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER amount");
    }
}
}

if (!function_exists('ensureUserLastLoginColumn')) {
function ensureUserLastLoginColumn($pdo) {
    // Track the last time each user logged in to avoid shared login timestamps
    $checkStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL AFTER full_name");
    }
}
}

if (!function_exists('ensurePoultryCategoryColumn')) {
function ensurePoultryCategoryColumn($pdo) {
    // Ensure the farm_expenses table can distinguish poultry expenses (broiler vs layer)
    $checkStmt = $pdo->query("SHOW COLUMNS FROM farm_expenses LIKE 'poultry_category'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE farm_expenses ADD COLUMN poultry_category ENUM('broiler','layer') NULL DEFAULT NULL AFTER farm_type");
    }
}
}

if (!function_exists('ensurePermissionsTable')) {
function ensurePermissionsTable($pdo) {
    // Ensure permissions table exists before attempting permission reads/writes
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'permissions'");
    if ($checkStmt->rowCount() === 0) {
        $pdo->exec(
            "CREATE TABLE permissions (" .
            "id INT AUTO_INCREMENT PRIMARY KEY," .
            // Use VARCHAR instead of ENUM to avoid insert errors when roles change
            "role VARCHAR(100) NOT NULL," .
            "module VARCHAR(100) NOT NULL," .
            "allowed TINYINT(1) NOT NULL DEFAULT 0," .
            "UNIQUE KEY unique_role_module (role, module)" .
            ")"
        );
    } else {
        // If the table exists from an older schema (ENUM), relax the column to VARCHAR
        $columnStmt = $pdo->query("SHOW COLUMNS FROM permissions LIKE 'role'");
        $column = $columnStmt->fetch(PDO::FETCH_ASSOC);
        if ($column && stripos($column['Type'], 'enum(') === 0) {
            $pdo->exec("ALTER TABLE permissions MODIFY COLUMN role VARCHAR(100) NOT NULL");
        }
    }
}
}

if (!function_exists('runSchemaMigrations')) {
function runSchemaMigrations($pdo, array $targets = []) {
    static $completed = [];

    $map = [
        'inventory_active' => 'ensureInventoryActiveColumn',
        'inventory_unit_cost' => 'ensureInventoryUnitCostColumn',
        'expense_unit' => 'ensureExpenseUnitColumn',
        'user_last_login' => 'ensureUserLastLoginColumn',
        'poultry_category' => 'ensurePoultryCategoryColumn',
        'permissions_table' => 'ensurePermissionsTable'
    ];

    $selectedTargets = empty($targets) ? array_keys($map) : $targets;
    sort($selectedTargets);
    $signature = implode('|', $selectedTargets);

    if (isset($completed[$signature])) {
        return;
    }

    foreach ($selectedTargets as $target) {
        if (!isset($map[$target])) {
            continue;
        }

        $functionName = $map[$target];
        if (function_exists($functionName)) {
            $functionName($pdo);
        }
    }

    $completed[$signature] = true;
}
}

if (!function_exists('hasPermission')) {
function hasPermission($role, $module) {
    global $pdo, $conn;
    if (!$role) return false;
    if (function_exists('isPlatformOwner') && isPlatformOwner()) return true;
    if (function_exists('farmHasModule')) {
        if (str_starts_with($module, 'poultry') && !farmHasModule('poultry')) return false;
        if (str_starts_with($module, 'ruminant') && !farmHasModule('ruminant')) return false;
        if (in_array($module, ['sales', 'expenses'], true) && !farmHasModule('sales')) return false;
    }
    if (function_exists('hasRole')) {
        if (hasRole('farm_admin')) return true;
        // Viewers never receive write-module permissions. They may only open
        // the shared reporting workspace for the farm in their session.
        if (hasRole('viewer')) return in_array($module, ['management', 'reports'], true);
        $role = $role ?: (currentUserRoles()[0] ?? '');
        if (str_starts_with($module, 'poultry') && !hasRole('poultry_manager')) return false;
        if (str_starts_with($module, 'ruminant') && !hasRole('ruminant_manager')) return false;
        if ($module === 'sales' && !hasRole('sales_rep')) return false;
    } elseif ($role === 'farm_admin') return true;
    if (isset($pdo)) {
        $sql = "SELECT allowed FROM permissions WHERE role = ? AND module = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$role,$module])) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return ($row['allowed'] == 1);
        }
        return false;
    } elseif (isset($conn)) {
        $sql = "SELECT allowed FROM permissions WHERE role = ? AND module = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss',$role,$module);
        $stmt->execute();
        $stmt->bind_result($allowed);
        if ($stmt->fetch()) { $stmt->close(); return ($allowed==1);} 
        $stmt->close();
        return false;
    }
    return false;
}
}

if (!function_exists('ensureAllowed')) {
function ensureAllowed($module) {
    if (!isset($_SESSION['user_type'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit();
    }
    if (!hasPermission($_SESSION['user_type'], $module)) {
        header('Location: ' . BASE_URL . '/no_access.php');
        exit();
    }
}
}
?>
