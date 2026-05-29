<?php
require __DIR__ . '/../app/bootstrap.php';

use NexusCMS\Models\User;
use NexusCMS\Models\LoginAttempts;
use NexusCMS\Core\Security;

$error = null;
$loginAttemptStatus = LoginAttempts::status(LoginAttempts::ipHash());
$requireCaptcha = !empty($loginAttemptStatus['captcha_required']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $error = "Security check failed.";
  } else {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    $ipHash = LoginAttempts::ipHash();
    $usernameHash = LoginAttempts::usernameHash($email);
    $attemptStatus = LoginAttempts::status($ipHash);

    if (!empty($attemptStatus['locked'])) {
      $error = "Too many failed login attempts. Try again later.";
    } elseif (!empty($attemptStatus['captcha_required']) && !login_captcha_check((string)($_POST['captcha_answer'] ?? ''))) {
      $attemptStatus = LoginAttempts::recordFailure($ipHash, $usernameHash, 'admin_login', 'captcha_failed');
      $error = !empty($attemptStatus['locked'])
        ? "Too many failed login attempts. Try again later."
        : "Complete the verification challenge and try again.";
    } else {
      $user = User::findByEmail($email);
      if ($user && !empty($user['password_hash']) && password_verify($pass, $user['password_hash'])) {
        LoginAttempts::reset($ipHash, $usernameHash, 'admin_login');
        login_captcha_question(true);
        establish_user_session($user);
        redirect('/admin/');
      } else {
        $attemptStatus = LoginAttempts::recordFailure($ipHash, $usernameHash, 'admin_login');
        $error = !empty($attemptStatus['locked'])
          ? "Too many failed login attempts. Try again later."
          : "Invalid credentials.";
      }
    }
    $requireCaptcha = !empty($attemptStatus['captcha_required']);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login — NexusCMS</title>
  <link rel="stylesheet" href="<?= Security::e(base_path()) ?>/public/assets/admin-shared.css?v=20260322">
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 24px;
      background:
        radial-gradient(circle at 20% 10%, rgba(59, 130, 246, .18), transparent 34%),
        radial-gradient(circle at 80% 0%, rgba(34, 197, 94, .10), transparent 28%),
        var(--admin-bg);
      color: var(--admin-text);
      font: 14px/1.4 Arial, Helvetica, sans-serif;
    }

    .login-shell {
      width: min(420px, 100%);
      border: 1px solid var(--admin-line);
      border-radius: 8px;
      background: var(--admin-surface);
      box-shadow: 0 24px 70px rgba(0, 0, 0, .28);
      overflow: hidden;
    }

    .login-head {
      padding: 22px 24px 18px;
      border-bottom: 1px solid var(--admin-line);
      background: color-mix(in srgb, var(--admin-surface-2) 76%, transparent);
    }

    .brand {
      margin: 0 0 6px;
      color: var(--admin-text-strong);
      font-size: 22px;
      line-height: 1.15;
      font-weight: 700;
      letter-spacing: 0;
    }

    .sub {
      margin: 0;
      color: var(--admin-muted);
      font-size: 13px;
    }

    .login-body {
      display: grid;
      gap: 14px;
      padding: 22px 24px 24px;
    }

    .error {
      margin: 0;
      padding: 10px 12px;
      border: 1px solid color-mix(in srgb, var(--admin-danger) 42%, var(--admin-line));
      border-radius: 4px;
      background: color-mix(in srgb, var(--admin-danger) 13%, transparent);
      color: var(--admin-danger);
      font-size: 13px;
      font-weight: 700;
    }

    .field {
      display: grid;
      gap: 6px;
    }

    label {
      color: var(--admin-text-strong);
      font-size: 12px;
      font-weight: 700;
    }

    input {
      width: 100%;
      min-height: 40px;
      padding: 9px 11px;
      border: 1px solid var(--admin-line);
      border-radius: 4px;
      background: var(--admin-surface-2);
      color: var(--admin-text);
      font: inherit;
    }

    input::placeholder {
      color: color-mix(in srgb, var(--admin-muted) 78%, transparent);
    }

    input:focus {
      outline: 2px solid color-mix(in srgb, var(--admin-accent) 72%, transparent);
      outline-offset: 2px;
      border-color: var(--admin-accent);
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      min-height: 40px;
      margin-top: 2px;
      padding: 0 14px;
      border: 1px solid color-mix(in srgb, var(--admin-accent) 65%, var(--admin-line));
      border-radius: 4px;
      background: var(--admin-accent);
      color: #fff;
      font: inherit;
      font-weight: 700;
      cursor: pointer;
    }

    .btn:hover {
      filter: brightness(1.08);
    }
  </style>
</head>
<body>
  <main class="login-shell" aria-label="Admin login">
    <div class="login-head">
      <h1 class="brand">NexusCMS Admin</h1>
      <p class="sub">Sign in to manage sites, content, users, and settings.</p>
    </div>
    <form class="login-body" method="post" autocomplete="on">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
      <?php if ($error): ?><p class="error"><?= Security::e($error) ?></p><?php endif; ?>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" required placeholder="Email address" autocomplete="username">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required placeholder="Password" autocomplete="current-password">
      </div>
      <?php if ($requireCaptcha): ?>
        <div class="field">
          <label for="captcha_answer">Verification: <?= Security::e(login_captcha_question()) ?></label>
          <input id="captcha_answer" name="captcha_answer" type="text" inputmode="numeric" autocomplete="off" required>
        </div>
      <?php endif; ?>
      <button class="btn" type="submit">Log in</button>
    </form>
  </main>
</body>
</html>
