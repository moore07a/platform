<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if (!isPlatformOwner() && !hasRole('farm_admin')) {
    header('Location: ../no_access.php');
    exit();
}

$stmt = $pdo->prepare("SELECT id, category_name, farm_type, unit FROM inventory_categories WHERE farm_id = ? ORDER BY category_name");
$stmt->execute([requireCurrentFarmId()]);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <?php include __DIR__ . '/../navbar_head.php'; ?>
  <title>Categories</title>
</head>
  <body>
  <?php include __DIR__ . '/../navbar.php'; ?>
  <div class="container mt-4">
    <h4>Inventory Categories</h4>
    <div class="mb-2">
      <a href="add_category.php" class="btn btn-sm btn-primary me-2">Add Category</a>
      <a href="delete_category.php" class="btn btn-sm btn-primary">Delete Category</a>
    </div>
    <table class="table table-striped">
      <thead><tr><th>ID</th><th>Name</th><th>Farm Type</th><th>Unit</th></tr></thead>
      <tbody>
      <?php foreach ($result as $row): ?>
        <tr><td><?= $row['id'] ?></td><td><?= htmlspecialchars($row['category_name']) ?></td><td><?= $row['farm_type'] ?></td><td><?= htmlspecialchars($row['unit']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="mt-2">
      <a href="../inventory.php" class="btn btn-sm btn-primary">Back</a>
    </div>
  </div>
</body>
</html>
