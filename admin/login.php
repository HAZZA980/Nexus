<?php
require __DIR__ . '/../app/bootstrap.php';

// HARD FAIL ON ERRORS (DEV ONLY)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


use NexusCMS\Models\User;
use NexusCMS\Core\Security;

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $error = "Security check failed.";
  } else {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $user = User::findByEmail($email);
    if ($user && password_verify($pass, $user['password_hash'])) {
      establish_user_session($user);
      redirect('/admin/');
    } else {
      $error = "Invalid credentials.";
    }
  }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Admin Login</title></head>
<body>
  <h1>Admin Login</h1>
  <?php if ($error): ?><p style="color:red;"><?= Security::e($error) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <label>Email</label><br>
    <input name="email" type="email" required value="admin@nexuscms.local"><br><br>
    <label>Password</label><br>
    <input name="password" type="password" required value="Admin123!"><br><br>
    <button type="submit">Login</button>
  </form>
</body>
</html>
