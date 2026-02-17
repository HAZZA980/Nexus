<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Models\Analytics;

$base = base_path();
$activeNav = 'sites';
$themeIsLight = ui_theme_is_light();
$sites = Site::all();
// attach published count
foreach ($sites as &$s) {
  $slug = $s['slug'] ?? '';
  $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM pages WHERE site_id=? AND status='published'");
  $stmt->execute([(int)$s['id']]);
  $s['_published'] = (int)$stmt->fetchColumn();
}
unset($s);

// Fetch the logged-in user for the header menu
$currentUser = null;
if (isset($_SESSION['user_id'])) {
  $stmt = DB::pdo()->prepare("SELECT id, email, display_name, role FROM users WHERE id = ? LIMIT 1");
  $stmt->execute([(int)$_SESSION['user_id']]);
  $currentUser = $stmt->fetch();
}

function normalize_status(array $site): string {
  if ((int)($site['_published'] ?? 0) === 0) return 'draft';
  $raw = strtolower(trim((string)($site['status'] ?? 'live')));
  if (in_array($raw, ['live', 'draft', 'disabled'], true)) return $raw;
  return 'live';
}

function domain_display(array $site, string $base): array {
  $domain = trim((string)($site['domain'] ?? $site['primary_domain'] ?? ''));
  $slug = trim((string)($site['slug'] ?? ''));

  if ($domain !== '') {
    $hasProtocol = (bool)preg_match('~^https?://~i', $domain);
    $url = $hasProtocol ? $domain : 'https://' . $domain;
    return ['display' => $domain, 'url' => $url];
  }

  if ($slug !== '') {
    $url = rtrim($base, '/') . '/s/' . rawurlencode($slug) . '/home';
    return ['display' => '/s/' . $slug, 'url' => $url];
  }

  return ['display' => 'No primary domain', 'url' => rtrim($base, '/') . '/'];
}

function last_updated_meta(array $site): array {
  $raw = $site['updated_at'] ?? $site['created_at'] ?? null;
  $dt = $raw ? date_create($raw) : null;
  if (!$dt) {
    return ['relative' => 'Just now', 'exact' => 'Date not available', 'timestamp' => 0];
  }

  $nowTs = time();
  $ts = $dt->getTimestamp();
  $diff = max(0, $nowTs - $ts);
  $units = [
    31536000 => 'year',
    2592000  => 'month',
    604800   => 'week',
    86400    => 'day',
    3600     => 'hour',
    60       => 'minute',
  ];
  $relative = 'Just now';
  foreach ($units as $seconds => $label) {
    if ($diff >= $seconds) {
      $value = (int)floor($diff / $seconds);
      $relative = $value . ' ' . $label . ($value !== 1 ? 's' : '') . ' ago';
      break;
    }
  }

  return [
    'relative'  => $relative,
    'exact'     => $dt->format('M j, Y g:i A'),
    'timestamp' => $ts,
  ];
}

$siteCards = [];
$stats = [
  'total' => count($sites),
  'live' => 0,
  'draft' => 0,
  'disabled' => 0,
  'published_pages' => 0,
];
foreach ($sites as $site) {
  $status = normalize_status($site);
  $domain = domain_display($site, $base);
  $lastUpdated = last_updated_meta($site);
  $published = (int)($site['_published'] ?? 0);
  $preview = ['views'=>0,'previous'=>0,'delta'=>0,'percent'=>0];
  try {
    $preview = Analytics::preview((int)$site['id'], 7);
  } catch (\Throwable $e) {}

  $stats['published_pages'] += $published;
  if (isset($stats[$status])) {
    $stats[$status] += 1;
  }

  $siteCards[] = [
    'id' => (int)$site['id'],
    'name' => $site['name'] ?: 'Untitled site',
    'slug' => $site['slug'] ?? '',
    'status' => $status,
    'domain' => $domain['display'],
    'domain_url' => $domain['url'],
    'admin_url' => $base . '/admin/site.php?id=' . (int)$site['id'],
    'view_url' => $domain['url'],
    'settings_url' => $base . '/admin/site.php?id=' . (int)$site['id'] . '#settings',
    'last_updated' => $lastUpdated,
    'analytics_preview' => $preview,
  ];
}

$statusOptions = array_values(array_unique(array_map(fn($s) => $s['status'], $siteCards)));
$statusOptions = array_values(array_intersect(['live', 'draft', 'disabled'], $statusOptions) ?: $statusOptions);

$recentActivity = null;
if ($siteCards) {
  usort($siteCards, fn($a, $b) => ($b['last_updated']['timestamp'] ?? 0) <=> ($a['last_updated']['timestamp'] ?? 0));
  $recentActivity = $siteCards[0];
  // Restore original order (newest id first) for display below
  usort($siteCards, fn($a, $b) => $b['id'] <=> $a['id']);
}

$canManageSettings = true;
if ($currentUser && isset($currentUser['role'])) {
  $role = strtolower((string)$currentUser['role']);
  if (in_array($role, ['viewer', 'read-only'], true)) {
    $canManageSettings = false;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin — Websites</title>
  <script>
    (function() {
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    :root {
      --bg: #0b1020;
      --surface: #111827;
      --panel: #0f172a;
      --border: #1e293b;
      --muted: #94a3b8;
      --text: #e2e8f0;
      --primary: #7c3aed;
      --primary-strong: #5b21b6;
      --live: #22c55e;
      --draft: #9ca3af;
      --disabled: #ef4444;
      --radius: 10px;
      --shadow: none;
      --focus: 0 0 0 2px rgba(124,58,237,0.35);
    }
    .theme-light {
      --bg: #f5f7fb;
      --surface: #ffffff;
      --panel: #ffffff;
      --border: #d6dee9;
      --muted: #4b5563;
      --text: #0f172a;
      --primary: #4f46e5;
      --primary-strong: #3730a3;
      --shadow: none;
      --focus: 0 0 0 2px rgba(79,70,229,0.25);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
    }
    a { color: inherit; text-decoration: none; }
    a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, summary:focus-visible {
      outline: none;
      box-shadow: var(--focus);
      border-color: var(--primary);
    }
    main { max-width: 1200px; margin: 0 auto; padding: 18px 18px 36px; }

    .page-head {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 14px;
      align-items: end;
      margin: 20px 0 10px;
    }
    .page-head h1 { margin: 0; font-size: 32px; letter-spacing: -0.02em; }
    .page-head p { margin: 6px 0 0; color: var(--muted); }

    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
    }
    .input {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--panel);
      min-height: 38px;
      color: var(--text);
    }
    .input input, .input select {
      background: transparent;
      border: none;
      outline: none;
      color: inherit;
      width: 180px;
      font-size: 14px;
    }
    .input select { width: auto; min-width: 120px; }

    .summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 10px;
      margin: 14px 0 10px;
    }
    .summary-item{
      border:1px solid var(--border);
      border-radius:8px;
      padding:10px 12px;
      background:var(--panel);
      display:flex;
      flex-direction:column;
      gap:4px;
    }
    .summary-label{font-size:13px;color:var(--muted);}
    .summary-value{font-size:18px;font-weight:700;letter-spacing:-0.01em;}

    table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--border); font-size: 14px; }
    th { color: var(--muted); font-weight: 600; }
    tr:last-child td { border-bottom: none; }
    .status {
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:4px 10px;
      border-radius:999px;
      font-weight:600;
      font-size:12px;
      border:1px solid var(--border);
      text-transform:capitalize;
    }
    .status.live { color: #0f5132; background: rgba(34,197,94,0.12); border-color: rgba(34,197,94,0.3); }
    .status.draft { color: #92400e; background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.3); }
    .status.disabled { color: #7f1d1d; background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.3); }
    .delta-chip{
      display:inline-flex;align-items:center;gap:6px;
      padding:4px 8px;border-radius:8px;border:1px solid var(--border);
      font-weight:700;font-size:12px;
    }
    .delta-chip.pos{color:#22c55e;border-color:rgba(34,197,94,0.35);}
    .delta-chip.neg{color:#ef4444;border-color:rgba(239,68,68,0.35);}

    .empty {
      margin: 18px 0;
      padding: 18px;
      border: 1px dashed var(--border);
      border-radius: var(--radius);
      background: var(--panel);
      text-align: center;
    }
    .empty h3 { margin: 0 0 10px; }
    .empty p { margin: 0 0 14px; color: var(--muted); }

    .sr-only {
      position: absolute;
      width: 1px; height: 1px;
      padding: 0; margin: -1px;
      overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
    }

    @media (max-width: 720px) {
      .page-head { grid-template-columns: 1fr; }
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <div class="page-head">
      <div>
        <h1>Websites</h1>
        <p>Administrative overview of all sites and their current state.</p>
      </div>
      <form class="filters" role="search" aria-label="Search websites">
        <label class="sr-only" for="siteSearch">Search websites</label>
        <div class="input">
          <input id="siteSearch" name="q" type="search" placeholder="Search websites…" autocomplete="off">
        </div>
        <?php if (count($statusOptions) > 1): ?>
          <label class="sr-only" for="statusFilter">Filter by status</label>
          <div class="input">
            <select id="statusFilter" name="status">
              <option value="">All statuses</option>
              <?php foreach ($statusOptions as $status): ?>
                <option value="<?= Security::e($status) ?>"><?= Security::e(ucfirst($status)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </form>
    </div>

    <div class="summary" aria-label="Overview metrics">
      <div class="summary-item">
        <div class="summary-label">Total sites</div>
        <div class="summary-value"><?= (int)$stats['total']; ?></div>
      </div>
      <div class="summary-item">
        <div class="summary-label">Live</div>
        <div class="summary-value"><?= (int)$stats['live']; ?></div>
      </div>
      <div class="summary-item">
        <div class="summary-label">Draft</div>
        <div class="summary-value"><?= (int)$stats['draft']; ?></div>
      </div>
      <div class="summary-item">
        <div class="summary-label">Disabled</div>
        <div class="summary-value"><?= (int)$stats['disabled']; ?></div>
      </div>
      <div class="summary-item">
        <div class="summary-label">Published pages</div>
        <div class="summary-value"><?= (int)$stats['published_pages']; ?></div>
      </div>
    </div>

    <?php if (!$siteCards): ?>
      <div class="empty" role="status">
        <h3>You don’t have any websites yet.</h3>
        <p>Create your first website to start managing content.</p>
        <a class="btn primary" href="<?= $base ?>/admin/site_new.php">Create your first website</a>
      </div>
    <?php else: ?>
      <table aria-label="Websites">
        <thead>
          <tr>
            <th scope="col">Site</th>
            <th scope="col">Domain</th>
            <th scope="col">Status</th>
            <th scope="col">Published pages</th>
            <th scope="col">Last 7d views</th>
            <th scope="col">Updated</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <?php foreach ($siteCards as $site): ?>
            <tr class="site-row" data-name="<?= Security::e(strtolower($site['name'])) ?>" data-domain="<?= Security::e(strtolower($site['domain'])) ?>" data-status="<?= Security::e($site['status']) ?>">
              <td>
                <a href="<?= Security::e($site['admin_url']) ?>"><?= Security::e($site['name']) ?></a>
                <div style="color:var(--muted);font-size:13px;"><?= $site['slug'] ? '/s/' . Security::e($site['slug']) : '—' ?></div>
              </td>
              <td><a href="<?= Security::e($site['domain_url']) ?>" target="_blank" rel="noopener"><?= Security::e($site['domain']) ?></a></td>
              <td><span class="status <?= Security::e($site['status']) ?>"><?= Security::e(ucfirst($site['status'])) ?></span></td>
              <td><?= (int)$site['_published'] ?></td>
              <td>
                <div style="display:flex;flex-direction:column;gap:4px;">
                  <div style="font-weight:700;"><?= (int)($site['analytics_preview']['views'] ?? 0) ?> views</div>
                  <?php $delta = (int)($site['analytics_preview']['delta'] ?? 0); $pct = $site['analytics_preview']['percent'] ?? 0; ?>
                  <div class="delta-chip <?= $delta>=0 ? 'pos' : 'neg' ?>">
                    <?= $delta>=0 ? '▲' : '▼' ?> <?= abs($pct) ?>%
                  </div>
                </div>
              </td>
              <td title="<?= Security::e($site['last_updated']['exact']) ?>"><?= Security::e($site['last_updated']['relative']) ?></td>
              <td>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                  <a class="btn primary" href="<?= Security::e($site['admin_url']) ?>">Manage</a>
                  <a class="btn" href="<?= Security::e($site['view_url']) ?>" target="_blank" rel="noopener">Open</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>

  <script>
    (function() {
      const searchInput = document.getElementById('siteSearch');
      const statusFilter = document.getElementById('statusFilter');
      const rows = Array.from(document.querySelectorAll('.site-row'));

      function matches(row, query, status) {
        const name = (row.dataset.name || '').toLowerCase();
        const domain = (row.dataset.domain || '').toLowerCase();
        const stat = (row.dataset.status || '').toLowerCase();
        const qMatch = !query || name.includes(query) || domain.includes(query);
        const sMatch = !status || stat === status;
        return qMatch && sMatch;
      }

      function applyFilters() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const status = (statusFilter?.value || '').toLowerCase();
        let visible = 0;
        rows.forEach(row => {
          const ok = matches(row, query, status);
          row.style.display = ok ? '' : 'none';
          if (ok) visible++;
        });
      }

      function relTime(ts) {
        const d = new Date(ts);
        if (Number.isNaN(d.getTime())) return ts;
        const diff = Date.now() - d.getTime();
        const mins = Math.floor(diff / 60000);
        if (mins < 1) return 'just now';
        if (mins < 60) return `${mins} min${mins>1?'s':''} ago`;
        const hrs = Math.floor(mins/60);
        if (hrs < 24) return `${hrs} hour${hrs>1?'s':''} ago`;
        const days = Math.floor(hrs/24);
        if (days < 30) return `${days} day${days>1?'s':''} ago`;
        const months = Math.floor(days/30);
        if (months < 12) return `${months} mo${months>1?'s':''} ago`;
        const years = Math.floor(months/12);
        return `${years} yr${years>1?'s':''} ago`;
      }
      document.querySelectorAll('.updated').forEach(el => {
        const ts = el.dataset.updated;
        if (!ts) return;
        el.textContent = relTime(ts);
      });

      if (searchInput) searchInput.addEventListener('input', applyFilters);
      if (statusFilter) statusFilter.addEventListener('change', applyFilters);
      applyFilters();
    })();
  </script>
</body>
</html>
