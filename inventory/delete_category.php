<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

if (!isPlatformOwner() && !hasRole('farm_admin')) {
    header('Location: ../no_access.php');
    exit();
}

$message = '';
$farmId = requireCurrentFarmId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('Invalid request token.');
    }
    $categoryId = (int)($_POST['category_id'] ?? 0);

    if ($categoryId <= 0) {
        $message = 'Please choose a category to delete.';
    } else {
        try {
            $pdo->beginTransaction();

            // Find stock items that belong to this category
            $itemStmt = $pdo->prepare('SELECT id, item_name FROM stock_items WHERE category_id = ? AND farm_id = ?');
            $itemStmt->execute([$categoryId, $farmId]);
            $items = $itemStmt->fetchAll();

            // Delete any transactions tied to those items first to satisfy FK constraints
            if (!empty($items)) {
                $deleteTrans = $pdo->prepare('DELETE FROM stock_transactions WHERE stock_item_id = ? AND farm_id = ?');
                $deleteItem = $pdo->prepare('DELETE FROM stock_items WHERE id = ? AND farm_id = ?');

                foreach ($items as $item) {
                    $deleteTrans->execute([$item['id'], $farmId]);
                    $deleteItem->execute([$item['id'], $farmId]);
                }
            }

            // Finally delete the category
            $delete = $pdo->prepare('DELETE FROM inventory_categories WHERE id = ? AND farm_id = ?');
            $delete->execute([$categoryId, $farmId]);

            $pdo->commit();

            $message = 'Category deleted.';
            if (!empty($items)) {
                $itemNames = array_column($items, 'item_name');
                $message .= ' Removed related items: ' . implode(', ', $itemNames);
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = 'Could not delete category. It may be in use.';
        }
    }
}

$categoriesStmt = $pdo->prepare('SELECT id, category_name, farm_type FROM inventory_categories WHERE farm_id = ? ORDER BY category_name');
$categoriesStmt->execute([$farmId]);
$categories = $categoriesStmt->fetchAll();
?>
<!doctype html>
<html>
<head>
  <?php include __DIR__ . '/../navbar_head.php'; ?>
  <title>Delete Category</title>
</head>
<body>
  <?php include __DIR__ . '/../navbar.php'; ?>
  <div class="container mt-4">
    <h4>Delete Inventory Category (Owner only)</h4>
    <?php if ($message): ?>
      <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>" />
      <div class="mb-3">
        <label class="form-label">Select Category to Delete</label>
        <select name="category_id" class="form-select" required>
          <option value="">-- Choose a category --</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>">
              <?= htmlspecialchars($cat['category_name']) ?> (<?= htmlspecialchars($cat['farm_type']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit">Delete Category</button>
      <a class="btn btn-primary ms-2" href="category_list.php">Back</a>
    </form>
  </div>
</body>
</html>
