<?php require_once(dirname(__DIR__) . '/init.php'); ?>
<?php
require_once(__DIR__ . '/../config.php');
requireLogin();

// Only owner can access user management
if (!hasRole('farm_admin')) {
    header('Location: dashboard.php');
    exit();
}
$farmId = requireCurrentFarmId();
$availableRoles = ['poultry_manager' => 'Poultry Manager', 'ruminant_manager' => 'Ruminant Manager', 'sales_rep' => 'Sales Representative', 'viewer' => 'Viewer'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(419); exit('Invalid request token.'); }
    if (isset($_POST['add_user'])) {
        $roles = validTenantRoleCodes($_POST['roles'] ?? []);
        if (!$roles) { $_SESSION['error'] = 'Select at least one role enabled by the platform owner.'; header('Location: users.php'); exit(); }
        $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (farm_id, username, password, user_type, full_name)
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $farmId,
            $_POST['username'],
            $hashedPassword,
            $roles[0],
            $_POST['full_name']
        ]);
        $userId = (int) $pdo->lastInsertId();
        $roleStmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?');
        foreach ($roles as $role) $roleStmt->execute([$userId, $role]);
        
        $_SESSION['success'] = "User added successfully!";
        header("Location: users.php");
        exit();
    }
    
    if (isset($_POST['delete_user'])) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND id != ? AND farm_id = ?");
        $stmt->execute([$_POST['user_id'], $_SESSION['user_id'], $farmId]);

        $_SESSION['success'] = "User deleted successfully!";
        header("Location: users.php");
        exit();
    }

    if (isset($_POST['edit_user'])) {
        $roles = validTenantRoleCodes($_POST['roles'] ?? []);
        if (!$roles) { $_SESSION['error'] = 'Select at least one role enabled by the platform owner.'; header('Location: users.php'); exit(); }
        $updateQuery = "UPDATE users SET username = ?, user_type = ?, full_name = ?";
        $params = [
            $_POST['username'],
            $roles[0],
            $_POST['full_name']
        ];

        if (!empty($_POST['password'])) {
            $updateQuery .= ", password = ?";
            $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }

        $updateQuery .= " WHERE id = ? AND farm_id = ?";
        $params[] = $_POST['user_id'];
        $params[] = $farmId;

        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute($params);
        $pdo->prepare('DELETE ur FROM user_roles ur INNER JOIN users u ON u.id = ur.user_id WHERE ur.user_id = ? AND u.farm_id = ?')->execute([$_POST['user_id'], $farmId]);
        $roleStmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?');
        foreach ($roles as $role) $roleStmt->execute([$_POST['user_id'], $role]);

        $_SESSION['success'] = "User updated successfully!";
        header("Location: users.php");
        exit();
    }
}

// Get all users
$usersStmt = $pdo->prepare("SELECT u.*, GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ',') AS role_codes FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE u.farm_id = ? GROUP BY u.id ORDER BY u.username");
$usersStmt->execute([$farmId]);
$users = $usersStmt->fetchAll();

$roleCounts = [
    'farm_admin' => 0,
    'poultry_manager' => 0,
    'ruminant_manager' => 0,
    'sales_rep' => 0
];

foreach ($users as $existingUser) {
    $role = $existingUser['user_type'] ?? '';
    if (isset($roleCounts[$role])) {
        $roleCounts[$role]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include(__DIR__ . '/../navbar_head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Renee Farms</title>
    <style>
        .user-management-shell {
            background: radial-gradient(circle at top right, rgba(25, 135, 84, 0.09), transparent 45%),
                        radial-gradient(circle at top left, rgba(13, 110, 253, 0.1), transparent 40%);
            border-radius: 1.5rem;
            padding: 1.5rem;
            border: 1px solid rgba(13, 110, 253, 0.08);
        }

        .hero-card {
            border: 0;
            border-radius: 1rem;
            background: linear-gradient(120deg, #0d6efd, #198754);
            color: #fff;
            box-shadow: 0 1rem 2rem rgba(13, 110, 253, 0.25);
        }

        .metric-card {
            border: 0;
            border-radius: .9rem;
            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .06);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 .8rem 1.3rem rgba(0, 0, 0, .1);
        }

        .users-table-wrap {
            border-radius: 1rem;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }

        .table thead th {
            border-bottom: 0;
            text-transform: uppercase;
            font-size: .75rem;
            letter-spacing: .04em;
        }

        .table tbody td {
            vertical-align: middle;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #0d6efd;
            background-color: rgba(13, 110, 253, .12);
        }
    </style>
</head>
<body>
    <?php include(__DIR__ . '/../navbar.php'); ?>

    <div class="container-fluid mt-4 mb-4">
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <div class="user-management-shell">
            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <div class="card hero-card h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div>
                                    <p class="text-uppercase small fw-semibold mb-2">Control Center</p>
                                    <h2 class="h3 mb-2"><i class="bi bi-people-fill me-2"></i>User Management</h2>
                                    <p class="mb-0 opacity-75">Manage staff access within the modules enabled by the platform owner for your subscription.</p>
                                </div>
                                <button class="btn btn-light text-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                    <i class="bi bi-person-plus-fill me-1"></i> Add User
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card metric-card h-100">
                        <div class="card-body">
                            <p class="text-muted mb-1">Total Team Members</p>
                            <h2 class="fw-bold mb-3"><?php echo count($users); ?></h2>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge bg-success-subtle text-success">Poultry: <?php echo $roleCounts['poultry_manager']; ?></span>
                                <span class="badge bg-warning-subtle text-dark">Ruminant: <?php echo $roleCounts['ruminant_manager']; ?></span>
                                <span class="badge bg-info-subtle text-info-emphasis">Sales: <?php echo $roleCounts['sales_rep']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="users-table-wrap table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="user-avatar">
                                                <?php echo strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1)); ?>
                                            </span>
                                            <div>
                                                <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                                <div class="text-muted small"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                            </div>
                                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                            <span class="badge bg-primary-subtle text-primary">You</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                            $badgeClasses = [
                                                'farm_admin' => 'danger',
                                                'poultry_manager' => 'success',
                                                'ruminant_manager' => 'warning',
                                                'sales_rep' => 'info'
                                            ];
                                            $badgeClass = $badgeClasses[$user['user_type']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($user['user_type']))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(date('d M Y', strtotime($user['created_at']))); ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary edit-user-btn"
                                                    data-user-id="<?php echo $user['id']; ?>"
                                                    data-username="<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>"
                                                    data-full-name="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>"
                                                    data-user-type="<?php echo $user['user_type']; ?>"
                                                    data-roles="<?php echo htmlspecialchars($user['role_codes'] ?? $user['user_type'], ENT_QUOTES); ?>">
                                                <i class="bi bi-pencil-square me-1"></i>Edit
                                            </button>

                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="delete_user"
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Delete this user?')">
                                                    <i class="bi bi-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Full Name</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="d-block">Roles</label>
                            <?php foreach ($availableRoles as $code => $label): $disabled = ($code === 'poultry_manager' && !farmHasModule('poultry')) || ($code === 'ruminant_manager' && !farmHasModule('ruminant')) || ($code === 'sales_rep' && !farmHasModule('sales')); ?>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="<?php echo $code; ?>" id="add-<?php echo $code; ?>" <?php echo $disabled ? 'disabled' : ''; ?>><label class="form-check-label" for="add-<?php echo $code; ?>"><?php echo $label; ?></label></div><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="user_id">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Full Name</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="d-block">Roles</label>
                            <?php foreach ($availableRoles as $code => $label): $disabled = ($code === 'poultry_manager' && !farmHasModule('poultry')) || ($code === 'ruminant_manager' && !farmHasModule('ruminant')) || ($code === 'sales_rep' && !farmHasModule('sales')); ?>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="<?php echo $code; ?>" id="edit-<?php echo $code; ?>" <?php echo $disabled ? 'disabled' : ''; ?>><label class="form-check-label" for="edit-<?php echo $code; ?>"><?php echo $label; ?></label></div><?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <label>New Password (leave blank to keep current)</label>
                            <input type="password" name="password" class="form-control" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_user" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/jquery/jquery.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/jquery.dataTables.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables/js/dataTables.bootstrap5.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/datatables-responsive/js/dataTables.responsive.min.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/js/main.js'); ?>"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/edit-modal.js"></script>


    <script>
    attachEditModal({
        buttonSelector: '.edit-user-btn',
        modalSelector: '#editUserModal',
        fieldMap: {
            userId: 'input[name="user_id"]',
            username: 'input[name="username"]',
            fullName: 'input[name="full_name"]'
        },
        onShow: ({ modalElement, data }) => {
            const passwordField = modalElement.querySelector('input[name="password"]');
            if (passwordField) passwordField.value = '';
            const roles = (data.roles || '').split(',');
            modalElement.querySelectorAll('input[name="roles[]"]').forEach((field) => { field.checked = roles.includes(field.value); });
        }
    });
    </script>
</body>
</html>
