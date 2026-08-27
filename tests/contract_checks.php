<?php

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function readFileOrFail(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $content;
}

$root = dirname(__DIR__);

// Tenant isolation is a non-negotiable SaaS boundary.
$tenantMigration = readFileOrFail($root . '/migrations/003_multi_tenant_saas.sql');
assertContains('CREATE TABLE IF NOT EXISTS farms', $tenantMigration, 'Tenant migration must create farms.');
assertContains('CREATE TABLE IF NOT EXISTS subscriptions', $tenantMigration, 'Tenant migration must create subscriptions.');
foreach (['users', 'stock_items', 'sales_records', 'farm_expenses', 'layer_daily_records', 'broiler_daily_records', 'ruminant_daily_records'] as $table) {
    assertContains("ALTER TABLE {$table} ADD COLUMN farm_id", $tenantMigration, "{$table} must have a farm_id.");
}

$login = readFileOrFail($root . '/sign.php');
assertContains('f.slug = ?', $login, 'Login must select a farm workspace.');
assertContains("name=\"farm_slug\"", $login, 'Login must ask for a farm workspace.');
assertContains("\$_SESSION['farm_id']", $login, 'Login must retain the current farm id.');

foreach (['api/delete_record.php', 'api/delete_sale.php', 'api/delete_expense.php', 'api/update_expense.php', 'api/update_stock.php'] as $relativePath) {
    assertContains('farm_id', readFileOrFail($root . '/' . $relativePath), "{$relativePath} must scope mutations to a farm.");
}

$rolesMigration = readFileOrFail($root . '/migrations/004_tenant_modules_and_roles.sql');
assertContains('CREATE TABLE IF NOT EXISTS farm_modules', $rolesMigration, 'SaaS must model per-farm module entitlements.');
assertContains('CREATE TABLE IF NOT EXISTS user_roles', $rolesMigration, 'SaaS must support multiple roles per user.');
$roleAlignmentMigration = readFileOrFail($root . '/migrations/005_align_five_role_access_model.sql');
foreach (['platform_owner', 'farm_admin', 'ruminant_manager', 'poultry_manager', 'sales_rep'] as $role) {
    assertContains("'{$role}'", $roleAlignmentMigration, "The five-role model must include {$role}.");
}

$config = readFileOrFail($root . '/config.php');
assertContains('function farmHasModule', $config, 'Authorization must check enabled farm modules.');
assertContains('function currentUserRoles', $config, 'Authorization must load user roles.');
assertContains('function isPlatformOwner', $config, 'Authorization must distinguish the Owner / Developer role.');
assertContains('function allowedSalesFarmTypes', $config, 'Sales-only farms must have a valid sales classification fallback.');
assertContains('$types[] = \'general\'', $config, 'Sales-only farms must use a durable neutral sales classification.');
assertContains('if (!$fallback) return \'\'', $config, 'Disallowed specialist farm types must not fall back to another module.');
assertContains('return $allowed[0] ?? \'\';', $config, 'A farm without livestock entitlements must not receive an unrestricted report filter.');

$expensesReport = readFileOrFail($root . '/management/expenses.php');
assertContains("e.farm_type = ? OR e.farm_type = 'both'", $expensesReport, 'Single-module expense reports must include shared expenses.');
assertContains("\$requestedFarmType === 'both' && count(enabledFarmTypes()) === 2", $expensesReport, 'Dual-module expense readers must receive the combined report scope.');
$salesReport = readFileOrFail($root . '/management/sales_records.php');
assertContains('$saleFarmTypes = allowedSalesFarmTypes()', $salesReport, 'Sales controls must support sales-only farms.');
assertContains('in_array($saleFarmType, $saleFarmTypes, true)', $salesReport, 'Sales mutations must validate against sales-compatible farm types.');
assertContains("s.farm_type = ? OR s.farm_type = 'general'", $salesReport, 'Module-filtered sales must retain neutral sales records.');
assertContains("\$salesOnlyScope\n    ? 'general'", $salesReport, 'Sales-only workspaces must select the neutral sales scope.');
assertContains('option value="general" selected>All Sales', $salesReport, 'The sales-only filter must preserve its neutral scope after redirects.');
assertContains("\$requestedFarmType === 'both' && count(enabledFarmTypes()) === 2", $salesReport, 'Dual-module sales readers must receive the combined report scope.');
$analyticsReport = readFileOrFail($root . '/management/reports.php');
assertContains("\$requestedFarmType === 'both' && count(enabledFarmTypes()) === 2", $analyticsReport, 'Dual-module analytics readers must receive the combined report scope.');
assertContains("\$salesOnlyScope\n    ? 'general'", $analyticsReport, 'Sales-only analytics must select the neutral general scope.');
$combinedReport = readFileOrFail($root . '/management/poultry_ruminant_report.php');
assertContains('$farmType === \'all\' && isset($salesSummary[\'general\'])', $combinedReport, 'Combined livestock reports must display general sales.');
assertContains('General Sales', $combinedReport, 'Combined livestock reports must label the general sales total.');
assertContains("\$requestedFarmType === 'both' && count(enabledFarmTypes()) === 2", $combinedReport, 'Dual-module viewers must receive the authorized combined report scope.');

$generalSalesMigration = readFileOrFail($root . '/migrations/010_general_sales_farm_type.sql');
assertContains("'general'", $generalSalesMigration, 'The sales schema must support durable neutral records.');

$farmsPage = readFileOrFail($root . '/management/farms.php');
assertContains('requirePlatformOwner()', $farmsPage, 'Farm provisioning must be Owner / Developer-only.');
assertContains('farm_modules', $farmsPage, 'Farm provisioning must save selected modules.');
assertContains('update_farm', $farmsPage, 'Platform owners must be able to edit farm profiles.');
assertContains('delete_farm', $farmsPage, 'Platform owners must be able to delete farm profiles.');
assertContains('deleteFarmData', $farmsPage, 'Farm deletion must remove tenant-owned records before the farm account.');

$inventory = readFileOrFail($root . '/inventory.php');
assertContains('requireCurrentFarmId()', $inventory, 'Inventory must identify the active farm.');
assertContains('si.farm_id = ?', $inventory, 'Inventory reads must be scoped to the active farm.');
assertContains('INSERT INTO inventory_categories (farm_id', $inventory, 'New categories must be assigned to the active farm.');
assertContains('INSERT INTO stock_items', $inventory, 'Inventory must create stock items.');
assertContains('(farm_id, item_name, category_id', $inventory, 'New stock items must be assigned to the active farm.');

$navbar = readFileOrFail($root . '/navbar.php');
assertContains('!isPlatformOwner() && hasPermission', $navbar, 'Platform owners should not be sent to farm-only user management.');

// Mutating APIs should enforce POST and CSRF.
$mutatingApis = [
    'api/delete_record.php',
    'api/delete_sale.php',
    'api/delete_expense.php',
    'api/update_expense.php',
    'api/update_stock.php'
];

foreach ($mutatingApis as $relativePath) {
    $content = readFileOrFail($root . '/' . $relativePath);
    assertContains("require_http_method('POST')", $content, "{$relativePath} must enforce POST.");
    assertContains("require_csrf_token()", $content, "{$relativePath} must enforce CSRF.");
}

// Read API should return success/data envelope.
$getRecord = readFileOrFail($root . '/api/get_record.php');
assertContains("'success' => true", $getRecord, "api/get_record.php should return success envelope.");
assertContains("'data' =>", $getRecord, "api/get_record.php should return data envelope.");

$chartData = readFileOrFail($root . '/api/get_chart_data.php');
foreach (['getProfitLossData', 'getSalesData', 'getExpenseData', 'getStockData', 'getProductionData'] as $function) {
    $start = strpos($chartData, "function {$function}");
    $next = strpos($chartData, '\nfunction ', $start + 1);
    $body = substr($chartData, $start, $next === false ? null : $next - $start);
    assertContains('requireCurrentFarmId()', $body, "{$function} must scope chart data to the active farm.");
}

assertContains("checkAccess('poultry')", readFileOrFail($root . '/api/delete_record.php'), 'Record deletion must enforce module and role access.');
assertContains('$itemFarmType', readFileOrFail($root . '/api/update_stock.php'), 'Stock updates must derive farm type from the tenant-owned item.');
foreach (['inventory/add_category.php', 'inventory/delete_category.php'] as $relativePath) {
    assertContains('verify_csrf_token', readFileOrFail($root . '/' . $relativePath), "{$relativePath} must enforce CSRF.");
}

$productionCycles = readFileOrFail($root . '/management/production_cycles.php');
assertContains('water_consumption_liters, other_details', $productionCycles, 'Ruminant cycle seed must include water consumption.');
assertContains('VALUES (?, ?, ?, ?, ?, 0, 0, 0, NULL', $productionCycles, 'Ruminant cycle seed must provide zero rather than NULL for required water consumption.');
assertContains('$pdo->beginTransaction()', $productionCycles, 'Cycle creation and its opening record must be atomic.');
assertContains('verify_csrf_token', $productionCycles, 'Production-cycle mutations must enforce CSRF.');

$dashboard = readFileOrFail($root . '/dashboard.php');
assertContains('$farmAccess = getUserFarmType();', $dashboard, 'Dashboard module visibility must use the shared farm-access resolver for every role.');
assertContains("if (in_array(\$farmAccess, ['poultry', 'ruminant', 'both'], true))", $dashboard, 'Active-cycle ticker must be available to all entitled dashboard roles.');

// Every specialist expense workspace must read and create records in the active tenant.
foreach (['poultry/broiler_expenses.php', 'poultry/layer_expenses.php', 'ruminant/ruminant_expenses.php'] as $relativePath) {
    $content = readFileOrFail($root . '/' . $relativePath);
    assertContains('requireCurrentFarmId()', $content, "{$relativePath} must resolve the active farm.");
    assertContains('WHERE e.farm_id = ?', $content, "{$relativePath} reads must be tenant scoped.");
    assertContains('(farm_id, expense_date', $content, "{$relativePath} inserts must store the active farm.");
}

$inventory = readFileOrFail($root . '/inventory.php');
assertContains('SELECT id FROM inventory_categories WHERE id = ? AND farm_id = ?', $inventory, 'Inventory must reject categories owned by another farm.');
foreach (['UPDATE stock_items SET is_active = 0 WHERE id = ? AND farm_id = ?', 'UPDATE stock_items SET is_active = 1 WHERE id = ? AND farm_id = ?', 'DELETE FROM stock_transactions WHERE stock_item_id = ? AND farm_id = ?'] as $tenantMutation) {
    assertContains($tenantMutation, $inventory, 'Inventory destructive mutations must include the active farm.');
}

assertContains("SELECT id FROM production_cycles WHERE id = ? AND farm_id = ? AND status = 'active'", $productionCycles, 'Stock batches must only link to an active cycle in the current farm.');

$farmsPage = readFileOrFail($root . '/management/farms.php');
assertContains('function detectFarmLogoExtension', $farmsPage, 'Farm logos must be validated before provisioning begins.');
assertContains('function findFarmAdminId', $farmsPage, 'Farm editing must recover legacy or partially-created admin users.');
assertContains("INSERT INTO users (farm_id, username, password, email, user_type, full_name)", $farmsPage, 'Editing an incomplete farm must be able to create its missing admin.');
assertContains('Incomplete farm cleanup failed', $farmsPage, 'Failed provisioning must perform compensating cleanup on non-transactional databases.');
assertContains('This farm was only partially created and has no admin account.', $farmsPage, 'Incomplete farms must show actionable repair guidance.');

echo "Contract checks passed.\n";
