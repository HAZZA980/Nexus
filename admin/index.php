<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\Site;
use NexusCMS\Models\Page;

$base = base_path();
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
foreach ($sites as $site) {
  $status = normalize_status($site);
  $domain = domain_display($site, $base);
  $lastUpdated = last_updated_meta($site);

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
      try {
        const stored = localStorage.getItem('nexusTheme');
        if (stored === 'light') document.documentElement.classList.add('theme-light');
      } catch(e) {}
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
      --live: #22c55e;
      --draft: #9ca3af;
      --disabled: #ef4444;
      --radius: 12px;
      --shadow: 0 12px 40px rgba(0,0,0,0.25);
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
      --live: #16a34a;
      --draft: #9ca3af;
      --disabled: #ef4444;
      --shadow: 0 10px 30px rgba(15,23,42,0.08);
      --focus: 0 0 0 3px rgba(37,99,235,0.28);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Inter", "Helvetica Neue", system-ui, -apple-system, sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
      transition: background 0.2s ease, color 0.2s ease;
    }
    a { color: inherit; text-decoration: none; }
    a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, summary:focus-visible {
      outline: none;
      box-shadow: var(--focus);
      border-color: var(--primary);
    }
    main { max-width: 1200px; margin: 0 auto; padding: 20px 20px 48px; }
    .top-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 14px 18px;
      background: linear-gradient(90deg, rgba(91,33,182,0.12), rgba(91,33,182,0));
      border-bottom: 1px solid var(--border);
      position: sticky;
      top: 0;
      backdrop-filter: blur(10px);
      z-index: 10;
    }
    .brand {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-weight: 600;
    }
    .brand-mark {
      width: 36px; height: 36px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--primary), #22c55e);
      display: grid; place-items: center;
      font-weight: 700;
      letter-spacing: -0.02em;
      box-shadow: var(--shadow);
    }
    .brand-text { display: flex; flex-direction: column; line-height: 1.2; }
    .brand-text small { color: var(--muted); font-weight: 500; }

    .top-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 14px;
      min-height: 44px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.06);
      color: var(--text);
      cursor: pointer;
      font-weight: 600;
      transition: transform 0.15s ease, background 0.15s ease, border 0.15s ease;
    }
    .btn:hover { background: rgba(255,255,255,0.1); transform: translateY(-1px); }
    .btn.primary {
      background: linear-gradient(135deg, var(--primary), var(--primary-strong));
      border-color: rgba(255,255,255,0.08);
      color: #f8fbff;
      box-shadow: 0 8px 24px rgba(37,99,235,0.35);
    }
    .btn.primary:hover { transform: translateY(-1px); background: linear-gradient(135deg, var(--primary-strong), var(--primary)); }

    .user-menu {
      position: relative;
      min-width: 180px;
    }
    .user-menu details {
      position: relative;
    }
    .user-menu summary {
      list-style: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      min-height: 44px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.05);
      font-weight: 600;
    }
    .user-menu summary::-webkit-details-marker { display: none; }
    .user-avatar {
      width: 34px; height: 34px;
      border-radius: 10px;
      background: linear-gradient(135deg, #22c55e, #3b82f6);
      display: grid; place-items: center;
      font-weight: 700;
      color: #0b1224;
    }
    .user-menu .menu {
      position: absolute;
      right: 0;
      top: calc(100% + 6px);
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 10px;
      min-width: 220px;
      box-shadow: var(--shadow);
      z-index: 5;
    }
    .user-menu .menu button.theme-toggle{
      width:100%;text-align:left;border:none;background:transparent;
      padding:10px 12px;border-radius:10px;color:var(--text);cursor:pointer;
    }
    .user-menu .menu button.theme-toggle:hover{background:rgba(255,255,255,0.06);}
    .user-menu .menu a {
      display: block;
      padding: 10px 10px;
      border-radius: 10px;
      text-decoration: none;
    }
    .user-menu .menu a:hover { background: rgba(255,255,255,0.06); }
    .user-meta { color: var(--muted); font-size: 14px; padding: 6px 10px 10px; }

    .page-head {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 14px;
      align-items: end;
      margin: 28px 0 12px;
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
      padding: 12px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.04);
      min-height: 44px;
      color: var(--text);
    }
    .input input, .input select {
      background: transparent;
      border: none;
      outline: none;
      color: inherit;
      width: 180px;
      font-size: 15px;
    }
    .input select { width: auto; min-width: 140px; }

    .cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 14px;
      margin-top: 18px;
    }
    .site-card {
      border: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
      border-radius: var(--radius);
      padding: 16px;
      box-shadow: var(--shadow);
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 13px;
      letter-spacing: -0.01em;
      border: 1px solid var(--border);
      text-transform: capitalize;
    }
    .status.live { color: #0f5132; background: rgba(34,197,94,0.18); border-color: rgba(34,197,94,0.45); }
    .status.draft { color: #6b3b05; background: rgba(245,158,11,0.16); border-color: rgba(245,158,11,0.4); }
    .status.disabled { color: #7f1d1d; background: rgba(239,68,68,0.14); border-color: rgba(239,68,68,0.4); }

    .site-card h2 { margin: 0; font-size: 20px; letter-spacing: -0.01em; }
    .site-card h2 a { text-decoration: none; }
    .site-card h2 a:hover { color: #cfe3ff; }

    .meta { display: flex; flex-wrap: wrap; gap: 8px 14px; color: var(--muted); font-size: 14px; }
    .meta strong { color: var(--text); font-weight: 600; }

    .actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .actions .btn {
      flex: 1 1 100px;
      justify-content: flex-start;
      background: rgba(255,255,255,0.06);
    }

    .empty {
      margin: 28px 0;
      padding: 26px;
      border: 1px dashed var(--border);
      border-radius: var(--radius);
      background: rgba(255,255,255,0.03);
      text-align: center;
    }
    .empty h3 { margin: 0 0 10px; }
    .empty p { margin: 0 0 14px; color: var(--muted); }

    .table-view { display: none; margin-top: 16px; }
    table { width: 100%; border-collapse: collapse; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--border); }
    th { color: var(--muted); font-weight: 600; font-size: 14px; }
    tr:last-child td { border-bottom: none; }
    .recent {
      margin-top: 24px;
      padding: 16px;
      border-radius: var(--radius);
      border: 1px solid var(--border);
      background: rgba(59,130,246,0.08);
    }
    .recent h3 { margin: 0 0 8px; }
    .sr-only {
      position: absolute;
      width: 1px; height: 1px;
      padding: 0; margin: -1px;
      overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
    }

    @media (max-width: 720px) {
      .page-head { grid-template-columns: 1fr; }
      .top-bar { flex-direction: column; align-items: flex-start; position: sticky; }
      .actions .btn { flex: 1 1 100%; }
      .cards-grid { display: none; }
      .table-view { display: block; }
    }
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
    <div class="top-actions">
      <a class="btn primary" href="<?= $base ?>/admin/site_new.php">+ Create new website</a>
      <div class="user-menu">
        <details>
          <summary aria-haspopup="menu">
            <span class="user-avatar" aria-hidden="true">
              <?php
                $initial = $currentUser['display_name'] ?? $currentUser['email'] ?? 'U';
                $initial = strtoupper(mb_substr($initial, 0, 1));
                echo Security::e($initial);
              ?>
            </span>
            <span>
              <?= Security::e($currentUser['display_name'] ?? $currentUser['email'] ?? 'User') ?>
              <?php if (!empty($currentUser['role'])): ?>
                <small style="display:block;color:var(--muted);font-weight:500;"><?= Security::e(ucfirst((string)$currentUser['role'])) ?></small>
              <?php endif; ?>
            </span>
          </summary>
          <div class="menu" role="menu">
            <div class="user-meta">Logged in <?= Security::e($currentUser['email'] ?? 'as admin') ?></div>
            <button type="button" class="theme-toggle" id="themeToggleBtn" role="menuitem">🌙 Switch to light</button>
            <a role="menuitem" href="<?= $base ?>/admin/logout.php">Logout</a>
          </div>
        </details>
      </div>
    </div>
  </header>
  <main>
    <div class="page-head">
      <div>
        <h1>Websites</h1>
        <p>Select a website to manage content, settings, and publishing.</p>
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

    <?php if (!$siteCards): ?>
      <div class="empty" role="status">
        <h3>You don’t have any websites yet.</h3>
        <p>Create your first website to start managing content.</p>
        <a class="btn primary" href="<?= $base ?>/admin/site_new.php">Create your first website</a>
      </div>
    <?php else: ?>
      <section aria-label="Websites" class="cards-view">
        <div id="cardGrid" class="cards-grid">
          <?php foreach ($siteCards as $site): ?>
            <article class="site-card" data-name="<?= Security::e(strtolower($site['name'])) ?>" data-domain="<?= Security::e(strtolower($site['domain'])) ?>" data-status="<?= Security::e($site['status']) ?>">
              <div class="meta" aria-label="Site status">
                <span class="status <?= Security::e($site['status']) ?>"><?= Security::e(ucfirst($site['status'])) ?></span>
              </div>
              <h2>
                <a href="<?= Security::e($site['admin_url']) ?>">
                  <?= Security::e($site['name']) ?>
                </a>
              </h2>
              <div class="meta">
                <div>
                  <strong>Domain: </strong>
                  <a href="<?= Security::e($site['domain_url']) ?>" target="_blank" rel="noopener"><?= Security::e($site['domain']) ?></a>
                </div>
                <div>
                  <strong>Last updated: </strong>
                  <span title="<?= Security::e($site['last_updated']['exact']) ?>" aria-label="Last updated <?= Security::e($site['last_updated']['exact']) ?>">
                    <?= Security::e($site['last_updated']['relative']) ?>
                  </span>
                </div>
              </div>
              <div class="actions" role="group" aria-label="Quick actions">
                <a class="btn" href="<?= Security::e($site['view_url']) ?>" target="_blank" rel="noopener" aria-label="View public site <?= Security::e($site['name']) ?>">View site</a>
                <?php if ($canManageSettings): ?>
                  <a class="btn" href="<?= Security::e($site['settings_url']) ?>" aria-label="Open settings for <?= Security::e($site['name']) ?>">Settings</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <div id="emptySearch" class="empty" role="status" hidden>
          <h3>No websites match your search.</h3>
          <p>Try adjusting your keywords or filters.</p>
          <button class="btn" type="button" id="resetFilters">Reset filters</button>
        </div>
      </section>

      <section class="table-view" aria-label="Websites table view">
        <table>
          <thead>
            <tr>
              <th scope="col">Site</th>
              <th scope="col">Status</th>
              <th scope="col">Domain</th>
              <th scope="col">Last updated</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody id="tableBody">
            <?php foreach ($siteCards as $site): ?>
              <tr class="site-row" data-name="<?= Security::e(strtolower($site['name'])) ?>" data-domain="<?= Security::e(strtolower($site['domain'])) ?>" data-status="<?= Security::e($site['status']) ?>">
                <td>
                  <a href="<?= Security::e($site['admin_url']) ?>"><?= Security::e($site['name']) ?></a>
                  <div style="color:var(--muted);font-size:13px;"><?= Security::e($site['slug'] ? '/s/' . $site['slug'] : $site['domain']) ?></div>
                </td>
                <td><span class="status <?= Security::e($site['status']) ?>"><?= Security::e(ucfirst($site['status'])) ?></span></td>
                <td><a href="<?= Security::e($site['domain_url']) ?>" target="_blank" rel="noopener"><?= Security::e($site['domain']) ?></a></td>
                <td>
                  <span title="<?= Security::e($site['last_updated']['exact']) ?>" aria-label="Last updated <?= Security::e($site['last_updated']['exact']) ?>">
                    <?= Security::e($site['last_updated']['relative']) ?>
                  </span>
                </td>
                <td>
                  <div class="actions">
                    <a class="btn" href="<?= Security::e($site['admin_url']) ?>">Open</a>
                    <a class="btn" href="<?= Security::e($site['view_url']) ?>" target="_blank" rel="noopener">View</a>
                    <?php if ($canManageSettings): ?>
                      <a class="btn" href="<?= Security::e($site['settings_url']) ?>">Settings</a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>

      <?php if ($recentActivity): ?>
        <section class="recent" aria-labelledby="recent-activity">
          <h3 id="recent-activity">Recent activity</h3>
          <p>
            <strong><?= Security::e($recentActivity['name']) ?></strong>
            was updated
            <span title="<?= Security::e($recentActivity['last_updated']['exact']) ?>">
              <?= Security::e($recentActivity['last_updated']['relative']) ?>
            </span>.
            <a href="<?= Security::e($recentActivity['admin_url']) ?>">Open admin</a>
          </p>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <script>
    (function() {
      const searchInput = document.getElementById('siteSearch');
      const statusFilter = document.getElementById('statusFilter');
      const rows = Array.from(document.querySelectorAll('.site-row'));
      const resetBtn = document.getElementById('resetFilters');
      const emptyTable = document.getElementById('emptyTable');
      const themeBtn = document.getElementById('themeToggleBtn');

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
        const noResults = visible === 0 && (!!query || !!status);
        if (resetBtn) resetBtn.style.display = (query || status) ? 'inline-flex' : 'none';
        if (emptyTable) emptyTable.style.display = noResults ? 'block' : 'none';
      }

      function resetFilters() {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = '';
        applyFilters();
        if (searchInput) searchInput.focus();
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

      function setTheme(mode) {
        if (mode === 'light') {
          document.documentElement.classList.add('theme-light');
          if (themeBtn) themeBtn.textContent = '🌙 Switch to dark';
        } else {
          document.documentElement.classList.remove('theme-light');
          if (themeBtn) themeBtn.textContent = '☀️ Switch to light';
        }
        try { localStorage.setItem('nexusTheme', mode); } catch(e) {}
      }
      if (themeBtn) {
        themeBtn.addEventListener('click', () => {
          const isLight = document.documentElement.classList.contains('theme-light');
          setTheme(isLight ? 'dark' : 'light');
        });
        const stored = (() => { try { return localStorage.getItem('nexusTheme'); } catch(e) { return null; } })();
        if (stored === 'light') setTheme('light'); else setTheme('dark');
      }

      const closeAllKebabs = () => document.querySelectorAll('.kebab-menu').forEach(m => m.style.display = 'none');
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('.kebab-btn');
        if (btn) {
          const menu = btn.parentElement.querySelector('.kebab-menu');
          const expanded = btn.getAttribute('aria-expanded') === 'true';
          closeAllKebabs();
          if (!expanded) {
            menu.style.display = 'block';
            btn.setAttribute('aria-expanded','true');
          } else {
            btn.setAttribute('aria-expanded','false');
          }
          return;
        }
        if (!e.target.closest('.kebab')) closeAllKebabs();
      });

      if (searchInput) searchInput.addEventListener('input', applyFilters);
      if (statusFilter) statusFilter.addEventListener('change', applyFilters);
      if (resetBtn) resetBtn.addEventListener('click', resetFilters);
      applyFilters();
    })();
  </script>
</body>
</html>
