<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\User;

$base = base_path();
$activeNav = 'users';
$themeIsLight = ui_theme_is_light();

$notice = null;
$error = null;
$allowedRoles = ['super_admin','website_admin','editor','institution_admin','student'];
$me = null;
if (isset($_SESSION['user_id'])) {
  $me = User::findById((int)$_SESSION['user_id']) ?: null;
}

// Handle create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] === 'create') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $error = 'Security check failed.';
  } else {
    $email = trim((string)($_POST['email'] ?? ''));
    $name = trim((string)($_POST['display_name'] ?? ''));
    $role = trim((string)($_POST['role'] ?? ''));
    if ($email === '') $error = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
    elseif (!in_array($role, $allowedRoles, true)) $error = 'Choose a valid role.';

    if (!$error) {
      try {
        $existing = User::findByEmail($email);
        if ($existing) {
          $error = 'A user with that email already exists.';
        } else {
          $tempPass = bin2hex(random_bytes(6));
          $hash = password_hash($tempPass, PASSWORD_DEFAULT);
          $stmt = DB::pdo()->prepare("INSERT INTO users (email, password_hash, display_name, role, access) VALUES (?,?,?,?, NULL)");
          $stmt->execute([$email, $hash, $name ?: $email, $role]);
          $notice = 'User invited. Temporary password generated.';
        }
      } catch (\Throwable $t) {
        $error = 'Unable to create user.';
      }
    }
  }
}

// Handle role change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'change_role') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $error = 'Security check failed.';
  } elseif (($me['role'] ?? '') !== 'super_admin') {
    $error = 'Only super admins can change roles.';
  } else {
    $targetId = (int)($_POST['user_id'] ?? 0);
    $newRole = trim((string)($_POST['new_role'] ?? ''));
    if ($targetId <= 0 || !in_array($newRole, $allowedRoles, true)) {
      $error = 'Invalid role change request.';
    } else {
      try {
        $st = DB::pdo()->prepare("UPDATE users SET role = ? WHERE id = ?");
        $st->execute([$newRole, $targetId]);
        $notice = 'Role updated.';
      } catch (\Throwable $t) {
        $error = 'Unable to update role.';
      }
    }
  }
}

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? 'all'));
$statusFilter = trim((string)($_GET['status'] ?? 'all')); // placeholder for future status column

$params = [];
$where = [];
if ($q !== '') {
  $where[] = "(email LIKE ? OR display_name LIKE ?)";
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}
if ($roleFilter !== 'all' && $roleFilter !== '') {
  $where[] = "role = ?";
  $params[] = $roleFilter;
}
// statusFilter currently informational (no status column); kept for UI parity

$sql = "SELECT id, email, display_name, role, created_at FROM users";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY created_at DESC";
$stmt = DB::pdo()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

function relative_time(?string $ts): string {
  if (!$ts) return '—';
  $time = strtotime($ts);
  if (!$time) return '—';
  $diff = time() - $time;
  if ($diff < 60) return 'Just now';
  $units = [
    31536000 => 'year',
    2592000  => 'month',
    604800   => 'week',
    86400    => 'day',
    3600     => 'hour',
    60       => 'minute',
  ];
  foreach ($units as $secs => $label) {
    if ($diff >= $secs) {
      $val = floor($diff / $secs);
      return $val . ' ' . $label . ($val === 1 ? '' : 's') . ' ago';
    }
  }
  return '—';
}

function role_label(string $role): string {
  $map = [
    'super_admin' => 'Super Admin',
    'website_admin' => 'Website Admin',
    'editor' => 'Editor',
    'institution_admin' => 'Institution Admin',
    'student' => 'Student',
    // Legacy role labels for existing records.
    'admin' => 'Website Admin',
    'staff_admin' => 'Website Admin',
    'user_admin' => 'Institution Admin',
    'viewer' => 'Student',
  ];
  $key = strtolower($role);
  return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Users — NexusCMS Admin</title>
  <script>
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    :root {
      --bg: #0f172a;
      --panel: #111827;
      --card: #111827;
      --border: #1f2937;
      --muted: #9ca3af;
      --text: #e5e7eb;
      --primary: #5b21b6;
      --primary-strong: #4c1d95;
      --shadow: 0 12px 40px rgba(0,0,0,0.25);
      --radius: 12px;
      --focus: 0 0 0 3px rgba(91,33,182,0.35);
    }
    .theme-light {
      --bg: #f8fafc;
      --panel: #ffffff;
      --card: #ffffff;
      --border: #e2e8f0;
      --muted: #475569;
      --text: #0f172a;
      --primary: #2563eb;
      --primary-strong: #1d4ed8;
      --shadow: 0 10px 30px rgba(15,23,42,0.08);
      --focus: 0 0 0 3px rgba(37,99,235,0.28);
    }
    *{box-sizing:border-box;}
    body{margin:0;background:var(--bg);color:var(--text);font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;line-height:1.5;}
    a{text-decoration:none;color:inherit;}
    a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:none;box-shadow:var(--focus);border-color:var(--primary);}
    main{max-width:1200px;margin:0 auto;padding:20px 20px 48px;}
    .page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;margin:24px 0 14px;}
    .page-head h1{margin:0;font-size:32px;letter-spacing:-0.02em;}
    .page-head p{margin:6px 0 0;color:var(--muted);}
    .page-tools{display:flex;align-items:center;gap:10px;margin:8px 0 14px;}
    .help-link{border:1px solid var(--border);background:transparent;color:var(--muted);padding:8px 12px;border-radius:10px;cursor:pointer;font-weight:600;}
    .help-link:hover{color:var(--text);}
    .filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:10px 0 18px;}
    .filters .grow{flex:1;min-width:260px;}
    .advanced-filters{margin-left:auto;}
    .advanced-filters details{position:relative;}
    .advanced-filters summary{list-style:none;cursor:pointer;}
    .advanced-filters summary::-webkit-details-marker{display:none;}
    .advanced-filters-menu{position:absolute;right:0;top:calc(100% + 6px);background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px;min-width:260px;z-index:8;box-shadow:var(--shadow);display:grid;gap:8px;}
    .advanced-filters-menu label{font-size:12px;color:var(--muted);font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
    .input{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,0.04);}
    .input input, .input select{background:transparent;border:none;color:var(--text);font-weight:600;min-width:200px;}
    .input input::placeholder{color:var(--muted);}
    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      min-height:36px;
      padding:8px 12px;
      border-radius:10px;
      border:1px solid var(--border);
      background:rgba(255,255,255,.06);
      color:var(--text);
      cursor:pointer;
      font-weight:700;
      text-decoration:none;
    }
    .btn:hover{background:rgba(255,255,255,.1);}
    .btn.primary{
      background:linear-gradient(135deg,var(--primary),var(--primary));
      color:#fff;
      border-color:rgba(255,255,255,.12);
      box-shadow:none;
    }
    .btn.ghost{
      background:transparent;
      border-color:transparent;
      color:var(--muted);
      padding:8px 10px;
    }
    .btn.ghost:hover{background:rgba(255,255,255,.05);color:var(--text);}
    .table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
    th,td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--border);}
    th{color:var(--muted);font-weight:600;font-size:14px;}
    tr:last-child td{border-bottom:none;}
    .badge{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-weight:700;font-size:12px;}
    .badge.role{background:rgba(59,130,246,0.12);color:#bfdbfe;}
    .badge.status{background:rgba(16,185,129,0.12);color:#a7f3d0;}
    .actions-menu{display:inline-flex;gap:6px;position:relative;}
    .muted{color:var(--muted);}
    .notice{margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid rgba(34,197,94,0.35);background:rgba(34,197,94,0.08);}
    .error-banner{margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid rgba(248,113,113,0.45);background:rgba(248,113,113,0.12);color:#fecdd3;font-weight:700;}
    .empty{padding:24px;border:1px dashed var(--border);border-radius:14px;text-align:center;color:var(--muted);}
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:30;}
    .modal{background:var(--card);border-radius:16px;border:1px solid var(--border);padding:18px;min-width:320px;max-width:420px;box-shadow:var(--shadow);}
    .modal h3{margin-top:0;}
    .modal p{margin:0;color:var(--muted);}
    .modal form{display:grid;gap:12px;}
    .modal label{font-weight:700;}
    .modal input,.modal select{width:100%;padding:10px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.06);color:var(--text);}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:4px;}
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <div class="page-head">
      <div>
        <h1>Users</h1>
        <p>Manage users and access permissions.</p>
      </div>
    </div>
    <div class="page-tools">
      <button type="button" class="help-link" id="openRoleHelp">Role help</button>
    </div>

    <?php if ($notice): ?><div class="notice"><?= Security::e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error-banner"><?= Security::e($error) ?></div><?php endif; ?>

    <form class="filters" method="get">
      <div class="input grow">
        <label class="sr-only" for="q">Search users</label>
        <input id="q" name="q" type="search" placeholder="Search users…" value="<?= Security::e($q) ?>">
      </div>
      <div class="advanced-filters">
        <details id="advancedFilters">
          <summary class="btn ghost">Advanced filters</summary>
          <div class="advanced-filters-menu">
            <label for="role">Role</label>
            <div class="input">
              <select id="role" name="role">
                <?php
                  $roles = ['all'=>'All roles','super_admin'=>'Super Admin','website_admin'=>'Website Admin','editor'=>'Editor','institution_admin'=>'Institution Admin','student'=>'Student'];
                  foreach ($roles as $val => $label) {
                    $sel = $roleFilter === $val ? 'selected' : '';
                    echo "<option value=\"".Security::e($val)."\" {$sel}>".Security::e($label)."</option>";
                  }
                ?>
              </select>
            </div>
            <label for="status">Status</label>
            <div class="input">
              <select id="status" name="status">
                <?php
                  $statuses = ['all'=>'All statuses','active'=>'Active','invited'=>'Invited','suspended'=>'Suspended'];
                  foreach ($statuses as $val => $label) {
                    $sel = $statusFilter === $val ? 'selected' : '';
                    echo "<option value=\"".Security::e($val)."\" {$sel}>".Security::e($label)."</option>";
                  }
                ?>
              </select>
            </div>
            <button class="btn" type="submit">Apply</button>
          </div>
        </details>
      </div>
      <button class="btn primary" type="button" id="openAddUser">+ Invite User</button>
      <?php if ($q !== '' || $roleFilter !== 'all' || $statusFilter !== 'all'): ?>
        <button class="btn" type="submit" name="reset" value="1" onclick="window.location='<?= $base ?>/admin/users.php';return false;">Reset</button>
      <?php endif; ?>
    </form>

    <?php if (!$users): ?>
      <div class="empty">No users found. <button class="btn primary" type="button" id="openAddUser2">+ Invite User</button></div>
    <?php else: ?>
      <table class="table" role="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Last active</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $uRow): ?>
            <?php
              $name = $uRow['display_name'] ?: explode('@', $uRow['email'])[0] ?? '—';
              $status = 'Active';
              $isCurrent = isset($_SESSION['user_id']) && (int)$uRow['id'] === (int)$_SESSION['user_id'];
            ?>
            <tr>
              <td>
                <div style="font-weight:700;"><?= Security::e($name) ?> <?= $isCurrent ? '<span class="badge status" style="background:rgba(59,130,246,0.12);color:#bfdbfe;">You</span>' : '' ?></div>
              </td>
              <td><?= Security::e($uRow['email']) ?></td>
              <td><span class="badge role"><?= Security::e(role_label((string)$uRow['role'])) ?></span></td>
              <td><span class="badge status"><?= Security::e($status) ?></span></td>
              <td><span title="<?= Security::e($uRow['created_at'] ?? '') ?>"><?= Security::e(relative_time($uRow['created_at'] ?? null)) ?></span></td>
              <td class="actions-menu">
                <button
                  type="button"
                  class="btn primary"
                  data-manage-user
                  data-user-id="<?= (int)$uRow['id'] ?>"
                  data-user-name="<?= Security::e($name) ?>"
                  data-user-email="<?= Security::e($uRow['email']) ?>"
                  data-user-role="<?= Security::e((string)$uRow['role']) ?>"
                >Manage</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>

  <div class="modal-backdrop" id="addUserModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addUserTitle">
      <h3 id="addUserTitle">Invite user</h3>
      <p>Create a user account and assign an initial role.</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
        <input type="hidden" name="mode" value="create">
        <label>Email</label>
        <input name="email" type="email" required>
        <label>Display name (optional)</label>
        <input name="display_name" type="text">
        <label>Role</label>
        <select name="role" required>
          <?php foreach (['student','institution_admin','editor','website_admin','super_admin'] as $r): ?>
            <option value="<?= Security::e($r) ?>" <?= $r==='student'?'selected':'' ?>><?= Security::e(role_label($r)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="modal-actions">
          <button type="button" class="btn" id="cancelModal">Cancel</button>
          <button type="submit" class="btn primary">Send invite</button>
        </div>
      </form>
    </div>
  </div>
  <div class="modal-backdrop" id="manageUserModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="manageUserTitle">
      <h3 id="manageUserTitle">Manage user</h3>
      <p id="manageUserMeta"></p>
      <?php if (($me['role'] ?? '') === 'super_admin'): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <input type="hidden" name="mode" value="change_role">
          <input type="hidden" name="user_id" id="manageUserId" value="">
          <label for="manageUserRole">Role</label>
          <select id="manageUserRole" name="new_role">
            <?php foreach ($allowedRoles as $r): ?>
              <option value="<?= Security::e($r) ?>"><?= Security::e(role_label($r)) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="modal-actions">
            <button type="button" class="btn" id="cancelManageModal">Cancel</button>
            <button type="submit" class="btn primary">Save changes</button>
          </div>
        </form>
      <?php else: ?>
        <div class="modal-actions" style="justify-content:flex-start;">
          <span class="muted">Only super admins can change roles.</span>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" id="cancelManageModal">Close</button>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="modal-backdrop" id="roleHelpModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="roleHelpTitle">
      <h3 id="roleHelpTitle">User roles</h3>
      <p><strong>Super Admin:</strong> full system access across all websites, including role changes.</p>
      <p><strong>Website Admin:</strong> manage specific platforms, platform settings, content, page creation, and database access.</p>
      <p><strong>Editor:</strong> create and publish content.</p>
      <p><strong>Institution Admin:</strong> teachers/librarians/lecturers who assign content to students on specific websites.</p>
      <p><strong>Student:</strong> basic learner-level access.</p>
      <p><strong>Inheritance:</strong> each level includes all permissions of the levels below it.</p>
      <div class="modal-actions">
        <button type="button" class="btn" id="closeRoleHelp">Close</button>
      </div>
    </div>
  </div>
  <script>
    const openBtns = [document.getElementById('openAddUser'), document.getElementById('openAddUser2')].filter(Boolean);
    const modal = document.getElementById('addUserModal');
    const cancel = document.getElementById('cancelModal');
    openBtns.forEach(b => b.addEventListener('click', () => { if(modal){ modal.style.display='flex'; } }));
    cancel?.addEventListener('click', () => { modal.style.display='none'; });
    modal?.addEventListener('click', (e) => { if (e.target === modal) modal.style.display='none'; });

    const manageModal = document.getElementById('manageUserModal');
    const manageMeta = document.getElementById('manageUserMeta');
    const manageId = document.getElementById('manageUserId');
    const manageRole = document.getElementById('manageUserRole');
    const cancelManage = document.getElementById('cancelManageModal');
    document.querySelectorAll('[data-manage-user]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!manageModal) return;
        const id = btn.getAttribute('data-user-id') || '';
        const name = btn.getAttribute('data-user-name') || 'User';
        const email = btn.getAttribute('data-user-email') || '';
        const role = btn.getAttribute('data-user-role') || '';
        if (manageMeta) manageMeta.textContent = name + (email ? ' (' + email + ')' : '');
        if (manageId) manageId.value = id;
        if (manageRole) manageRole.value = role;
        manageModal.style.display = 'flex';
      });
    });
    cancelManage?.addEventListener('click', () => { if (manageModal) manageModal.style.display = 'none'; });
    manageModal?.addEventListener('click', (e) => { if (e.target === manageModal) manageModal.style.display='none'; });

    const roleHelp = document.getElementById('roleHelpModal');
    document.getElementById('openRoleHelp')?.addEventListener('click', () => { if (roleHelp) roleHelp.style.display = 'flex'; });
    document.getElementById('closeRoleHelp')?.addEventListener('click', () => { if (roleHelp) roleHelp.style.display = 'none'; });
    roleHelp?.addEventListener('click', (e) => { if (e.target === roleHelp) roleHelp.style.display='none'; });

    const advancedFilters = document.getElementById('advancedFilters');
    document.addEventListener('click', (e) => {
      if (!advancedFilters || !advancedFilters.open) return;
      if (!advancedFilters.contains(e.target)) advancedFilters.open = false;
    });
  </script>
</body>
</html>
