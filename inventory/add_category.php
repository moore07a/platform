<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if (!isPlatformOwner() && !hasRole('farm_admin')) {
    header('Location: ../no_access.php');
    exit();
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('Invalid request token.');
    }
    $name = trim($_POST['category_name'] ?? '');
    $farm_type = $_POST['farm_type'] ?? 'both';
    $unit = trim($_POST['unit'] ?? '');

    if (!in_array($farm_type, allowedFarmTypes(), true)) {
        $message = 'That farm type is not enabled for this farm.';
    } elseif ($name === '') {
        $message = 'Category name is required.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO inventory_categories (farm_id, category_name, farm_type, unit) VALUES (?, ?, ?, ?)");
        try {
            $stmt->execute([requireCurrentFarmId(), $name, $farm_type, $unit]);
            $message = 'Category added.';
        } catch (PDOException $e) {
            $message = 'Could not add category. It may already exist.';
        }
    }
}
?>
<!doctype html>
<html>
<head>
  <?php include __DIR__ . '/../navbar_head.php'; ?>
  <title>Add Category</title>
</head>
<body>
  <?php include __DIR__ . '/../navbar.php'; ?>
  <div class="container mt-4">
    <h4>Add Inventory Category (Owner only)</h4>
    <?php if ($message): ?>
      <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>" />
      <div class="mb-3">
        <label class="form-label">Category Name</label>
        <input name="category_name" class="form-control" required />
      </div>
      <div class="mb-3">
        <label class="form-label">Farm Type</label>
        <select name="farm_type" class="form-select">
          <?php foreach (allowedFarmTypes() as $type): ?><option value="<?= $type ?>" <?= $type === 'both' ? 'selected' : '' ?>><?= ucfirst($type) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
      <label class="form-label">Unit (e.g., kg, bags)</label>
      <input name="unit" class="form-control" />
      </div>
      <button class="btn btn-primary" type="submit">Add Category</button>
      <a class="btn btn-primary ms-2" href="../inventory.php">Back</a>
    </form>
  </div>
</body>
</html>
