<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit('Not found.');
}

require __DIR__ . '/app/bootstrap.php';
use NexusCMS\Core\DB;

$pdo = DB::pdo();
$exists = $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];

if ($exists > 0) {
  echo "Already installed.";
  exit;
}

$plainPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
$adminEmail = getenv('NEXUSCMS_INSTALL_ADMIN_EMAIL');
$adminEmail = is_string($adminEmail) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
  ? $adminEmail
  : 'admin-' . bin2hex(random_bytes(4)) . '@nexuscms.local';

$hash = password_hash($plainPassword, PASSWORD_DEFAULT);
$pdo->prepare(
  "INSERT INTO users (email,password_hash,display_name,role)
   VALUES (?,?,?, 'super_admin')"
)->execute([$adminEmail, $hash, 'Admin']);

echo "Installed.\n";
echo "Admin email: {$adminEmail}\n";
echo "Admin password: {$plainPassword}\n";
echo "Store this password now; it will not be shown again.\n";

@unlink(__FILE__);
@unlink(__DIR__ . '/app/install.php');
