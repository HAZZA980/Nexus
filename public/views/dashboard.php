<?php
use NexusCMS\Core\Security;

$base = base_path();
$activeNav = 'dashboard';
$themeIsLight = ui_theme_is_light();
$displayRole = trim((string)($currentUser['role'] ?? $_SESSION['user_role'] ?? 'administrator'));
$displayRole = $displayRole !== '' ? ucwords(str_replace('_', ' ', $displayRole)) : 'Administrator';

$summaryCards = [
  ['label' => 'Sites', 'value' => (int)($totals['sites'] ?? 0), 'tone' => 'neutral'],
  ['label' => 'Published Pages', 'value' => (int)($totals['pages_published'] ?? 0), 'tone' => 'success'],
  ['label' => 'Draft Pages', 'value' => (int)($totals['pages_draft'] ?? 0), 'tone' => 'warning'],
  ['label' => 'Views (7d)', 'value' => number_format((int)($totals['views'] ?? 0)), 'tone' => 'neutral'],
  ['label' => 'Unique Visitors', 'value' => number_format((int)($totals['unique'] ?? 0)), 'tone' => 'neutral'],
  ['label' => 'Avg Load', 'value' => ((int)($totals['avg_load_ms'] ?? 0) > 0 ? (int)$totals['avg_load_ms'] . ' ms' : 'n/a'), 'tone' => 'neutral'],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NexusCMS Dashboard</title>
  <script nonce="<?= Security::e(csp_nonce()) ?>">
    document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
  </script>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
  <style>
    :root{
      --dash-bg:
        radial-gradient(circle at top left, rgba(59,130,246,.12), transparent 32%),
        linear-gradient(180deg, #0f172a 0%, #0b1220 100%);
      --dash-text: #e5e7eb;
      --dash-muted: #94a3b8;
      --dash-panel: #111827;
      --dash-panel-soft: #0f172a;
      --dash-line: #334155;
      --dash-shadow: 0 18px 40px rgba(2,6,23,.32);
      --dash-accent: #60a5fa;
      --dash-accent-strong: #93c5fd;
      --dash-success: #22c55e;
      --dash-warn: #f59e0b;
      --dash-danger: #f87171;
      --dash-status-disabled-bg: #1f2937;
      --dash-status-disabled-text: #cbd5e1;
    }
    .theme-light{
      --dash-bg:
        radial-gradient(circle at top left, rgba(31,94,255,.12), transparent 32%),
        linear-gradient(180deg, #f7f9fc 0%, #eef2f7 100%);
      --dash-text: #122033;
      --dash-muted: #556274;
      --dash-panel: #ffffff;
      --dash-panel-soft: #fbfcfe;
      --dash-line: #d7dde7;
      --dash-shadow: 0 18px 40px rgba(15,23,42,.08);
      --dash-accent: #1f5eff;
      --dash-accent-strong: #153fb3;
      --dash-success: #166534;
      --dash-warn: #9a3412;
      --dash-danger: #b91c1c;
      --dash-status-disabled-bg: #eef2f7;
      --dash-status-disabled-text: #475569;
    }
    body{
      margin:0;
      background:var(--dash-bg);
      color:var(--dash-text);
      font:14px/1.45 Arial, Helvetica, sans-serif;
    }
    .shell{
      padding:14px;
      display:grid;
      gap:12px;
    }
    .hero{
      background:linear-gradient(135deg, #112448 0%, #1a3870 52%, #244c99 100%);
      color:#fff;
      border-radius:20px;
      padding:20px 22px;
      box-shadow:var(--dash-shadow);
      display:grid;
      grid-template-columns:1.3fr .9fr;
      gap:14px;
    }
    .eyebrow{
      margin:0 0 8px;
      font-size:11px;
      letter-spacing:.18em;
      text-transform:uppercase;
      color:rgba(255,255,255,.68);
      font-weight:700;
    }
    .hero h1{
      margin:0;
      font-size:32px;
      line-height:1.05;
      letter-spacing:-.03em;
    }
    .hero p{
      margin:10px 0 0;
      max-width:54ch;
      color:rgba(255,255,255,.82);
    }
    .hero-meta{
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:10px;
      align-content:start;
    }
    .hero-chip{
      padding:12px 14px;
      border-radius:14px;
      background:rgba(255,255,255,.1);
      border:1px solid rgba(255,255,255,.14);
      backdrop-filter:blur(10px);
    }
    .hero-chip-label{
      font-size:11px;
      letter-spacing:.14em;
      text-transform:uppercase;
      color:rgba(255,255,255,.62);
      font-weight:700;
    }
    .hero-chip-value{
      margin-top:4px;
      font-size:20px;
      font-weight:700;
      letter-spacing:-.03em;
    }
    .actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-top:14px;
    }
    .dashboard-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:36px;
      padding:0 14px;
      border-radius:999px;
      font-weight:700;
      font-size:13px;
      text-decoration:none;
      border:1px solid transparent;
    }
    .dashboard-btn.primary{background:#fff;color:#10213e}
    .dashboard-btn.secondary{
      background:transparent;
      color:#fff;
      border-color:rgba(255,255,255,.25);
    }
    .summary{
      display:grid;
      grid-template-columns:repeat(6, minmax(0, 1fr));
      gap:10px;
    }
    .card{
      background:var(--dash-panel);
      border:1px solid var(--dash-line);
      border-radius:16px;
      box-shadow:var(--dash-shadow);
    }
    .metric{
      padding:14px;
      min-height:92px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
    }
    .metric-label{
      color:var(--dash-muted);
      font-size:11px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:.08em;
    }
    .metric-value{
      margin-top:6px;
      font-size:26px;
      line-height:1;
      letter-spacing:-.04em;
      font-weight:800;
    }
    .metric.success .metric-value{color:var(--dash-success)}
    .metric.warning .metric-value{color:var(--dash-warn)}
    .grid-2{
      display:grid;
      grid-template-columns:1.2fr .8fr;
      gap:14px;
    }
    .panel-head{
      padding:14px 16px 0;
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
    }
    .panel-head h2{
      margin:0;
      font-size:20px;
      line-height:1.15;
      letter-spacing:-.02em;
    }
    .panel-head p{
      margin:6px 0 0;
      color:var(--dash-muted);
      font-size:12px;
    }
    .stack{
      padding:12px 16px 16px;
      display:grid;
      gap:10px;
    }
    .message,
    .alert{
      border:1px solid var(--dash-line);
      border-radius:14px;
      padding:12px 14px;
      background:var(--dash-panel-soft);
    }
    .message.error,
    .alert.warning{
      border-color:color-mix(in srgb, var(--dash-danger) 38%, var(--dash-line));
      background:color-mix(in srgb, var(--dash-danger) 10%, var(--dash-panel-soft));
    }
    .message-title,
    .alert-title{
      font-weight:700;
      margin:0 0 4px;
    }
    .message-body,
    .alert-body{
      margin:0;
      color:var(--dash-muted);
    }
    .alert-actions{
      margin-top:8px;
      font-weight:700;
      color:var(--dash-accent-strong);
    }
    .site-grid{
      padding:12px 16px 16px;
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:10px;
    }
    .site-card{
      border:1px solid var(--dash-line);
      border-radius:14px;
      padding:14px;
      display:grid;
      gap:10px;
      background:linear-gradient(180deg, color-mix(in srgb, var(--dash-panel) 88%, #ffffff 12%) 0%, var(--dash-panel-soft) 100%);
    }
    .site-card-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }
    .site-name{
      margin:0;
      font-size:18px;
      line-height:1.15;
      letter-spacing:-.02em;
    }
    .site-meta{
      color:var(--dash-muted);
      font-size:12px;
      margin-top:4px;
    }
    .status{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:74px;
      padding:5px 10px;
      border-radius:999px;
      font-size:11px;
      font-weight:700;
      text-transform:uppercase;
      letter-spacing:.08em;
    }
    .status.live{background:color-mix(in srgb, var(--dash-success) 16%, transparent);color:var(--dash-success)}
    .status.draft{background:color-mix(in srgb, var(--dash-warn) 16%, transparent);color:var(--dash-warn)}
    .status.disabled{background:var(--dash-status-disabled-bg);color:var(--dash-status-disabled-text)}
    .site-stats{
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:8px;
    }
    .site-stat{
      border-radius:12px;
      background:color-mix(in srgb, var(--dash-panel-soft) 88%, transparent);
      padding:10px;
    }
    .site-stat-label{
      color:var(--dash-muted);
      font-size:11px;
      text-transform:uppercase;
      letter-spacing:.08em;
      font-weight:700;
    }
    .site-stat-value{
      margin-top:5px;
      font-size:18px;
      line-height:1;
      font-weight:800;
      letter-spacing:-.03em;
    }
    .site-actions{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      font-size:12px;
      font-weight:700;
      color:var(--dash-accent-strong);
    }
    .table-wrap{
      padding:10px 16px 16px;
      overflow:auto;
    }
    table{
      width:100%;
      border-collapse:collapse;
    }
    th,td{
      padding:11px 10px;
      border-bottom:1px solid var(--dash-line);
      text-align:left;
      vertical-align:top;
    }
    th{
      color:var(--dash-muted);
      font-size:11px;
      text-transform:uppercase;
      letter-spacing:.08em;
    }
    td strong{
      display:block;
      font-size:15px;
      margin-bottom:2px;
    }
    .empty{
      padding:14px 16px 16px;
      color:var(--dash-muted);
    }
    @media (max-width:1200px){
      .summary{grid-template-columns:repeat(3, minmax(0, 1fr))}
      .grid-2{grid-template-columns:1fr}
    }
    @media (max-width:920px){
      .hero{grid-template-columns:1fr}
      .hero h1{font-size:32px}
      .summary{grid-template-columns:repeat(2, minmax(0, 1fr))}
      .site-grid{grid-template-columns:1fr}
    }
    @media (max-width:640px){
      .shell{padding:12px}
      .summary{grid-template-columns:1fr}
      .hero-meta{grid-template-columns:1fr}
      .site-stats{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../../admin/partials/header.php'; ?>
  <main class="shell">
    <section class="hero">
      <div>
        <p class="eyebrow">NexusCMS Dashboard</p>
        <h1><?= Security::e($userName) ?></h1>
        <p>Messages, alerts, analytics, and site performance are gathered here so the root of the CMS works as your actual dashboard instead of redirecting into the admin area.</p>
        <div class="actions">
          <a class="dashboard-btn primary" href="<?= Security::e($base . '/admin/index.php') ?>">Manage Sites</a>
          <a class="dashboard-btn secondary" href="<?= Security::e($base . '/admin/users.php') ?>">Manage Users</a>
          <a class="dashboard-btn secondary" href="<?= Security::e($base . '/admin/images.php') ?>">Media Library</a>
        </div>
      </div>
      <div class="hero-meta">
        <div class="hero-chip">
          <div class="hero-chip-label">Role</div>
          <div class="hero-chip-value"><?= Security::e($displayRole) ?></div>
        </div>
        <div class="hero-chip">
          <div class="hero-chip-label">Analytics Window</div>
          <div class="hero-chip-value"><?= Security::e($rangeLabel) ?></div>
        </div>
        <div class="hero-chip">
          <div class="hero-chip-label">Sessions</div>
          <div class="hero-chip-value"><?= number_format((int)($totals['sessions'] ?? 0)) ?></div>
        </div>
        <div class="hero-chip">
          <div class="hero-chip-label">404 Hits</div>
          <div class="hero-chip-value"><?= number_format((int)($totals['four_oh_four'] ?? 0)) ?></div>
        </div>
      </div>
    </section>

    <section class="summary">
      <?php foreach ($summaryCards as $card): ?>
        <article class="card metric <?= Security::e((string)$card['tone']) ?>">
          <div class="metric-label"><?= Security::e((string)$card['label']) ?></div>
          <div class="metric-value"><?= Security::e((string)$card['value']) ?></div>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="grid-2">
      <article class="card">
        <div class="panel-head">
          <div>
            <h2>Messages</h2>
            <p>Recent system notices and dashboard context for this account.</p>
          </div>
        </div>
        <div class="stack">
          <?php foreach ($messages as $message): ?>
            <div class="message <?= Security::e((string)($message['type'] ?? 'notice')) ?>">
              <p class="message-title"><?= Security::e((string)($message['title'] ?? 'Message')) ?></p>
              <p class="message-body"><?= Security::e((string)($message['body'] ?? '')) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </article>

      <article class="card">
        <div class="panel-head">
          <div>
            <h2>Alerts</h2>
            <p>Items that need attention across your current site portfolio.</p>
          </div>
        </div>
        <?php if ($alerts): ?>
          <div class="stack">
            <?php foreach ($alerts as $alert): ?>
              <div class="alert <?= Security::e((string)($alert['level'] ?? 'info')) ?>">
                <p class="alert-title"><?= Security::e((string)($alert['title'] ?? 'Alert')) ?></p>
                <p class="alert-body"><?= Security::e((string)($alert['body'] ?? '')) ?></p>
                <?php if (!empty($alert['href'])): ?>
                  <div class="alert-actions"><a href="<?= Security::e((string)$alert['href']) ?>"><?= Security::e((string)($alert['cta'] ?? 'Open')) ?></a></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty">No active alerts for the current account.</div>
        <?php endif; ?>
      </article>
    </section>

    <article class="card">
      <div class="panel-head">
        <div>
          <h2>Key Analytics</h2>
          <p>Top sites by recent traffic with page inventory and performance context.</p>
        </div>
      </div>
      <?php if ($analyticsCards): ?>
        <div class="site-grid">
          <?php foreach ($analyticsCards as $site): ?>
            <section class="site-card">
              <div class="site-card-top">
                <div>
                  <h3 class="site-name"><?= Security::e((string)$site['name']) ?></h3>
                  <div class="site-meta">/<?= Security::e((string)$site['slug']) ?><?php if (!(bool)$site['analytics_enabled']): ?> • analytics off<?php endif; ?></div>
                </div>
                <span class="status <?= Security::e((string)$site['status']) ?>"><?= Security::e((string)$site['status']) ?></span>
              </div>
              <div class="site-stats">
                <div class="site-stat">
                  <div class="site-stat-label">Views</div>
                  <div class="site-stat-value"><?= number_format((int)$site['views']) ?></div>
                </div>
                <div class="site-stat">
                  <div class="site-stat-label">Visitors</div>
                  <div class="site-stat-value"><?= number_format((int)$site['unique']) ?></div>
                </div>
                <div class="site-stat">
                  <div class="site-stat-label">Sessions</div>
                  <div class="site-stat-value"><?= number_format((int)$site['sessions']) ?></div>
                </div>
                <div class="site-stat">
                  <div class="site-stat-label">Published Pages</div>
                  <div class="site-stat-value"><?= number_format((int)$site['pages_published']) ?></div>
                </div>
                <div class="site-stat">
                  <div class="site-stat-label">Avg Load</div>
                  <div class="site-stat-value"><?= (int)$site['avg_load_ms'] > 0 ? number_format((int)$site['avg_load_ms']) . ' ms' : 'n/a' ?></div>
                </div>
                <div class="site-stat">
                  <div class="site-stat-label">404 Hits</div>
                  <div class="site-stat-value"><?= number_format((int)$site['four_oh_four']) ?></div>
                </div>
              </div>
              <div class="site-actions">
                <a href="<?= Security::e($base . '/admin/site.php?id=' . (int)$site['id']) ?>">Open Site</a>
                <a href="<?= Security::e($site['public_url']) ?>">View Public</a>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">No accessible sites were found for this account.</div>
      <?php endif; ?>
    </article>

    <section class="grid-2">
      <article class="card">
        <div class="panel-head">
          <div>
            <h2>Site Performance Metrics</h2>
            <p>Operational view for each accessible site in the current analytics window.</p>
          </div>
        </div>
        <?php if ($siteStats): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Site</th>
                  <th>Traffic</th>
                  <th>Pages</th>
                  <th>Performance</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($siteStats as $site): ?>
                  <tr>
                    <td>
                      <strong><?= Security::e((string)$site['name']) ?></strong>
                      <span class="site-meta">/<?= Security::e((string)$site['slug']) ?> • <?= Security::e((string)$site['status']) ?></span>
                    </td>
                    <td>
                      <strong><?= number_format((int)$site['views']) ?> views</strong>
                      <span class="site-meta"><?= number_format((int)$site['unique']) ?> visitors • <?= number_format((int)$site['sessions']) ?> sessions</span>
                    </td>
                    <td>
                      <strong><?= number_format((int)$site['pages_published']) ?> live</strong>
                      <span class="site-meta"><?= number_format((int)$site['pages_draft']) ?> draft • <?= number_format((int)$site['pages_total']) ?> total</span>
                    </td>
                    <td>
                      <strong><?= (int)$site['avg_load_ms'] > 0 ? number_format((int)$site['avg_load_ms']) . ' ms' : 'n/a' ?></strong>
                      <span class="site-meta">TTFB <?= (int)$site['avg_ttfb_ms'] > 0 ? number_format((int)$site['avg_ttfb_ms']) . ' ms' : 'n/a' ?> • 404s <?= number_format((int)$site['four_oh_four']) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty">No site metrics are available yet.</div>
        <?php endif; ?>
      </article>

      <article class="card">
        <div class="panel-head">
          <div>
            <h2>Recent Updates</h2>
            <p>Latest page edits across the sites you can access.</p>
          </div>
        </div>
        <?php if ($recentPages): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Page</th>
                  <th>Site</th>
                  <th>Status</th>
                  <th>Updated</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentPages as $page): ?>
                  <tr>
                    <td>
                      <strong><?= Security::e((string)($page['title'] ?? 'Untitled page')) ?></strong>
                      <span class="site-meta">/<?= Security::e((string)($page['slug'] ?? '')) ?></span>
                    </td>
                    <td><?= Security::e((string)($page['site_name'] ?? '')) ?></td>
                    <td><?= Security::e((string)($page['status'] ?? 'draft')) ?></td>
                    <td>
                      <?php
                        $updated = trim((string)($page['updated_at'] ?? ''));
                        echo Security::e($updated !== '' ? date('Y-m-d H:i', strtotime($updated)) : 'n/a');
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty">No recent page activity was found.</div>
        <?php endif; ?>
      </article>
    </section>
  </main>
</body>
</html>
