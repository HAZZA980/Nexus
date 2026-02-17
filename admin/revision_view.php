<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Revision;
use NexusCMS\Models\Page;
use NexusCMS\Models\Site;
use NexusCMS\Services\Renderer;
use NexusCMS\Core\Security;

$id = (int)($_GET['id'] ?? 0);
$rev = Revision::get($id);
if (!$rev) { http_response_code(404); echo "Revision not found"; exit; }

$page = Page::find((int)$rev['page_id']);
if (!$page) { http_response_code(404); echo "Page not found"; exit; }
$site = Site::find((int)$page['site_id']);
if (!$site) { http_response_code(404); echo "Site not found"; exit; }

$doc = json_decode($rev['doc_json'] ?? '{}', true) ?: ['version'=>1,'rows'=>[]];
$content = Renderer::render($doc);

$base = base_path();
$themeIsLight = ui_theme_is_light();
$activeNav = 'sites';
$label = $rev['name'] ?: "Revision #{$rev['id']}";
$note = $rev['note'] ?? '';
$ts = $rev['created_at'] ?? '';
$author = $rev['created_by_user_id'] ?? null;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Revision <?= Security::e($label) ?></title>
  <script>
    (function() {
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
  <link rel="stylesheet" href="<?= $base ?>/public/assets/site.css">
  <style>
    :root {
      --bg: #0b1224;
      --panel: #0f172a;
      --border: rgba(255,255,255,.12);
      --muted: #cbd5e1;
      --text: #e6eaf2;
      --primary: #5b21b6;
      --primary-strong: #4c1d95;
    }
    .theme-light {
      --bg: #f8fafc;
      --panel: #ffffff;
      --border: #e2e8f0;
      --muted: #475569;
      --text: #0f172a;
      --primary: #2563eb;
      --primary-strong: #1d4ed8;
    }
    body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0;padding:0;}
    .wrap{max-width:1100px;margin:0 auto;padding:16px;}
    .rev-head{border:1px solid var(--border);border-radius:14px;padding:14px;background:rgba(255,255,255,.04);margin-bottom:14px;}
    .rev-meta{display:flex;gap:12px;flex-wrap:wrap;color:var(--muted);}
    .rev-head .btn{display:inline-flex;align-items:center;gap:6px;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.08);color:var(--text);text-decoration:none;}
    .rev-head .btn:hover{background:rgba(255,255,255,.12);}
    .actions{display:flex;gap:10px;margin:12px 0;}
    .note{margin-top:8px;border:1px dashed var(--border);border-radius:12px;padding:10px;color:var(--muted);background:rgba(255,255,255,.02);}
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <div class="wrap">
    <div class="rev-head">
      <div style="font-weight:900;font-size:20px;"><?= Security::e($label) ?></div>
      <div class="rev-meta">
        <span><?= Security::e($ts) ?></span>
        <?php if ($author): ?><span>Author: #<?= (int)$author ?></span><?php endif; ?>
        <?php if (!empty($rev['is_milestone'])): ?><span>⭐ Milestone</span><?php endif; ?>
      </div>
      <div class="note">
        <?php if ($note): ?>
          <?= nl2br(Security::e($note)) ?>
        <?php else: ?>
          <em>No notes</em>
        <?php endif; ?>
      </div>
      <div class="actions">
        <a class="btn" href="<?= $base ?>/admin/page_builder.php?id=<?= (int)$page['id'] ?>">Back</a>
        <button class="btn" id="restoreBtn" type="button">Restore this revision</button>
      </div>
    </div>

    <div class="nexus-page"><?= $content ?></div>
  </div>

  <script>
    document.getElementById('restoreBtn')?.addEventListener('click', async () => {
      if (!confirm('Restore this revision? This will create a new revision snapshot.')) return;
      try {
        const res = await fetch('<?= $base ?>/api/revisions/restore', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          credentials:'same-origin',
          body: JSON.stringify({ _csrf: '<?= Security::e(Security::csrfToken()) ?>', revision_id: <?= (int)$rev['id'] ?>, mode:'replace' })
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || 'Restore failed');
        alert('Restored and snapshot saved.');
        window.location.href = '<?= $base ?>/admin/page_builder.php?id=<?= (int)$page['id'] ?>';
      } catch (e) {
        alert(e.message);
      }
    });
  </script>
</body>
</html>
