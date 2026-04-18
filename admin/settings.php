<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\Site;
use NexusCMS\Models\User;

$base = base_path();
$activeNav = 'settings';
$themeIsLight = ui_theme_is_light();
$csrfToken = Security::csrfToken();
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
  redirect('/login.php');
}

function settings_role_label(string $role): string {
  $map = [
    'super_admin' => 'Super Admin',
    'website_admin' => 'Website Admin',
    'editor' => 'Editor',
    'institution_admin' => 'Institution Admin',
    'student' => 'Student',
    'admin' => 'Website Admin',
    'staff_admin' => 'Website Admin',
    'user_admin' => 'Institution Admin',
    'viewer' => 'Student',
  ];
  $key = strtolower(trim($role));
  return $map[$key] ?? ($key !== '' ? ucwords(str_replace('_', ' ', $key)) : 'Unknown');
}

function settings_relative_time(?string $ts): string {
  if (!$ts) return '—';
  $time = strtotime($ts);
  if (!$time) return '—';
  $diff = max(0, time() - $time);
  if ($diff < 60) return 'Just now';
  $units = [31536000 => 'year', 2592000 => 'month', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
  foreach ($units as $secs => $label) {
    if ($diff >= $secs) {
      $v = (int) floor($diff / $secs);
      return $v . ' ' . $label . ($v === 1 ? '' : 's') . ' ago';
    }
  }
  return '—';
}

$flash = $_SESSION['admin_settings_flash'] ?? null;
unset($_SESSION['admin_settings_flash']);

$pdo = DB::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $_SESSION['admin_settings_flash'] = ['type' => 'error', 'message' => 'Security check failed.'];
    header('Location: ' . $base . '/admin/settings.php');
    exit;
  }

  $mode = (string)($_POST['mode'] ?? '');

  try {
    $currentUser = User::findById($userId);
    if (!$currentUser) throw new RuntimeException('User not found.');

    if ($mode === 'profile') {
      $displayName = trim((string)($_POST['display_name'] ?? ''));
      $email = strtolower(trim((string)($_POST['email'] ?? '')));
      $institution = trim((string)($_POST['institution_name'] ?? ''));

      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
      }

      $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
      $dup->execute([$email, $userId]);
      if ($dup->fetch()) {
        throw new RuntimeException('That email address is already in use.');
      }

      $st = $pdo->prepare("UPDATE users SET display_name = ?, email = ?, institution_name = ? WHERE id = ? LIMIT 1");
      $st->execute([$displayName, $email, $institution, $userId]);

      $_SESSION['user_name'] = $displayName;
      $_SESSION['admin_settings_flash'] = ['type' => 'notice', 'message' => 'Profile updated.'];
    } elseif ($mode === 'password') {
      $currentPassword = (string)($_POST['current_password'] ?? '');
      $newPassword = (string)($_POST['new_password'] ?? '');
      $confirmPassword = (string)($_POST['confirm_password'] ?? '');

      if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        throw new RuntimeException('Complete all password fields.');
      }
      if (!password_verify($currentPassword, (string)($currentUser['password_hash'] ?? ''))) {
        throw new RuntimeException('Current password is incorrect.');
      }
      if (strlen($newPassword) < 10) {
        throw new RuntimeException('New password must be at least 10 characters.');
      }
      if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        throw new RuntimeException('New password must include upper case, lower case, and a number.');
      }
      if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('New password and confirmation do not match.');
      }
      if (password_verify($newPassword, (string)($currentUser['password_hash'] ?? ''))) {
        throw new RuntimeException('New password must be different from the current password.');
      }

      $hash = password_hash($newPassword, PASSWORD_DEFAULT);
      $st = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
      $st->execute([$hash, $userId]);
      $_SESSION['admin_settings_flash'] = ['type' => 'notice', 'message' => 'Password changed.'];
    } elseif ($mode === 'preferences') {
      $theme = strtolower(trim((string)($_POST['theme_mode'] ?? 'dark')));
      if (!in_array($theme, ['dark', 'light'], true)) $theme = 'dark';
      $_SESSION['ui_theme_mode'] = $theme;
      $_SESSION['admin_settings_flash'] = ['type' => 'notice', 'message' => 'Preferences saved.'];
    }
  } catch (\Throwable $e) {
    $_SESSION['admin_settings_flash'] = ['type' => 'error', 'message' => $e->getMessage() ?: 'Unable to save settings.'];
  }

  header('Location: ' . $base . '/admin/settings.php');
  exit;
}

$currentUser = User::findById($userId);
if (!$currentUser) {
  http_response_code(404);
  echo 'User not found';
  exit;
}

$userRole = (string)($currentUser['role'] ?? '');
$siteAccess = User::siteAccess($userId, $userRole);
$siteNames = [];
if (in_array('*', $siteAccess, true)) {
  foreach (Site::all() as $site) {
    $siteNames[] = trim((string)($site['name'] ?? 'Untitled site'));
  }
} elseif ($siteAccess) {
  $allSites = Site::all();
  foreach ($allSites as $site) {
    if (in_array((string)($site['slug'] ?? ''), $siteAccess, true)) {
      $siteNames[] = trim((string)($site['name'] ?? 'Untitled site'));
    }
  }
}
sort($siteNames, SORT_NATURAL | SORT_FLAG_CASE);

$userLabel = trim((string)($currentUser['display_name'] ?? $currentUser['email'] ?? 'Administrator'));
if ($userLabel === '') $userLabel = 'Administrator';
$themeMode = ui_theme_mode();
$createdAt = trim((string)($currentUser['created_at'] ?? ''));
$lastActive = trim((string)($currentUser['last_active_at'] ?? ''));
$lastDevice = trim((string)($currentUser['last_device'] ?? ''));
$lastIp = trim((string)($currentUser['last_login_ip'] ?? ''));
$institutionName = trim((string)($currentUser['institution_name'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Settings — NexusCMS Admin</title>
  <script>
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
  <style>
    body{margin:0;background:var(--admin-bg);color:var(--admin-text);font:14px/1.4 Arial, Helvetica, sans-serif;}
    .content{padding:14px;display:grid;gap:12px;}
    .panel{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;}
    .panel-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid var(--admin-line);}
    .panel-title{margin:0;font-size:16px;font-weight:700;color:var(--admin-text-strong)}
    .notice,.error-banner{margin:0;padding:10px 12px;border-radius:4px;border:1px solid transparent;font-size:13px}
    .notice{border-color:color-mix(in srgb, var(--admin-success) 40%, var(--admin-line));background:color-mix(in srgb, var(--admin-success) 16%, transparent);color:var(--admin-success)}
    .error-banner{border-color:color-mix(in srgb, var(--admin-danger) 40%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 14%, transparent);color:var(--admin-danger)}
    .hero{display:grid;grid-template-columns:1.2fr .8fr;gap:12px;padding:12px;}
    .hero-card{border:1px solid var(--admin-line);border-radius:6px;padding:14px;background:var(--admin-surface-2);}
    .hero-title{margin:0 0 6px;font-size:24px;line-height:1.1;color:var(--admin-text-strong)}
    .hero-copy{margin:0;color:var(--admin-muted);max-width:60ch}
    .mini-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .mini-stat{border:1px solid var(--admin-line);border-radius:6px;padding:10px;background:var(--admin-surface)}
    .mini-stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--admin-muted)}
    .mini-stat-value{margin-top:4px;font-size:18px;font-weight:700;color:var(--admin-text-strong)}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:12px}
    .field{display:grid;gap:6px}
    .field.full{grid-column:1 / -1}
    .field label{font-size:12px;font-weight:700;color:var(--admin-text-strong)}
    .field input,.field select,.field textarea{width:100%;padding:9px 10px;border-radius:4px;border:1px solid var(--admin-line);background:var(--admin-surface-2);color:var(--admin-text);font:inherit}
    .muted{color:var(--admin-muted);font-size:12px}
    .actions{display:flex;justify-content:flex-end;gap:8px;padding:0 12px 12px}
    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:0 10px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text-strong);font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
    .btn.primary{border-color:color-mix(in srgb, var(--admin-accent) 60%, var(--admin-line));background:var(--admin-accent);color:#fff}
    .detail-grid{display:grid;grid-template-columns:180px 1fr;gap:8px 12px;padding:12px}
    .detail-label{font-size:12px;font-weight:700;color:var(--admin-muted)}
    .detail-value{color:var(--admin-text)}
    .chip-list{display:flex;flex-wrap:wrap;gap:8px}
    .chip{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid var(--admin-line);background:var(--admin-surface-2);font-size:12px;font-weight:600;color:var(--admin-text)}
    .radio-row{display:flex;gap:16px;flex-wrap:wrap}
    .radio-option{display:inline-flex;align-items:center;gap:8px}
    @media (max-width:980px){
      .hero,.split,.form-grid{grid-template-columns:1fr}
      .field.full{grid-column:auto}
      .mini-grid{grid-template-columns:1fr 1fr}
    }
    @media (max-width:640px){
      .mini-grid,.detail-grid{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="content">
    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <p class="<?= ($flash['type'] ?? 'notice') === 'error' ? 'error-banner' : 'notice' ?>"><?= Security::e((string)$flash['message']) ?></p>
    <?php endif; ?>

    <section class="panel" aria-label="Settings overview">
      <div class="panel-head">
        <h1 class="panel-title">Settings</h1>
      </div>
      <div class="hero">
        <div class="hero-card">
          <h2 class="hero-title"><?= Security::e($userLabel) ?></h2>
          <p class="hero-copy">Internal CMS account settings for staff. Update your profile, change your password, choose your admin interface theme, and review your CMS access from here.</p>
        </div>
        <div class="mini-grid">
          <div class="mini-stat">
            <div class="mini-stat-label">Role</div>
            <div class="mini-stat-value"><?= Security::e(settings_role_label($userRole)) ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">Theme</div>
            <div class="mini-stat-value"><?= Security::e(ucfirst($themeMode)) ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">Last Active</div>
            <div class="mini-stat-value"><?= Security::e(settings_relative_time($lastActive)) ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">Accessible Sites</div>
            <div class="mini-stat-value"><?= count($siteNames) ?></div>
          </div>
        </div>
      </div>
    </section>

    <section class="split">
      <section class="panel" aria-label="Profile settings">
        <div class="panel-head">
          <h2 class="panel-title">Profile</h2>
        </div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
          <input type="hidden" name="mode" value="profile">
          <div class="form-grid">
            <div class="field">
              <label for="display_name">Display name</label>
              <input id="display_name" name="display_name" type="text" value="<?= Security::e((string)($currentUser['display_name'] ?? '')) ?>">
            </div>
            <div class="field">
              <label for="email">Email / username</label>
              <input id="email" name="email" type="email" required value="<?= Security::e((string)($currentUser['email'] ?? '')) ?>">
            </div>
            <div class="field full">
              <label for="institution_name">Institution</label>
              <input id="institution_name" name="institution_name" type="text" value="<?= Security::e($institutionName) ?>" placeholder="University / College">
              <div class="muted">Used internally for staff account context only.</div>
            </div>
          </div>
          <div class="actions">
            <button class="btn primary" type="submit">Save profile</button>
          </div>
        </form>
      </section>

      <section class="panel" aria-label="Security settings">
        <div class="panel-head">
          <h2 class="panel-title">Security</h2>
        </div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
          <input type="hidden" name="mode" value="password">
          <div class="form-grid">
            <div class="field full">
              <label for="current_password">Current password</label>
              <input id="current_password" name="current_password" type="password" autocomplete="current-password">
            </div>
            <div class="field">
              <label for="new_password">New password</label>
              <input id="new_password" name="new_password" type="password" autocomplete="new-password">
            </div>
            <div class="field">
              <label for="confirm_password">Confirm new password</label>
              <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password">
            </div>
            <div class="field full">
              <div class="muted">Use at least 10 characters with upper case, lower case, and a number.</div>
            </div>
          </div>
          <div class="actions">
            <button class="btn primary" type="submit">Change password</button>
          </div>
        </form>
      </section>
    </section>

    <section class="split">
      <section class="panel" aria-label="Preferences">
        <div class="panel-head">
          <h2 class="panel-title">Preferences</h2>
        </div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
          <input type="hidden" name="mode" value="preferences">
          <div class="form-grid">
            <div class="field full">
              <label>Theme</label>
              <div class="radio-row">
                <label class="radio-option"><input type="radio" name="theme_mode" value="dark" <?= $themeMode === 'dark' ? 'checked' : '' ?>> <span>Dark</span></label>
                <label class="radio-option"><input type="radio" name="theme_mode" value="light" <?= $themeMode === 'light' ? 'checked' : '' ?>> <span>Light</span></label>
              </div>
              <div class="muted">This controls the internal CMS interface theme for your current session.</div>
            </div>
          </div>
          <div class="actions">
            <button class="btn primary" type="submit">Save preferences</button>
          </div>
        </form>
      </section>

      <section class="panel" aria-label="Access and account details">
        <div class="panel-head">
          <h2 class="panel-title">Access & Activity</h2>
        </div>
        <div class="detail-grid">
          <div class="detail-label">Role</div><div class="detail-value"><?= Security::e(settings_role_label($userRole)) ?></div>
          <div class="detail-label">Date joined</div><div class="detail-value"><?= Security::e($createdAt !== '' ? date('Y-m-d H:i', strtotime($createdAt)) : '—') ?></div>
          <div class="detail-label">Last active</div><div class="detail-value"><?= Security::e($lastActive !== '' ? settings_relative_time($lastActive) . ' (' . $lastActive . ')' : '—') ?></div>
          <div class="detail-label">Last device</div><div class="detail-value"><?= Security::e($lastDevice !== '' ? $lastDevice : '—') ?></div>
          <div class="detail-label">Last login IP</div><div class="detail-value"><?= Security::e($lastIp !== '' ? $lastIp : '—') ?></div>
          <div class="detail-label">Accessible sites</div>
          <div class="detail-value">
            <?php if ($siteNames): ?>
              <div class="chip-list">
                <?php foreach ($siteNames as $siteName): ?>
                  <span class="chip"><?= Security::e($siteName) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <span class="muted">No site access assigned.</span>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </section>
  </main>
</body>
</html>
