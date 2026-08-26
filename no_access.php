<?php require_once(__DIR__ . '/init.php'); ?>
<!doctype html>
<html>
<head>
  <?php include(__DIR__ . '/navbar_head.php'); ?>
  <title>No Access</title>
</head>
<body>
  <?php include(__DIR__ . '/navbar.php'); ?>
  <div class="container mt-5">
    <div class="alert alert-danger">
      <h4 class="alert-heading">Access Denied</h4>
      <p>You do not have permission to access this page. If you believe this is an error, contact the system owner.</p>
      <hr>
      <a href="dashboard.php" class="btn btn-primary">Return to Dashboard</a>
    </div>
  </div>
  <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/vendor/bootstrap5/js/bootstrap.bundle.min.js'); ?>"></script>
  <script src="<?php echo BASE_URL; ?><?php echo versioned_asset('/assets/js/main.js'); ?>"></script>
</body>
</html>
