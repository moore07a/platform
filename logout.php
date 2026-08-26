<?php require_once(__DIR__ . '/init.php'); ?>
<?php
session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit();
?>