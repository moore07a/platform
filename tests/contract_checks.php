<?php

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function assertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
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
assertContains('ON DUPLICATE KEY UPDATE', $roleAlignmentMigration, 'Role alignment must be safe to resume after creating farm_admin.');

$config = readFileOrFail($root . '/config.php');
assertContains('function farmHasModule', $config, 'Authorization must check enabled farm modules.');
assertContains('function currentUserRoles', $config, 'Authorization must load user roles.');
assertContains('function isPlatformOwner', $config, 'Authorization must distinguish the Owner / Developer role.');
assertContains('function allowedSalesFarmTypes', $config, 'Sales-only farms must have a valid sales classification fallback.');
assertContains('$types[] = \'general\'', $config, 'Sales-only farms must use a durable neutral sales classification.');
assertContains('if (!$fallback) return \'\'', $config, 'Disallowed specialist farm types must not fall back to another module.');
assertContains('return $allowed[0] ?? \'\';', $config, 'A farm without livestock entitlements must not receive an unrestricted report filter.');
assertContains('function allowedFeedCategories', $config, 'Feed classifications must be derived from farm subscriptions for every role.');
assertContains('function accessibleFarmTypes', $config, 'Selectable farm types must account for platform-owner access.');
assertContains("return isPlatformOwner() ? ['poultry', 'ruminant'] : enabledFarmTypes();", $config, 'Platform owners must receive both livestock choices even in the dedicated owner workspace.');
assertContains("if (in_array('poultry', \$farmTypes, true))", $config, 'Poultry feed classifications must require poultry access.');
assertContains("if (in_array('ruminant', \$farmTypes, true)) \$categories[] = 'ruminant';", $config, 'Ruminant feed classifications must require ruminant access.');

$expensesReport = readFileOrFail($root . '/management/expenses.php');
assertContains("e.farm_type = ? OR e.farm_type = 'both'", $expensesReport, 'Single-module expense reports must include shared expenses.');
assertContains("\$requestedFarmType === 'both' && count(accessibleFarmTypes()) === 2", $expensesReport, 'Dual-module expense readers must receive the combined report scope.');
$salesReport = readFileOrFail($root . '/management/sales_records.php');
assertContains('$saleFarmTypes = allowedSalesFarmTypes()', $salesReport, 'Sales controls must support sales-only farms.');
assertContains('in_array($saleFarmType, $saleFarmTypes, true)', $salesReport, 'Sales mutations must validate against sales-compatible farm types.');
assertContains("s.farm_type = ? OR s.farm_type = 'general'", $salesReport, 'Module-filtered sales must retain neutral sales records.');
assertContains("&& (isPlatformOwner() || hasRole('farm_admin', 'sales_rep', 'viewer'))", $salesReport, 'Sales-only workspaces must reject stale livestock-only roles.');
assertContains("\$salesOnlyScope\n    ? 'general'", $salesReport, 'Authorized sales-only workspaces must select the neutral sales scope.');
assertContains('option value="general" selected>All Sales', $salesReport, 'The sales-only filter must preserve its neutral scope after redirects.');
assertContains("\$requestedFarmType === 'both' && count(accessibleFarmTypes()) === 2", $salesReport, 'Dual-module sales readers must receive the combined report scope.');
$analyticsReport = readFileOrFail($root . '/management/reports.php');
assertContains("\$requestedFarmType === 'both' && count(accessibleFarmTypes()) === 2", $analyticsReport, 'Dual-module analytics readers must receive the combined report scope.');
assertContains("&& (isPlatformOwner() || hasRole('farm_admin', 'sales_rep', 'viewer'))", $analyticsReport, 'Sales-only analytics must reject stale livestock-only roles.');
assertContains("\$salesOnlyScope\n    ? 'general'", $analyticsReport, 'Authorized sales-only analytics must select the neutral general scope.');
assertContains("if (\$farmType === '' || \$salesOnlyScope)", $analyticsReport, 'Sales-only analytics must not deduct livestock expenses.');
assertContains("if (\$farmType === '' || \$salesOnlyScope) {\n    \$expenseQuery .= \" AND 1 = 0\";", $analyticsReport, 'Empty and sales-only expense breakdown scopes must be empty.');
$combinedReport = readFileOrFail($root . '/management/poultry_ruminant_report.php');
assertContains('$farmType === \'all\' && isset($salesSummary[\'general\'])', $combinedReport, 'Combined livestock reports must display general sales.');
assertContains('General Sales', $combinedReport, 'Combined livestock reports must label the general sales total.');
assertContains("\$requestedFarmType === 'both' && count(accessibleFarmTypes()) === 2", $combinedReport, 'Dual-module viewers must receive the authorized combined report scope.');

$generalSalesMigration = readFileOrFail($root . '/migrations/010_general_sales_farm_type.sql');
assertContains("'general'", $generalSalesMigration, 'The sales schema must support durable neutral records.');

$farmsPage = readFileOrFail($root . '/management/farms.php');
assertContains('requirePlatformOwner()', $farmsPage, 'Farm provisioning must be Owner / Developer-only.');
assertContains('farm_modules', $farmsPage, 'Farm provisioning must save selected modules.');
assertContains('update_farm', $farmsPage, 'Platform owners must be able to edit farm profiles.');
assertContains('delete_farm', $farmsPage, 'Platform owners must be able to delete farm profiles.');
assertContains('deleteFarmData', $farmsPage, 'Farm deletion must remove tenant-owned records before the farm account.');
assertTrue(
    strpos($farmsPage, "'ruminant_daily_records'") < strpos($farmsPage, "'stock_transactions'"),
    'Farm deletion must remove linked daily records before their stock transactions.'
);

$inventory = readFileOrFail($root . '/inventory.php');
assertContains('requireCurrentFarmId()', $inventory, 'Inventory must identify the active farm.');
assertContains('si.farm_id = ?', $inventory, 'Inventory reads must be scoped to the active farm.');
assertContains('INSERT INTO inventory_categories (farm_id', $inventory, 'New categories must be assigned to the active farm.');
assertContains('INSERT INTO stock_items', $inventory, 'Inventory must create stock items.');
assertContains('(farm_id, item_name, category_id', $inventory, 'New stock items must be assigned to the active farm.');
assertContains('name="initial_stock_date"', $inventory, 'New inventory items must provide a calendar date for their opening stock.');
assertContains("createFromFormat('!Y-m-d', \$initialStockDate)", $inventory, 'The opening stock date must be validated on the server.');
assertContains("\$parsedInitialStockDate > new DateTimeImmutable('today')", $inventory, 'Opening stock must not be dated in the future.');
assertContains('max="<?php echo date(\'Y-m-d\'); ?>"', $inventory, 'The opening-stock date input must not offer future dates.');
assertContains("\$parsedInitialStockDate->format('Y-m-d')", $inventory, 'The selected opening stock date must be used by the initial stock transaction.');

foreach (['poultry/layers_daily_record.php' => 'layer', 'poultry/broiler_daily_record.php' => 'broiler', 'ruminant/ruminant_daily_record.php' => 'ruminant'] as $dailyPage => $feedCategory) {
    $dailyRecord = readFileOrFail($root . '/' . $dailyPage);
    assertContains('name="feed_item_id"', $dailyRecord, "{$dailyPage} must let the user select a feed inventory item.");
    assertContains("feed_category = '{$feedCategory}'", $dailyRecord, "{$dailyPage} must show only matching feed inventory items.");
    assertContains('syncDailyFeedConsumption(', $dailyRecord, "{$dailyPage} must synchronize daily consumption with current feed stock.");
    assertContains('$checkSql .= " FOR UPDATE"', $dailyRecord, "{$dailyPage} must lock an existing daily record before reading its feed movement.");
    assertContains('data-feed-item-id=', $dailyRecord, "{$dailyPage} edit controls must retain the linked feed item.");
    assertContains("feedItemId: '#feedItemId'", $dailyRecord, "{$dailyPage} edit modal must prefill the linked feed item.");
    assertContains('$linkedFeedItemId = $movementId ? $feedItemId : null;', $dailyRecord, "{$dailyPage} must not retain an inventory link for zero consumption.");
    assertContains("\$parsedRecordDate > new DateTimeImmutable('today')", $dailyRecord, "{$dailyPage} must reject future record dates server-side.");
    assertContains('id="selectedDate" max="<?php echo date(\'Y-m-d\'); ?>"', $dailyRecord, "{$dailyPage} must prevent future record dates in the form.");
    assertContains("document.getElementById('feedItemId').required = parseFloat(document.getElementById('feedConsumption').value) > 0;", $dailyRecord, "{$dailyPage} must reset the conditional feed-item requirement.");
    assertTrue(
        strpos($dailyRecord, '$pdo->beginTransaction()') < strpos($dailyRecord, '$checkStmt->execute($checkParams)'),
        "{$dailyPage} must begin its transaction before looking up an existing daily record."
    );
}
$inventoryFunctions = readFileOrFail($root . '/includes/functions.php');
assertContains("transaction_type = 'used'", $inventoryFunctions, 'Daily feed deductions must be recorded as auditable used-stock movements.');
assertContains("FOR UPDATE", $inventoryFunctions, 'Daily feed deductions must lock stock while validating availability.');
assertContains('stockTransactionHasDailyFeedLinks', $inventoryFunctions, 'Ledger mutations must be able to identify daily-record-managed movements.');
assertContains('detachDailyFeedTransaction', $inventoryFunctions, 'Generated movements must be detached from daily records before restrictive deletion.');
assertContains('would make stock negative on its transaction date', $inventoryFunctions, 'Backdated movements must not create a negative historical balance.');
assertContains('?string $fromDate = null, int $fromId = 0', $inventoryFunctions, 'Ledger replay must support recalculating only the affected chronological suffix.');
assertContains("id >= ?", $inventoryFunctions, 'Ledger replay must lock only transactions at or after the earliest changed movement.');
assertTrue(
    strpos($inventoryFunctions, "SELECT * FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE") < strpos($inventoryFunctions, "transaction_type = 'used' FOR UPDATE"),
    'Daily feed synchronization must lock stock items before generated movements.'
);
assertContains("error_log('Inventory database operation failed: '", $inventory, 'Inventory database failures must be logged server-side.');
assertContains("\$e instanceof PDOException", $inventory, 'Inventory database failures must be replaced with a safe user-facing message.');
foreach (['poultry/layer_feeds.php', 'poultry/broiler_feeds.php', 'ruminant/ruminant_feeds_record.php'] as $feedLedgerPage) {
    $feedLedger = readFileOrFail($root . '/' . $feedLedgerPage);
    assertContains('SELECT * FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE', $feedLedger, "{$feedLedgerPage} must lock stock before adding a movement.");
    assertContains('stockTransactionHasDailyFeedLinks(', $feedLedger, "{$feedLedgerPage} must protect daily-record-managed movements.");
    assertTrue(
        strpos($feedLedger, 'SELECT * FROM stock_items WHERE id = ? AND farm_id = ? FOR UPDATE') < strpos($feedLedger, 'stock_item_id = ? AND farm_type'),
        "{$feedLedgerPage} must lock stock before locking an editable ledger movement."
    );
}
assertContains("WHERE id = ? AND farm_id = ?", readFileOrFail($root . '/ruminant/ruminant_daily_record.php'), 'Ruminant edits must update only the daily record that was locked.');
$deleteCategory = readFileOrFail($root . '/inventory/delete_category.php');
assertTrue(
    strpos($deleteCategory, 'catch (PDOException $e)') < strpos($deleteCategory, 'catch (Throwable $e)'),
    'Category deletion must sanitize PDO failures before handling safe validation errors.'
);
assertContains('foreach (allowedFeedCategories() as $category)', $inventory, 'The feed type dropdown must show only subscribed feed classifications.');
assertContains('in_array($feedCategory, allowedFeedCategories(), true)', $inventory, 'Inventory must reject feed classifications outside the farm subscription.');

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
assertContains('inventoryItemHasDailyFeedLinks(', readFileOrFail($root . '/inventory/delete_category.php'), 'The standalone category deletion route must preserve daily feed links.');

$baseSchema = readFileOrFail($root . '/database_schema.sql');
foreach (['layer', 'broiler', 'ruminant'] as $recordType) {
    assertContains("fk_{$recordType}_feed_item", $baseSchema, "The base schema must constrain {$recordType} feed item links.");
    assertContains("fk_{$recordType}_feed_transaction", $baseSchema, "The base schema must constrain {$recordType} feed transaction links.");
}
$feedLinkMigration = readFileOrFail($root . '/migrations/012_daily_feed_inventory_links.sql');
assertTrue(substr_count($feedLinkMigration, 'ALTER TABLE') >= 15, 'Feed-link migration operations must be split so duplicate columns do not skip indexes or constraints.');

$productionCycles = readFileOrFail($root . '/management/production_cycles.php');
assertContains('water_consumption_liters, other_details', $productionCycles, 'Ruminant cycle seed must include water consumption.');
assertContains('VALUES (?, ?, ?, ?, ?, 0, 0, 0, NULL', $productionCycles, 'Ruminant cycle seed must provide zero rather than NULL for required water consumption.');
assertContains('$pdo->beginTransaction()', $productionCycles, 'Cycle creation and its opening record must be atomic.');
assertContains('verify_csrf_token', $productionCycles, 'Production-cycle mutations must enforce CSRF.');
assertContains('foreach (allowedFarmTypes(false) as $type)', $productionCycles, 'The cycle form Farm Type dropdown must follow the active farm subscription for platform owners and farm admins.');

// Platform owners work inside an active farm workspace, so their report filters
// must offer that farm's enabled modules rather than exposing unrelated types.
foreach ([
    'management/sales_records.php',
    'management/expenses.php',
    'management/poultry_ruminant_report.php',
    'management/reports.php',
] as $relativePath) {
    $reportPage = readFileOrFail($root . '/' . $relativePath);
    assertContains('$canChooseFarmType = isPlatformOwner()', $reportPage, "{$relativePath} must let platform owners choose the report farm type.");
    assertContains('count(accessibleFarmTypes()) === 2', $reportPage, "{$relativePath} must offer All Farms for dual access, including platform owners.");
    assertContains('foreach (accessibleFarmTypes() as $type)', $reportPage, "{$relativePath} must list every livestock type accessible to the current user.");
}

$dashboard = readFileOrFail($root . '/dashboard.php');
assertContains('$farmAccess = getUserFarmType();', $dashboard, 'Dashboard module visibility must use the shared farm-access resolver for every role.');
assertContains("\$farmAccess === 'both' && count(enabledFarmTypes()) === 2", $dashboard, 'Combined dashboards must include neutral sales whenever both livestock modules are enabled.');
assertNotContains("count(enabledFarmTypes()) === 2 && farmHasModule('sales')", $dashboard, 'Combined dashboard neutral sales must not depend on the current Sales entitlement.');
assertContains("if (in_array(\$farmAccess, ['poultry', 'ruminant', 'both'], true))", $dashboard, 'Active-cycle ticker must be available to all entitled dashboard roles.');
assertContains("? \"(s.farm_type = ? OR s.farm_type = 'general')\"", $dashboard, 'Single-module dashboards must show neutral sales in recent sales.');
assertContains("? \" AND (farm_type = ? OR farm_type = 'general')\"", $dashboard, 'Single-module dashboard profit fallback must include neutral sales.');
assertContains("} elseif (\$includeGeneralSales) {", $dashboard, 'Single-module dashboard summaries must add neutral sales to existing summary rows.');
assertContains("? \"(farm_type = ? OR farm_type = 'general')\"", $dashboard, 'Single-module dashboard activity must include neutral sales.');

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
assertContains('function ensureTenantRoles', $farmsPage, 'Farm provisioning must repair missing canonical tenant roles.');
assertContains('function validSubscriptionDate', $farmsPage, 'Farm provisioning must validate real subscription dates.');
assertContains('$endDate < $startDate', $farmsPage, 'Farm provisioning must reject an end date before its start date.');

$roleRepairMigration = readFileOrFail($root . '/migrations/011_repair_access_roles.sql');
assertContains("('farm_admin', 'Admin / Farm Owner', 0)", $roleRepairMigration, 'Role repair must restore farm_admin.');
assertContains("u.user_type IN ('platform_owner', 'platform_admin')", $roleRepairMigration, 'Role repair must preserve legacy platform owners.');
assertContains("u.user_type IN ('farm_admin', 'owner', 'admin')", $roleRepairMigration, 'Role repair must preserve legacy farm owners.');
assertContains("r.code IN ('platform_owner', 'platform_admin')", $login, 'Platform login must accept legacy owner roles.');
assertNotContains('INSERT IGNORE INTO user_roles', $login, 'Signing in must not modify authorization tables; migrations own role repair.');
assertContains("u.user_type IN ('platform_owner', 'platform_admin') OR r.code IN ('platform_owner', 'platform_admin')", $config, 'Session validation must keep legacy platform owners signed in until migration repair runs.');

echo "Contract checks passed.\n";
