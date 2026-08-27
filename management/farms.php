<?php
require_once dirname(__DIR__) . '/init.php';
requireLogin();
requirePlatformOwner();

const PLATFORM_WORKSPACE_SLUG = 'owner';
const FARM_OWNER_MIN_PASSWORD_LENGTH = 8;

$roleLabels = ['farm_admin' => 'Admin / Farm Owner', 'poultry_manager' => 'Poultry Manager', 'ruminant_manager' => 'Ruminant Manager', 'sales_rep' => 'Sales Representative', 'viewer' => 'Viewer'];
$moduleRoles = ['poultry_manager' => 'poultry', 'ruminant_manager' => 'ruminant', 'sales_rep' => 'sales'];

function redirectFarms(): void { header('Location: ' . BASE_URL . '/management/farms.php'); exit(); }
function validFarmId($value): int { return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0; }
function validSubscriptionDate(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function editableFarm(PDO $pdo, int $farmId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM farms WHERE id = ? AND slug <> ? LIMIT 1");
    $stmt->execute([$farmId, PLATFORM_WORKSPACE_SLUG]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function detectFarmLogoExtension(?array $file): ?string {
    if (empty($file['tmp_name'])) return null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 2 * 1024 * 1024) throw new RuntimeException('Logo upload must be an image smaller than 2 MB.');

    $mime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : null;
    $mimeExtensions = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $imageInfo = @getimagesize($file['tmp_name']);
    $imageType = $imageInfo[2] ?? null;
    $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $extension = $extensions[$imageType] ?? ($mimeExtensions[$mime] ?? null);
    if ($extension === null) throw new RuntimeException('Logo must contain valid JPG, PNG, or WebP image data. Try re-exporting the image as JPG before uploading.');
    return $extension;
}
function saveFarmLogoUpload(?array $file, int $farmId, ?string $existing = null, ?string $validatedExtension = null): ?string {
    if (empty($file['tmp_name'])) return $existing;
    $extension = $validatedExtension ?? detectFarmLogoExtension($file);

    $directory = dirname(__DIR__) . '/uploads/farms';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Unable to create the logo directory.');
    $filename = $farmId . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) throw new RuntimeException('Unable to save the logo.');
    return '/uploads/farms/' . $filename;
}
function selectedRoles(array $input): array {
    global $roleLabels;
    return array_values(array_unique(array_intersect($input, array_keys($roleLabels))));
}
function ensureTenantRoles(PDO $pdo): void {
    global $roleLabels;
    $stmt = $pdo->prepare('INSERT INTO roles (code, name, is_platform_role) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE name = VALUES(name), is_platform_role = 0');
    foreach ($roleLabels as $code => $name) $stmt->execute([$code, $name]);
}
function saveModulesAndRoles(PDO $pdo, int $farmId, int $ownerId, array $roles): void {
    global $moduleRoles;
    $modules = selectedModulesFromRoles($roles);
    $pdo->prepare('DELETE FROM farm_modules WHERE farm_id = ?')->execute([$farmId]);
    $moduleStmt = $pdo->prepare('INSERT INTO farm_modules (farm_id, module_code, is_enabled) VALUES (?, ?, 1)');
    foreach ($modules as $module) $moduleStmt->execute([$farmId, $module]);
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$ownerId]);
    $roleStmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?');
    foreach ($roles as $role) {
        $roleStmt->execute([$ownerId, $role]);
        if ($roleStmt->rowCount() !== 1) throw new RuntimeException("Required role '{$role}' is missing from the roles table.");
    }
}
function selectedModulesFromRoles(array $roles): array {
    global $moduleRoles;
    return array_values(array_unique(array_intersect_key($moduleRoles, array_flip($roles))));
}
function findFarmAdminId(PDO $pdo, int $farmId): int {
    $stmt = $pdo->prepare("SELECT u.id FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE u.farm_id = ? AND (r.code = 'farm_admin' OR u.user_type = 'farm_admin') ORDER BY (r.code = 'farm_admin') DESC, u.id LIMIT 1");
    $stmt->execute([$farmId]);
    return (int)$stmt->fetchColumn();
}
function deleteFarmData(PDO $pdo, int $farmId): void {
    // Delete dependent rows explicitly: old installations use RESTRICT foreign keys, so a farm DELETE alone is not sufficient.
    $pdo->prepare('DELETE ur FROM user_roles ur INNER JOIN users u ON u.id = ur.user_id WHERE u.farm_id = ?')->execute([$farmId]);
    foreach (['customer_ledger_entries', 'stock_transactions', 'stock_batches', 'layer_daily_records', 'broiler_daily_records', 'ruminant_daily_records', 'farm_expenses', 'profit_loss_summary', 'sales_records', 'production_cycles', 'stock_items', 'inventory_categories', 'farm_modules', 'subscriptions', 'users'] as $table) {
        $pdo->prepare("DELETE FROM {$table} WHERE farm_id = ?")->execute([$farmId]);
    }
    $pdo->prepare('DELETE FROM farms WHERE id = ?')->execute([$farmId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('Invalid request token.'); }
    $farmId = validFarmId($_POST['farm_id'] ?? null);
    if (isset($_POST['suspend_farm'], $_POST['farm_id']) || isset($_POST['reactivate_farm'], $_POST['farm_id'])) {
        if (!editableFarm($pdo, $farmId)) { $_SESSION['error'] = 'That farm account cannot be changed.'; redirectFarms(); }
        $status = isset($_POST['suspend_farm']) ? 'suspended' : 'active';
        $pdo->prepare('UPDATE farms SET subscription_status = ? WHERE id = ?')->execute([$status, $farmId]);
        $_SESSION['success'] = $status === 'suspended' ? 'Farm account suspended. All farm users are blocked from signing in.' : 'Farm account reactivated.';
        redirectFarms();
    }
    if (isset($_POST['delete_farm'], $_POST['farm_id'])) {
        $farm = editableFarm($pdo, $farmId);
        if (!$farm) { $_SESSION['error'] = 'The platform workspace cannot be deleted.'; redirectFarms(); }
        $logoPath = $farm['logo_path'] ?? null;
        try { $pdo->beginTransaction(); deleteFarmData($pdo, $farmId); $pdo->commit();
            if ($logoPath && preg_match('#^/uploads/farms/[a-zA-Z0-9._-]+$#', $logoPath)) @unlink(dirname(__DIR__) . $logoPath);
            $_SESSION['success'] = 'Farm account and all data stored for it were permanently deleted.';
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $_SESSION['error'] = 'Unable to delete this farm and its data. No data was removed.'; }
        redirectFarms();
    }

    $name = trim($_POST['name'] ?? ''); $slug = strtolower(trim($_POST['slug'] ?? '')); $color = trim($_POST['primary_color'] ?? '#198754');
    $roles = selectedRoles($_POST['roles'] ?? []); $username = trim($_POST['owner_username'] ?? ''); $password = $_POST['owner_password'] ?? ''; $email = trim($_POST['owner_email'] ?? '');
    $startDate = trim($_POST['subscription_starts_at'] ?? ''); $endDate = trim($_POST['subscription_ends_at'] ?? '');
    $plan = $_POST['plan'] ?? 'starter'; $status = $_POST['status'] ?? 'trial';
    $repairOwnerNeeded = isset($_POST['update_farm']) && $farmId > 0 && findFarmAdminId($pdo, $farmId) === 0;
    if ($name === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || $slug === PLATFORM_WORKSPACE_SLUG || !preg_match('/^#[0-9a-fA-F]{6}$/', $color) || $username === '' || !in_array('farm_admin', $roles, true)) {
        $error = 'Enter farm details, select Admin / Farm Owner, and use a unique lowercase Farm Workspace ID.';
    } elseif (count(selectedModulesFromRoles($roles)) < 1) $error = 'Select at least one subscribed access module (Poultry, Ruminant, or Sales) so the farm dashboard can load active statistics.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid owner email address.';
    elseif (!in_array($plan, ['starter', 'growth', 'pro'], true) || !in_array($status, ['trial', 'active', 'past_due', 'suspended'], true)) $error = 'Select a valid subscription plan and status.';
    elseif (($startDate !== '' && !validSubscriptionDate($startDate)) || ($endDate !== '' && !validSubscriptionDate($endDate))) $error = 'Subscription dates must be valid dates.';
    elseif ($startDate !== '' && $endDate !== '' && $endDate < $startDate) $error = 'Subscription end date cannot be before its start date.';
    elseif (isset($_POST['create_farm']) && strlen($password) < FARM_OWNER_MIN_PASSWORD_LENGTH) $error = 'The owner password must be at least ' . FARM_OWNER_MIN_PASSWORD_LENGTH . ' characters.';
    elseif ($repairOwnerNeeded && strlen($password) < FARM_OWNER_MIN_PASSWORD_LENGTH) $error = 'This incomplete farm has no admin account. Enter a password of at least ' . FARM_OWNER_MIN_PASSWORD_LENGTH . ' characters to create its admin account while saving.';
    elseif (isset($_POST['update_farm']) && $password !== '' && strlen($password) < FARM_OWNER_MIN_PASSWORD_LENGTH) $error = 'A replacement password must be at least ' . FARM_OWNER_MIN_PASSWORD_LENGTH . ' characters.';
    else try {
        $logoExtension = detectFarmLogoExtension($_FILES['logo'] ?? null);
        $createdFarmId = 0;
        $newLogoPath = null;
        $pdo->beginTransaction();
        ensureTenantRoles($pdo);
        if (isset($_POST['create_farm'])) {
            $stmt = $pdo->prepare('INSERT INTO farms (name, slug, primary_color, contact_name, contact_email, subscription_plan, subscription_status, subscription_starts_at, subscription_ends_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $slug, $color, trim($_POST['contact_name'] ?? ''), trim($_POST['contact_email'] ?? ''), $plan, $status, $startDate ? "$startDate 00:00:00" : null, $endDate ? "$endDate 23:59:59" : null]);
            $farmId = (int)$pdo->lastInsertId(); $createdFarmId = $farmId; $logoPath = saveFarmLogoUpload($_FILES['logo'] ?? null, $farmId, null, $logoExtension); $newLogoPath = $logoPath;
            if ($logoPath) $pdo->prepare('UPDATE farms SET logo_path = ? WHERE id = ?')->execute([$logoPath, $farmId]);
            $owner = $pdo->prepare('INSERT INTO users (farm_id, username, password, email, user_type, full_name) VALUES (?, ?, ?, ?, ?, ?)');
            $owner->execute([$farmId, $username, password_hash($password, PASSWORD_DEFAULT), $email, 'farm_admin', trim($_POST['owner_name'] ?? $username)]); $ownerId = (int)$pdo->lastInsertId();
            saveModulesAndRoles($pdo, $farmId, $ownerId, $roles); $message = "Created {$name}.";
        } elseif (isset($_POST['update_farm'])) {
            $farm = editableFarm($pdo, $farmId); if (!$farm) throw new RuntimeException('That farm cannot be edited.');
            $ownerId = findFarmAdminId($pdo, $farmId);
            if (!$ownerId) {
                $ownerStmt = $pdo->prepare('INSERT INTO users (farm_id, username, password, email, user_type, full_name) VALUES (?, ?, ?, ?, ?, ?)');
                $ownerStmt->execute([$farmId, $username, password_hash($password, PASSWORD_DEFAULT), $email, 'farm_admin', trim($_POST['owner_name'] ?? $username)]);
                $ownerId = (int)$pdo->lastInsertId();
            }
            $logoPath = saveFarmLogoUpload($_FILES['logo'] ?? null, $farmId, $farm['logo_path'], $logoExtension); $newLogoPath = ($logoPath !== ($farm['logo_path'] ?? null)) ? $logoPath : null;
            $pdo->prepare('UPDATE farms SET name = ?, slug = ?, primary_color = ?, contact_name = ?, contact_email = ?, subscription_plan = ?, subscription_status = ?, subscription_starts_at = ?, subscription_ends_at = ?, logo_path = ? WHERE id = ?')->execute([$name, $slug, $color, trim($_POST['contact_name'] ?? ''), trim($_POST['contact_email'] ?? ''), $plan, $status, $startDate ? "$startDate 00:00:00" : null, $endDate ? "$endDate 23:59:59" : null, $logoPath, $farmId]);
            $ownerSql = 'UPDATE users SET username = ?, email = ?, full_name = ?, user_type = ?' . ($password !== '' ? ', password = ?' : '') . ' WHERE id = ? AND farm_id = ?'; $params = [$username, $email, trim($_POST['owner_name'] ?? $username), 'farm_admin']; if ($password !== '') $params[] = password_hash($password, PASSWORD_DEFAULT); $params[] = $ownerId; $params[] = $farmId; $pdo->prepare($ownerSql)->execute($params);
            saveModulesAndRoles($pdo, $farmId, $ownerId, $roles); $message = "Updated {$name}.";
        } else throw new RuntimeException('Unknown farm action.');
        $pdo->commit(); $_SESSION['success'] = $message; redirectFarms();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!empty($createdFarmId) && editableFarm($pdo, $createdFarmId)) {
            try { deleteFarmData($pdo, $createdFarmId); } catch (Throwable $cleanupError) { error_log('Incomplete farm cleanup failed: ' . $cleanupError->getMessage()); }
        }
        if (!empty($newLogoPath) && preg_match('#^/uploads/farms/[a-zA-Z0-9._-]+$#', $newLogoPath)) @unlink(dirname(__DIR__) . $newLogoPath);
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to save this farm. Its workspace ID or owner username may already exist.';
    }
}

$editFarm = null; $editOwner = null; $editModules = [];
if (isset($_GET['edit'])) { $editFarm = editableFarm($pdo, validFarmId($_GET['edit'])); if ($editFarm) { $ownerId = findFarmAdminId($pdo, (int)$editFarm['id']); if ($ownerId) { $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND farm_id = ?'); $stmt->execute([$ownerId, $editFarm['id']]); $editOwner = $stmt->fetch(PDO::FETCH_ASSOC); } $moduleStmt = $pdo->prepare('SELECT module_code FROM farm_modules WHERE farm_id = ? AND is_enabled = 1'); $moduleStmt->execute([$editFarm['id']]); $editModules = $moduleStmt->fetchAll(PDO::FETCH_COLUMN); } }
$farms = $pdo->query("SELECT f.*, GROUP_CONCAT(CASE WHEN fm.is_enabled = 1 THEN fm.module_code END ORDER BY fm.module_code SEPARATOR ', ') AS modules, DATEDIFF(f.subscription_ends_at, CURDATE()) AS days_remaining FROM farms f LEFT JOIN farm_modules fm ON fm.farm_id = f.id WHERE f.slug <> 'owner' GROUP BY f.id ORDER BY f.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$form = $editFarm ?: ['name'=>'','slug'=>'','primary_color'=>'#198754','contact_name'=>'','contact_email'=>'','subscription_plan'=>'starter','subscription_status'=>'trial','subscription_starts_at'=>'','subscription_ends_at'=>''];
$owner = $editOwner ?: ['username'=>'','email'=>'','full_name'=>''];
$ownerNeedsRepair = $editFarm !== null && $editOwner === null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($error)) {
    $form = array_merge($form, [
        'name' => trim($_POST['name'] ?? ''),
        'slug' => strtolower(trim($_POST['slug'] ?? '')),
        'primary_color' => trim($_POST['primary_color'] ?? '#198754'),
        'contact_name' => trim($_POST['contact_name'] ?? ''),
        'contact_email' => trim($_POST['contact_email'] ?? ''),
        'subscription_plan' => $_POST['plan'] ?? 'starter',
        'subscription_status' => $_POST['status'] ?? 'trial',
        'subscription_starts_at' => trim($_POST['subscription_starts_at'] ?? ''),
        'subscription_ends_at' => trim($_POST['subscription_ends_at'] ?? ''),
    ]);
    $owner = array_merge($owner, [
        'username' => trim($_POST['owner_username'] ?? ''),
        'email' => trim($_POST['owner_email'] ?? ''),
        'full_name' => trim($_POST['owner_name'] ?? ''),
    ]);
}
?>
<!doctype html><html lang="en"><head><?php include dirname(__DIR__) . '/navbar_head.php'; ?><title>Platform farms</title></head><body><?php include dirname(__DIR__) . '/navbar.php'; ?>
<main class="container py-4"><div class="d-flex justify-content-between"><h1 class="h3">Platform farms</h1><span class="badge bg-dark">Owner / Developer</span></div>
<?php if (!empty($error)): ?><div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error); ?></div><?php endif; ?><?php if (!empty($_SESSION['error'])): ?><div class="alert alert-danger mt-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?><?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success mt-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
<?php if ($ownerNeedsRepair): ?><div class="alert alert-warning mt-3">This farm was only partially created and has no admin account. Complete the username, password, name, and subscribed access below; saving will create and link its admin safely.</div><?php endif; ?>
<div class="card my-3"><div class="card-body"><h2 class="h5"><?php echo $editFarm ? 'Edit Farm' : 'Add New Farm'; ?></h2><form method="post" enctype="multipart/form-data" class="row g-3" id="farmAccountForm" novalidate><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>"><?php if ($editFarm): ?><input type="hidden" name="farm_id" value="<?php echo (int)$editFarm['id']; ?>"><?php endif; ?>
<div class="col-md-4"><label class="form-label">Username</label><input class="form-control" name="owner_username" value="<?php echo htmlspecialchars($owner['username']); ?>" required></div><div class="col-md-4"><label class="form-label">Password<?php echo $editFarm && !$ownerNeedsRepair ? ' (leave blank to keep)' : ''; ?></label><input class="form-control" type="password" name="owner_password" <?php echo !$editFarm || $ownerNeedsRepair ? 'minlength="' . FARM_OWNER_MIN_PASSWORD_LENGTH . '" required' : ''; ?>></div><div class="col-md-4"><label class="form-label">Email</label><input class="form-control" type="email" name="owner_email" value="<?php echo htmlspecialchars($owner['email']); ?>"></div>
<div class="col-md-6"><label class="form-label">Full Name</label><input class="form-control" name="owner_name" value="<?php echo htmlspecialchars($owner['full_name']); ?>" required></div><div class="col-md-6"><label class="form-label">Farm name</label><input class="form-control" name="name" value="<?php echo htmlspecialchars($form['name']); ?>" required></div><div class="col-md-6"><label class="form-label">Farm Workspace ID</label><input class="form-control" name="slug" value="<?php echo htmlspecialchars($form['slug']); ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" required></div><div class="col-md-6"><label class="form-label">Logo upload</label><input class="form-control" type="file" name="logo" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"><?php if ($editFarm && !empty($editFarm['logo_path'])): ?><div class="form-text">Current logo is saved and will be kept unless you choose a replacement.</div><img src="<?php echo BASE_URL . htmlspecialchars($editFarm['logo_path']); ?>" alt="Current farm logo" class="img-thumbnail mt-2" style="max-height:72px;"><?php endif; ?></div>
<div class="col-md-4"><label class="form-label">Primary colour</label><input class="form-control form-control-color" type="color" name="primary_color" value="<?php echo htmlspecialchars($form['primary_color']); ?>"></div><div class="col-md-4"><label class="form-label">Contact name</label><input class="form-control" name="contact_name" value="<?php echo htmlspecialchars($form['contact_name']); ?>"></div><div class="col-md-4"><label class="form-label">Contact email</label><input class="form-control" type="email" name="contact_email" value="<?php echo htmlspecialchars($form['contact_email']); ?>"></div>
<div class="col-md-12"><label class="form-label d-block">Roles / subscribed access</label><?php foreach ($roleLabels as $value => $label): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="roles[]" value="<?php echo $value; ?>" <?php echo isset($moduleRoles[$value]) ? 'data-module-role="1"' : ''; ?> <?php echo $value === 'farm_admin' || ($editFarm && isset($moduleRoles[$value]) && in_array($moduleRoles[$value], $editModules, true)) ? 'checked' : ''; ?>><label class="form-check-label"><?php echo $label; ?></label></div><?php endforeach; ?></div>
<div class="col-md-3"><label class="form-label">Plan</label><select class="form-select" name="plan"><?php foreach (['starter'=>'Starter','growth'=>'Growth','pro'=>'Pro'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo $form['subscription_plan']===$value?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (['trial'=>'Trial','active'=>'Active','past_due'=>'Past Due','suspended'=>'Suspended'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo $form['subscription_status']===$value?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Starts Date</label><input class="form-control" type="date" name="subscription_starts_at" value="<?php echo htmlspecialchars($form['subscription_starts_at'] ? date('Y-m-d', strtotime($form['subscription_starts_at'])) : ''); ?>"></div><div class="col-md-3"><label class="form-label">End Date</label><input class="form-control" type="date" name="subscription_ends_at" value="<?php echo htmlspecialchars($form['subscription_ends_at'] ? date('Y-m-d', strtotime($form['subscription_ends_at'])) : ''); ?>"></div>
<div class="col-12"><button name="<?php echo $editFarm ? 'update_farm' : 'create_farm'; ?>" class="btn btn-success"><?php echo $editFarm ? 'Save farm changes' : 'Create farm and admin'; ?></button><?php if ($editFarm): ?><a class="btn btn-outline-secondary ms-2" href="<?php echo BASE_URL; ?>/management/farms.php">Cancel</a><?php endif; ?></div></form></div></div>
<div class="card"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Farm</th><th>Farm Workspace ID</th><th>Modules</th><th>Plan</th><th>Status</th><th>Subscription Ends</th><th>Actions</th></tr></thead><tbody><?php foreach ($farms as $farm): ?><tr><td><?php echo htmlspecialchars($farm['name']); ?></td><td><?php echo htmlspecialchars($farm['slug']); ?></td><td><?php echo htmlspecialchars($farm['modules'] ?: 'None'); ?></td><td><?php echo htmlspecialchars($farm['subscription_plan']); ?></td><td><?php echo htmlspecialchars($farm['subscription_status']); ?></td><td><?php echo htmlspecialchars($farm['subscription_ends_at'] ? date('d M Y', strtotime($farm['subscription_ends_at'])) : 'Not set'); ?></td><td><a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int)$farm['id']; ?>">Edit</a> <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="farm_id" value="<?php echo (int)$farm['id']; ?>"><?php if ($farm['subscription_status'] === 'suspended'): ?><button name="reactivate_farm" class="btn btn-sm btn-outline-success">Reactivate</button><?php else: ?><button name="suspend_farm" class="btn btn-sm btn-outline-warning" onclick="return confirm('Suspend this farm and all users?')">Suspend</button><?php endif; ?><button name="delete_farm" class="btn btn-sm btn-outline-danger" onclick="return confirm('Permanently delete this farm, every user, and every stored record? This cannot be undone.')">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></main>
<script>
(function () {
    const form = document.getElementById('farmAccountForm');
    if (!form) return;

    const moduleCheckboxes = Array.from(form.querySelectorAll('[data-module-role="1"]'));
    const moduleMessage = 'Select at least one subscribed access module so dashboard statistics and active-cycle stock can load.';
    const logoInput = form.querySelector('input[name="logo"]');
    const allowedLogoTypes = ['image/jpeg', 'image/png', 'image/webp'];

    function validateModules() {
        const hasModule = moduleCheckboxes.some((checkbox) => checkbox.checked);
        moduleCheckboxes.forEach((checkbox) => checkbox.setCustomValidity(hasModule ? '' : moduleMessage));
        return hasModule;
    }

    function validateLogo() {
        if (!logoInput || !logoInput.files.length) return true;
        const file = logoInput.files[0];
        const validType = allowedLogoTypes.includes(file.type);
        const validName = /\.(jpe?g|png|webp)$/i.test(file.name || '');
        const validSize = file.size <= 2 * 1024 * 1024;
        const message = !validSize ? 'Logo upload must be smaller than 2 MB.' : 'Logo must be a JPG, PNG, or WebP image.';
        logoInput.setCustomValidity((validType || validName) && validSize ? '' : message);
        return logoInput.checkValidity();
    }

    moduleCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', validateModules));
    if (logoInput) logoInput.addEventListener('change', validateLogo);

    form.addEventListener('submit', function (event) {
        validateModules();
        validateLogo();
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    });
})();
</script>
</body></html>
