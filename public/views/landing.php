<?php
use NexusCMS\Core\Security;

$base = base_path();
$activeNav = 'sites';
$themeIsLight = ui_theme_is_light();

$siteRows = [];
foreach ($sites as $s) {
  $status = strtolower(trim((string)($s['status'] ?? 'live')));
  if (!in_array($status, ['live', 'draft', 'disabled'], true)) $status = 'live';

  $slug = trim((string)($s['slug'] ?? ''));
  $domain = trim((string)($s['domain'] ?? $s['primary_domain'] ?? ''));
  $url = $domain !== ''
    ? (preg_match('~^https?://~i', $domain) ? $domain : 'https://' . $domain)
    : ($slug !== '' ? $base . '/s/' . $slug . '/home' : $base . '/');

  $siteRows[] = [
    'id' => (int)($s['id'] ?? 0),
    'name' => trim((string)($s['name'] ?? '')) ?: 'Untitled site',
    'url' => $url,
    'status' => $status,
  ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NexusCMS — Sites</title>
  <script>
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
  <style>
    :root{
      --bg:#f5f7fb;
      --surface:#ffffff;
      --border:#e5e7eb;
      --text:#111827;
      --muted:#6b7280;
      --radius:12px;
    }
    .theme-light{
      --bg:#f5f7fb;
      --surface:#ffffff;
      --border:#e5e7eb;
      --text:#111827;
      --muted:#6b7280;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background:var(--bg);
      color:var(--text);
      font-family:"Inter","Helvetica Neue",system-ui,-apple-system,sans-serif;
      line-height:1.45;
    }
    a{color:inherit;text-decoration:none}
    main{
      max-width:1120px;
      margin:0 auto;
      padding:40px 20px 64px;
    }
    .section-head{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      margin-bottom:18px;
    }
    h1{
      margin:0;
      font-size:30px;
      font-weight:700;
      letter-spacing:-0.02em;
    }
    .btn-primary{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      min-height:42px;
      padding:0 16px;
      border-radius:10px;
      border:0;
      font-size:16px;
      font-weight:700;
      background:linear-gradient(135deg,var(--primary),var(--primary-strong));
      color:#fff;
    }
    .sites-wrap{
      background:#fff;
      border:1px solid var(--border);
      border-radius:var(--radius);
      overflow:hidden;
    }
    table{width:100%;border-collapse:collapse}
    th,td{
      padding:14px 16px;
      border-bottom:1px solid var(--border);
      text-align:left;
      vertical-align:middle;
      font-size:14px;
    }
    th{
      font-size:12px;
      font-weight:700;
      color:var(--muted);
      letter-spacing:.06em;
      text-transform:uppercase;
      background:#fafafa;
    }
    tr:last-child td{border-bottom:0}
    .site-name{font-weight:600;color:#111827}
    .site-url{color:#4b5563;word-break:break-all}
    .site-url:hover{color:#111827;text-decoration:underline}
    .status{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:72px;
      border-radius:999px;
      padding:4px 10px;
      font-size:12px;
      font-weight:600;
      text-transform:capitalize;
      border:1px solid transparent;
    }
    .status.live{background:#ecfdf3;color:#166534;border-color:#bbf7d0}
    .status.draft{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
    .status.disabled{background:#fef2f2;color:#991b1b;border-color:#fecaca}
    .manage-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:34px;
      padding:0 12px;
      border-radius:8px;
      border:1px solid var(--border);
      background:#fff;
      color:#111827;
      font-size:13px;
      font-weight:600;
    }
    .manage-btn:hover{background:#f9fafb}
    .empty{
      padding:30px 16px;
      font-size:14px;
      color:var(--muted);
    }
    @media (max-width: 900px){
      .section-head{flex-direction:column;align-items:flex-start}
      h1{font-size:28px}
      .btn-primary{font-size:16px}
      th,td{font-size:14px}
      th{font-size:12px}
      .status{font-size:12px}
      .manage-btn{font-size:13px}
      .empty{font-size:14px}
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../../admin/partials/header.php'; ?>
  <main>
    <section aria-labelledby="your-sites-title">
      <div class="section-head">
        <h1 id="your-sites-title">Your Sites</h1>
        <a class="btn-primary" href="<?= $base ?>/admin/site_new.php">+ Create New Website</a>
      </div>

      <div class="sites-wrap">
        <?php if (!$siteRows): ?>
          <div class="empty">No sites yet. Create your first website to get started.</div>
        <?php else: ?>
          <table aria-label="Your Sites">
            <thead>
              <tr>
                <th>Name</th>
                <th>URL</th>
                <th>Status</th>
                <th>Manage</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($siteRows as $row): ?>
                <tr>
                  <td><span class="site-name"><?= Security::e($row['name']) ?></span></td>
                  <td><a class="site-url" href="<?= Security::e($row['url']) ?>" target="_blank" rel="noopener noreferrer"><?= Security::e($row['url']) ?></a></td>
                  <td><span class="status <?= Security::e($row['status']) ?>"><?= Security::e($row['status']) ?></span></td>
                  <td><a class="manage-btn" href="<?= $base ?>/admin/site.php?id=<?= (int)$row['id'] ?>">Manage</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
