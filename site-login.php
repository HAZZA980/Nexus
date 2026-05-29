<?php
require __DIR__ . '/app/bootstrap.php';

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\LoginAttempts;
use NexusCMS\Models\User;

$siteSlug = trim((string)($_GET['site'] ?? $_POST['site'] ?? ''));
$return = trim((string)($_GET['return'] ?? $_POST['return'] ?? '/'));
$prefillEmail = isset($_GET['prefill_email']) ? trim((string)$_GET['prefill_email']) : '';
$basePath = rtrim(base_path(), '/');
$makeTarget = function(string $ret) use ($basePath): string {
  $ret = trim($ret);
  if ($ret === '') return $basePath . '/';

  if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $ret)) {
    return $basePath . '/';
  }

  if ($basePath !== '' && str_starts_with($ret, $basePath)) {
    $ret = substr($ret, strlen($basePath));
  }

  if ($ret === '' || $ret[0] !== '/') $ret = '/' . ltrim($ret, '/');
  if (str_starts_with($ret, '//')) return $basePath . '/';
  return $basePath . $ret;
};
$error = null;
$message = null;

if (!empty($_SESSION['registration_notice'])) {
  $message = (string)$_SESSION['registration_notice'];
  unset($_SESSION['registration_notice']);
}

$loginAttemptStatus = LoginAttempts::status(LoginAttempts::ipHash());
$requireCaptcha = !empty($loginAttemptStatus['captcha_required']);

/**
 * Ensure auth schema exists even on older databases.
 */
function ensureAuthSchema(): void {
  $pdo = DB::pdo();
  // add access column if missing
  try {
    $pdo->query("SELECT access FROM users LIMIT 1");
  } catch (\Throwable $t) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN access JSON NULL"); } catch (\Throwable $t2) {}
  }
  // create user_site_access table
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_site_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    site_id INT NOT NULL,
    UNIQUE KEY uniq_user_site (user_id, site_id),
    INDEX idx_site (site_id),
    CONSTRAINT fk_user_site_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_site_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureAuthSchema();

// Fetch site
$siteStmt = DB::pdo()->prepare("SELECT * FROM sites WHERE slug = ? LIMIT 1");
$siteStmt->execute([$siteSlug]);
$site = $siteStmt->fetch();
if (!$site) {
  $error = "Unknown site.";
}

function ensureAccessMapping(int $userId, int $siteId): void {
  $st = DB::pdo()->prepare("INSERT IGNORE INTO user_site_access (user_id, site_id) VALUES (?, ?)");
  $st->execute([$userId, $siteId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $error = "Security check failed.";
  } else {
    $mode = $_POST['mode'] === 'register' ? 'register' : 'login';
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $name  = trim((string)($_POST['display_name'] ?? ''));

    if ($mode === 'register') {
      if ($email === '' || $pass === '') {
        $error = "Email and password are required.";
      } else {
        $existing = User::findByEmail($email);
        if ($existing) {
          $_SESSION['registration_notice'] = 'An account already exists for that email. Please sign in.';
          $goto = $makeTarget("/site-login.php?site={$siteSlug}&return=" . urlencode($return) . "&prefill_email=" . urlencode($email));
          header('Location: ' . $goto);
          exit;
        } else {
          $hash = password_hash($pass, PASSWORD_DEFAULT);
          $st = DB::pdo()->prepare("INSERT INTO users (email,password_hash,display_name,role,access) VALUES (?,?,?,?,NULL)");
          $st->execute([$email, $hash, $name ?: $email, 'student']);
          $uid = (int)DB::pdo()->lastInsertId();
          ensureAccessMapping($uid, (int)$site['id']);
          $_SESSION['registration_notice'] = 'Account created. Please sign in.';
          $goto = $makeTarget("/site-login.php?site={$siteSlug}&return=" . urlencode($return) . "&prefill_email=" . urlencode($email));
          header('Location: ' . $goto);
          exit;
        }
      }
    } else { // login
      $ipHash = LoginAttempts::ipHash();
      $usernameHash = LoginAttempts::usernameHash($email);
      $attemptStatus = LoginAttempts::status($ipHash);

      if (!empty($attemptStatus['locked'])) {
        $error = "Too many failed login attempts. Try again later.";
      } elseif (!empty($attemptStatus['captcha_required']) && !login_captcha_check((string)($_POST['captcha_answer'] ?? ''))) {
        $attemptStatus = LoginAttempts::recordFailure($ipHash, $usernameHash, 'site_login', 'captcha_failed');
        $error = !empty($attemptStatus['locked'])
          ? "Too many failed login attempts. Try again later."
          : "Complete the verification challenge and try again.";
      } else {
        $user = User::findByEmail($email);
        if ($user && !empty($user['password_hash']) && password_verify($pass, $user['password_hash'])) {
          // Check access
          $role = (string)$user['role'];
          if ($role !== 'super_admin') {
            $st = DB::pdo()->prepare("SELECT 1 FROM user_site_access usa JOIN sites s ON s.id = usa.site_id WHERE usa.user_id=? AND s.slug=? LIMIT 1");
            $st->execute([(int)$user['id'], $siteSlug]);
            if (!$st->fetch()) {
              $attemptStatus = LoginAttempts::recordFailure($ipHash, $usernameHash, 'site_login', 'site_access_denied');
              $error = "You don't have access to this site.";
            }
          }

          if (!$error) {
            LoginAttempts::reset($ipHash, $usernameHash, 'site_login');
            login_captcha_question(true);
            establish_user_session($user);
            header('Location: ' . $makeTarget($return));
            exit;
          }
        } else {
          $attemptStatus = LoginAttempts::recordFailure($ipHash, $usernameHash, 'site_login');
          $error = !empty($attemptStatus['locked'])
            ? "Too many failed login attempts. Try again later."
            : "Invalid credentials.";
        }
      }
      $requireCaptcha = !empty($attemptStatus['captcha_required']);
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
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 14px 40px rgba(0,0,0,0.08);max-width:460px;width:100%;padding:22px;display:flex;flex-direction:column;gap:14px;}
    .brand-img{width:100%;border-radius:12px;overflow:hidden;}
    .brand-img img{width:100%;display:block;}
    label{display:block;margin:8px 0 4px;font-weight:700;font-size:14px;color:#111827;}
    input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;}
    button{margin-top:6px;padding:10px 14px;border-radius:10px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-weight:700;cursor:pointer;width:100%;}
    .error{color:#b91c1c;margin-bottom:4px;font-weight:700;}
    .toggle{display:flex;gap:10px;margin-top:4px;}
    .toggle button{flex:1;background:#e5e7eb;border-color:#e5e7eb;color:#111;}
    .toggle button.active{background:#2563eb;border-color:#2563eb;color:#fff;}
    .muted{color:#6b7280;font-size:13px;}
  </style>
</head>
<body>
  <div class="card">
    <div class="brand-img">
      <img src="https://pub-mediabox-storage.rxweb-prd.com/exhibitor/cover/exh-b160a402-5b1c-43d4-8c0a-97158d629c5d/desktop-cover/1db46f2e-ae4b-4fcd-a4bf-7f514eb29b24.jpg" alt="Login">
    </div>
    <?php if ($error): ?><div class="error"><?= Security::e($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="muted"><?= Security::e($message) ?></div><?php endif; ?>
    <div class="toggle">
      <button type="button" id="btnLogin" class="active">Login</button>
      <button type="button" id="btnRegister">Create account</button>
    </div>
    <form method="post" id="authForm">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="site" value="<?= Security::e($siteSlug) ?>">
      <input type="hidden" name="return" value="<?= Security::e($return) ?>">
      <input type="hidden" name="mode" id="modeField" value="login">
      <div id="nameField" style="display:none;">
        <label>Display name (optional)</label>
        <input name="display_name" type="text" autocomplete="name">
      </div>
      <label>Email</label>
      <input name="email" type="email" required autocomplete="email" value="<?= Security::e($prefillEmail) ?>">
      <label>Password</label>
      <input name="password" type="password" required autocomplete="current-password">
      <?php if ($requireCaptcha): ?>
        <label>Verification: <?= Security::e(login_captcha_question()) ?></label>
        <input name="captcha_answer" type="text" inputmode="numeric" autocomplete="off" required data-login-required="1">
      <?php endif; ?>
      <button type="submit">Continue</button>
      <div class="muted">You are signing in for site: <?= Security::e($site['name'] ?? $siteSlug) ?></div>
    </form>
  </div>

  <script nonce="<?= Security::e(csp_nonce()) ?>">
    const btnLogin = document.getElementById('btnLogin');
    const btnReg = document.getElementById('btnRegister');
    const modeField = document.getElementById('modeField');
    const nameField = document.getElementById('nameField');
    const passwordInput = document.querySelector('input[name="password"]');
    const loginRequiredInputs = document.querySelectorAll('[data-login-required="1"]');
    const setMode = (mode) => {
      if (mode === 'register') {
        modeField.value = 'register';
        btnReg.classList.add('active'); btnLogin.classList.remove('active');
        nameField.style.display = '';
        if (passwordInput) passwordInput.setAttribute('autocomplete','new-password');
        loginRequiredInputs.forEach((input) => input.required = false);
      } else {
        modeField.value = 'login';
        btnLogin.classList.add('active'); btnReg.classList.remove('active');
        nameField.style.display = 'none';
        if (passwordInput) passwordInput.setAttribute('autocomplete','current-password');
        loginRequiredInputs.forEach((input) => input.required = true);
      }
    };
    btnLogin.addEventListener('click', () => setMode('login'));
    btnReg.addEventListener('click', () => {
      setMode('register');
    });

    // If prefilled, keep user on login tab
    if ("<?= $prefillEmail !== '' ? '1' : '' ?>") {
      setMode('login');
    }
  </script>
</body>
</html>
