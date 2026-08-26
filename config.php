<?php
// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');

// Add to config.php
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));

// Determine the application's base URL so links work consistently from any directory
if (!defined('BASE_URL')) {
    $appRoot = str_replace('\\', '/', realpath(__DIR__));
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
    $relativePath = '/' . trim(str_replace($docRoot, '', $appRoot), '/');

    // If the application lives in the web root, keep the base empty; otherwise include the folder name
    define('BASE_URL', $relativePath === '/' ? '' : $relativePath);
}

// Start session and enforce inactivity timeout
define('SESSION_TIMEOUT', 900); // 15 minutes
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
    'path' => BASE_URL ?: '/',
]);
session_start();

// If a user is logged in, expire the session after prolonged inactivity
if (isset($_SESSION['user_id']) && isset($_SESSION['LAST_ACTIVITY'])) {
    if (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php?timeout=1');
        exit();
    }
}

// Track the timestamp of the current request
if (isset($_SESSION['user_id'])) {
    $_SESSION['LAST_ACTIVITY'] = time();
}

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check user type
function getUserType() {
    return $_SESSION['user_type'] ?? null;
}

function getCurrentFarmId(): int {
    return (int) ($_SESSION['farm_id'] ?? 0);
}

function requireCurrentFarmId(): int {
    $farmId = getCurrentFarmId();
    if ($farmId < 1) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/sign.php');
        exit();
    }
    return $farmId;
}

function currentFarm(): ?array {
    static $farm = false;
    if ($farm !== false) return $farm;
    $farmId = getCurrentFarmId();
    if ($farmId < 1) return $farm = null;
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, name, slug, logo_path, primary_color, contact_name, contact_email, subscription_plan, subscription_status, trial_ends_at, subscription_ends_at FROM farms WHERE id = ? LIMIT 1');
    $stmt->execute([$farmId]);
    return $farm = ($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
}

function farmBrandName(): string {
    return currentFarm()['name'] ?? 'Farm Operations';
}

function farmLogoUrl(): string {
    $logoPath = currentFarm()['logo_path'] ?? '';
    if ($logoPath !== '' && preg_match('#^/uploads/farms/[a-zA-Z0-9._/-]+$#', $logoPath)) return BASE_URL . $logoPath;
    return BASE_URL . '/assets/images/logo.jpg';
}

function currentUserRoles(): array {
    static $roles = null;
    if ($roles !== null) return $roles;
    if (!isset($_SESSION['user_id']) || getCurrentFarmId() < 1) return $roles = [];
    global $pdo;
    $stmt = $pdo->prepare('SELECT r.code FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE ur.user_id = ? AND u.farm_id = ?');
    $stmt->execute([$_SESSION['user_id'], getCurrentFarmId()]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    // Supports existing users until the role backfill migration has run.
    if (!$roles && !empty($_SESSION['user_type'])) $roles = [$_SESSION['user_type']];
    return $roles;
}

function hasRole(string ...$requiredRoles): bool {
    return (bool) array_intersect(currentUserRoles(), $requiredRoles);
}

function isPlatformOwner(): bool {
    return hasRole('platform_owner');
}

function isPlatformAdmin(): bool {
    return isPlatformOwner(); // Compatibility alias for existing integrations.
}

function farmHasModule(string $module): bool {
    static $modules = null;
    if ($modules === null) {
        $modules = [];
        if (getCurrentFarmId() > 0) {
            global $pdo;
            $stmt = $pdo->prepare('SELECT module_code FROM farm_modules WHERE farm_id = ? AND is_enabled = 1');
            $stmt->execute([getCurrentFarmId()]);
            $modules = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    }
    return in_array($module, $modules, true);
}

function requirePlatformOwner(): void {
    if (!isPlatformOwner()) { http_response_code(403); exit('Owner / Developer access required.'); }
}

function requirePlatformAdmin(): void {
    requirePlatformOwner(); // Compatibility alias.
}

function validTenantRoleCodes(array $roles): array {
    $allowed = isPlatformOwner() ? ['farm_admin', 'poultry_manager', 'ruminant_manager', 'sales_rep', 'viewer'] : ['poultry_manager', 'ruminant_manager', 'sales_rep', 'viewer'];
    $roles = array_values(array_unique(array_intersect($roles, $allowed)));
    if (in_array('poultry_manager', $roles, true) && !farmHasModule('poultry')) $roles = array_diff($roles, ['poultry_manager']);
    if (in_array('ruminant_manager', $roles, true) && !farmHasModule('ruminant')) $roles = array_diff($roles, ['ruminant_manager']);
    if (in_array('sales_rep', $roles, true) && !farmHasModule('sales')) $roles = array_diff($roles, ['sales_rep']);
    return array_values($roles);
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit();
    }

    // Ensure the user still exists (e.g., was not deleted by an owner)
    global $pdo;
    if ($pdo instanceof PDO && isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT u.id, f.subscription_status,
                                      MAX(CASE WHEN r.code = 'platform_owner' THEN 1 ELSE 0 END) AS is_platform_owner
                               FROM users u
                               INNER JOIN farms f ON f.id = u.farm_id
                               LEFT JOIN user_roles ur ON ur.user_id = u.id
                               LEFT JOIN roles r ON r.id = ur.role_id
                               WHERE u.id = ? AND u.farm_id = ?
                               GROUP BY u.id, f.subscription_status
                               LIMIT 1");
        $stmt->execute([$_SESSION['user_id'], getCurrentFarmId()]);

        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        $isPlatformOwnerAccount = $account !== false && (int) ($account['is_platform_owner'] ?? 0) === 1;
        if ($account === false || (!$isPlatformOwnerAccount && in_array($account['subscription_status'], ['suspended', 'cancelled'], true))) {
            session_unset();
            session_destroy();
            header('Location: ' . BASE_URL . '/login.php');
            exit();
        }
    }
}

// Check access permissions
/**
 * Viewer accounts are read-only members of their own farm workspace.  Reports
 * deliberately remain available without a sales-module entitlement so every
 * subscriber can review sales and expense performance; the entitlement only
 * controls whether a separate Sales Representative login can be created.
 */
function canViewBusinessReports(): bool {
    return isPlatformOwner() || hasRole('farm_admin', 'poultry_manager', 'ruminant_manager', 'sales_rep', 'viewer');
}

function requireBusinessReportAccess(): void {
    if (!canViewBusinessReports()) {
        http_response_code(403);
        exit('Report access required.');
    }
}

function checkAccess($requiredType) {
    if (isPlatformOwner()) return true;
    if ($requiredType === 'poultry') return farmHasModule('poultry') && hasRole('farm_admin', 'poultry_manager');
    if ($requiredType === 'ruminant') return farmHasModule('ruminant') && hasRole('farm_admin', 'ruminant_manager');
    if ($requiredType === 'sales') return farmHasModule('sales') && hasRole('farm_admin', 'sales_rep');
    return false;
}

// Get farm type for current user
function getUserFarmType() {
    if (isPlatformOwner()) return 'all';
    if (hasRole('farm_admin')) {
        if (farmHasModule('poultry') && farmHasModule('ruminant')) return 'all';
        if (farmHasModule('poultry')) return 'poultry';
        if (farmHasModule('ruminant')) return 'ruminant';
        return 'both';
    }
    if (hasRole('poultry_manager') && !hasRole('ruminant_manager')) return 'poultry';
    if (hasRole('ruminant_manager') && !hasRole('poultry_manager')) return 'ruminant';
    return 'both';
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>
