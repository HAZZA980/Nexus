<?php
require __DIR__ . '/app/bootstrap.php';

use NexusCMS\Models\User;
use NexusCMS\Core\Security;

$error = null;
$return = isset($_GET['return']) ? trim((string)$_GET['return']) : '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $error = "Security check failed.";
  } else {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $return = trim((string)($_POST['return'] ?? '/'));
    $user = User::findByEmail($email);
    if ($user && password_verify($pass, $user['password_hash'])) {
      $_SESSION['user_id'] = (int)$user['id'];
      $_SESSION['user_role'] = $user['role'];
      $_SESSION['site_access'] = User::siteAccess((int)$user['id'], (string)$user['role']);
      $base = base_path();
      // Normalize return to avoid double base paths
      $to = $return ?: '/';
      if (str_starts_with($to, $base)) {
        $to = substr($to, strlen($base));
        if ($to === '') $to = '/';
      }
      if ($to !== '/' && $to[0] !== '/') $to = '/' . $to;
      header('Location: ' . rtrim($base, '/') . $to);
      exit;
    } else {
      $error = "Invalid credentials.";
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Login</title>
  <style>
    :root{font-family:Arial,sans-serif;background:#f5f5f7;}
    body{margin:0;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;padding:24px;}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 14px 40px rgba(0,0,0,0.08);max-width:460px;width:100%;padding:22px;display:flex;flex-direction:column;gap:16px;}
    .brand-img{width:100%;border-radius:12px;overflow:hidden;}
    .brand-img img{width:100%;display:block;}
    label{display:block;margin:8px 0 4px;font-weight:700;font-size:14px;color:#111827;}
    input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;}
    button{margin-top:6px;padding:10px 14px;border-radius:10px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-weight:700;cursor:pointer;width:100%;}
    .error{color:#b91c1c;margin-bottom:4px;font-weight:700;}
  </style>
</head>
<body>
  <div class="card">
    <div class="brand-img">
      <img src="https://pub-mediabox-storage.rxweb-prd.com/exhibitor/cover/exh-b160a402-5b1c-43d4-8c0a-97158d629c5d/desktop-cover/1db46f2e-ae4b-4fcd-a4bf-7f514eb29b24.jpg" alt="Login">
    </div>
    <?php if ($error): ?><div class="error"><?= Security::e($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="return" value="<?= Security::e($return) ?>">
      <label>Email</label>
      <input name="email" type="email" required>
      <label>Password</label>
      <input name="password" type="password" required>
      <button type="submit">Login</button>
    </form>
  </div>
</body>
</html>
