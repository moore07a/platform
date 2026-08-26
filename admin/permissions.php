<?php require_once(__DIR__ . '/../init.php'); ?>
<?php
// permissions.php - Owner UI to manage module permissions
// init.php loads config.php and starts session

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

ensurePermissionsTable($pdo);

// ensure owner
if (!isPlatformOwner() && !hasRole('farm_admin')) {
    header('Location: /no_access.php');
    exit();
}

$moduleGroups = [
    'Poultry' => [
        'poultry_overview' => 'Poultry dashboard and overall summary',
        'poultry_daily_layer' => 'Daily records for layer birds',
        'poultry_daily_broiler' => 'Daily records for broiler birds',
        'poultry_feeds' => 'Feed tracking and feed usage',
        'poultry_health' => 'Health monitoring and treatment records',
        'poultry_expenses' => 'Poultry-specific expenses'
    ],
    'Ruminant' => [
        'ruminant_overview' => 'Ruminant dashboard and overall summary',
        'ruminant_daily' => 'Daily records for ruminants',
        'ruminant_feeds' => 'Ruminant feed records and usage',
        'ruminant_expenses' => 'Ruminant-specific expenses'
    ],
    'Inventory & Operations' => [
        'inventory' => 'Inventory listing and stock movement',
        'inventory_add_new_item' => 'Add new item to inventory',
        'update_stock' => 'Update stock quantities and adjustments',
        'sales' => 'Sales entry and sales records',
        'expenses' => 'General expense management'
    ],
    'Administration' => [
        'reports' => 'View and generate reports',
        'users' => 'User account management',
        'management' => 'Management dashboards and controls',
        'settings' => 'System and site settings',
        'permissions' => 'Permission matrix management'
    ]
];

$modules = [];
foreach ($moduleGroups as $groupModules) {
    $modules = array_merge($modules, array_keys($groupModules));
}

$roles = isPlatformOwner() ? ['poultry_manager', 'ruminant_manager', 'sales_rep'] : validTenantRoleCodes(['poultry_manager', 'ruminant_manager', 'sales_rep']);
$roleLabels = [
    'poultry_manager' => 'Poultry Manager',
    'ruminant_manager' => 'Ruminant Manager',
    'sales_rep' => 'Sales Representative'
];

$rolePlaceholders = rtrim(str_repeat('?,', count($roles)), ',');

// load existing permissions
$permissions = [];
if ($roles) {
    $stmt = $pdo->prepare("SELECT role,module,allowed FROM permissions WHERE role IN ($rolePlaceholders)");
    $stmt->execute($roles);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $permissions[$row['role']][$row['module']] = $row['allowed'];
    }
}

$errorDetail = $_SESSION['permission_error_detail'] ?? null;
unset($_SESSION['permission_error_detail']);
?>
<!doctype html>
<html>
<head>
  <?php include __DIR__ . '/../navbar_head.php'; ?>
  <title>Permissions - Renee Farms</title>
  <style>
    .permissions-shell {
      max-width: 1200px;
      margin: 0 auto;
    }

    .permissions-card {
      border: 1px solid #e9ecef;
      border-radius: 14px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
      overflow: hidden;
    }

    .permissions-card .card-header {
      background: linear-gradient(120deg, #198754 0%, #157347 100%);
      color: #fff;
      border: 0;
      padding: 1rem 1.25rem;
    }

    .permissions-intro {
      color: #5c6770;
      margin-bottom: 0;
      font-size: 0.95rem;
    }

    .permission-group-title {
      background: #f8faf9;
      color: #1f5136;
      font-weight: 600;
      font-size: 0.9rem;
      letter-spacing: .02em;
      text-transform: uppercase;
    }

    .permission-module {
      min-width: 260px;
    }

    .permission-module strong {
      display: block;
      color: #1f2937;
      margin-bottom: 0.15rem;
      font-size: 0.95rem;
    }

    .permission-module small {
      color: #6b7280;
      line-height: 1.35;
      display: inline-block;
    }

    .permission-check {
      transform: scale(1.2);
      cursor: pointer;
    }

    .sticky-actions {
      position: sticky;
      bottom: 0;
      background: #fff;
      border-top: 1px solid #e9ecef;
      padding: 1rem 1.25rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: .75rem;
    }

    @media (max-width: 768px) {
      .permission-module {
        min-width: 220px;
      }

      .sticky-actions {
        flex-direction: column;
        align-items: stretch;
      }

      .sticky-actions button {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../navbar.php'; ?>
  <div class="container py-4 permissions-shell">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
      <div>
        <h3 class="mb-1">Module Permissions</h3>
        <p class="permissions-intro">Control which role can access each module across Poultry, Ruminant, Inventory, and Administration.</p>
      </div>
      <span class="badge text-bg-light border"><?php echo isPlatformOwner() ? 'Owner Access' : 'Farm Admin Scoped Access'; ?></span>
    </div>

    <?php if (isset($_GET['updated'])): ?>
      <div class="alert alert-success">Permissions updated successfully.</div>
    <?php elseif (isset($_GET['error'])): ?>
      <div class="alert alert-danger">Unable to save permissions. Please try again or check the error log.</div>
      <?php if ($errorDetail): ?>
        <div class="alert alert-warning"><strong>Details:</strong> <?= htmlspecialchars($errorDetail) ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="permissions_save.php">
      <div class="card permissions-card">
        <div class="card-header">
          <h5 class="mb-1">Role Access Matrix</h5>
          <small>Check the boxes to grant access. Unchecked means blocked.</small>
        </div>

        <div class="table-responsive">
          <table class="table table-hover table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="permission-module">Module</th>
                <?php foreach ($roles as $role): ?>
                  <th class="text-center"><?= htmlspecialchars($roleLabels[$role] ?? $role) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($moduleGroups as $groupName => $groupModules): ?>
                <tr class="permission-group-title">
                  <td colspan="<?= count($roles) + 1 ?>"><?= htmlspecialchars($groupName) ?></td>
                </tr>
                <?php foreach ($groupModules as $module => $description): ?>
                  <tr>
                    <td class="permission-module">
                      <strong><?= htmlspecialchars($module) ?></strong>
                      <small><?= htmlspecialchars($description) ?></small>
                    </td>
                    <?php foreach ($roles as $role):
                      $checked = (!empty($permissions[$role][$module]) && (int)$permissions[$role][$module] === 1) ? 'checked' : '';
                    ?>
                      <td class="text-center">
                        <input
                          class="form-check-input permission-check"
                          type="checkbox"
                          name="perm[<?= htmlspecialchars($role) ?>][<?= htmlspecialchars($module) ?>]"
                          value="1"
                          <?= $checked ?>
                          aria-label="<?= htmlspecialchars($role) ?> access to <?= htmlspecialchars($module) ?>"
                        />
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="sticky-actions">
          <small class="text-muted">Tip: Review Administration modules before saving to avoid locking out managers from essential tools.</small>
          <button class="btn btn-success" type="submit">
            <i class="bi bi-check2-circle me-1"></i> Save Permissions
          </button>
        </div>
      </div>
    </form>
  </div>
</body>
</html>
