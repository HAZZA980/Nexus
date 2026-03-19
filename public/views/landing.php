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
    'description' => trim((string)($s['description'] ?? '')),
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
    main{max-width:1120px;margin:0 auto;padding:28px 20px 64px;}
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
    .sites-wrap{display:grid;gap:16px;}
    .site-card{
      display:grid;
      grid-template-columns:280px 1fr;
      gap:18px;
      padding:14px;
      border:1px solid var(--border);
      border-radius:14px;
      background:#fff;
      box-shadow:0 8px 24px rgba(15,23,42,0.08);
    }
    .site-preview{
      width:100%;
      aspect-ratio:16 / 9;
      border-radius:12px;
      border:1px solid var(--border);
      padding:12px;
      display:flex;
      align-items:flex-end;
      font-size:22px;
      font-weight:700;
      color:#fff;
      background:
        linear-gradient(135deg, rgba(79,70,229,.82), rgba(14,165,233,.68)),
        radial-gradient(circle at top right, rgba(255,255,255,.28), transparent 45%);
    }
    .site-content{display:grid;gap:10px;align-content:center;}
    .site-name{margin:0;font-size:30px;font-weight:700;letter-spacing:-.02em;color:#111827;}
    .site-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .site-url{
      display:inline-block;
      max-width:min(64ch,100%);
      color:#4b5563;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .site-url:hover{color:#111827;text-decoration:underline;}
    .site-description{
      margin:0;
      font-size:15px;
      line-height:1.5;
      color:var(--muted);
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
      max-width:86ch;
    }
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
      min-height:36px;
      padding:0 14px;
      border-radius:8px;
      border:1px solid var(--border);
      background:#fff;
      color:#111827;
      font-size:14px;
      font-weight:600;
    }
    .manage-btn:hover{background:#f9fafb}
    .open-link{font-size:14px;font-weight:600;color:var(--muted);text-decoration:underline;text-underline-offset:3px;}
    .open-link:hover{color:#111827;}
    .site-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .empty{padding:30px 16px;font-size:14px;color:var(--muted);background:#fff;border:1px solid var(--border);border-radius:14px;}
    @media (max-width: 900px){
      .section-head{flex-direction:column;align-items:flex-start}
      h1{font-size:30px}
      .btn-primary{font-size:16px}
      .site-card{grid-template-columns:1fr;}
      .site-name{font-size:26px;}
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
          <?php foreach ($siteRows as $row): ?>
            <?php
              $desc = $row['description'] !== '' ? $row['description'] : 'Manage pages, content, and publishing settings for this website.';
              $previewChar = strtoupper(substr((string)$row['name'], 0, 1));
              if ($previewChar === '') $previewChar = 'S';
            ?>
            <article class="site-card">
              <div class="site-preview" aria-hidden="true"><?= Security::e($previewChar) ?></div>
              <div class="site-content">
                <h2 class="site-name"><?= Security::e($row['name']) ?></h2>
                <div class="site-meta">
                  <a class="site-url" href="<?= Security::e($row['url']) ?>" target="_blank" rel="noopener noreferrer"><?= Security::e($row['url']) ?></a>
                  <span class="status <?= Security::e($row['status']) ?>"><?= Security::e($row['status']) ?></span>
                </div>
                <p class="site-description"><?= Security::e($desc) ?></p>
                <div class="site-actions">
                  <a class="manage-btn" href="<?= $base ?>/admin/site.php?id=<?= (int)$row['id'] ?>">Manage</a>
                  <a class="open-link" href="<?= Security::e($row['url']) ?>" target="_blank" rel="noopener noreferrer">Open site</a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
