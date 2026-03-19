<?php
require __DIR__ . '/app/bootstrap.php';
use NexusCMS\Core\DB;

$pdo = DB::pdo();
$exists = $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];

if ($exists > 0) {
  echo "Already installed.";
  exit;
}

$hash = password_hash('Admin123!', PASSWORD_DEFAULT);
$pdo->prepare(
  "INSERT INTO users (email,password_hash,display_name,role,access)
   VALUES ('admin@nexuscms.local',?,?, 'super_admin', NULL)"
)->execute([$hash, 'Admin']);

echo "Installed. <a href='/NexusCMS/admin/login.php'>Go to Admin</a>";
