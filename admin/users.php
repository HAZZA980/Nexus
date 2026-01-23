<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\User;

$base = base_path();
$activeNav = 'users';

$notice = null;
$error = null;
$allowedRoles = ['super_admin','admin','staff_admin','user_admin','editor','viewer','student'];
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Users — NexusCMS Admin</title>
  <script>
    (function(){
      try{
        const stored=localStorage.getItem('nexusTheme');
        const theme=stored==='light'?'light':'dark';
        localStorage.setItem('nexusTheme', theme);
        document.documentElement.classList.toggle('theme-light', theme==='light');
      }catch(e){}
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
    .top-bar{display:flex;align-items:center;gap:16px;padding:14px 18px;background:linear-gradient(90deg, rgba(91,33,182,0.12), rgba(91,33,182,0));border-bottom:1px solid var(--border);position:sticky;top:0;backdrop-filter:blur(10px);z-index:10;}
    .brand{display:inline-flex;align-items:center;gap:10px;font-weight:600;}
    .brand-mark{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg, var(--primary), #22c55e);display:grid;place-items:center;font-weight:700;letter-spacing:-0.02em;box-shadow:var(--shadow);}
    .brand-text{display:flex;flex-direction:column;line-height:1.2;}
    .brand-text small{color:var(--muted);font-weight:500;}
    .top-nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;}
    .top-nav .nav-link{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.05);color:var(--text);font-weight:700;min-height:40px;}
    .top-nav .nav-link:hover{background:rgba(255,255,255,0.1);}
    .top-nav .nav-link.active{background:linear-gradient(135deg, var(--primary), var(--primary-strong));color:#fff;border-color:transparent;box-shadow:var(--shadow);}
    .top-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,0.06);color:var(--text);cursor:pointer;font-weight:700;min-height:44px;}
    .btn:hover{background:rgba(255,255,255,0.1);}
    .btn.primary{background:linear-gradient(135deg, var(--primary), var(--primary-strong));border:none;color:#f8fbff;box-shadow:0 10px 30px rgba(37,99,235,0.35);}
    .user-menu{position:relative;min-width:180px;}
    .user-menu summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:10px;padding:10px 12px;min-height:44px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,0.05);font-weight:600;}
    .user-menu summary::-webkit-details-marker{display:none;}
    .user-avatar{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#22c55e,#3b82f6);display:grid;place-items:center;font-weight:700;color:#0b1224;}
    .user-menu .menu{position:absolute;right:0;top:calc(100% + 6px);background:var(--card);border:1px solid var(--border);border-radius:14px;padding:10px;min-width:220px;box-shadow:var(--shadow);z-index:5;}
    .user-menu .menu a,.user-menu .menu button{display:block;padding:10px 10px;border-radius:10px;text-decoration:none;background:transparent;border:none;color:var(--text);width:100%;text-align:left;cursor:pointer;}
    .user-menu .menu a:hover,.user-menu .menu button:hover{background:rgba(255,255,255,0.06);}
    .user-meta{color:var(--muted);font-size:14px;padding:6px 10px 10px;}
    .page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;margin:24px 0 14px;}
    .page-head h1{margin:0;font-size:32px;letter-spacing:-0.02em;}
    .page-head p{margin:6px 0 0;color:var(--muted);}
    .filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:10px 0 18px;}
    .input{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,0.04);}
    .input input, .input select{background:transparent;border:none;color:var(--text);font-weight:600;min-width:200px;}
    .input input::placeholder{color:var(--muted);}
    .table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
    th,td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--border);}
    th{color:var(--muted);font-weight:600;font-size:14px;}
    tr:last-child td{border-bottom:none;}
    .badge{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-weight:700;font-size:12px;}
    .badge.role{background:rgba(59,130,246,0.12);color:#bfdbfe;}
    .badge.status{background:rgba(16,185,129,0.12);color:#a7f3d0;}
    .actions-menu{display:inline-flex;gap:6px;position:relative;}
    .actions-dd{position:relative;}
    .actions-dd summary{list-style:none;cursor:pointer;}
    .actions-dd summary::-webkit-details-marker{display:none;}
    .actions-dd .menu{position:absolute;right:0;top:calc(100% + 4px);background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px;min-width:220px;box-shadow:var(--shadow);z-index:5;}
    .actions-dd .menu form{display:grid;gap:8px;}
    .actions-dd .menu select{width:100%;padding:8px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.06);color:var(--text);}
    .actions-dd .menu button{padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.06);color:var(--text);cursor:pointer;font-weight:700;}
    .muted{color:var(--muted);}
    .notice{margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid rgba(34,197,94,0.35);background:rgba(34,197,94,0.08);}
    .error-banner{margin:10px 0;padding:10px 12px;border-radius:12px;border:1px solid rgba(248,113,113,0.45);background:rgba(248,113,113,0.12);color:#fecdd3;font-weight:700;}
    .empty{padding:24px;border:1px dashed var(--border);border-radius:14px;text-align:center;color:var(--muted);}
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:30;}
    .modal{background:var(--card);border-radius:16px;border:1px solid var(--border);padding:18px;min-width:320px;max-width:420px;box-shadow:var(--shadow);}
    .modal h3{margin-top:0;}
    .modal form{display:grid;gap:12px;}
    .modal label{font-weight:700;}
    .modal input,.modal select{width:100%;padding:10px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.06);color:var(--text);}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:4px;}
  </style>
</head>
<body>
  <header class="top-bar" role="banner">
    <div class="brand" aria-label="NexusCMS Admin">
      <div class="brand-mark" aria-hidden="true">N</div>
      <div class="brand-text">
        <span>NexusCMS</span>
        <small>Admin</small>
      </div>
    </div>
    <nav class="top-nav" aria-label="Admin navigation">
      <a class="nav-link <?= $activeNav === 'sites' ? 'active' : '' ?>" href="<?= $base ?>/admin/index.php">Sites</a>
      <a class="nav-link <?= $activeNav === 'users' ? 'active' : '' ?>" href="<?= $base ?>/admin/users.php">Users</a>
      <a class="nav-link <?= $activeNav === 'images' ? 'active' : '' ?>" href="<?= $base ?>/admin/images.php">Images</a>
    </nav>
    <div class="top-actions">
      <a class="btn primary" href="<?= $base ?>/admin/site_new.php">+ Create new website</a>
      <div class="user-menu">
        <details>
          <summary aria-haspopup="menu">
            <span class="user-avatar" aria-hidden="true">
              <?php
                $initial = $_SESSION['user_id'] ?? 'U';
                if (isset($_SESSION['user_id'])) {
                  $u = User::findById((int)$_SESSION['user_id']);
                  $initial = strtoupper(mb_substr($u['display_name'] ?? $u['email'] ?? 'U', 0, 1));
                }
                echo Security::e($initial);
              ?>
            </span>
            <span>
              <?= Security::e($u['display_name'] ?? $u['email'] ?? 'User') ?>
              <?php if (!empty($u['role'])): ?>
                <small style="display:block;color:var(--muted);font-weight:500;"><?= Security::e(ucfirst((string)$u['role'])) ?></small>
              <?php endif; ?>
            </span>
          </summary>
          <div class="menu" role="menu">
            <div class="user-meta">Logged in <?= Security::e($u['email'] ?? '') ?></div>
            <a role="menuitem" href="<?= $base ?>/admin/logout.php">Logout</a>
          </div>
        </details>
      </div>
    </div>
  </header>
  <main>
    <div class="page-head">
      <div>
        <h1>Users</h1>
        <p>Manage users and access permissions.</p>
      </div>
    </div>

    <?php if ($notice): ?><div class="notice"><?= Security::e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error-banner"><?= Security::e($error) ?></div><?php endif; ?>

    <form class="filters" method="get">
      <div class="input">
        <label class="sr-only" for="q">Search users</label>
        <input id="q" name="q" type="search" placeholder="Search users…" value="<?= Security::e($q) ?>">
      </div>
      <div class="input">
        <label class="sr-only" for="role">Role</label>
        <select id="role" name="role">
          <?php
            $roles = ['all'=>'All roles','super_admin'=>'Super_admin','admin'=>'Admin','staff_admin'=>'Staff_admin','user_admin'=>'User_admin','editor'=>'Editor','viewer'=>'Viewer','student'=>'Student'];
            foreach ($roles as $val => $label) {
              $sel = $roleFilter === $val ? 'selected' : '';
              echo "<option value=\"".Security::e($val)."\" {$sel}>".Security::e($label)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="input">
        <label class="sr-only" for="status">Status</label>
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
      <button class="btn primary" type="button" id="openAddUser">+ Add user</button>
      <?php if ($q !== '' || $roleFilter !== 'all' || $statusFilter !== 'all'): ?>
        <button class="btn text" type="submit" name="reset" value="1" onclick="window.location='<?= $base ?>/admin/users.php';return false;">Reset filters</button>
      <?php endif; ?>
    </form>

    <?php if (!$users): ?>
      <div class="empty">No users found. <button class="btn primary" type="button" id="openAddUser2">+ Add user</button></div>
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
              <td><span class="badge role"><?= Security::e($uRow['role']) ?></span></td>
              <td><span class="badge status"><?= Security::e($status) ?></span></td>
              <td><span title="<?= Security::e($uRow['created_at'] ?? '') ?>"><?= Security::e(relative_time($uRow['created_at'] ?? null)) ?></span></td>
              <td class="actions-menu">
                <details class="actions-dd">
                  <summary class="btn" style="min-height:36px;padding:8px 10px;">⋯</summary>
                  <div class="menu" role="menu">
                    <?php if (($me['role'] ?? '') === 'super_admin'): ?>
                      <form method="post">
                        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                        <input type="hidden" name="mode" value="change_role">
                        <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                        <label style="font-weight:700;">Change role</label>
                        <select name="new_role">
                          <?php foreach ($allowedRoles as $r): ?>
                            <option value="<?= Security::e($r) ?>" <?= $r === $uRow['role'] ? 'selected' : '' ?>><?= Security::e(ucfirst(str_replace('_',' ',$r))) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit">Update</button>
                      </form>
                    <?php else: ?>
                      <div class="muted">Only super admins can change roles.</div>
                    <?php endif; ?>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>

  <div class="modal-backdrop" id="addUserModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addUserTitle">
      <h3 id="addUserTitle">Add user</h3>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
        <input type="hidden" name="mode" value="create">
        <label>Email</label>
        <input name="email" type="email" required>
        <label>Display name (optional)</label>
        <input name="display_name" type="text">
        <label>Role</label>
        <select name="role" required>
          <?php foreach (['student','viewer','editor','user_admin','staff_admin','admin','super_admin'] as $r): ?>
            <option value="<?= Security::e($r) ?>" <?= $r==='student'?'selected':'' ?>><?= Security::e(ucfirst(str_replace('_',' ',$r))) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="modal-actions">
          <button type="button" class="btn" id="cancelModal">Cancel</button>
          <button type="submit" class="btn primary">Create user</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    const openBtns = [document.getElementById('openAddUser'), document.getElementById('openAddUser2')].filter(Boolean);
    const modal = document.getElementById('addUserModal');
    const cancel = document.getElementById('cancelModal');
    openBtns.forEach(b => b.addEventListener('click', () => { if(modal){ modal.style.display='flex'; } }));
    cancel?.addEventListener('click', () => { modal.style.display='none'; });
    modal?.addEventListener('click', (e) => { if (e.target === modal) modal.style.display='none'; });
  </script>
</body>
</html>
