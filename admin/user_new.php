<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();
require_role('website_admin');

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\Site;
use NexusCMS\Models\User;

$base = base_path();
$activeNav = 'user_new';
$themeIsLight = ui_theme_is_light();
$pdo = DB::pdo();

$me = null;
if (isset($_SESSION['user_id'])) {
  $me = User::findById((int)$_SESSION['user_id']) ?: null;
}
$myId = (int)($_SESSION['user_id'] ?? 0);
$myRole = strtolower((string)($me['role'] ?? $_SESSION['user_role'] ?? ''));
$canManageUsers = role_level($myRole) >= role_level('website_admin');
$canCreateSuperAdmin = $myRole === 'super_admin';

if (!$canManageUsers) {
  http_response_code(403);
  exit('Forbidden');
}

function role_label_new_user(string $role): string {
  $map = [
    'super_admin' => 'Super Admin',
    'website_admin' => 'Website Admin',
    'editor' => 'Editor',
    'institution_admin' => 'Institution Admin',
    'student' => 'Student',
  ];
  return $map[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function ensure_user_new_columns(PDO $pdo): array {
  $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $have = array_flip($cols ?: []);
  $alter = [];
  if (!isset($have['status'])) $alter[] = "ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'";
  if (!isset($have['invited_by_user_id'])) $alter[] = "ADD COLUMN invited_by_user_id INT NULL";
  if (!isset($have['invited_at'])) $alter[] = "ADD COLUMN invited_at DATETIME NULL";
  if (!isset($have['last_active_at'])) $alter[] = "ADD COLUMN last_active_at DATETIME NULL";
  if (!isset($have['institution_name'])) $alter[] = "ADD COLUMN institution_name VARCHAR(190) NULL";
  if ($alter) {
    try {
      $pdo->exec("ALTER TABLE users " . implode(',', $alter));
    } catch (\Throwable $e) {
      // Best effort for mixed local schemas.
    }
  }
  $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  return array_flip($cols ?: []);
}

function set_new_user_access(PDO $pdo, int $userId, array $siteIds): void {
  $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
  $siteIds = array_values(array_filter($siteIds, fn($v) => $v > 0));
  $pdo->beginTransaction();
  try {
    $del = $pdo->prepare("DELETE FROM user_site_access WHERE user_id = ?");
    $del->execute([$userId]);
    if ($siteIds) {
      $ins = $pdo->prepare("INSERT INTO user_site_access (user_id, site_id) VALUES (?, ?)");
      foreach ($siteIds as $sid) {
        $ins->execute([$userId, $sid]);
      }
    }
    $pdo->commit();
  } catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

$cols = ensure_user_new_columns($pdo);
$allSites = Site::all();
usort($allSites, fn($a, $b) => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

$roleOptions = $canCreateSuperAdmin
  ? ['student', 'institution_admin', 'editor', 'website_admin', 'super_admin']
  : ['student', 'institution_admin', 'editor', 'website_admin'];

$values = [
  'display_name' => '',
  'email' => '',
  'role' => 'student',
  'institution_name' => '',
  'password' => '',
  'password_confirm' => '',
  'access_site_ids' => [],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $errors['form'] = 'Security check failed. Please try again.';
  } else {
    $values['display_name'] = trim((string)($_POST['display_name'] ?? ''));
    $values['email'] = trim((string)($_POST['email'] ?? ''));
    $values['role'] = strtolower(trim((string)($_POST['role'] ?? 'student')));
    $values['institution_name'] = trim((string)($_POST['institution_name'] ?? ''));
    $values['password'] = (string)($_POST['password'] ?? '');
    $values['password_confirm'] = (string)($_POST['password_confirm'] ?? '');
    $values['access_site_ids'] = array_values(array_filter(array_map('intval', (array)($_POST['access_site_ids'] ?? [])), fn($v) => $v > 0));

    if ($values['display_name'] === '') $errors['display_name'] = 'Display name is required.';
    if ($values['email'] === '') $errors['email'] = 'Email is required.';
    elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    elseif (User::findByEmail($values['email'])) $errors['email'] = 'A user with that email already exists.';

    if (!in_array($values['role'], $roleOptions, true)) $errors['role'] = 'Choose a valid role.';
    if (strlen($values['password']) < 10) $errors['password'] = 'Password must be at least 10 characters.';
    if ($values['password'] !== $values['password_confirm']) $errors['password_confirm'] = 'Passwords do not match.';

    $validSiteIds = array_map('intval', array_column($allSites, 'id'));
    $validSiteSet = array_flip($validSiteIds);
    $values['access_site_ids'] = array_values(array_filter($values['access_site_ids'], fn($sid) => isset($validSiteSet[$sid])));

    if (!$errors) {
      try {
        $passwordHash = password_hash($values['password'], PASSWORD_DEFAULT);

        $insertCols = ['email', 'password_hash', 'display_name', 'role', 'access'];
        $insertVals = [$values['email'], $passwordHash, $values['display_name'], $values['role'], null];

        if (isset($cols['status'])) {
          $insertCols[] = 'status';
          $insertVals[] = 'active';
        }
        if (isset($cols['invited_at'])) {
          $insertCols[] = 'invited_at';
          $insertVals[] = null;
        }
        if (isset($cols['invited_by_user_id'])) {
          $insertCols[] = 'invited_by_user_id';
          $insertVals[] = null;
        }
        if (isset($cols['last_active_at'])) {
          $insertCols[] = 'last_active_at';
          $insertVals[] = null;
        }
        if (isset($cols['institution_name'])) {
          $insertCols[] = 'institution_name';
          $insertVals[] = ($values['institution_name'] !== '' ? $values['institution_name'] : null);
        }

        $ph = implode(',', array_fill(0, count($insertCols), '?'));
        $stmt = $pdo->prepare("INSERT INTO users (" . implode(',', $insertCols) . ") VALUES ({$ph})");
        $stmt->execute($insertVals);
        $newUserId = (int)$pdo->lastInsertId();
        set_new_user_access($pdo, $newUserId, $values['access_site_ids']);

        $_SESSION['admin_users_flash'] = [
          'type' => 'notice',
          'message' => 'User created successfully.',
        ];
        header('Location: ' . $base . '/admin/users.php');
        exit;
      } catch (\Throwable $e) {
        $errors['form'] = 'We could not create the user. Please try again. (' . $e->getMessage() . ')';
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create new user</title>
  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    body{margin:0;background:var(--admin-bg);color:var(--admin-text);font:14px/1.5 Arial, Helvetica, sans-serif}
    a{color:inherit;text-decoration:none}
    .content{padding:14px;display:grid;gap:12px}
    .panel{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px}
    .panel-head{padding:12px 14px;border-bottom:1px solid var(--admin-line)}
    .panel-title{margin:0;font-size:20px;font-weight:700;color:var(--admin-text-strong)}
    .panel-subtitle{margin:4px 0 0;color:var(--admin-muted)}
    .panel-body{padding:0}
    .form-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,.9fr)}
    .form-main{padding:16px 18px}
    .form-side{padding:16px 18px;border-left:1px solid var(--admin-line);background:color-mix(in srgb, var(--admin-surface-2) 55%, transparent)}
    .grid{display:grid;grid-template-columns:180px minmax(0,1fr);gap:12px 18px;align-items:start}
    .field{display:contents}
    .field.full{grid-column:1 / -1}
    .field label{font-weight:700;color:var(--admin-text-strong)}
    .field-control{display:grid;gap:6px;min-width:0}
    .field input,.field select{width:100%;min-height:38px;padding:8px 10px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text);font:inherit}
    .helper{font-size:12px;color:var(--admin-muted)}
    .section-title{margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--admin-muted)}
    .site-table{border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface);overflow:hidden}
    .site-table-head,.site-row{display:grid;grid-template-columns:44px minmax(0,1fr)}
    .site-table-head{background:var(--admin-surface-2);border-bottom:1px solid var(--admin-line);font-size:12px;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.04em}
    .site-table-head span,.site-row span{padding:10px 12px}
    .site-row{border-top:1px solid var(--admin-line)}
    .site-row:first-child{border-top:0}
    .site-row label{display:contents;cursor:pointer}
    .site-row input{margin:12px auto 0}
    .site-row span{font-weight:600}
    .form-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:14px}
    .check-item input{margin-top:2px}
    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text-strong);font-size:13px;font-weight:600;cursor:pointer}
    .btn.primary{border-color:color-mix(in srgb, var(--admin-accent) 60%, var(--admin-line));background:var(--admin-accent);color:#fff}
    .error-banner{margin:0;padding:10px 12px;border-radius:4px;border:1px solid color-mix(in srgb, var(--admin-danger) 40%, var(--admin-line));font-size:13px;background:color-mix(in srgb, var(--admin-danger) 14%, transparent);color:var(--admin-danger)}
    .inline-errors{display:grid;gap:4px}
    .inline-errors p{margin:0;font-size:12px;color:var(--admin-danger)}
    @media (max-width: 900px){
      .form-layout{grid-template-columns:1fr}
      .form-side{border-left:0;border-top:1px solid var(--admin-line)}
      .grid{grid-template-columns:1fr}
      .field{display:grid}
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="content">
    <?php if (isset($errors['form'])): ?>
      <p class="error-banner"><?= Security::e((string)$errors['form']) ?></p>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-head">
        <h1 class="panel-title">Create New User</h1>
        <p class="panel-subtitle">Set up an internal CMS account with role, password, and site access.</p>
      </div>
      <div class="panel-body">
        <form method="post" class="form-layout">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <div class="form-main">
            <div class="grid">
              <div class="field">
                <label for="display_name">Display name</label>
                <div class="field-control">
                  <input id="display_name" name="display_name" type="text" required value="<?= Security::e($values['display_name']) ?>">
                  <?php if (isset($errors['display_name'])): ?><div class="inline-errors"><p><?= Security::e($errors['display_name']) ?></p></div><?php endif; ?>
                </div>
              </div>

              <div class="field">
                <label for="email">Email / username</label>
                <div class="field-control">
                  <input id="email" name="email" type="email" required value="<?= Security::e($values['email']) ?>">
                  <?php if (isset($errors['email'])): ?><div class="inline-errors"><p><?= Security::e($errors['email']) ?></p></div><?php endif; ?>
                </div>
              </div>

              <div class="field">
                <label for="role">Role</label>
                <div class="field-control">
                  <select id="role" name="role" required>
                    <?php foreach ($roleOptions as $role): ?>
                      <option value="<?= Security::e($role) ?>" <?= $values['role'] === $role ? 'selected' : '' ?>><?= Security::e(role_label_new_user($role)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (isset($errors['role'])): ?><div class="inline-errors"><p><?= Security::e($errors['role']) ?></p></div><?php endif; ?>
                </div>
              </div>

              <div class="field">
                <label for="institution_name">University / College</label>
                <div class="field-control">
                  <input id="institution_name" name="institution_name" type="text" value="<?= Security::e($values['institution_name']) ?>" placeholder="Internal context only">
                </div>
              </div>

              <div class="field">
                <label for="password">Password</label>
                <div class="field-control">
                  <input id="password" name="password" type="password" value="<?= Security::e($values['password']) ?>">
                  <div class="helper">Minimum 10 characters.</div>
                  <?php if (isset($errors['password'])): ?><div class="inline-errors"><p><?= Security::e($errors['password']) ?></p></div><?php endif; ?>
                </div>
              </div>

              <div class="field">
                <label for="password_confirm">Confirm password</label>
                <div class="field-control">
                  <input id="password_confirm" name="password_confirm" type="password" value="<?= Security::e($values['password_confirm']) ?>">
                  <?php if (isset($errors['password_confirm'])): ?><div class="inline-errors"><p><?= Security::e($errors['password_confirm']) ?></p></div><?php endif; ?>
                </div>
              </div>
            </div>

            <div class="form-actions">
              <a class="btn" href="<?= $base ?>/admin/users.php">Cancel</a>
              <button class="btn primary" type="submit">Create user</button>
            </div>
          </div>

          <aside class="form-side">
            <h2 class="section-title">Site Access</h2>
            <?php if (!$allSites): ?>
              <div class="helper">No sites available yet.</div>
            <?php else: ?>
              <?php $selectedAccess = array_flip($values['access_site_ids']); ?>
              <div class="site-table">
                <div class="site-table-head">
                  <span></span>
                  <span>Website</span>
                </div>
                <?php foreach ($allSites as $site): ?>
                  <?php $siteId = (int)($site['id'] ?? 0); ?>
                  <div class="site-row">
                    <label>
                      <input type="checkbox" name="access_site_ids[]" value="<?= $siteId ?>" <?= isset($selectedAccess[$siteId]) ? 'checked' : '' ?>>
                      <span><?= Security::e((string)($site['name'] ?? 'Untitled site')) ?></span>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="helper" style="margin-top:8px;">Assign the websites this user should manage or access.</div>
            <?php endif; ?>
          </aside>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
