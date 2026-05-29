<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();
require_role('website_admin');

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\Site;
use NexusCMS\Models\User;

$base = base_path();
$activeNav = 'users';
$themeIsLight = ui_theme_is_light();
$csrfToken = Security::csrfToken();
$allowedRoles = ['super_admin','website_admin','editor','institution_admin','student'];
$allowedStatuses = ['active','invited','suspended'];

$me = null;
if (isset($_SESSION['user_id'])) {
  $me = User::findById((int)$_SESSION['user_id']) ?: null;
}
$myRole = strtolower((string)($me['role'] ?? $_SESSION['user_role'] ?? ''));
$canManageUsers = role_level($myRole) >= role_level('website_admin');
$canChangeRole = $myRole === 'super_admin';

function role_label(string $role): string {
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
  return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}

function relative_time(?string $ts): string {
  if (!$ts) return '—';
  $time = strtotime($ts);
  if (!$time) return '—';
  $diff = max(0, time() - $time);
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
      $val = (int)floor($diff / $secs);
      return $val . ' ' . $label . ($val === 1 ? '' : 's') . ' ago';
    }
  }
  return '—';
}

function ensure_users_admin_columns(PDO $pdo): array {
  $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $have = array_flip($cols ?: []);
  $alter = [];
  if (!isset($have['status'])) $alter[] = "ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'";
  if (!isset($have['invited_by_user_id'])) $alter[] = "ADD COLUMN invited_by_user_id INT NULL";
  if (!isset($have['invited_at'])) $alter[] = "ADD COLUMN invited_at DATETIME NULL";
  if (!isset($have['last_active_at'])) $alter[] = "ADD COLUMN last_active_at DATETIME NULL";
  if (!isset($have['last_login_ip'])) $alter[] = "ADD COLUMN last_login_ip VARCHAR(64) NULL";
  if (!isset($have['last_device'])) $alter[] = "ADD COLUMN last_device VARCHAR(120) NULL";
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

function user_exists(PDO $pdo, int $id): bool {
  $st = $pdo->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
  $st->execute([$id]);
  return (bool)$st->fetch();
}

function set_user_status(PDO $pdo, int $id, string $status): void {
  $st = $pdo->prepare("UPDATE users SET status = ? WHERE id = ? LIMIT 1");
  $st->execute([$status, $id]);
}

function set_user_access(PDO $pdo, int $userId, array $siteIds): void {
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

$pdo = DB::pdo();
$cols = ensure_users_admin_columns($pdo);
$flash = $_SESSION['admin_users_flash'] ?? null;
unset($_SESSION['admin_users_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $_SESSION['admin_users_flash'] = ['type' => 'error', 'message' => 'Security check failed.'];
    header('Location: ' . $base . '/admin/users.php');
    exit;
  }

  try {
    $mode = (string)($_POST['mode'] ?? '');

    if ($mode === 'create') {
      if (!$canManageUsers) throw new RuntimeException('You do not have permission to invite users.');
      $email = trim((string)($_POST['email'] ?? ''));
      $name = trim((string)($_POST['display_name'] ?? ''));
      $role = strtolower(trim((string)($_POST['role'] ?? 'student')));
      $institutionName = trim((string)($_POST['institution_name'] ?? ''));
      if ($email === '') throw new RuntimeException('Email is required.');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
      if (!in_array($role, $allowedRoles, true)) throw new RuntimeException('Choose a valid role.');

      $existing = User::findByEmail($email);
      if ($existing) throw new RuntimeException('A user with that email already exists.');

      $tempPass = bin2hex(random_bytes(6));
      $hash = password_hash($tempPass, PASSWORD_DEFAULT);
      $inviterId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
      $display = $name !== '' ? $name : $email;

      $insertCols = ['email','password_hash','display_name','role','access'];
      $insertVals = [$email, $hash, $display, $role, null];
      if (isset($cols['status'])) {
        $insertCols[] = 'status';
        $insertVals[] = 'invited';
      }
      if (isset($cols['invited_at'])) {
        $insertCols[] = 'invited_at';
        $insertVals[] = date('Y-m-d H:i:s');
      }
      if (isset($cols['invited_by_user_id'])) {
        $insertCols[] = 'invited_by_user_id';
        $insertVals[] = $inviterId;
      }
      if (isset($cols['last_active_at'])) {
        $insertCols[] = 'last_active_at';
        $insertVals[] = null;
      }
      if (isset($cols['institution_name'])) {
        $insertCols[] = 'institution_name';
        $insertVals[] = ($institutionName !== '' ? $institutionName : null);
      }

      $ph = implode(',', array_fill(0, count($insertCols), '?'));
      $sql = "INSERT INTO users (" . implode(',', $insertCols) . ") VALUES ({$ph})";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($insertVals);
      $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'User invited. Temporary password: ' . $tempPass];
    }

    if ($mode === 'inline_role') {
      if (!$canChangeRole) throw new RuntimeException('Only super admins can change roles.');
      $userId = (int)($_POST['user_id'] ?? 0);
      $newRole = strtolower(trim((string)($_POST['role'] ?? '')));
      if ($userId <= 0 || !in_array($newRole, $allowedRoles, true)) throw new RuntimeException('Invalid role change request.');
      $st = $pdo->prepare("UPDATE users SET role=? WHERE id=? LIMIT 1");
      $st->execute([$newRole, $userId]);
      $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'Role updated.'];
    }

    if ($mode === 'inline_status') {
      if (!$canManageUsers) throw new RuntimeException('You do not have permission to change user status.');
      $userId = (int)($_POST['user_id'] ?? 0);
      $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
      if ($userId <= 0 || !in_array($newStatus, $allowedStatuses, true)) throw new RuntimeException('Invalid status change request.');
      if ($userId === (int)($_SESSION['user_id'] ?? 0) && $newStatus !== 'active') {
        throw new RuntimeException('You cannot suspend your own account.');
      }
      set_user_status($pdo, $userId, $newStatus);
      $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'Status updated.'];
    }

    if ($mode === 'bulk_action') {
      if (!$canManageUsers) throw new RuntimeException('You do not have permission for bulk actions.');
      $action = strtolower(trim((string)($_POST['bulk_action'] ?? '')));
      $ids = array_values(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])), fn($v) => $v > 0));
      if (!$ids) throw new RuntimeException('Select at least one user.');

      $myId = (int)($_SESSION['user_id'] ?? 0);
      $ids = array_values(array_filter($ids, fn($id) => $id !== $myId));
      if (!$ids) throw new RuntimeException('No valid users selected.');

      if (in_array($action, $allowedStatuses, true)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$action], $ids);
        $up = $pdo->prepare("UPDATE users SET status=? WHERE id IN ({$ph})");
        $up->execute($params);
        $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'Status updated for selected users.'];
      } elseif (str_starts_with($action, 'role:')) {
        if (!$canChangeRole) throw new RuntimeException('Only super admins can change roles in bulk.');
        $role = substr($action, 5);
        if (!in_array($role, $allowedRoles, true)) throw new RuntimeException('Invalid target role.');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$role], $ids);
        $up = $pdo->prepare("UPDATE users SET role=? WHERE id IN ({$ph})");
        $up->execute($params);
        $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'Role updated for selected users.'];
      } elseif ($action === 'delete') {
        if (!$canChangeRole) throw new RuntimeException('Only super admins can delete users.');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
          $delAcc = $pdo->prepare("DELETE FROM user_site_access WHERE user_id IN ({$ph})");
          $delAcc->execute($ids);
        } catch (\Throwable $e) {
          // table may not exist
        }
        $del = $pdo->prepare("DELETE FROM users WHERE id IN ({$ph})");
        $del->execute($ids);
        $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'Selected users deleted.'];
      } else {
        throw new RuntimeException('Choose a valid bulk action.');
      }
    }

    if ($mode === 'row_action') {
      if (!$canManageUsers) throw new RuntimeException('You do not have permission for this action.');
      $userId = (int)($_POST['user_id'] ?? 0);
      $action = strtolower(trim((string)($_POST['action'] ?? '')));
      if ($userId <= 0 || !user_exists($pdo, $userId)) throw new RuntimeException('User not found.');
      $myId = (int)($_SESSION['user_id'] ?? 0);

      if ($action === 'suspend') {
        if ($userId === $myId) throw new RuntimeException('You cannot suspend your own account.');
        set_user_status($pdo, $userId, 'suspended');
        $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'User suspended.'];
      } elseif ($action === 'activate') {
        set_user_status($pdo, $userId, 'active');
        $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'User activated.'];
      } elseif ($action === 'resend_invite') {
        $st = $pdo->prepare("UPDATE users SET status='invited', invited_at=?, invited_by_user_id=? WHERE id=? LIMIT 1");
        $st->execute([date('Y-m-d H:i:s'), $myId ?: null, $userId]);
        $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'Invite status reset and invite re-sent.'];
      } elseif ($action === 'reset_password') {
        $tempPass = bin2hex(random_bytes(6));
        $hash = password_hash($tempPass, PASSWORD_DEFAULT);
        $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? LIMIT 1");
        $st->execute([$hash, $userId]);
        $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'Temporary password: ' . $tempPass];
      } elseif ($action === 'delete') {
        if (!$canChangeRole) throw new RuntimeException('Only super admins can delete users.');
        if ($userId === $myId) throw new RuntimeException('You cannot delete your own account.');
        try {
          $st = $pdo->prepare("DELETE FROM user_site_access WHERE user_id=?");
          $st->execute([$userId]);
        } catch (\Throwable $e) {
          // table may not exist
        }
        $del = $pdo->prepare("DELETE FROM users WHERE id=? LIMIT 1");
        $del->execute([$userId]);
        $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'User deleted.'];
      } else {
        throw new RuntimeException('Unknown action.');
      }
    }

    if ($mode === 'update_access') {
      if (!$canManageUsers) throw new RuntimeException('You do not have permission to manage site access.');
      $userId = (int)($_POST['user_id'] ?? 0);
      if ($userId <= 0 || !user_exists($pdo, $userId)) throw new RuntimeException('User not found.');
      $siteIds = array_map('intval', (array)($_POST['access_site_ids'] ?? []));
      $validSiteIds = array_map('intval', array_column(Site::all(), 'id'));
      $validSet = array_flip($validSiteIds);
      $siteIds = array_values(array_filter($siteIds, fn($sid) => isset($validSet[$sid])));
      set_user_access($pdo, $userId, $siteIds);
      $_SESSION['admin_users_flash'] = ['type' => 'notice', 'message' => 'User access updated.'];
    }
  } catch (\Throwable $e) {
    $_SESSION['admin_users_flash'] = ['type' => 'error', 'message' => (string)($e->getMessage() ?: 'Action failed.')];
  }

  header('Location: ' . $base . '/admin/users.php');
  exit;
}

$statusExpr = isset($cols['status']) ? "u.status" : "'active'";
$invitedAtExpr = isset($cols['invited_at']) ? "u.invited_at" : "NULL";
$lastActiveExpr = isset($cols['last_active_at']) ? "u.last_active_at" : "u.created_at";
$lastIpExpr = isset($cols['last_login_ip']) ? "u.last_login_ip" : "NULL";
$lastDevExpr = isset($cols['last_device']) ? "u.last_device" : "NULL";
$institutionExpr = isset($cols['institution_name']) ? "u.institution_name" : "NULL";
$allSites = Site::all();
usort($allSites, fn($a, $b) => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
$siteNameById = [];
foreach ($allSites as $siteRow) {
  $sid = (int)($siteRow['id'] ?? 0);
  if ($sid > 0) $siteNameById[$sid] = (string)($siteRow['name'] ?? 'Untitled site');
}

$sql = "
  SELECT
    u.id,
    MAX(u.email) AS email,
    MAX(u.display_name) AS display_name,
    MAX(u.role) AS role,
    MAX(u.created_at) AS created_at,
    MAX({$statusExpr}) AS status,
    MAX({$invitedAtExpr}) AS invited_at,
    MAX({$lastActiveExpr}) AS last_active_at,
    MAX({$lastIpExpr}) AS last_login_ip,
    MAX({$lastDevExpr}) AS last_device,
    COALESCE(NULLIF(MAX({$institutionExpr}), ''), '—') AS institution_label,
    COALESCE(NULLIF(GROUP_CONCAT(DISTINCT usa.site_id ORDER BY usa.site_id SEPARATOR ','), ''), '') AS access_site_ids
  FROM users u
  LEFT JOIN user_site_access usa ON usa.user_id = u.id
  GROUP BY u.id
  ORDER BY COALESCE(MAX({$lastActiveExpr}), MAX(u.created_at)) DESC, MAX(u.created_at) DESC
";
$users = $pdo->query($sql)->fetchAll();

$institutionOptions = [];
foreach ($users as $u) {
  $label = trim((string)($u['institution_label'] ?? ''));
  if ($label === '' || $label === '—') continue;
  $institutionOptions[$label] = true;
}
$institutionOptions = array_keys($institutionOptions);
sort($institutionOptions, SORT_NATURAL | SORT_FLAG_CASE);

$userStats = [
  'total' => count($users),
  'active' => 0,
  'invited' => 0,
  'suspended' => 0,
  'super_admin' => 0,
  'website_admin' => 0,
  'editor' => 0,
  'institution_admin' => 0,
  'student' => 0,
];
foreach ($users as $u) {
  $status = strtolower((string)($u['status'] ?? 'active'));
  if (!in_array($status, $allowedStatuses, true)) $status = 'active';
  if (isset($userStats[$status])) $userStats[$status]++;
  $r = strtolower((string)($u['role'] ?? ''));
  if (isset($userStats[$r])) $userStats[$r]++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Users — NexusCMS Admin</title>
  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    body{margin:0;background:var(--admin-bg);color:var(--admin-text);font:14px/1.4 Arial, Helvetica, sans-serif;}
    a{color:inherit;text-decoration:none}
    .content{padding:14px;display:grid;gap:12px;}
    .panel{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;}
    .panel-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid var(--admin-line);}
    .panel-title{margin:0;font-size:16px;font-weight:700;color:var(--admin-text-strong)}
    .summary{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:8px;padding:10px 12px;}
    .metric{border:1px solid var(--admin-line);border-radius:4px;padding:8px;background:var(--admin-surface-2)}
    .metric-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--admin-muted)}
    .metric-value{margin-top:2px;font-size:20px;font-weight:700;color:var(--admin-text-strong)}
    .metric.active .metric-value{color:var(--admin-success)}
    .metric.warn .metric-value{color:var(--admin-warn)}
    .metric.off .metric-value{color:var(--admin-danger)}

    .notice,.error-banner{margin:0;padding:10px 12px;border-radius:4px;border:1px solid transparent;font-size:13px}
    .notice{border-color:color-mix(in srgb, var(--admin-success) 40%, var(--admin-line));background:color-mix(in srgb, var(--admin-success) 16%, transparent);color:var(--admin-success)}
    .error-banner{border-color:color-mix(in srgb, var(--admin-danger) 40%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 14%, transparent);color:var(--admin-danger)}

    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:0 10px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text-strong);font-size:13px;font-weight:600;cursor:pointer}
    .btn.primary{border-color:color-mix(in srgb, var(--admin-accent) 60%, var(--admin-line));background:var(--admin-accent);color:#fff}
    .btn.small{min-height:28px;padding:0 8px;font-size:12px}
    .btn.ghost{background:transparent}
    .btn.danger{border-color:color-mix(in srgb, var(--admin-danger) 56%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 8%, transparent);color:var(--admin-danger)}
    .btn:disabled{opacity:.55;cursor:not-allowed}

    .filters{display:flex;gap:8px;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid var(--admin-line);background:var(--admin-surface-2)}
    .field{display:flex;align-items:center;gap:8px;padding:0 8px;height:32px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface)}
    .field input,.field select{border:0;outline:0;background:transparent;font:inherit;color:inherit}
    .field input{min-width:160px}

    .bulkbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid var(--admin-line);background:color-mix(in srgb, var(--admin-accent) 8%, transparent)}
    .bulkbar .bulk-count{font-size:12px;color:var(--admin-muted);font-weight:600}

    .table-wrap{overflow:auto}
    table{width:100%;border-collapse:collapse;font-size:13px;background:var(--admin-surface);table-layout:fixed}
    thead th{text-align:left;background:var(--admin-surface-2);border-top:1px solid var(--admin-line);border-bottom:1px solid var(--admin-line);padding:7px 8px;font-weight:700}
    tbody tr{cursor:pointer}
    tbody td{padding:7px 8px;border-bottom:1px solid var(--admin-line);vertical-align:middle}
    tbody tr:hover{background:color-mix(in srgb, var(--admin-accent) 10%, transparent)}
    tbody tr.selected{background:color-mix(in srgb, var(--admin-accent) 14%, transparent)}

    .check-col{width:34px}
    .name-link{font-weight:700;color:var(--admin-text-strong);text-decoration:none}
    .name-link:hover{text-decoration:underline}
    .meta{font-size:12px;color:var(--admin-muted);margin-top:1px}
    .muted{font-size:12px;color:var(--admin-muted)}

    .role-select{height:28px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface);color:var(--admin-text);font:inherit;padding:0 6px;min-width:100px;max-width:100%}
    .role-select:disabled{opacity:.6}

    .row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end}
    .access-menu{position:relative}
    .access-menu summary{list-style:none}
    .access-menu summary::-webkit-details-marker{display:none}
    .access-pop{position:absolute;right:0;top:calc(100% + 4px);min-width:260px;max-width:320px;padding:8px;background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;display:grid;gap:8px;z-index:45}
    .access-grid{max-height:220px;overflow:auto;display:grid;gap:6px;padding-right:2px}
    .access-item{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--admin-text)}
    .access-item input{margin:0}
    .access-actions{display:flex;justify-content:flex-end}
    .row-menu{position:relative}
    .row-menu summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);font-size:15px;line-height:1}
    .row-menu summary::-webkit-details-marker{display:none}
    .row-menu-list{position:absolute;right:0;top:calc(100% + 4px);min-width:170px;padding:6px;background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;display:grid;gap:4px;z-index:40}
    .row-menu-list button{display:block;width:100%;border:0;background:transparent;color:var(--admin-text);font:inherit;font-size:12px;font-weight:600;text-align:left;padding:7px 8px;border-radius:4px;cursor:pointer}
    .row-menu-list button:hover{background:color-mix(in srgb, var(--admin-accent) 12%, transparent)}
    .row-menu-list button.danger{color:var(--admin-danger)}

    .sort-btn{border:0;background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:6px}
    .sort-btn .arrow{font-size:10px;color:var(--admin-muted)}
    .sort-btn.active .arrow{color:var(--admin-text-strong)}

    .badge-you{display:inline-flex;align-items:center;padding:2px 6px;border-radius:999px;font-size:11px;font-weight:700;background:color-mix(in srgb, var(--admin-accent) 20%, transparent);color:var(--admin-accent)}

    .empty{padding:14px;color:var(--admin-muted)}
    th,td{overflow-wrap:anywhere}
    td:nth-child(2){width:18%}
    td:nth-child(3){width:20%}
    td:nth-child(4){width:14%}
    td:nth-child(5),td:nth-child(6),td:nth-child(7){width:14%}

    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:40}
    .modal{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;padding:18px;min-width:320px;max-width:460px}
    .modal h3{margin-top:0;color:var(--admin-text-strong)}
    .modal p{margin:0;color:var(--admin-muted)}
    .modal form{display:grid;gap:12px}
    .modal label{font-weight:700;color:var(--admin-text-strong)}
    .modal input,.modal select{width:100%;padding:8px;border-radius:4px;border:1px solid var(--admin-line);background:var(--admin-surface-2);color:var(--admin-text)}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:4px}
    .detail-grid{display:grid;grid-template-columns:140px 1fr;gap:6px 10px;margin:12px 0 2px}
    .detail-label{font-size:12px;color:var(--admin-muted);font-weight:700}
    .detail-value{font-size:13px;color:var(--admin-text)}
    #manageUserModal .modal{width:min(760px, calc(100vw - 28px));max-width:760px;padding:20px 22px}
    #manageUserModal .manage-section{border:1px solid var(--admin-line);border-radius:6px;padding:12px 14px;margin-top:10px;background:color-mix(in srgb, var(--admin-surface-2) 65%, transparent)}
    #manageUserModal .manage-section-title{display:block;margin:0 0 8px 0;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--admin-muted)}
    #manageUserModal .detail-grid{grid-template-columns:170px 1fr;margin:0}
    #manageUserModal .access-grid{grid-template-columns:repeat(2,minmax(0,1fr));max-height:240px;gap:8px 12px}
    #manageUserModal .access-item{display:grid;grid-template-columns:16px 1fr;align-items:start;column-gap:8px}
    #manageUserModal .access-item input{margin-top:2px}
    #manageUserModal .modal-actions{margin-top:10px}

    @media (max-width: 1100px){
      .summary{grid-template-columns:repeat(3,minmax(120px,1fr));}
    }
    @media (max-width: 960px){
      .summary{grid-template-columns:repeat(2,minmax(120px,1fr));}
      .field input{min-width:140px}
      #manageUserModal .modal{width:min(96vw, 760px);padding:14px}
      #manageUserModal .detail-grid{grid-template-columns:1fr}
      #manageUserModal .access-grid{grid-template-columns:1fr}
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="content">
    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <p class="<?= ($flash['type'] ?? 'notice') === 'error' ? 'error-banner' : 'notice' ?>"><?= Security::e((string)$flash['message']) ?></p>
    <?php endif; ?>

    <section class="panel" aria-label="Users overview">
      <div class="panel-head">
        <h1 class="panel-title">Users Overview</h1>
        <div style="display:flex;gap:8px;align-items:center;">
          <button type="button" class="btn" id="openRoleHelp">Role help</button>
          <a class="btn primary" href="<?= $base ?>/admin/user_new.php">+ New User</a>
        </div>
      </div>
      <div class="summary">
        <div class="metric"><div class="metric-label">Total Users</div><div class="metric-value"><?= (int)$userStats['total'] ?></div></div>
        <div class="metric"><div class="metric-label">Super Admins</div><div class="metric-value"><?= (int)$userStats['super_admin'] ?></div></div>
        <div class="metric"><div class="metric-label">Website Admins</div><div class="metric-value"><?= (int)$userStats['website_admin'] ?></div></div>
      </div>
    </section>

    <section class="panel" aria-label="All users">
      <div class="panel-head">
        <h2 class="panel-title">All Users</h2>
      </div>

      <div class="filters">
        <label class="field" aria-label="Search users"><span>Search</span><input id="userSearch" type="search" placeholder="Name, email, or university/college"></label>
        <label class="field" aria-label="Filter by role">
          <span>Role</span>
          <select id="userRoleFilter">
            <option value="">All roles</option>
            <?php foreach ($allowedRoles as $r): ?>
              <option value="<?= Security::e($r) ?>"><?= Security::e(role_label($r)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field" aria-label="Filter by university or college">
          <span>University / College</span>
          <select id="userInstitutionFilter">
            <option value="">All universities/colleges</option>
            <?php foreach ($institutionOptions as $institution): ?>
              <option value="<?= Security::e($institution) ?>"><?= Security::e($institution) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn" type="button" id="resetFilters">Reset</button>
      </div>

      <form id="bulkForm" method="post" class="bulkbar">
        <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="mode" value="bulk_action">
        <span class="bulk-count" id="bulkCount">0 selected</span>
        <label class="field" style="min-width:250px;">
          <span>Bulk action</span>
          <select id="bulkAction" name="bulk_action">
            <option value="">Choose action</option>
            <option value="active">Set Active</option>
            <option value="invited">Set Invited</option>
            <option value="suspended">Set Suspended</option>
            <?php if ($canChangeRole): ?>
              <option value="role:student">Change role to Student</option>
              <option value="role:institution_admin">Change role to Institution Admin</option>
              <option value="role:editor">Change role to Editor</option>
              <option value="role:website_admin">Change role to Website Admin</option>
              <option value="role:super_admin">Change role to Super Admin</option>
            <?php endif; ?>
            <?php if ($canChangeRole): ?>
              <option value="delete">Delete selected</option>
            <?php endif; ?>
          </select>
        </label>
        <button type="submit" class="btn small" id="applyBulk" disabled>Apply</button>
      </form>

      <?php if (!$users): ?>
        <div class="empty">No users found.</div>
      <?php else: ?>
      <div class="table-wrap">
        <table id="usersTable" role="table">
          <thead>
            <tr>
              <th class="check-col"><input type="checkbox" id="selectAllUsers" aria-label="Select all users"></th>
              <th style="min-width:220px;"><button type="button" class="sort-btn" data-sort="name">User <span class="arrow">↕</span></button></th>
              <th style="min-width:220px;">Email</th>
              <th style="min-width:170px;"><button type="button" class="sort-btn" data-sort="role">Role <span class="arrow">↕</span></button></th>
              <th style="min-width:170px;"><button type="button" class="sort-btn active" data-sort="last_active">Last Active <span class="arrow">↓</span></button></th>
              <th style="min-width:150px;"><button type="button" class="sort-btn" data-sort="joined">Date Joined <span class="arrow">↕</span></button></th>
              <th style="min-width:180px;">University / College</th>
              <th style="min-width:240px;text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $uRow): ?>
              <?php
                $name = trim((string)($uRow['display_name'] ?? ''));
                if ($name === '') $name = (string)explode('@', (string)($uRow['email'] ?? 'unknown@user'))[0];
                $status = strtolower(trim((string)($uRow['status'] ?? 'active')));
                if (!in_array($status, $allowedStatuses, true)) $status = 'active';
                $isCurrent = isset($_SESSION['user_id']) && (int)$uRow['id'] === (int)$_SESSION['user_id'];
                $lastActiveRaw = (string)($uRow['last_active_at'] ?? '');
                $joinedRaw = (string)($uRow['created_at'] ?? '');
                $lastActiveTs = strtotime($lastActiveRaw ?: $joinedRaw) ?: 0;
                $joinedTs = strtotime($joinedRaw) ?: 0;
                $institutionLabel = trim((string)($uRow['institution_label'] ?? '—'));
                if ($institutionLabel === '') $institutionLabel = '—';
                $accessSiteIds = array_values(array_filter(array_map('intval', explode(',', (string)($uRow['access_site_ids'] ?? ''))), fn($v) => $v > 0));
                $accessSet = array_flip($accessSiteIds);
                $accessNames = [];
                foreach ($accessSiteIds as $sid) {
                  if (isset($siteNameById[$sid])) $accessNames[] = $siteNameById[$sid];
                }
                $accessLabel = $accessNames ? implode(', ', $accessNames) : 'No site access';
                $joinedDisplay = ($joinedRaw !== '' && $joinedTs > 0) ? date('Y-m-d', (int)$joinedTs) : '—';
                $lastActiveDisplay = relative_time($lastActiveRaw ?: $joinedRaw);
                $lastActiveRawDisplay = ($lastActiveRaw !== '' ? $lastActiveRaw : $joinedRaw);
                $deviceIpLabel = trim((string)($uRow['last_device'] ?? ''));
                $ipLabel = trim((string)($uRow['last_login_ip'] ?? ''));
                if ($deviceIpLabel !== '' && $ipLabel !== '') $deviceIpLabel .= ' · ' . $ipLabel;
                elseif ($deviceIpLabel === '' && $ipLabel !== '') $deviceIpLabel = $ipLabel;
                if ($deviceIpLabel === '') $deviceIpLabel = '—';
              ?>
              <tr
                data-user-id="<?= (int)$uRow['id'] ?>"
                data-search="<?= Security::e(strtolower($name . ' ' . (string)$uRow['email'] . ' ' . $institutionLabel)) ?>"
                data-role="<?= Security::e(strtolower((string)$uRow['role'])) ?>"
                data-status="<?= Security::e($status) ?>"
                data-institutions="<?= Security::e(strtolower($institutionLabel)) ?>"
                data-last-active-ts="<?= (int)$lastActiveTs ?>"
                data-joined-ts="<?= (int)$joinedTs ?>"
              >
                <td class="check-col">
                  <input type="checkbox" class="row-check" name="user_ids[]" value="<?= (int)$uRow['id'] ?>" form="bulkForm" aria-label="Select <?= Security::e($name) ?>" <?= $isCurrent ? 'disabled' : '' ?>>
                </td>
                <td>
                  <a href="#" class="name-link" data-manage-user
                     data-user-id="<?= (int)$uRow['id'] ?>"
                     data-user-name="<?= Security::e($name) ?>"
                     data-user-email="<?= Security::e((string)$uRow['email']) ?>"
                     data-user-role="<?= Security::e((string)$uRow['role']) ?>"
                     data-user-status="<?= Security::e($status) ?>"
                     data-user-status-label="<?= Security::e(ucfirst($status)) ?>"
                     data-user-joined="<?= Security::e($joinedDisplay) ?>"
                     data-user-last-active="<?= Security::e($lastActiveDisplay) ?>"
                     data-user-last-active-raw="<?= Security::e($lastActiveRawDisplay) ?>"
                     data-user-institution="<?= Security::e($institutionLabel) ?>"
                     data-user-device-ip="<?= Security::e($deviceIpLabel) ?>"
                     data-user-access="<?= Security::e($accessLabel) ?>"
                     data-user-access-ids="<?= Security::e(implode(',', $accessSiteIds)) ?>"
                     onclick="return false;"><?= Security::e($name) ?></a>
                  <div class="meta">
                    <?= $isCurrent ? '<span class="badge-you">You</span>' : '&nbsp;' ?>
                  </div>
                </td>
                <td>
                  <div class="muted"><?= Security::e((string)$uRow['email']) ?></div>
                  <?php if (!empty($uRow['last_device']) || !empty($uRow['last_login_ip'])): ?>
                    <div class="meta"><?= Security::e(trim((string)($uRow['last_device'] ?? ''))) ?><?= !empty($uRow['last_device']) && !empty($uRow['last_login_ip']) ? ' · ' : '' ?><?= Security::e(trim((string)($uRow['last_login_ip'] ?? ''))) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" class="role-form">
                    <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="mode" value="inline_role">
                    <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                    <select class="role-select" name="role" <?= $canChangeRole ? '' : 'disabled' ?> aria-label="Change role for <?= Security::e($name) ?>">
                      <?php foreach ($allowedRoles as $r): ?>
                        <option value="<?= Security::e($r) ?>" <?= strtolower((string)$uRow['role']) === $r ? 'selected' : '' ?>><?= Security::e(role_label($r)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td>
                  <div title="<?= Security::e($lastActiveRawDisplay) ?>"><?= Security::e($lastActiveDisplay) ?></div>
                  <div class="meta">Recent activity</div>
                </td>
                <td>
                  <div><?= Security::e($joinedDisplay) ?></div>
                </td>
                <td><div class="muted"><?= Security::e($institutionLabel) ?></div></td>
                <td>
                  <div class="row-actions">
                    <button class="btn small primary" type="button" data-manage-user
                      data-user-id="<?= (int)$uRow['id'] ?>"
                      data-user-name="<?= Security::e($name) ?>"
                      data-user-email="<?= Security::e((string)$uRow['email']) ?>"
                      data-user-role="<?= Security::e((string)$uRow['role']) ?>"
                      data-user-status="<?= Security::e($status) ?>"
                      data-user-status-label="<?= Security::e(ucfirst($status)) ?>"
                      data-user-joined="<?= Security::e($joinedDisplay) ?>"
                      data-user-last-active="<?= Security::e($lastActiveDisplay) ?>"
                      data-user-last-active-raw="<?= Security::e($lastActiveRawDisplay) ?>"
                      data-user-institution="<?= Security::e($institutionLabel) ?>"
                      data-user-device-ip="<?= Security::e($deviceIpLabel) ?>"
                      data-user-access="<?= Security::e($accessLabel) ?>"
                      data-user-access-ids="<?= Security::e(implode(',', $accessSiteIds)) ?>">Manage</button>
                    <details class="access-menu">
                      <summary class="btn small ghost" aria-label="Edit site access">Access</summary>
                      <div class="access-pop">
                        <form method="post">
                          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                          <input type="hidden" name="mode" value="update_access">
                          <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                          <div class="access-grid">
                            <?php if (!$allSites): ?>
                              <div class="muted">No sites available.</div>
                            <?php else: ?>
                              <?php foreach ($allSites as $site): ?>
                                <?php $sid = (int)($site['id'] ?? 0); ?>
                                <label class="access-item">
                                  <input type="checkbox" name="access_site_ids[]" value="<?= $sid ?>" <?= isset($accessSet[$sid]) ? 'checked' : '' ?>>
                                  <span><?= Security::e((string)($site['name'] ?? 'Untitled site')) ?></span>
                                </label>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </div>
                          <div class="access-actions">
                            <button type="submit" class="btn small">Save access</button>
                          </div>
                        </form>
                      </div>
                    </details>
                    <details class="row-menu">
                      <summary aria-label="More actions">⋮</summary>
                      <div class="row-menu-list">
                        <form method="post">
                          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                          <input type="hidden" name="mode" value="row_action">
                          <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                          <button type="submit" name="action" value="activate">Activate</button>
                        </form>
                        <form method="post">
                          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                          <input type="hidden" name="mode" value="row_action">
                          <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                          <button type="submit" name="action" value="suspend">Suspend</button>
                        </form>
                        <form method="post">
                          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                          <input type="hidden" name="mode" value="row_action">
                          <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                          <button type="submit" name="action" value="resend_invite">Resend Invite</button>
                        </form>
                        <form method="post">
                          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                          <input type="hidden" name="mode" value="row_action">
                          <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                          <button type="submit" name="action" value="reset_password">Reset Password</button>
                        </form>
                        <?php if ($canChangeRole): ?>
                          <form method="post">
                            <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                            <input type="hidden" name="mode" value="row_action">
                            <input type="hidden" name="user_id" value="<?= (int)$uRow['id'] ?>">
                            <button class="danger" type="submit" name="action" value="delete">Delete</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </details>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </main>

  <div class="modal-backdrop" id="manageUserModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="manageUserTitle">
      <h3 id="manageUserTitle">Manage user</h3>
      <p id="manageUserMeta"></p>
      <div class="manage-section">
        <span class="manage-section-title">User details</span>
        <div class="detail-grid" aria-label="User details">
          <div class="detail-label">Status</div><div class="detail-value" id="manageUserStatus">—</div>
          <div class="detail-label">Role</div><div class="detail-value" id="manageUserRoleLabel">—</div>
          <div class="detail-label">Date joined</div><div class="detail-value" id="manageUserJoined">—</div>
          <div class="detail-label">Last active</div><div class="detail-value" id="manageUserLastActive">—</div>
          <div class="detail-label">University / College</div><div class="detail-value" id="manageUserInstitution">—</div>
          <div class="detail-label">Device / IP</div><div class="detail-value" id="manageUserDeviceIp">—</div>
          <div class="detail-label">Site access</div><div class="detail-value" id="manageUserAccess">—</div>
        </div>
      </div>
      <form method="post" id="manageUserAccessForm" class="manage-section">
        <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="mode" value="update_access">
        <input type="hidden" name="user_id" id="manageUserAccessId" value="">
        <span class="manage-section-title">Site access</span>
        <div class="access-grid" id="manageUserAccessGrid">
          <?php if (!$allSites): ?>
            <div class="muted">No sites available.</div>
          <?php else: ?>
            <?php foreach ($allSites as $site): ?>
              <?php $sid = (int)($site['id'] ?? 0); ?>
              <label class="access-item">
                <input type="checkbox" name="access_site_ids[]" value="<?= $sid ?>" data-manage-access-check>
                <span><?= Security::e((string)($site['name'] ?? 'Untitled site')) ?></span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="modal-actions">
          <button type="submit" class="btn">Save access</button>
        </div>
      </form>
      <form method="post" id="manageUserRoleForm" class="manage-section" <?= $canChangeRole ? '' : 'style="display:none"' ?>>
        <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="mode" value="inline_role">
        <input type="hidden" name="user_id" id="manageUserId" value="">
        <span class="manage-section-title">Role</span>
        <label for="manageUserRole">Role</label>
        <select id="manageUserRole" name="role">
          <?php foreach ($allowedRoles as $r): ?>
            <option value="<?= Security::e($r) ?>"><?= Security::e(role_label($r)) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="modal-actions">
          <button type="button" class="btn" id="cancelManageUser">Close</button>
          <button type="submit" class="btn primary">Save role</button>
        </div>
      </form>
      <?php if (!$canChangeRole): ?>
        <div class="modal-actions" style="justify-content:flex-start;">
          <span class="muted">Only super admins can change roles.</span>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" id="cancelManageUser">Close</button>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="modal-backdrop" id="roleHelpModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="roleHelpTitle">
      <h3 id="roleHelpTitle">User roles</h3>
      <p><strong>Super Admin:</strong> full system access across all websites, including role changes.</p>
      <p><strong>Website Admin:</strong> manage websites, users, media, and settings.</p>
      <p><strong>Editor:</strong> create and publish content.</p>
      <p><strong>Institution Admin:</strong> manage assigned users and institution workflows.</p>
      <p><strong>Student:</strong> learner-level access.</p>
      <div class="modal-actions">
        <button type="button" class="btn" id="closeRoleHelp">Close</button>
      </div>
    </div>
  </div>

  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
      const searchEl = document.getElementById('userSearch');
      const roleEl = document.getElementById('userRoleFilter');
      const institutionEl = document.getElementById('userInstitutionFilter');
      const resetEl = document.getElementById('resetFilters');
      const rows = Array.from(document.querySelectorAll('#usersTable tbody tr'));
      const sortButtons = Array.from(document.querySelectorAll('.sort-btn'));
      const selectAll = document.getElementById('selectAllUsers');
      const rowChecks = Array.from(document.querySelectorAll('.row-check'));
      const bulkCount = document.getElementById('bulkCount');
      const bulkAction = document.getElementById('bulkAction');
      const applyBulk = document.getElementById('applyBulk');
      const bulkForm = document.getElementById('bulkForm');
      let sortKey = 'last_active';
      let sortDir = 'desc';

      function updateBulkState() {
        if (!bulkCount || !bulkAction || !applyBulk) return;
        const selected = rowChecks.filter((c) => c.checked).length;
        bulkCount.textContent = selected + ' selected';
        applyBulk.disabled = selected === 0 || !(bulkAction.value || '').trim();
        const visibleChecks = rowChecks.filter((c) => c.closest('tr')?.style.display !== 'none' && !c.disabled);
        const visibleSelected = visibleChecks.filter((c) => c.checked).length;
        if (selectAll) {
          selectAll.checked = visibleChecks.length > 0 && visibleSelected === visibleChecks.length;
          selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleChecks.length;
        }
        rows.forEach((row) => row.classList.toggle('selected', !!row.querySelector('.row-check:checked')));
      }

      function applyFilters() {
        const q = (searchEl?.value || '').trim().toLowerCase();
        const role = (roleEl?.value || '').trim().toLowerCase();
        const institution = (institutionEl?.value || '').trim().toLowerCase();
        rows.forEach((row) => {
          const hay = row.getAttribute('data-search') || '';
          const rowRole = row.getAttribute('data-role') || '';
          const rowInstitutions = row.getAttribute('data-institutions') || '';
          const matchQ = q === '' || hay.includes(q);
          const matchRole = role === '' || role === rowRole;
          const matchInstitution = institution === '' || rowInstitutions.includes(institution);
          row.style.display = (matchQ && matchRole && matchInstitution) ? '' : 'none';
        });
        updateBulkState();
      }

      function sortRows() {
        const tbody = document.querySelector('#usersTable tbody');
        if (!tbody) return;
        const sorted = [...rows].sort((a, b) => {
          if (sortKey === 'name') {
            const av = (a.querySelector('.name-link')?.textContent || '').toLowerCase();
            const bv = (b.querySelector('.name-link')?.textContent || '').toLowerCase();
            return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
          }
          if (sortKey === 'role') {
            const av = (a.getAttribute('data-role') || '').toLowerCase();
            const bv = (b.getAttribute('data-role') || '').toLowerCase();
            return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
          }
          const key = sortKey === 'joined' ? 'data-joined-ts' : 'data-last-active-ts';
          const av = parseInt(a.getAttribute(key) || '0', 10);
          const bv = parseInt(b.getAttribute(key) || '0', 10);
          return sortDir === 'asc' ? av - bv : bv - av;
        });
        sorted.forEach((r) => tbody.appendChild(r));
      }

      sortButtons.forEach((btn) => {
        btn.addEventListener('click', function(){
          const key = btn.getAttribute('data-sort') || 'last_active';
          if (sortKey === key) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
          else {
            sortKey = key;
            sortDir = (key === 'name' || key === 'role') ? 'asc' : 'desc';
          }
          sortButtons.forEach((b) => {
            b.classList.toggle('active', b === btn);
            const arrow = b.querySelector('.arrow');
            if (!arrow) return;
            arrow.textContent = (b === btn) ? (sortDir === 'asc' ? '↑' : '↓') : '↕';
          });
          sortRows();
        });
      });

      searchEl?.addEventListener('input', applyFilters);
      roleEl?.addEventListener('change', applyFilters);
      institutionEl?.addEventListener('change', applyFilters);
      resetEl?.addEventListener('click', function(){
        if (searchEl) searchEl.value = '';
        if (roleEl) roleEl.value = '';
        if (institutionEl) institutionEl.value = '';
        applyFilters();
      });

      rowChecks.forEach((cb) => cb.addEventListener('change', updateBulkState));
      selectAll?.addEventListener('change', function(){
        const shouldCheck = !!selectAll.checked;
        rowChecks.forEach((cb) => {
          if (cb.disabled) return;
          if (cb.closest('tr')?.style.display === 'none') return;
          cb.checked = shouldCheck;
        });
        updateBulkState();
      });
      bulkAction?.addEventListener('change', updateBulkState);
      bulkForm?.addEventListener('submit', function(e){
        const action = (bulkAction?.value || '').trim();
        if (!action) { e.preventDefault(); return; }
        if (action === 'delete' && !confirm('Delete selected users? This action cannot be undone.')) e.preventDefault();
      });

      document.querySelectorAll('.role-form .role-select').forEach((sel) => {
        sel.addEventListener('change', function(){
          const form = sel.closest('form');
          if (form && !sel.disabled) form.submit();
        });
      });
      document.querySelectorAll('.row-menu-list form').forEach((form) => {
        form.addEventListener('submit', function(e){
          const action = (form.querySelector('button[type="submit"]')?.value || '').toLowerCase();
          if (action === 'delete' && !confirm('Delete this user? This action cannot be undone.')) e.preventDefault();
        });
      });
      document.addEventListener('click', function(e){
        document.querySelectorAll('.row-menu[open]').forEach((menu) => {
          if (!menu.contains(e.target)) menu.open = false;
        });
        document.querySelectorAll('.access-menu[open]').forEach((menu) => {
          if (!menu.contains(e.target)) menu.open = false;
        });
      });

      const manageModal = document.getElementById('manageUserModal');
      const manageMeta = document.getElementById('manageUserMeta');
      const manageId = document.getElementById('manageUserId');
      const manageRole = document.getElementById('manageUserRole');
      const manageStatus = document.getElementById('manageUserStatus');
      const manageRoleLabel = document.getElementById('manageUserRoleLabel');
      const manageJoined = document.getElementById('manageUserJoined');
      const manageLastActive = document.getElementById('manageUserLastActive');
      const manageInstitution = document.getElementById('manageUserInstitution');
      const manageDeviceIp = document.getElementById('manageUserDeviceIp');
      const manageAccess = document.getElementById('manageUserAccess');
      const manageAccessId = document.getElementById('manageUserAccessId');
      const manageAccessChecks = Array.from(document.querySelectorAll('[data-manage-access-check]'));
      const closeManage = document.getElementById('cancelManageUser');
      document.querySelectorAll('[data-manage-user]').forEach((btn) => {
        btn.addEventListener('click', function(){
          if (!manageModal) return;
          const id = btn.getAttribute('data-user-id') || '';
          const name = btn.getAttribute('data-user-name') || 'User';
          const email = btn.getAttribute('data-user-email') || '';
          const role = btn.getAttribute('data-user-role') || '';
          const statusLabel = btn.getAttribute('data-user-status-label') || '—';
          const joined = btn.getAttribute('data-user-joined') || '—';
          const lastActive = btn.getAttribute('data-user-last-active') || '—';
          const lastActiveRaw = btn.getAttribute('data-user-last-active-raw') || '';
          const institution = btn.getAttribute('data-user-institution') || '—';
          const deviceIp = btn.getAttribute('data-user-device-ip') || '—';
          const access = btn.getAttribute('data-user-access') || '—';
          const accessIdsRaw = btn.getAttribute('data-user-access-ids') || '';
          const accessIds = new Set(accessIdsRaw.split(',').map((v) => v.trim()).filter((v) => v !== ''));
          if (manageMeta) manageMeta.textContent = name + (email ? ' (' + email + ')' : '');
          if (manageId) manageId.value = id;
          if (manageAccessId) manageAccessId.value = id;
          if (manageRole) manageRole.value = role;
          if (manageStatus) manageStatus.textContent = statusLabel;
          if (manageRoleLabel) manageRoleLabel.textContent = role.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
          if (manageJoined) manageJoined.textContent = joined;
          if (manageLastActive) {
            manageLastActive.textContent = lastActive;
            if (lastActiveRaw) manageLastActive.title = lastActiveRaw;
          }
          if (manageInstitution) manageInstitution.textContent = institution;
          if (manageDeviceIp) manageDeviceIp.textContent = deviceIp;
          if (manageAccess) manageAccess.textContent = access;
          manageAccessChecks.forEach((cb) => {
            cb.checked = accessIds.has(String(cb.value));
          });
          manageModal.style.display = 'flex';
        });
      });
      closeManage?.addEventListener('click', () => { if (manageModal) manageModal.style.display = 'none'; });
      manageModal?.addEventListener('click', (e) => { if (e.target === manageModal) manageModal.style.display = 'none'; });

      rows.forEach((row) => {
        row.addEventListener('click', function(e){
          if (e.target.closest('a,button,input,select,summary,details,form,label')) return;
          const trigger = row.querySelector('[data-manage-user]');
          if (trigger) trigger.click();
        });
      });

      const roleHelp = document.getElementById('roleHelpModal');
      document.getElementById('openRoleHelp')?.addEventListener('click', () => { if (roleHelp) roleHelp.style.display = 'flex'; });
      document.getElementById('closeRoleHelp')?.addEventListener('click', () => { if (roleHelp) roleHelp.style.display = 'none'; });
      roleHelp?.addEventListener('click', (e) => { if (e.target === roleHelp) roleHelp.style.display = 'none'; });

      sortRows();
      applyFilters();
      updateBulkState();
    })();
  </script>
</body>
</html>
