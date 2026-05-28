<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\PageFlag;
use NexusCMS\Models\Site;

$base = base_path();

function ensure_site_status_column(\PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM sites")->fetchAll(\PDO::FETCH_COLUMN);
    $have = array_flip($cols ?: []);
    if (!isset($have['status'])) {
      $pdo->exec("ALTER TABLE sites ADD COLUMN status ENUM('live','draft','disabled') NULL DEFAULT NULL");
    }
  } catch (\Throwable $e) {
    // Best effort. Table can still work with derived status.
  }
}

function site_status_value(array $site): string {
  $raw = strtolower(trim((string)($site['status'] ?? '')));
  if (in_array($raw, ['live', 'draft', 'disabled'], true)) return $raw;
  return ((int)($site['_published'] ?? 0) > 0) ? 'live' : 'draft';
}

function build_unique_site_slug(\PDO $pdo, string $baseSlug): string {
  $slug = preg_replace('~[^a-z0-9-]+~', '-', strtolower(trim($baseSlug)));
  $slug = trim((string)$slug, '-');
  if ($slug === '') $slug = 'site-copy';
  $candidate = $slug;
  $n = 2;
  while (true) {
    $st = $pdo->prepare("SELECT id FROM sites WHERE slug = ? LIMIT 1");
    $st->execute([$candidate]);
    if (!$st->fetch()) return $candidate;
    $candidate = $slug . '-' . $n;
    $n++;
  }
}

function duplicate_site(\PDO $pdo, int $siteId): ?int {
  $st = $pdo->prepare("SELECT * FROM sites WHERE id = ? LIMIT 1");
  $st->execute([$siteId]);
  $site = $st->fetch();
  if (!$site) return null;

  $newName = trim((string)($site['name'] ?? 'Untitled')) . ' Copy';
  $newSlug = build_unique_site_slug($pdo, ((string)($site['slug'] ?? 'site')) . '-copy');
  $newDescription = (string)($site['description'] ?? '');
  $newStatus = strtolower(trim((string)($site['status'] ?? 'draft')));
  if (!in_array($newStatus, ['live', 'draft', 'disabled'], true)) $newStatus = 'draft';

  $cols = $pdo->query("SHOW COLUMNS FROM sites")->fetchAll(\PDO::FETCH_COLUMN);
  $hasStatus = in_array('status', $cols ?: [], true);
  if ($hasStatus) {
    $ins = $pdo->prepare("INSERT INTO sites (name, slug, description, status) VALUES (?,?,?,?)");
    $ins->execute([$newName, $newSlug, $newDescription, $newStatus]);
  } else {
    $ins = $pdo->prepare("INSERT INTO sites (name, slug, description) VALUES (?,?,?)");
    $ins->execute([$newName, $newSlug, $newDescription]);
  }
  return (int)$pdo->lastInsertId();
}

function delete_sites(\PDO $pdo, array $siteIds): int {
  if (!$siteIds) return 0;
  $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
  $siteIds = array_values(array_filter($siteIds, fn($v) => $v > 0));
  if (!$siteIds) return 0;

  $ph = implode(',', array_fill(0, count($siteIds), '?'));
  $tables = [
    'pages',
    'user_site_access',
    'analytics_events',
    'analytics_sessions',
    'analytics_visitors',
    'analytics_referrers_daily',
    'analytics_pages_daily',
    'analytics_ip_daily',
  ];
  foreach ($tables as $t) {
    try {
      $del = $pdo->prepare("DELETE FROM {$t} WHERE site_id IN ({$ph})");
      $del->execute($siteIds);
    } catch (\Throwable $e) {
      // Table may not exist in every deployment.
    }
  }

  $delSites = $pdo->prepare("DELETE FROM sites WHERE id IN ({$ph})");
  $delSites->execute($siteIds);
  return (int)$delSites->rowCount();
}

$pdo = DB::pdo();
ensure_site_status_column($pdo);
$flash = $_SESSION['admin_sites_flash'] ?? null;
unset($_SESSION['admin_sites_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $_SESSION['admin_sites_flash'] = ['type' => 'error', 'message' => 'Security check failed.'];
    header('Location: ' . $base . '/admin/index.php');
    exit;
  }

  $mode = (string)($_POST['mode'] ?? '');
  $selectedIds = array_values(array_filter(array_map('intval', (array)($_POST['site_ids'] ?? [])), fn($v) => $v > 0));
  try {
    if ($mode === 'bulk_action') {
      $action = strtolower(trim((string)($_POST['bulk_action'] ?? '')));
      if (!$selectedIds) {
        throw new \RuntimeException('Select at least one site.');
      }
      if (in_array($action, ['live', 'draft', 'disabled'], true)) {
        $ph = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([$action], $selectedIds);
        $up = $pdo->prepare("UPDATE sites SET status = ? WHERE id IN ({$ph})");
        $up->execute($params);
        $_SESSION['admin_sites_flash'] = ['type' => 'notice', 'message' => 'Status updated for selected sites.'];
      } elseif ($action === 'delete') {
        $pdo->beginTransaction();
        $count = delete_sites($pdo, $selectedIds);
        $pdo->commit();
        $_SESSION['admin_sites_flash'] = ['type' => 'notice', 'message' => "Deleted {$count} site(s)."];
      } else {
        throw new \RuntimeException('Choose a valid bulk action.');
      }
    } elseif ($mode === 'row_status') {
      $siteId = (int)($_POST['site_id'] ?? 0);
      $status = strtolower(trim((string)($_POST['status'] ?? '')));
      if ($siteId <= 0 || !in_array($status, ['live', 'draft', 'disabled'], true)) {
        throw new \RuntimeException('Invalid status change request.');
      }
      $up = $pdo->prepare("UPDATE sites SET status = ? WHERE id = ? LIMIT 1");
      $up->execute([$status, $siteId]);
      $_SESSION['admin_sites_flash'] = ['type' => 'notice', 'message' => 'Site status updated.'];
    } elseif ($mode === 'row_action') {
      $siteId = (int)($_POST['site_id'] ?? 0);
      $action = strtolower(trim((string)($_POST['action'] ?? '')));
      if ($siteId <= 0) throw new \RuntimeException('Invalid site action request.');
      if ($action === 'duplicate') {
        $newId = duplicate_site($pdo, $siteId);
        $_SESSION['admin_sites_flash'] = ['type' => 'notice', 'message' => $newId ? 'Site duplicated.' : 'Site not found.'];
      } elseif ($action === 'archive') {
        $up = $pdo->prepare("UPDATE sites SET status = 'disabled' WHERE id = ? LIMIT 1");
        $up->execute([$siteId]);
        $_SESSION['admin_sites_flash'] = ['type' => 'notice', 'message' => 'Site archived.'];
      } elseif ($action === 'delete') {
        $pdo->beginTransaction();
        $count = delete_sites($pdo, [$siteId]);
        $pdo->commit();
        $_SESSION['admin_sites_flash'] = ['type' => 'notice', 'message' => $count ? 'Site deleted.' : 'Site not found.'];
      } else {
        throw new \RuntimeException('Unknown site action.');
      }
    }
  } catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['admin_sites_flash'] = ['type' => 'error', 'message' => $e->getMessage() ?: 'Action failed.'];
  }
  header('Location: ' . $base . '/admin/index.php');
  exit;
}

$sites = Site::all();

foreach ($sites as &$s) {
  $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM pages WHERE site_id = ? AND status = 'published'");
  $stmt->execute([(int)$s['id']]);
  $s['_published'] = (int)$stmt->fetchColumn();
}
unset($s);

$currentUser = null;
if (isset($_SESSION['user_id'])) {
  $stmt = DB::pdo()->prepare("SELECT id, email, display_name, role FROM users WHERE id = ? LIMIT 1");
  $stmt->execute([(int)$_SESSION['user_id']]);
  $currentUser = $stmt->fetch();
}

$userName = trim((string)($currentUser['display_name'] ?? $currentUser['email'] ?? $_SESSION['username'] ?? 'Administrator'));
if ($userName === '') $userName = 'Administrator';
$rawRole = strtolower(trim((string)($currentUser['role'] ?? $_SESSION['user_role'] ?? '')));
$canManageUsersNav = role_level($rawRole) >= role_level('website_admin');
$roleMap = [
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
$topbarRoleLabel = $roleMap[$rawRole] ?? ($rawRole !== '' ? ucwords(str_replace('_', ' ', $rawRole)) : 'Administrator');
$themeEndpoint = $base . '/admin/theme.php';
$csrfToken = Security::csrfToken();
$themeIsLight = ui_theme_is_light();
$notificationCount = $currentUser ? PageFlag::inboxCountForUser((int)($currentUser['id'] ?? 0), (string)($currentUser['role'] ?? ''), (array)($_SESSION['site_access'] ?? [])) : 0;

function normalize_status(array $site): string {
  return site_status_value($site);
}

function domain_meta(array $site, string $base): array {
  $domain = trim((string)($site['domain'] ?? $site['primary_domain'] ?? ''));
  $slug = trim((string)($site['slug'] ?? ''));

  if ($domain !== '') {
    $url = preg_match('~^https?://~i', $domain) ? $domain : 'https://' . $domain;
    return ['display' => $domain, 'url' => $url];
  }

  if ($slug !== '') {
    $url = rtrim($base, '/') . '/s/' . rawurlencode($slug) . '/home';
    return ['display' => '/s/' . $slug, 'url' => $url];
  }

  return ['display' => 'n/a', 'url' => rtrim($base, '/') . '/'];
}

function updated_display(array $site): string {
  $raw = (string)($site['updated_at'] ?? $site['created_at'] ?? '');
  if ($raw === '') return 'n/a';
  $ts = strtotime($raw);
  if (!$ts) return 'n/a';
  return date('Y-m-d H:i', $ts);
}

$rows = [];
$stats = ['total' => 0, 'live' => 0, 'draft' => 0, 'disabled' => 0, 'published_pages' => 0];

foreach ($sites as $site) {
  $status = normalize_status($site);
  $domain = domain_meta($site, $base);
  $published = (int)($site['_published'] ?? 0);

  $rows[] = [
    'id' => (int)$site['id'],
    'name' => trim((string)($site['name'] ?? '')) ?: 'Untitled site',
    'slug' => trim((string)($site['slug'] ?? '')),
    'domain' => $domain['display'],
    'domain_url' => $domain['url'],
    'status' => $status,
    'published' => $published,
    'updated' => updated_display($site),
    'updated_ts' => strtotime((string)($site['updated_at'] ?? $site['created_at'] ?? '')) ?: 0,
  ];

  $stats['total']++;
  $stats['published_pages'] += $published;
  if (isset($stats[$status])) $stats[$status]++;
}

usort($rows, function (array $a, array $b): int {
  if ($a['updated_ts'] === $b['updated_ts']) return strcmp((string)$a['name'], (string)$b['name']);
  return $b['updated_ts'] <=> $a['updated_ts'];
});
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NexusCMS Admin</title>
  <script>
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    :root{
      --bg:#0f172a;
      --surface:#111827;
      --surface-2:#0b1220;
      --line:#334155;
      --line-soft:#1e293b;
      --text:#e5e7eb;
      --text-strong:#f8fafc;
      --muted:#94a3b8;
      --accent:#3b82f6;
      --ok:#22c55e;
      --warn:#f59e0b;
      --off:#94a3b8;
      --danger:#f87171;
      --sidebar-w:220px;
    }
    .theme-light{
      --bg:#f3f4f6;
      --surface:#ffffff;
      --surface-2:#f9fafb;
      --line:#d1d5db;
      --line-soft:#e5e7eb;
      --text:#111827;
      --text-strong:#0f172a;
      --muted:#4b5563;
      --accent:#1d4ed8;
      --ok:#166534;
      --warn:#92400e;
      --off:#374151;
      --danger:#b91c1c;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font:14px/1.4 Arial, Helvetica, sans-serif;}
    a{color:inherit;text-decoration:none}

    .shell{min-height:100vh;display:grid;grid-template-columns:var(--sidebar-w) 1fr;}

    .sidebar{background:var(--surface-2);border-right:1px solid var(--line);padding:12px;}
    .brand{font-size:18px;font-weight:700;margin:2px 6px 12px;}
    .nav-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin:12px 8px 6px;}
    .nav-link{display:block;padding:8px 10px;border:1px solid transparent;border-radius:4px;margin-bottom:4px;font-weight:600;color:var(--text-strong);}
    .nav-link:hover{background:color-mix(in srgb, var(--accent) 16%, transparent);border-color:color-mix(in srgb, var(--accent) 28%, var(--line))}
    .nav-link.active{background:color-mix(in srgb, var(--accent) 22%, transparent);border-color:color-mix(in srgb, var(--accent) 42%, var(--line));color:var(--text-strong)}

    .workspace{min-width:0;display:flex;flex-direction:column;}
    .topbar{height:48px;background:var(--surface);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 14px;}
    .topbar-title{display:inline-flex;align-items:center;gap:8px;font-weight:700}
    .topbar-role{color:var(--muted);font-size:12px;font-weight:500}
    .topbar-actions{display:flex;align-items:center;gap:12px;color:var(--muted)}
    .nx-user-menu { position: relative; }
    .nx-user-menu summary {
      list-style: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1px solid var(--line);
      border-radius: 4px;
      background: var(--surface-2);
      color: var(--text-strong);
      font-weight: 600;
      padding: 6px 10px;
    }
    .nx-user-menu summary::-webkit-details-marker { display: none; }
    .nx-user-arrow { color: var(--muted); font-size: 11px; line-height: 1; }
    .nx-user-menu[open] .nx-user-arrow { transform: rotate(180deg); }
    .nx-user-dropdown {
      position: absolute;
      right: 0;
      top: calc(100% + 6px);
      background: var(--surface-2);
      border: 1px solid var(--line);
      border-radius: 4px;
      min-width: 180px;
      padding: 6px;
      display: grid;
      gap: 4px;
      box-shadow: 0 8px 20px rgba(2, 6, 23, 0.32);
      z-index: 3700;
    }
    .nx-user-dropdown a,
    .nx-user-dropdown button {
      border: 0;
      background: transparent;
      color: var(--text-strong);
      text-align: left;
      text-decoration: none;
      font: inherit;
      font-size: 13px;
      font-weight: 600;
      border-radius: 4px;
      padding: 8px 10px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
    }
    .nx-user-dropdown a:hover,
    .nx-user-dropdown button:hover { background: color-mix(in srgb, var(--accent) 16%, transparent); }
    .nx-user-dropdown a.logout { color: var(--danger); }
    .nx-menu-icon {
      width: 14px;
      display: inline-flex;
      justify-content: center;
      align-items: center;
      color: var(--muted);
      font-size: 13px;
    }

    .content{padding:14px;display:grid;gap:12px;}
    .panel{background:var(--surface);border:1px solid var(--line);border-radius:4px;}
    .panel-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid var(--line);}
    .panel-title{margin:0;font-size:16px;font-weight:700}
    .notice,.error-banner{margin:0;padding:10px 12px;border-radius:4px;border:1px solid transparent;font-size:13px;}
    .notice{border-color:color-mix(in srgb, var(--ok) 42%, var(--line));background:color-mix(in srgb, var(--ok) 16%, transparent);color:var(--ok);}
    .error-banner{border-color:color-mix(in srgb, var(--danger) 42%, var(--line));background:color-mix(in srgb, var(--danger) 16%, transparent);color:var(--danger);}

    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:0 10px;border:1px solid var(--line);border-radius:4px;background:var(--surface-2);color:var(--text-strong);font-size:13px;font-weight:600;cursor:pointer}
    .btn.primary{border-color:color-mix(in srgb, var(--accent) 60%, var(--line));background:var(--accent);color:#fff}
    .btn.small{min-height:28px;padding:0 8px;font-size:12px}
    .btn.ghost{background:transparent}
    .btn.danger{border-color:color-mix(in srgb, var(--danger) 56%, var(--line));color:var(--danger);background:color-mix(in srgb, var(--danger) 8%, transparent)}
    .btn:disabled{opacity:.55;cursor:not-allowed}

    .summary{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:8px;padding:10px 12px;}
    .metric{border:1px solid var(--line);border-radius:4px;background:var(--surface);padding:8px;}
    .metric-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--muted)}
    .metric-value{margin-top:2px;font-size:20px;font-weight:700}
    .metric.live .metric-value{color:var(--ok)}
    .metric.draft .metric-value{color:var(--warn)}
    .metric.disabled .metric-value{color:var(--off)}

    .filters{display:flex;gap:8px;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid var(--line);background:var(--surface-2)}
    .bulkbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid var(--line);background:color-mix(in srgb, var(--accent) 8%, transparent)}
    .bulkbar .bulk-count{font-size:12px;color:var(--muted);font-weight:600}
    .field{display:flex;align-items:center;gap:8px;padding:0 8px;height:32px;border:1px solid var(--line);border-radius:4px;background:var(--surface)}
    .field input,.field select{border:0;outline:0;background:transparent;font:inherit;color:inherit;}
    .field input{min-width:200px;}

    .table-wrap{overflow:auto}
    table{width:100%;border-collapse:collapse;font-size:13px}
    thead th{text-align:left;background:var(--surface-2);border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:7px 8px;font-weight:700;white-space:nowrap}
    tbody td{padding:7px 8px;border-bottom:1px solid var(--line-soft);vertical-align:middle}
    tbody tr{cursor:pointer}
    tbody tr:hover{background:color-mix(in srgb, var(--accent) 10%, transparent)}
    tbody tr.selected{background:color-mix(in srgb, var(--accent) 14%, transparent)}

    .check-col{width:34px}
    .site-name-link{font-weight:700;color:var(--text-strong);text-decoration:none}
    .site-name-link:hover{text-decoration:underline}
    .site-meta{font-size:12px;color:var(--muted);margin-top:1px}
    .published-label{font-size:12px;font-weight:600}
    .muted{color:var(--muted);font-size:12px}
    .status{display:inline-block;min-width:68px;text-align:center;padding:2px 8px;border-radius:999px;border:1px solid transparent;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.2px;}
    .status.live{background:color-mix(in srgb, var(--ok) 18%, transparent);border-color:color-mix(in srgb, var(--ok) 36%, var(--line));color:var(--ok)}
    .status.draft{background:color-mix(in srgb, var(--warn) 18%, transparent);border-color:color-mix(in srgb, var(--warn) 36%, var(--line));color:var(--warn)}
    .status.disabled{background:color-mix(in srgb, var(--off) 18%, transparent);border-color:color-mix(in srgb, var(--off) 36%, var(--line));color:var(--off)}
    .status-select{height:28px;border:1px solid var(--line);border-radius:4px;background:var(--surface);color:var(--text);font:inherit;padding:0 6px}

    .row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end}
    .open-link{font-size:12px;font-weight:600;color:var(--accent);text-decoration:underline;text-underline-offset:2px}
    .row-menu{position:relative}
    .row-menu summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid var(--line);border-radius:4px;background:var(--surface-2);font-size:15px;line-height:1}
    .row-menu summary::-webkit-details-marker{display:none}
    .row-menu-list{position:absolute;right:0;top:calc(100% + 4px);min-width:150px;padding:6px;background:var(--surface);border:1px solid var(--line);border-radius:4px;display:grid;gap:4px;z-index:40}
    .row-menu-list button{display:block;width:100%;border:0;background:transparent;color:var(--text);font:inherit;font-size:12px;font-weight:600;text-align:left;padding:7px 8px;border-radius:4px;cursor:pointer}
    .row-menu-list button:hover{background:color-mix(in srgb, var(--accent) 12%, transparent)}
    .row-menu-list button.danger{color:var(--danger)}

    .sort-btn{border:0;background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:6px}
    .sort-btn .arrow{font-size:10px;color:var(--muted)}
    .sort-btn.active .arrow{color:var(--text-strong)}

    @media (max-width:960px){
      .shell{grid-template-columns:1fr}
      .sidebar{display:none}
      .summary{grid-template-columns:repeat(2,minmax(120px,1fr));}
      .field input{min-width:140px;}
      .bulkbar{align-items:flex-start}
    }
  </style>
</head>
<body>
  <div class="shell">
    <aside class="sidebar" aria-label="Primary navigation">
      <div class="brand">NexusCMS</div>

      <div class="nav-label">Content Management</div>
      <a class="nav-link" href="<?= $base ?>/">Dashboard</a>
      <a class="nav-link active" href="<?= $base ?>/admin/index.php">Sites</a>
      <?php if ($canManageUsersNav): ?>
        <a class="nav-link" href="<?= $base ?>/admin/users.php">Users</a>
      <?php endif; ?>
      <a class="nav-link" href="<?= $base ?>/admin/images.php">Media</a>
      <a class="nav-link" href="<?= $base ?>/admin/databases.php">Database</a>

      <div class="nav-label">Create</div>
      <a class="nav-link" href="<?= $base ?>/admin/site_new.php">New Site</a>
      <?php if ($canManageUsersNav): ?>
        <a class="nav-link" href="<?= $base ?>/admin/user_new.php">New User</a>
      <?php endif; ?>
    </aside>

    <div class="workspace">
      <header class="topbar">
        <div class="topbar-title">
          <span>Admin Dashboard</span>
          <span class="topbar-role"><?= Security::e($topbarRoleLabel) ?></span>
        </div>
        <div class="topbar-actions">
          <a class="nx-icon-btn nx-icon-link" id="nxNotificationsBtnSites" href="<?= $base ?>/admin/notifications.php" aria-label="Notifications" title="Notifications">
            <span aria-hidden="true">🔔</span>
            <?php if ($notificationCount > 0): ?><span class="nx-icon-badge"><?= (int)$notificationCount ?></span><?php endif; ?>
          </a>
          <?php include __DIR__ . '/partials/assistant_widget.php'; ?>
          <button type="button" class="nx-icon-btn" id="nxThemeToggleSites" aria-label="Toggle theme" title="Toggle theme">
            <span id="nxThemeToggleIconSites" aria-hidden="true">◐</span>
          </button>
          <details class="nx-user-menu" id="nxUserMenuSites">
            <summary aria-haspopup="menu" aria-label="Open account menu">
              <span class="nx-user-label"><?= Security::e($userName) ?></span>
              <span class="nx-user-arrow" aria-hidden="true">▾</span>
            </summary>
            <div class="nx-user-dropdown" role="menu">
              <a role="menuitem" href="<?= $base ?>/admin/settings.php">
                <span class="nx-menu-icon" aria-hidden="true">⚙</span>
                <span>Settings</span>
              </a>
              <a class="logout" role="menuitem" href="<?= $base ?>/admin/logout.php">
                <span class="nx-menu-icon" aria-hidden="true">↪</span>
                <span>Logout</span>
              </a>
            </div>
          </details>
        </div>
      </header>

      <main class="content">
        <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
          <p class="<?= ($flash['type'] ?? 'notice') === 'error' ? 'error-banner' : 'notice' ?>"><?= Security::e((string)$flash['message']) ?></p>
        <?php endif; ?>
        <section class="panel" aria-label="Dashboard summary">
          <div class="panel-head">
            <h1 class="panel-title">Sites Overview</h1>
            <a class="btn primary" href="<?= $base ?>/admin/site_new.php">Create Site</a>
          </div>
          <div class="summary">
            <div class="metric"><div class="metric-label">Total Sites</div><div class="metric-value"><?= (int)$stats['total'] ?></div></div>
            <div class="metric live"><div class="metric-label">Live</div><div class="metric-value"><?= (int)$stats['live'] ?></div></div>
            <div class="metric draft"><div class="metric-label">Draft</div><div class="metric-value"><?= (int)$stats['draft'] ?></div></div>
            <div class="metric disabled"><div class="metric-label">Disabled</div><div class="metric-value"><?= (int)$stats['disabled'] ?></div></div>
            <div class="metric"><div class="metric-label">Published Pages</div><div class="metric-value"><?= (int)$stats['published_pages'] ?></div></div>
          </div>
        </section>

        <section class="panel" aria-label="Sites table">
          <div class="panel-head">
            <h2 class="panel-title">All Sites</h2>
          </div>

          <div class="filters">
            <label class="field" aria-label="Search sites">
              <span>Search</span>
              <input id="siteSearch" type="search" placeholder="Name, slug, or domain">
            </label>
            <label class="field" aria-label="Filter by status">
              <span>Status</span>
              <select id="siteStatusFilter">
                <option value="">All</option>
                <option value="live">Live</option>
                <option value="draft">Draft</option>
                <option value="disabled">Disabled</option>
              </select>
            </label>
            <label class="field" aria-label="Filter by published page count">
              <span>Pages</span>
              <select id="sitePublishedFilter">
                <option value="">Any</option>
                <option value="has">Has pages</option>
                <option value="none">No pages</option>
              </select>
            </label>
          </div>
          <form id="bulkForm" method="post" class="bulkbar">
            <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="mode" value="bulk_action">
            <span class="bulk-count" id="bulkCount">0 selected</span>
            <label class="field" style="min-width:220px;">
              <span>Bulk action</span>
              <select id="bulkAction" name="bulk_action">
                <option value="">Choose action</option>
                <option value="live">Publish (set live)</option>
                <option value="draft">Move to draft</option>
                <option value="disabled">Disable</option>
                <option value="delete">Delete selected</option>
              </select>
            </label>
            <button type="submit" class="btn small" id="applyBulk" disabled>Apply</button>
          </form>

          <div class="table-wrap">
            <table id="sitesTable">
              <thead>
                <tr>
                  <th class="check-col"><input type="checkbox" id="selectAllSites" aria-label="Select all sites"></th>
                  <th style="min-width:260px;">
                    <button type="button" class="sort-btn" data-sort="name">Site <span class="arrow">↕</span></button>
                  </th>
                  <th style="min-width:170px;">Domain / Path</th>
                  <th style="min-width:140px;">Status</th>
                  <th style="min-width:130px;">
                    <button type="button" class="sort-btn" data-sort="published">Published <span class="arrow">↕</span></button>
                  </th>
                  <th style="min-width:170px;">
                    <button type="button" class="sort-btn active" data-sort="updated">Last Updated <span class="arrow">↓</span></button>
                  </th>
                  <th style="min-width:240px;text-align:right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr
                    data-site-id="<?= (int)$row['id'] ?>"
                    data-status="<?= Security::e($row['status']) ?>"
                    data-search="<?= Security::e(strtolower($row['name'] . ' ' . $row['slug'] . ' ' . $row['domain'])) ?>"
                    data-published="<?= (int)$row['published'] ?>"
                    data-updated-ts="<?= (int)$row['updated_ts'] ?>"
                    data-manage-url="<?= $base ?>/admin/site.php?id=<?= (int)$row['id'] ?>"
                  >
                    <td class="check-col">
                      <input type="checkbox" class="row-check" name="site_ids[]" value="<?= (int)$row['id'] ?>" form="bulkForm" aria-label="Select <?= Security::e($row['name']) ?>">
                    </td>
                    <td>
                      <a class="site-name-link" href="<?= $base ?>/admin/site.php?id=<?= (int)$row['id'] ?>"><?= Security::e($row['name']) ?></a>
                      <div class="site-meta">Slug: <code><?= Security::e($row['slug'] !== '' ? $row['slug'] : 'n/a') ?></code></div>
                    </td>
                    <td>
                      <a class="open-link" href="<?= Security::e($row['domain_url']) ?>" target="_blank" rel="noopener noreferrer"><?= Security::e($row['domain']) ?></a>
                    </td>
                    <td>
                      <form method="post" class="status-form">
                        <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="mode" value="row_status">
                        <input type="hidden" name="site_id" value="<?= (int)$row['id'] ?>">
                        <select class="status-select" name="status" aria-label="Change status for <?= Security::e($row['name']) ?>">
                          <option value="live" <?= $row['status'] === 'live' ? 'selected' : '' ?>>Live</option>
                          <option value="draft" <?= $row['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                          <option value="disabled" <?= $row['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                      </form>
                    </td>
                    <td>
                      <span class="published-label"><?= (int)$row['published'] ?> page<?= (int)$row['published'] === 1 ? '' : 's' ?></span>
                    </td>
                    <td>
                      <div><?= Security::e($row['updated']) ?></div>
                      <div class="muted">Most recent edit</div>
                    </td>
                    <td>
                      <div class="row-actions">
                        <a class="btn small primary" href="<?= $base ?>/admin/site.php?id=<?= (int)$row['id'] ?>">Manage</a>
                        <a class="btn small ghost" href="<?= Security::e($row['domain_url']) ?>" target="_blank" rel="noopener noreferrer">Open</a>
                        <details class="row-menu">
                          <summary aria-label="More actions">⋮</summary>
                          <div class="row-menu-list">
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                              <input type="hidden" name="mode" value="row_action">
                              <input type="hidden" name="site_id" value="<?= (int)$row['id'] ?>">
                              <button type="submit" name="action" value="duplicate">Duplicate</button>
                            </form>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                              <input type="hidden" name="mode" value="row_action">
                              <input type="hidden" name="site_id" value="<?= (int)$row['id'] ?>">
                              <button type="submit" name="action" value="archive">Archive</button>
                            </form>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                              <input type="hidden" name="mode" value="row_action">
                              <input type="hidden" name="site_id" value="<?= (int)$row['id'] ?>">
                              <button class="danger" type="submit" name="action" value="delete">Delete</button>
                            </form>
                          </div>
                        </details>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      </main>
    </div>
  </div>

  <script>
    (function () {
      const searchEl = document.getElementById('siteSearch');
      const statusEl = document.getElementById('siteStatusFilter');
      const publishedEl = document.getElementById('sitePublishedFilter');
      const rows = Array.from(document.querySelectorAll('#sitesTable tbody tr'));
      const selectAll = document.getElementById('selectAllSites');
      const rowChecks = Array.from(document.querySelectorAll('.row-check'));
      const bulkCount = document.getElementById('bulkCount');
      const bulkAction = document.getElementById('bulkAction');
      const applyBulk = document.getElementById('applyBulk');
      const bulkForm = document.getElementById('bulkForm');
      const sortButtons = Array.from(document.querySelectorAll('.sort-btn'));
      let sortKey = 'updated';
      let sortDir = 'desc';

      function updateBulkState() {
        const selected = rowChecks.filter((c) => c.checked).length;
        bulkCount.textContent = selected + ' selected';
        applyBulk.disabled = selected === 0 || !(bulkAction.value || '').trim();
        const visibleChecks = rowChecks.filter((c) => c.closest('tr')?.style.display !== 'none');
        const visibleSelected = visibleChecks.filter((c) => c.checked).length;
        selectAll.checked = visibleChecks.length > 0 && visibleSelected === visibleChecks.length;
        selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleChecks.length;
        rows.forEach((row) => row.classList.toggle('selected', row.querySelector('.row-check')?.checked));
      }

      function applyFilters() {
        const q = (searchEl.value || '').trim().toLowerCase();
        const st = (statusEl.value || '').trim().toLowerCase();
        const pg = (publishedEl.value || '').trim().toLowerCase();

        rows.forEach((row) => {
          const hay = row.getAttribute('data-search') || '';
          const rowStatus = row.getAttribute('data-status') || '';
          const rowPublished = parseInt(row.getAttribute('data-published') || '0', 10);
          const matchesQ = q === '' || hay.includes(q);
          const matchesSt = st === '' || rowStatus === st;
          const matchesPg = pg === '' || (pg === 'has' ? rowPublished > 0 : rowPublished === 0);
          row.style.display = (matchesQ && matchesSt && matchesPg) ? '' : 'none';
        });
        updateBulkState();
      }

      function sortRows() {
        const tbody = document.querySelector('#sitesTable tbody');
        if (!tbody) return;
        const sorted = [...rows].sort((a, b) => {
          let av = '';
          let bv = '';
          if (sortKey === 'name') {
            av = (a.querySelector('.site-name-link')?.textContent || '').toLowerCase();
            bv = (b.querySelector('.site-name-link')?.textContent || '').toLowerCase();
            return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
          }
          if (sortKey === 'published') {
            av = parseInt(a.getAttribute('data-published') || '0', 10);
            bv = parseInt(b.getAttribute('data-published') || '0', 10);
            return sortDir === 'asc' ? av - bv : bv - av;
          }
          av = parseInt(a.getAttribute('data-updated-ts') || '0', 10);
          bv = parseInt(b.getAttribute('data-updated-ts') || '0', 10);
          return sortDir === 'asc' ? av - bv : bv - av;
        });
        sorted.forEach((row) => tbody.appendChild(row));
      }

      sortButtons.forEach((btn) => {
        btn.addEventListener('click', function () {
          const key = btn.getAttribute('data-sort') || 'updated';
          if (sortKey === key) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
          } else {
            sortKey = key;
            sortDir = key === 'name' ? 'asc' : 'desc';
          }
          sortButtons.forEach((b) => {
            b.classList.toggle('active', b === btn);
            const arrow = b.querySelector('.arrow');
            if (!arrow) return;
            if (b === btn) arrow.textContent = sortDir === 'asc' ? '↑' : '↓';
            else arrow.textContent = '↕';
          });
          sortRows();
        });
      });

      searchEl.addEventListener('input', applyFilters);
      statusEl.addEventListener('change', applyFilters);
      publishedEl.addEventListener('change', applyFilters);

      rowChecks.forEach((cb) => cb.addEventListener('change', updateBulkState));
      selectAll.addEventListener('change', function () {
        const shouldCheck = !!selectAll.checked;
        rowChecks.forEach((cb) => {
          if (cb.closest('tr')?.style.display === 'none') return;
          cb.checked = shouldCheck;
        });
        updateBulkState();
      });
      bulkAction.addEventListener('change', updateBulkState);

      bulkForm?.addEventListener('submit', function (e) {
        const action = (bulkAction.value || '').trim();
        if (!action) {
          e.preventDefault();
          return;
        }
        if (action === 'delete' && !confirm('Delete selected sites? This action cannot be undone.')) {
          e.preventDefault();
        }
      });

      document.querySelectorAll('.status-form .status-select').forEach((sel) => {
        sel.addEventListener('change', function () {
          const form = sel.closest('form');
          if (form) form.submit();
        });
      });

      document.querySelectorAll('.row-menu-list form').forEach((form) => {
        form.addEventListener('submit', function (e) {
          const action = (form.querySelector('button[type=\"submit\"]')?.value || '').toLowerCase();
          if (action === 'delete' && !confirm('Delete this site? This action cannot be undone.')) {
            e.preventDefault();
          }
        });
      });

      document.addEventListener('click', function (e) {
        document.querySelectorAll('.row-menu[open]').forEach((menu) => {
          if (!menu.contains(e.target)) menu.open = false;
        });
      });

      rows.forEach((row) => {
        row.addEventListener('click', function (e) {
          if (e.target.closest('a,button,input,select,summary,details,form,label')) return;
          const url = row.getAttribute('data-manage-url');
          if (url) window.location.href = url;
        });
      });

      sortRows();
      applyFilters();
      updateBulkState();
    })();

    (function () {
      var root = document.documentElement;
      var menu = document.getElementById('nxUserMenuSites');
      var toggle = document.getElementById('nxThemeToggleSites');
      var icon = document.getElementById('nxThemeToggleIconSites');
      var endpoint = <?= json_encode($themeEndpoint, JSON_UNESCAPED_SLASHES) ?>;
      var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;

      function currentTheme() {
        return root.classList.contains('theme-light') ? 'light' : 'dark';
      }

      function updateLabel() {
        if (!icon) return;
        icon.textContent = currentTheme() === 'light' ? '☾' : '☀️';
        if (toggle) {
          var nextMode = currentTheme() === 'light' ? 'dark' : 'light';
          toggle.setAttribute('aria-label', nextMode === 'dark' ? 'Switch to dark mode' : 'Switch to light mode');
          toggle.setAttribute('title', nextMode === 'dark' ? 'Switch to dark mode' : 'Switch to light mode');
        }
      }

      function persistTheme(mode) {
        try { localStorage.setItem('nexusTheme', mode); } catch (e) {}
        try {
          fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ mode: mode, _csrf: csrf })
          });
        } catch (e) {}
      }

      updateLabel();

      toggle?.addEventListener('click', function (e) {
        e.preventDefault();
        var next = currentTheme() === 'light' ? 'dark' : 'light';
        root.classList.toggle('theme-light', next === 'light');
        updateLabel();
        persistTheme(next);
        if (menu) menu.open = false;
      });

      document.addEventListener('click', function (e) {
        if (!menu || !menu.open) return;
        if (!menu.contains(e.target)) menu.open = false;
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && menu && menu.open) menu.open = false;
      });
    })();
  </script>
</body>
</html>
