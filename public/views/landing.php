<?php
use NexusCMS\Core\Security;

$base = base_path();

$userName = trim((string)($_SESSION['username'] ?? $_SESSION['display_name'] ?? 'Administrator'));
if ($userName === '') $userName = 'Administrator';

$siteRows = [];
$statusCounts = ['live' => 0, 'draft' => 0, 'disabled' => 0];

foreach ($sites as $s) {
  $status = strtolower(trim((string)($s['status'] ?? 'live')));
  if (!in_array($status, ['live', 'draft', 'disabled'], true)) $status = 'live';

  $slug = trim((string)($s['slug'] ?? ''));
  $domain = trim((string)($s['domain'] ?? $s['primary_domain'] ?? ''));
  $url = $domain !== ''
    ? (preg_match('~^https?://~i', $domain) ? $domain : 'https://' . $domain)
    : ($slug !== '' ? $base . '/s/' . $slug . '/home' : $base . '/');

  $updatedAtRaw = trim((string)($s['updated_at'] ?? $s['created_at'] ?? ''));
  $updatedAt = $updatedAtRaw !== '' ? date('Y-m-d H:i', strtotime($updatedAtRaw)) : 'n/a';

  $siteRows[] = [
    'id' => (int)($s['id'] ?? 0),
    'name' => trim((string)($s['name'] ?? '')) ?: 'Untitled site',
    'slug' => $slug,
    'url' => $url,
    'status' => $status,
    'updated_at' => $updatedAt,
    'description' => trim((string)($s['description'] ?? '')),
  ];

  $statusCounts[$status]++;
}

$totalSites = count($siteRows);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NexusCMS Admin</title>
  <style>
    :root{
      --bg:#f3f4f6;
      --panel:#ffffff;
      --line:#d1d5db;
      --text:#111827;
      --muted:#4b5563;
      --accent:#1d4ed8;
      --danger:#b91c1c;
      --ok:#166534;
      --warn:#92400e;
      --off:#374151;
      --sidebar-w:220px;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background:var(--bg);
      color:var(--text);
      font:14px/1.4 Arial, Helvetica, sans-serif;
    }
    a{color:inherit;text-decoration:none}

    .admin-shell{
      min-height:100vh;
      display:grid;
      grid-template-columns:var(--sidebar-w) 1fr;
    }

    .sidebar{
      border-right:1px solid var(--line);
      background:#f9fafb;
      padding:12px;
    }
    .brand{
      font-weight:700;
      font-size:18px;
      margin:2px 4px 12px;
      letter-spacing:.2px;
    }
    .nav-group{margin-top:6px}
    .nav-label{
      color:var(--muted);
      font-size:11px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:.5px;
      margin:12px 8px 6px;
    }
    .nav-link{
      display:block;
      padding:8px 10px;
      border:1px solid transparent;
      border-radius:4px;
      margin-bottom:4px;
      color:#1f2937;
      font-weight:600;
    }
    .nav-link:hover{background:#eef2f7;border-color:#dbe1ea}
    .nav-link.active{
      background:#e8eefc;
      border-color:#c7d5fb;
      color:#1e3a8a;
    }

    .workspace{
      min-width:0;
      display:flex;
      flex-direction:column;
    }

    .utility-bar{
      height:48px;
      border-bottom:1px solid var(--line);
      background:var(--panel);
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:0 14px;
    }
    .utility-left{font-weight:700}
    .utility-right{
      display:flex;
      align-items:center;
      gap:12px;
      color:var(--muted);
    }
    .util-link{
      color:#1f2937;
      font-weight:600;
      text-decoration:underline;
      text-decoration-thickness:1px;
      text-underline-offset:2px;
    }
    .util-link.logout{color:var(--danger)}

    .content{
      padding:14px;
      display:grid;
      gap:12px;
    }

    .panel{
      background:var(--panel);
      border:1px solid var(--line);
      border-radius:4px;
    }
    .panel-head{
      padding:10px 12px;
      border-bottom:1px solid var(--line);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:8px;
    }
    .panel-title{
      margin:0;
      font-size:16px;
      font-weight:700;
    }

    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:30px;
      padding:0 10px;
      border:1px solid #9ca3af;
      border-radius:4px;
      background:#fff;
      color:#111827;
      font-weight:600;
      font-size:13px;
    }
    .btn.primary{
      border-color:#1e40af;
      background:#1d4ed8;
      color:#fff;
    }

    .summary{
      display:grid;
      grid-template-columns:repeat(4,minmax(120px,1fr));
      gap:8px;
      padding:10px 12px;
    }
    .metric{
      border:1px solid var(--line);
      border-radius:4px;
      padding:8px;
      background:#fff;
    }
    .metric-label{font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700;letter-spacing:.4px}
    .metric-value{font-size:20px;font-weight:700;margin-top:2px}
    .metric.live .metric-value{color:var(--ok)}
    .metric.draft .metric-value{color:var(--warn)}
    .metric.disabled .metric-value{color:var(--off)}

    table{
      width:100%;
      border-collapse:collapse;
      font-size:13px;
    }
    thead th{
      text-align:left;
      background:#f9fafb;
      border-bottom:1px solid var(--line);
      border-top:1px solid var(--line);
      padding:8px 10px;
      color:#111827;
      font-weight:700;
    }
    tbody td{
      border-bottom:1px solid #e5e7eb;
      padding:8px 10px;
      vertical-align:top;
    }
    tbody tr:hover{background:#f8fafc}

    .site-name{font-weight:700}
    .meta{color:var(--muted);font-size:12px;margin-top:2px}
    .status{
      display:inline-block;
      min-width:68px;
      text-align:center;
      padding:2px 8px;
      border-radius:999px;
      border:1px solid transparent;
      font-size:11px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:.2px;
    }
    .status.live{background:#ecfdf3;border-color:#bbf7d0;color:#166534}
    .status.draft{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
    .status.disabled{background:#f3f4f6;border-color:#d1d5db;color:#374151}

    .actions{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }
    .action-link{
      color:#1e3a8a;
      text-decoration:underline;
      text-decoration-thickness:1px;
      text-underline-offset:2px;
      font-weight:600;
      font-size:12px;
    }
    .empty{padding:14px;color:var(--muted)}

    @media (max-width:960px){
      .admin-shell{grid-template-columns:1fr}
      .sidebar{display:none}
      .summary{grid-template-columns:repeat(2,minmax(120px,1fr));}
    }
  </style>
</head>
<body>
  <div class="admin-shell">
    <aside class="sidebar" aria-label="Primary navigation">
      <div class="brand">NexusCMS</div>

      <div class="nav-group">
        <div class="nav-label">Content Management</div>
        <a class="nav-link active" href="<?= $base ?>/admin/index.php">Sites</a>
        <a class="nav-link" href="<?= $base ?>/admin/users.php">Users</a>
        <a class="nav-link" href="<?= $base ?>/admin/images.php">Media</a>
        <a class="nav-link" href="<?= $base ?>/admin/databases.php">Databases</a>
      </div>

      <div class="nav-group">
        <div class="nav-label">Actions</div>
        <a class="nav-link" href="<?= $base ?>/admin/site_new.php">Create Site</a>
      </div>
    </aside>

    <div class="workspace">
      <header class="utility-bar">
        <div class="utility-left">Admin Dashboard</div>
        <div class="utility-right">
          <span><?= Security::e($userName) ?></span>
          <a class="util-link" href="<?= $base ?>/admin/users.php">Settings</a>
          <a class="util-link logout" href="<?= $base ?>/admin/logout.php">Logout</a>
        </div>
      </header>

      <main class="content">
        <section class="panel" aria-label="Site summary">
          <div class="panel-head">
            <h1 class="panel-title">Sites</h1>
            <a class="btn primary" href="<?= $base ?>/admin/site_new.php">Create Site</a>
          </div>
          <div class="summary">
            <div class="metric all">
              <div class="metric-label">Total Sites</div>
              <div class="metric-value"><?= (int)$totalSites ?></div>
            </div>
            <div class="metric live">
              <div class="metric-label">Live</div>
              <div class="metric-value"><?= (int)$statusCounts['live'] ?></div>
            </div>
            <div class="metric draft">
              <div class="metric-label">Draft</div>
              <div class="metric-value"><?= (int)$statusCounts['draft'] ?></div>
            </div>
            <div class="metric disabled">
              <div class="metric-label">Disabled</div>
              <div class="metric-value"><?= (int)$statusCounts['disabled'] ?></div>
            </div>
          </div>
        </section>

        <section class="panel" aria-label="Site list">
          <div class="panel-head">
            <h2 class="panel-title">All Sites</h2>
          </div>

          <?php if (!$siteRows): ?>
            <div class="empty">No sites available. Use <strong>Create Site</strong> to add your first site.</div>
          <?php else: ?>
            <div style="overflow:auto;">
              <table>
                <thead>
                  <tr>
                    <th style="min-width:220px;">Site</th>
                    <th style="min-width:140px;">Slug</th>
                    <th style="min-width:190px;">Status</th>
                    <th style="min-width:170px;">Last Updated</th>
                    <th style="min-width:230px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($siteRows as $row): ?>
                    <tr>
                      <td>
                        <div class="site-name"><?= Security::e($row['name']) ?></div>
                        <div class="meta"><?= Security::e($row['description'] !== '' ? $row['description'] : 'No description') ?></div>
                      </td>
                      <td><code><?= Security::e($row['slug'] !== '' ? $row['slug'] : 'n/a') ?></code></td>
                      <td><span class="status <?= Security::e($row['status']) ?>"><?= Security::e($row['status']) ?></span></td>
                      <td><?= Security::e($row['updated_at']) ?></td>
                      <td>
                        <div class="actions">
                          <a class="action-link" href="<?= $base ?>/admin/site.php?id=<?= (int)$row['id'] ?>">Manage Site</a>
                          <a class="action-link" href="<?= Security::e($row['url']) ?>" target="_blank" rel="noopener noreferrer">Open Site</a>
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
    </div>
  </div>
</body>
</html>
