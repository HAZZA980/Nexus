<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\DB;
use NexusCMS\Core\Security;
use NexusCMS\Models\Site;

$base = base_path();
$activeNav = 'databases';
$themeIsLight = ui_theme_is_light();

$citationSite = Site::findBySlug('cite-them-right');
$citationUpdatedAt = null;

if ($citationSite) {
  try {
    $st = DB::pdo()->prepare("SELECT MAX(created_at) FROM citation_revisions WHERE site_slug = ?");
    $st->execute([(string)$citationSite['slug']]);
    $citationUpdatedAt = $st->fetchColumn() ?: null;
  } catch (\Throwable $e) {
    $citationUpdatedAt = null;
  }
}

$formatUpdated = static function (?string $ts): string {
  if (!$ts) return 'No updates yet';
  $time = strtotime($ts);
  if (!$time) return 'Unknown';
  return date('M j, Y g:i A', $time);
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Databases — NexusCMS Admin</title>
  <script>
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
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
      --focus: 0 0 0 3px rgba(37,99,235,0.28);
    }
    *{box-sizing:border-box;}
    body{margin:0;background:var(--bg);color:var(--text);font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;line-height:1.5;}
    a{text-decoration:none;color:inherit;}
    a:focus-visible,button:focus-visible{outline:none;box-shadow:var(--focus);}
    main{max-width:1200px;margin:0 auto;padding:20px 20px 48px;}
    .page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;margin:24px 0 14px;}
    .page-head h1{margin:0;font-size:32px;letter-spacing:-0.02em;}
    .page-head p{margin:6px 0 0;color:var(--muted);}
    .table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
    th,td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--border);}
    th{color:var(--muted);font-weight:600;font-size:14px;}
    tr:last-child td{border-bottom:none;}
    .muted{color:var(--muted);}
    .empty{padding:24px;border:1px dashed var(--border);border-radius:14px;text-align:center;color:var(--muted);}
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <div class="page-head">
      <div>
        <h1>Databases</h1>
        <p>Manage reusable data stores used across sites.</p>
      </div>
    </div>

    <?php if (!$citationSite): ?>
      <div class="empty">Citation DB is unavailable because the `cite-them-right` site was not found.</div>
    <?php else: ?>
      <table class="table" aria-label="Databases list">
        <thead>
          <tr>
            <th>Database</th>
            <th>Source site</th>
            <th>Last updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="font-weight:700;">Citation DB</td>
            <td class="muted"><?= Security::e($citationSite['name'] ?? $citationSite['slug']) ?></td>
            <td class="muted"><?= Security::e($formatUpdated($citationUpdatedAt)) ?></td>
            <td><a class="btn primary" href="<?= $base ?>/admin/database_citation.php">Open</a></td>
          </tr>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
</body>
</html>
