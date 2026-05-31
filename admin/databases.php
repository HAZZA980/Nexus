<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\Site;

$base = base_path();
$activeNav = 'databases';
$themeIsLight = ui_theme_is_light();
$csrfToken = Security::csrfToken();

function db_default_rows(string $base): array {
  $now = date('Y-m-d H:i:s');
  return [
    [
      'id' => 1,
      'name' => 'Citation DB',
      'key' => 'citation-db',
      'type' => 'citation',
      'status' => 'healthy',
      'source_site' => 'cite-them-right',
      'connected_sites' => ['cite-them-right'],
      'records' => 1520,
      'storage_mb' => 84.2,
      'last_sync' => date('Y-m-d H:i:s', strtotime('-2 days')),
      'last_updated' => date('Y-m-d H:i:s', strtotime('-1 day')),
      'open_url' => $base . '/admin/database_citation.php',
      'schema' => 'citation_examples, citation_revisions, citation_releases',
    ],
    [
      'id' => 2,
      'name' => 'Content Index',
      'key' => 'content-index',
      'type' => 'content',
      'status' => 'outdated',
      'source_site' => 'skills-for-study',
      'connected_sites' => ['skills-for-study', 'nexus-hub'],
      'records' => 39450,
      'storage_mb' => 320.8,
      'last_sync' => date('Y-m-d H:i:s', strtotime('-34 days')),
      'last_updated' => date('Y-m-d H:i:s', strtotime('-20 days')),
      'open_url' => '#',
      'schema' => 'pages_index, blocks_index, tags_index',
    ],
    [
      'id' => 3,
      'name' => 'Analytics Warehouse',
      'key' => 'analytics-warehouse',
      'type' => 'analytics',
      'status' => 'syncing',
      'source_site' => 'nexus-hub',
      'connected_sites' => ['nexus-hub', 'cite-them-right', 'skills-for-study'],
      'records' => 1894520,
      'storage_mb' => 2048.6,
      'last_sync' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
      'last_updated' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
      'open_url' => '#',
      'schema' => 'analytics_sessions, analytics_events, rollups_daily',
    ],
    [
      'id' => 4,
      'name' => 'Legacy Archive',
      'key' => 'legacy-archive',
      'type' => 'content',
      'status' => 'error',
      'source_site' => 'cite-them-right-copy',
      'connected_sites' => ['cite-them-right-copy'],
      'records' => 11200,
      'storage_mb' => 512.4,
      'last_sync' => date('Y-m-d H:i:s', strtotime('-90 days')),
      'last_updated' => date('Y-m-d H:i:s', strtotime('-80 days')),
      'open_url' => '#',
      'schema' => 'archive_pages, archive_media_map',
    ],
  ];
}

function db_find_index(array $rows, int $id): int {
  foreach ($rows as $idx => $row) {
    if ((int)($row['id'] ?? 0) === $id) return $idx;
  }
  return -1;
}

function db_relative_time(?string $ts): string {
  if (!$ts) return '—';
  $time = strtotime($ts);
  if (!$time) return '—';
  $diff = max(0, time() - $time);
  if ($diff < 60) return 'Just now';
  $units = [31536000 => 'year', 2592000 => 'month', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
  foreach ($units as $secs => $label) {
    if ($diff >= $secs) {
      $v = (int)floor($diff / $secs);
      return $v . ' ' . $label . ($v === 1 ? '' : 's') . ' ago';
    }
  }
  return '—';
}

function db_status_label(string $status): string {
  $map = ['healthy' => 'Healthy', 'outdated' => 'Outdated', 'syncing' => 'Syncing', 'error' => 'Error'];
  $s = strtolower(trim($status));
  return $map[$s] ?? 'Unknown';
}

function db_stale_days(?string $ts): int {
  if (!$ts) return 9999;
  $time = strtotime($ts);
  if (!$time) return 9999;
  return (int)floor((time() - $time) / 86400);
}

$sites = Site::all();
$siteMap = [];
foreach ($sites as $s) {
  $slug = (string)($s['slug'] ?? '');
  if ($slug === '') continue;
  $siteMap[$slug] = [
    'name' => (string)($s['name'] ?? $slug),
    'url' => $base . '/s/' . rawurlencode($slug) . '/home',
  ];
}

if (!isset($_SESSION['admin_db_rows']) || !is_array($_SESSION['admin_db_rows'])) {
  $_SESSION['admin_db_rows'] = db_default_rows($base);
}
$rows = $_SESSION['admin_db_rows'];

// Databases page should only expose the citation database in this deployment.
$rows = array_values(array_filter($rows, static function (array $r): bool {
  $key = strtolower(trim((string)($r['key'] ?? '')));
  $name = strtolower(trim((string)($r['name'] ?? '')));
  $type = strtolower(trim((string)($r['type'] ?? '')));
  return $key === 'citation-db' || $name === 'citation db' || $type === 'citation';
}));

if (!$rows) {
  $sourceSlug = 'cite-them-right';
  $lastUpdated = null;
  $recordCount = 0;
  try {
    $st = DB::pdo()->prepare("SELECT MAX(created_at) FROM citation_revisions WHERE site_slug = ?");
    $st->execute([$sourceSlug]);
    $lastUpdated = (string)($st->fetchColumn() ?: '');
  } catch (\Throwable $e) {}
  try {
    $st = DB::pdo()->prepare("SELECT COUNT(*) FROM citation_examples WHERE site_slug = ?");
    $st->execute([$sourceSlug]);
    $recordCount = (int)$st->fetchColumn();
  } catch (\Throwable $e) {}
  if ($lastUpdated === '') $lastUpdated = date('Y-m-d H:i:s', strtotime('-1 day'));
  $rows = [[
    'id' => 1,
    'name' => 'Citation DB',
    'key' => 'citation-db',
    'type' => 'citation',
    'status' => 'healthy',
    'source_site' => $sourceSlug,
    'connected_sites' => [$sourceSlug],
    'records' => max(0, $recordCount),
    'storage_mb' => 84.2,
    'last_sync' => $lastUpdated,
    'last_updated' => $lastUpdated,
    'open_url' => $base . '/admin/database_citation.php',
    'schema' => 'citation_examples, citation_revisions, citation_releases',
  ]];
}

$flash = $_SESSION['admin_db_flash'] ?? null;
unset($_SESSION['admin_db_flash']);

$allowedStatuses = ['healthy', 'outdated', 'syncing', 'error'];
$allowedTypes = ['citation', 'content', 'analytics'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $_SESSION['admin_db_flash'] = ['type' => 'error', 'message' => 'Security check failed.'];
    header('Location: ' . $base . '/admin/databases.php');
    exit;
  }

  try {
    $mode = (string)($_POST['mode'] ?? '');

    if ($mode === 'row_status') {
      $id = (int)($_POST['database_id'] ?? 0);
      $status = strtolower(trim((string)($_POST['status'] ?? '')));
      if ($id <= 0 || !in_array($status, $allowedStatuses, true)) throw new RuntimeException('Invalid status update request.');
      $idx = db_find_index($rows, $id);
      if ($idx < 0) throw new RuntimeException('Database not found.');
      $rows[$idx]['status'] = $status;
      if ($status === 'healthy' || $status === 'syncing') $rows[$idx]['last_sync'] = date('Y-m-d H:i:s');
      $rows[$idx]['last_updated'] = date('Y-m-d H:i:s');
      $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Database status updated.'];
    }

    if ($mode === 'row_action') {
      $id = (int)($_POST['database_id'] ?? 0);
      $action = strtolower(trim((string)($_POST['action'] ?? '')));
      if ($id <= 0) throw new RuntimeException('Invalid database action.');
      $idx = db_find_index($rows, $id);
      if ($idx < 0) throw new RuntimeException('Database not found.');

      if ($action === 'refresh') {
        $rows[$idx]['status'] = 'healthy';
        $rows[$idx]['last_sync'] = date('Y-m-d H:i:s');
        $rows[$idx]['last_updated'] = date('Y-m-d H:i:s');
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Database refreshed.'];
      } elseif ($action === 'rebuild') {
        $rows[$idx]['status'] = 'syncing';
        $rows[$idx]['last_updated'] = date('Y-m-d H:i:s');
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Rebuild started.'];
      } elseif ($action === 'reconnect') {
        $rows[$idx]['status'] = 'healthy';
        $rows[$idx]['last_sync'] = date('Y-m-d H:i:s');
        $rows[$idx]['last_updated'] = date('Y-m-d H:i:s');
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Database reconnected.'];
      } elseif ($action === 'disconnect') {
        $rows[$idx]['status'] = 'outdated';
        $rows[$idx]['last_updated'] = date('Y-m-d H:i:s');
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Database disconnected.'];
      } elseif ($action === 'delete') {
        array_splice($rows, $idx, 1);
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Database deleted.'];
      } elseif ($action === 'export') {
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Export queued.'];
      } elseif ($action === 'schema') {
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Schema view opened in details.'];
      } else {
        throw new RuntimeException('Unknown action.');
      }
    }

    if ($mode === 'bulk_action') {
      $action = strtolower(trim((string)($_POST['bulk_action'] ?? '')));
      $ids = array_values(array_filter(array_map('intval', (array)($_POST['database_ids'] ?? [])), fn($v) => $v > 0));
      if (!$ids) throw new RuntimeException('Select at least one database.');

      if ($action === 'delete') {
        $rows = array_values(array_filter($rows, fn($r) => !in_array((int)$r['id'], $ids, true)));
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Selected databases deleted.'];
      } elseif (in_array($action, ['refresh', 'rebuild', 'reconnect', 'disconnect'], true)) {
        foreach ($rows as &$r) {
          if (!in_array((int)$r['id'], $ids, true)) continue;
          if ($action === 'refresh' || $action === 'reconnect') {
            $r['status'] = 'healthy';
            $r['last_sync'] = date('Y-m-d H:i:s');
          } elseif ($action === 'rebuild') {
            $r['status'] = 'syncing';
          } elseif ($action === 'disconnect') {
            $r['status'] = 'outdated';
          }
          $r['last_updated'] = date('Y-m-d H:i:s');
        }
        unset($r);
        $_SESSION['admin_db_flash'] = ['type' => 'notice', 'message' => 'Bulk action applied.'];
      } else {
        throw new RuntimeException('Choose a valid bulk action.');
      }
    }
  } catch (\Throwable $e) {
    $_SESSION['admin_db_flash'] = ['type' => 'error', 'message' => (string)($e->getMessage() ?: 'Action failed.')];
  }

  $_SESSION['admin_db_rows'] = $rows;
  header('Location: ' . $base . '/admin/databases.php');
  exit;
}

$dbStats = [
  'total' => count($rows),
  'connected_sites' => 0,
  'healthy' => 0,
  'outdated' => 0,
  'syncing' => 0,
  'error' => 0,
  'stale_30d' => 0,
];

$allSourceSites = [];
foreach ($rows as &$r) {
  $r['status'] = strtolower((string)($r['status'] ?? 'healthy'));
  if (!in_array($r['status'], $allowedStatuses, true)) $r['status'] = 'healthy';
  $r['type'] = strtolower((string)($r['type'] ?? 'content'));
  if (!in_array($r['type'], $allowedTypes, true)) $r['type'] = 'content';
  $r['last_sync_ts'] = strtotime((string)($r['last_sync'] ?? '')) ?: 0;
  $r['last_updated_ts'] = strtotime((string)($r['last_updated'] ?? '')) ?: 0;
  $r['connected_sites_count'] = count((array)($r['connected_sites'] ?? []));
  $r['size_label'] = number_format((int)($r['records'] ?? 0)) . ' rec / ' . number_format((float)($r['storage_mb'] ?? 0), 1) . ' MB';
  $r['health_weight'] = ['healthy' => 1, 'outdated' => 2, 'syncing' => 3, 'error' => 4][$r['status']] ?? 9;
  $r['stale_days'] = db_stale_days((string)($r['last_sync'] ?? ''));

  $dbStats['connected_sites'] += (int)$r['connected_sites_count'];
  if (isset($dbStats[$r['status']])) $dbStats[$r['status']]++;
  if ($r['stale_days'] >= 30) $dbStats['stale_30d']++;

  $src = (string)($r['source_site'] ?? '');
  if ($src !== '') $allSourceSites[$src] = true;
}
unset($r);

$sourceSites = array_keys($allSourceSites);
sort($sourceSites, SORT_NATURAL | SORT_FLAG_CASE);

usort($rows, function(array $a, array $b): int {
  if ((int)$a['last_updated_ts'] === (int)$b['last_updated_ts']) return strcmp((string)$a['name'], (string)$b['name']);
  return (int)$b['last_updated_ts'] <=> (int)$a['last_updated_ts'];
});
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Databases — NexusCMS Admin</title>
  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    body{margin:0;background:var(--admin-bg);color:var(--admin-text);font:14px/1.4 Arial, Helvetica, sans-serif;}
    a{color:inherit;text-decoration:none}
    main{max-width:none;margin:0;padding:14px;display:grid;gap:12px;min-width:0;}
    .panel{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;min-width:0;}
    .panel-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid var(--admin-line);}
    .panel-title{margin:0;font-size:16px;font-weight:700;color:var(--admin-text-strong)}

    .notice,.error-banner{margin:0;padding:10px 12px;border-radius:4px;border:1px solid transparent;font-size:13px}
    .notice{border-color:color-mix(in srgb, var(--admin-success) 38%, var(--admin-line));background:color-mix(in srgb, var(--admin-success) 14%, transparent);color:var(--admin-success)}
    .error-banner{border-color:color-mix(in srgb, var(--admin-danger) 42%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 14%, transparent);color:var(--admin-danger)}

    .summary{display:grid;grid-template-columns:repeat(7,minmax(100px,1fr));gap:8px;padding:10px 12px;background:var(--admin-surface-2)}
    .metric{border:1px solid var(--admin-line);border-radius:4px;padding:8px;background:var(--admin-surface)}
    .metric-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--admin-muted)}
    .metric-value{margin-top:2px;font-size:20px;font-weight:700;color:var(--admin-text-strong)}
    .metric.active .metric-value{color:var(--admin-success)}
    .metric.warn .metric-value{color:var(--admin-warn)}
    .metric.off .metric-value{color:var(--admin-danger)}

    .filters{display:flex;gap:8px;flex-wrap:wrap;padding:8px 12px;border-top:1px solid var(--admin-line);border-bottom:1px solid var(--admin-line);background:var(--admin-surface-2)}
    .field{display:flex;align-items:center;gap:8px;padding:0 8px;height:32px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface)}
    .field input,.field select{border:0;outline:0;background:transparent;font:inherit;color:inherit}
    .field input{min-width:180px}

    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:0 10px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text-strong);font-size:13px;font-weight:600;cursor:pointer}
    .btn.primary{border-color:color-mix(in srgb, var(--admin-accent) 60%, var(--admin-line));background:var(--admin-accent);color:#fff}
    .btn.small{min-height:28px;padding:0 8px;font-size:12px}
    .btn.ghost{background:transparent}
    .btn.danger{border-color:color-mix(in srgb, var(--admin-danger) 56%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 8%, transparent);color:var(--admin-danger)}
    .btn:disabled{opacity:.55;cursor:not-allowed}

    .bulkbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid var(--admin-line);background:color-mix(in srgb, var(--admin-accent) 8%, transparent)}
    .bulk-count{font-size:12px;color:var(--admin-muted);font-weight:600}

    .table-wrap{overflow:auto;max-width:100%}
    table{width:100%;border-collapse:collapse;font-size:13px;background:var(--admin-surface);table-layout:fixed}
    thead th{text-align:left;background:var(--admin-surface-2);border-top:1px solid var(--admin-line);border-bottom:1px solid var(--admin-line);padding:7px 8px;font-weight:700;white-space:nowrap}
    tbody tr{cursor:pointer}
    tbody td{padding:7px 8px;border-bottom:1px solid var(--admin-line);vertical-align:middle}
    tbody tr:hover{background:color-mix(in srgb, var(--admin-accent) 10%, transparent)}
    tbody tr.selected{background:color-mix(in srgb, var(--admin-accent) 14%, transparent)}

    .check-col{width:34px}
    .db-link{display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;vertical-align:bottom;font-weight:700;color:var(--admin-text-strong);text-decoration:none}
    .db-link:hover{text-decoration:underline}
    .meta{font-size:12px;color:var(--admin-muted)}

    .warning{font-size:11px;color:var(--admin-danger);font-weight:700}

    .row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:nowrap}
    .row-actions .btn{white-space:nowrap}
    #dbTable th:nth-child(1),#dbTable td:nth-child(1){width:34px}
    #dbTable th:nth-child(2),#dbTable td:nth-child(2){width:19%}
    #dbTable th:nth-child(3),#dbTable td:nth-child(3){width:8%}
    #dbTable th:nth-child(4),#dbTable td:nth-child(4){width:14%}
    #dbTable th:nth-child(5),#dbTable td:nth-child(5){width:8%}
    #dbTable th:nth-child(6),#dbTable td:nth-child(6){width:13%}
    #dbTable th:nth-child(7),#dbTable td:nth-child(7){width:11%}
    #dbTable th:nth-child(8),#dbTable td:nth-child(8){width:10%}
    #dbTable th:nth-child(9),#dbTable td:nth-child(9){width:72px}
    #dbTable td{overflow:hidden;text-overflow:ellipsis}

    .sort-btn{border:0;background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:6px}
    .sort-btn .arrow{font-size:10px;color:var(--admin-muted)}
    .sort-btn.active .arrow{color:var(--admin-text-strong)}

    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:55}
    .modal{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;padding:16px;min-width:320px;max-width:680px;width:min(680px, calc(100vw - 24px));max-height:calc(100vh - 36px);overflow:auto}
    .modal h3{margin:0 0 8px;color:var(--admin-text-strong)}
    .modal p{margin:0;color:var(--admin-muted)}
    .detail-grid{display:grid;grid-template-columns:180px 1fr;gap:7px 10px;margin-top:12px}
    .label{font-size:12px;color:var(--admin-muted);font-weight:700}
    .value{font-size:13px;color:var(--admin-text)}
    .modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}

    .empty{padding:14px;color:var(--admin-muted)}

    @media (max-width:1100px){.summary{grid-template-columns:repeat(4,minmax(100px,1fr));}}
    @media (max-width:960px){
      .summary{grid-template-columns:repeat(2,minmax(100px,1fr));}
      .field input{min-width:130px}
      .detail-grid{grid-template-columns:1fr}
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <p class="<?= ($flash['type'] ?? 'notice') === 'error' ? 'error-banner' : 'notice' ?>"><?= Security::e((string)$flash['message']) ?></p>
    <?php endif; ?>

    <section class="panel" aria-label="Databases overview">
      <div class="panel-head">
        <h1 class="panel-title">Database Overview</h1>
      </div>
      <div class="summary">
        <div class="metric"><div class="metric-label">Total Databases</div><div class="metric-value"><?= (int)$dbStats['total'] ?></div></div>
        <div class="metric active"><div class="metric-label">Healthy</div><div class="metric-value"><?= (int)$dbStats['healthy'] ?></div></div>
        <div class="metric warn"><div class="metric-label">Outdated</div><div class="metric-value"><?= (int)$dbStats['outdated'] ?></div></div>
        <div class="metric"><div class="metric-label">Syncing</div><div class="metric-value"><?= (int)$dbStats['syncing'] ?></div></div>
        <div class="metric off"><div class="metric-label">Errors</div><div class="metric-value"><?= (int)$dbStats['error'] ?></div></div>
        <div class="metric"><div class="metric-label">Connected Sites</div><div class="metric-value"><?= (int)$dbStats['connected_sites'] ?></div></div>
        <div class="metric off"><div class="metric-label">Stale (30d+)</div><div class="metric-value"><?= (int)$dbStats['stale_30d'] ?></div></div>
      </div>
    </section>

    <section class="panel" aria-label="Databases list">
      <div class="panel-head">
        <h2 class="panel-title">All Databases</h2>
      </div>

      <div class="filters">
        <label class="field"><span>Search</span><input id="dbSearch" type="search" placeholder="Database, key, source site"></label>
        <label class="field"><span>Status</span><select id="dbStatusFilter"><option value="">All</option><?php foreach ($allowedStatuses as $st): ?><option value="<?= Security::e($st) ?>"><?= Security::e(db_status_label($st)) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Type</span><select id="dbTypeFilter"><option value="">All</option><?php foreach ($allowedTypes as $tp): ?><option value="<?= Security::e($tp) ?>"><?= Security::e(ucfirst($tp)) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Source Site</span><select id="dbSourceFilter"><option value="">All</option><?php foreach ($sourceSites as $slug): ?><option value="<?= Security::e($slug) ?>"><?= Security::e($siteMap[$slug]['name'] ?? $slug) ?></option><?php endforeach; ?></select></label>
        <button class="btn" type="button" id="dbResetFilters">Reset</button>
      </div>

      <form id="dbBulkForm" method="post" class="bulkbar">
        <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="mode" value="bulk_action">
        <span class="bulk-count" id="dbBulkCount">0 selected</span>
        <label class="field" style="min-width:250px;"><span>Bulk action</span>
          <select id="dbBulkAction" name="bulk_action">
            <option value="">Choose action</option>
            <option value="refresh">Refresh</option>
            <option value="rebuild">Rebuild</option>
            <option value="reconnect">Reconnect</option>
            <option value="disconnect">Disconnect</option>
            <option value="delete">Delete</option>
          </select>
        </label>
        <button type="submit" class="btn small" id="dbApplyBulk" disabled>Apply</button>
      </form>

      <?php if (!$rows): ?>
        <div class="empty">No databases available.</div>
      <?php else: ?>
      <div class="table-wrap">
        <table id="dbTable" aria-label="Databases list">
          <thead>
            <tr>
              <th class="check-col"><input type="checkbox" id="dbSelectAll" aria-label="Select all databases"></th>
              <th><button type="button" class="sort-btn" data-sort="name">Database <span class="arrow">↕</span></button></th>
              <th><button type="button" class="sort-btn" data-sort="type">Type <span class="arrow">↕</span></button></th>
              <th><button type="button" class="sort-btn" data-sort="source">Source site <span class="arrow">↕</span></button></th>
              <th><button type="button" class="sort-btn" data-sort="connected">Connected <span class="arrow">↕</span></button></th>
              <th><button type="button" class="sort-btn" data-sort="size">Size <span class="arrow">↕</span></button></th>
              <th><button type="button" class="sort-btn" data-sort="last_sync">Last sync <span class="arrow">↕</span></button></th>
              <th><button type="button" class="sort-btn active" data-sort="last_updated">Updated <span class="arrow">↓</span></button></th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $sourceSlug = (string)($r['source_site'] ?? '');
                $sourceName = $siteMap[$sourceSlug]['name'] ?? $sourceSlug;
                $sourceUrl = $siteMap[$sourceSlug]['url'] ?? ($base . '/s/' . rawurlencode($sourceSlug) . '/home');
                $connected = (array)($r['connected_sites'] ?? []);
                $connectedLabel = implode(', ', $connected);
                if ($connectedLabel === '') $connectedLabel = '—';
                $staleWarning = ((int)$r['stale_days'] >= 30);
                $detail = [
                  'name' => (string)$r['name'],
                  'key' => (string)$r['key'],
                  'type' => ucfirst((string)$r['type']),
                  'status' => db_status_label((string)$r['status']),
                  'source' => $sourceName,
                  'connected' => $connectedLabel,
                  'size' => (string)$r['size_label'],
                  'records' => number_format((int)($r['records'] ?? 0)),
                  'storage' => number_format((float)($r['storage_mb'] ?? 0), 1) . ' MB',
                  'last_sync' => (string)$r['last_sync'],
                  'last_updated' => (string)$r['last_updated'],
                  'schema' => (string)($r['schema'] ?? 'n/a'),
                ];
              ?>
              <tr
                data-id="<?= (int)$r['id'] ?>"
                data-search="<?= Security::e(strtolower((string)$r['name'] . ' ' . (string)$r['key'] . ' ' . $sourceSlug)) ?>"
                data-name="<?= Security::e(strtolower((string)$r['name'])) ?>"
                data-type="<?= Security::e((string)$r['type']) ?>"
                data-source="<?= Security::e(strtolower($sourceSlug)) ?>"
                data-connected="<?= (int)$r['connected_sites_count'] ?>"
                data-size="<?= (float)$r['storage_mb'] ?>"
                data-last-sync="<?= (int)$r['last_sync_ts'] ?>"
                data-last-updated="<?= (int)$r['last_updated_ts'] ?>"
                data-status="<?= Security::e((string)$r['status']) ?>"
                data-open-url="<?= Security::e((string)($r['open_url'] ?: '#')) ?>"
                data-detail='<?= Security::e(json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'
              >
                <td class="check-col"><input type="checkbox" class="db-check" name="database_ids[]" value="<?= (int)$r['id'] ?>" form="dbBulkForm" aria-label="Select <?= Security::e((string)$r['name']) ?>"></td>
                <td>
                  <a href="#" class="db-link" data-open-detail onclick="return false;"><?= Security::e((string)$r['name']) ?></a>
                  <div class="meta">Key: <code><?= Security::e((string)$r['key']) ?></code></div>
                </td>
                <td><?= Security::e(ucfirst((string)$r['type'])) ?></td>
                <td><a class="db-link" href="<?= Security::e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= Security::e($sourceName) ?></a></td>
                <td><?= (int)$r['connected_sites_count'] ?></td>
                <td><?= Security::e((string)$r['size_label']) ?></td>
                <td>
                  <div title="<?= Security::e((string)$r['last_sync']) ?>"><?= Security::e(db_relative_time((string)$r['last_sync'])) ?></div>
                  <?php if ($staleWarning): ?><div class="warning">Stale 30+ days</div><?php endif; ?>
                </td>
                <td><div title="<?= Security::e((string)$r['last_updated']) ?>"><?= Security::e(db_relative_time((string)$r['last_updated'])) ?></div></td>
                <td>
                  <div class="row-actions">
                    <a class="btn small primary" href="<?= Security::e((string)($r['open_url'] ?: '#')) ?>">Open</a>
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

  <div class="modal-backdrop" id="dbDetailModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dbDetailTitle">
      <h3 id="dbDetailTitle">Database details</h3>
      <p id="dbDetailSub"></p>
      <div class="detail-grid">
        <div class="label">Database</div><div class="value" id="dName">—</div>
        <div class="label">Key</div><div class="value" id="dKey">—</div>
        <div class="label">Type</div><div class="value" id="dType">—</div>
        <div class="label">Health</div><div class="value" id="dStatus">—</div>
        <div class="label">Source site</div><div class="value" id="dSource">—</div>
        <div class="label">Connected sites</div><div class="value" id="dConnected">—</div>
        <div class="label">Size</div><div class="value" id="dSize">—</div>
        <div class="label">Records</div><div class="value" id="dRecords">—</div>
        <div class="label">Storage</div><div class="value" id="dStorage">—</div>
        <div class="label">Last sync</div><div class="value" id="dSync">—</div>
        <div class="label">Last updated</div><div class="value" id="dUpdated">—</div>
        <div class="label">Schema</div><div class="value" id="dSchema">—</div>
      </div>
      <div class="modal-actions"><button type="button" class="btn" id="dbCloseModal">Close</button></div>
    </div>
  </div>

  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
      const rows = Array.from(document.querySelectorAll('#dbTable tbody tr'));
      const searchEl = document.getElementById('dbSearch');
      const statusEl = document.getElementById('dbStatusFilter');
      const typeEl = document.getElementById('dbTypeFilter');
      const sourceEl = document.getElementById('dbSourceFilter');
      const resetBtn = document.getElementById('dbResetFilters');

      const sortButtons = Array.from(document.querySelectorAll('.sort-btn'));
      let sortKey = 'last_updated';
      let sortDir = 'desc';

      const selectAll = document.getElementById('dbSelectAll');
      const checks = Array.from(document.querySelectorAll('.db-check'));
      const bulkCount = document.getElementById('dbBulkCount');
      const bulkAction = document.getElementById('dbBulkAction');
      const applyBulk = document.getElementById('dbApplyBulk');
      const bulkForm = document.getElementById('dbBulkForm');

      const detailModal = document.getElementById('dbDetailModal');

      function updateBulkState() {
        const selected = checks.filter((c) => c.checked).length;
        if (bulkCount) bulkCount.textContent = selected + ' selected';
        if (applyBulk) applyBulk.disabled = selected === 0 || !(bulkAction?.value || '').trim();

        const visibleChecks = checks.filter((c) => c.closest('tr')?.style.display !== 'none');
        const visibleSelected = visibleChecks.filter((c) => c.checked).length;
        if (selectAll) {
          selectAll.checked = visibleChecks.length > 0 && visibleSelected === visibleChecks.length;
          selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleChecks.length;
        }
        rows.forEach((row) => row.classList.toggle('selected', !!row.querySelector('.db-check:checked')));
      }

      function applyFilters() {
        const q = (searchEl?.value || '').trim().toLowerCase();
        const st = (statusEl?.value || '').trim().toLowerCase();
        const tp = (typeEl?.value || '').trim().toLowerCase();
        const src = (sourceEl?.value || '').trim().toLowerCase();

        rows.forEach((row) => {
          const hay = row.getAttribute('data-search') || '';
          const rs = row.getAttribute('data-status') || '';
          const rt = row.getAttribute('data-type') || '';
          const rr = row.getAttribute('data-source') || '';
          const ok = (!q || hay.includes(q)) && (!st || rs === st) && (!tp || rt === tp) && (!src || rr === src);
          row.style.display = ok ? '' : 'none';
        });
        updateBulkState();
      }

      function sortRows() {
        const tbody = document.querySelector('#dbTable tbody');
        if (!tbody) return;
        const sorted = [...rows].sort((a, b) => {
          const getNum = (row, key) => parseFloat(row.getAttribute(key) || '0');
          const getStr = (row, key) => (row.getAttribute(key) || '').toLowerCase();

          let cmp = 0;
          if (sortKey === 'name') cmp = getStr(a, 'data-name').localeCompare(getStr(b, 'data-name'));
          else if (sortKey === 'type') cmp = getStr(a, 'data-type').localeCompare(getStr(b, 'data-type'));
          else if (sortKey === 'source') cmp = getStr(a, 'data-source').localeCompare(getStr(b, 'data-source'));
          else if (sortKey === 'connected') cmp = getNum(a, 'data-connected') - getNum(b, 'data-connected');
          else if (sortKey === 'size') cmp = getNum(a, 'data-size') - getNum(b, 'data-size');
          else if (sortKey === 'last_sync') cmp = getNum(a, 'data-last-sync') - getNum(b, 'data-last-sync');
          else if (sortKey === 'last_updated') cmp = getNum(a, 'data-last-updated') - getNum(b, 'data-last-updated');

          return sortDir === 'asc' ? cmp : -cmp;
        });
        sorted.forEach((r) => tbody.appendChild(r));
      }

      sortButtons.forEach((btn) => {
        btn.addEventListener('click', function(){
          const key = btn.getAttribute('data-sort') || 'last_updated';
          if (sortKey === key) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
          else {
            sortKey = key;
            sortDir = (key === 'name' || key === 'type' || key === 'source') ? 'asc' : 'desc';
          }
          sortButtons.forEach((b) => {
            b.classList.toggle('active', b === btn);
            const arrow = b.querySelector('.arrow');
            if (!arrow) return;
            arrow.textContent = b === btn ? (sortDir === 'asc' ? '↑' : '↓') : '↕';
          });
          sortRows();
        });
      });

      [searchEl, statusEl, typeEl, sourceEl].forEach((el) => {
        el?.addEventListener('input', applyFilters);
        el?.addEventListener('change', applyFilters);
      });

      resetBtn?.addEventListener('click', function(){
        if (searchEl) searchEl.value = '';
        if (statusEl) statusEl.value = '';
        if (typeEl) typeEl.value = '';
        if (sourceEl) sourceEl.value = '';
        applyFilters();
      });

      checks.forEach((c) => c.addEventListener('change', updateBulkState));
      selectAll?.addEventListener('change', function(){
        const checked = !!selectAll.checked;
        checks.forEach((c) => {
          if (c.closest('tr')?.style.display === 'none') return;
          c.checked = checked;
        });
        updateBulkState();
      });
      bulkAction?.addEventListener('change', updateBulkState);
      bulkForm?.addEventListener('submit', function(e){
        const action = (bulkAction?.value || '').trim();
        if (!action) { e.preventDefault(); return; }
        if (action === 'delete' && !confirm('Delete selected databases? This action cannot be undone.')) e.preventDefault();
      });

      function parseDetail(row) {
        try { return JSON.parse(row.getAttribute('data-detail') || '{}'); }
        catch (_) { return {}; }
      }

      function openDetail(row) {
        const d = parseDetail(row);
        document.getElementById('dbDetailSub').textContent = d.key || '';
        document.getElementById('dName').textContent = d.name || '—';
        document.getElementById('dKey').textContent = d.key || '—';
        document.getElementById('dType').textContent = d.type || '—';
        document.getElementById('dStatus').textContent = d.status || '—';
        document.getElementById('dSource').textContent = d.source || '—';
        document.getElementById('dConnected').textContent = d.connected || '—';
        document.getElementById('dSize').textContent = d.size || '—';
        document.getElementById('dRecords').textContent = d.records || '—';
        document.getElementById('dStorage').textContent = d.storage || '—';
        document.getElementById('dSync').textContent = d.last_sync || '—';
        document.getElementById('dUpdated').textContent = d.last_updated || '—';
        document.getElementById('dSchema').textContent = d.schema || '—';
        detailModal.style.display = 'flex';
      }

      rows.forEach((row) => {
        row.querySelectorAll('[data-open-detail]').forEach((el) => {
          el.addEventListener('click', () => openDetail(row));
        });
        row.addEventListener('click', function(e){
          if (e.target.closest('a,button,input,select,summary,details,form,label')) return;
          const openUrl = row.getAttribute('data-open-url') || '#';
          if (openUrl && openUrl !== '#') window.location.href = openUrl;
          else openDetail(row);
        });
      });

      document.getElementById('dbCloseModal')?.addEventListener('click', function(){
        if (detailModal) detailModal.style.display = 'none';
      });
      detailModal?.addEventListener('click', function(e){
        if (e.target === detailModal) detailModal.style.display = 'none';
      });

      sortRows();
      applyFilters();
      updateBulkState();
    })();
  </script>
</body>
</html>
