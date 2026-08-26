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

echo "Contract checks passed.\n";
