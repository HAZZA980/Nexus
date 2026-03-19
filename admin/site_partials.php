<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Site;
use NexusCMS\Support\PartialsManager;
use NexusCMS\Core\Security;

$base = base_path();
$activeNav = 'sites';
$themeIsLight = ui_theme_is_light();
$siteId = (int)($_GET['site_id'] ?? 0);
$create = $_GET['create'] ?? null;
$site = Site::find($siteId);
if (!$site) { http_response_code(404); echo "Site not found"; exit; }

$slug = PartialsManager::safeSlug($site['slug'] ?? '');
if ($slug === '') { http_response_code(400); echo "Invalid site slug"; exit; }

$paths = PartialsManager::paths($slug);
$createdFlags = [];
$creationError = null;

function maybeCreate(string $type, array $paths, array &$createdFlags, array $site): void {
  $root = $paths['root'];
  if ($type === 'header') {
    $createdFlags['header'] = PartialsManager::ensureFile($paths['header'], $root, PartialsManager::boilerplateHeader($site['slug'], $site['name']));
  } elseif ($type === 'footer') {
    $createdFlags['footer'] = PartialsManager::ensureFile($paths['footer'], $root, PartialsManager::boilerplateFooter($site['slug'], $site['name']));
  } elseif ($type === 'assets') {
    $createdFlags['css'] = PartialsManager::ensureFile($paths['css'], $root, PartialsManager::boilerplateCss());
    $createdFlags['js'] = PartialsManager::ensureFile($paths['js'], $root, PartialsManager::boilerplateJs());
  }
}

if (in_array($create, ['header','footer','assets'], true)) {
  try {
    maybeCreate($create, $paths, $createdFlags, $site);
  } catch (\Throwable $e) {
    $creationError = $e->getMessage();
  }
}

$status = [
  'header' => file_exists($paths['header']) ? 'exists' : 'missing',
  'footer' => file_exists($paths['footer']) ? 'exists' : 'missing',
  'css'    => file_exists($paths['css']) ? 'exists' : 'missing',
  'js'     => file_exists($paths['js']) ? 'exists' : 'missing',
];

function rel_path(string $rootedPath): string {
  $root = PartialsManager::projectRoot();
  return ltrim(str_replace($root, '', $rootedPath), '/');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Site partials — <?= Security::e($site['name']) ?></title>
  <script>
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    :root { --bg:#0b1224;--panel:#0f172a;--card:#111a32;--border:rgba(255,255,255,0.08);--muted:#9aa4b5;--text:#e7ecf4;--primary:#3b82f6;--radius:16px;--focus:0 0 0 3px rgba(59,130,246,0.45); }
    * { box-sizing:border-box; }
    body { margin:0; font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif; background:radial-gradient(120% 120% at 50% 0%, rgba(59,130,246,0.15), rgba(59,130,246,0) 55%), var(--bg); color:var(--text); line-height:1.5; }
    a { color:inherit; text-decoration:none; }
    a:focus-visible, button:focus-visible { outline:none; box-shadow:var(--focus); }
    main { max-width:1100px; margin:0 auto; padding:24px 20px 48px; }
    .back-link { display:inline-flex; align-items:center; gap:8px; color:var(--muted); min-height:44px; padding:8px 0; font-weight:600; }
    .page-head h1 { margin:0; font-size:30px; letter-spacing:-0.02em; }
    .page-head p { margin:6px 0 0; color:var(--muted); }
    .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; margin-top:18px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:18px; box-shadow:0 10px 40px rgba(0,0,0,0.28); }
    .muted { color:var(--muted); }
    .badge { display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; font-weight:700; font-size:13px; }
    .badge.ok { background:rgba(34,197,94,0.18); color:#0f5132; border:1px solid rgba(34,197,94,0.45); }
    .badge.warn { background:rgba(239,68,68,0.14); color:#7f1d1d; border:1px solid rgba(239,68,68,0.4); }
    .path { font-family:monospace; background:rgba(255,255,255,0.06); padding:8px 10px; border-radius:10px; word-break:break-all; }
    .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 12px; min-height:44px; border-radius:12px; border:1px solid var(--border); background:rgba(255,255,255,0.06); color:var(--text); cursor:pointer; font-weight:600; text-decoration:none; }
    .btn:hover { background:rgba(255,255,255,0.1); }
    .card h3 { margin:0 0 6px; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
</head>
  <body>
    <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <a class="back-link" href="<?= $base ?>/admin/site.php?id=<?= (int)$site['id'] ?>">← Back to site</a>
    <div class="page-head">
      <h1>Site partials</h1>
      <p>Edit in your IDE. Files below apply site-wide. Slug: <strong><?= Security::e($slug) ?></strong></p>
      <?php if ($creationError): ?>
        <p style="color:#f87171;font-weight:700">Error: <?= Security::e($creationError) ?></p>
      <?php endif; ?>
    </div>

    <div class="grid">
      <div class="card">
        <h3>Header</h3>
        <div class="badge <?= $status['header']==='exists' ? 'ok' : 'warn' ?>"><?= $status['header']==='exists' ? 'Created' : 'Missing' ?></div>
        <div class="path" id="headerPath"><?= Security::e(rel_path($paths['header'])) ?></div>
        <p class="muted">Edit this file in VS Code; changes apply site-wide. Includes search form.</p>
        <div class="actions">
          <a class="btn" href="<?= $base ?>/admin/site_partials.php?site_id=<?= (int)$site['id'] ?>&create=header">Ensure header</a>
          <button class="btn" data-copy="#headerPath">Copy path</button>
        </div>
      </div>

      <div class="card">
        <h3>Footer</h3>
        <div class="badge <?= $status['footer']==='exists' ? 'ok' : 'warn' ?>"><?= $status['footer']==='exists' ? 'Created' : 'Missing' ?></div>
        <div class="path" id="footerPath"><?= Security::e(rel_path($paths['footer'])) ?></div>
        <p class="muted">Edit this file in VS Code; changes apply site-wide.</p>
        <div class="actions">
          <a class="btn" href="<?= $base ?>/admin/site_partials.php?site_id=<?= (int)$site['id'] ?>&create=footer">Ensure footer</a>
          <button class="btn" data-copy="#footerPath">Copy path</button>
        </div>
      </div>

      <div class="card">
        <h3>Site assets (optional)</h3>
        <div class="badge <?= ($status['css']==='exists' || $status['js']==='exists') ? 'ok' : 'warn' ?>"><?= ($status['css']==='exists' || $status['js']==='exists') ? 'Created' : 'Missing' ?></div>
        <div class="path" id="assetsPath"><?= Security::e(rel_path($paths['css'])) ?> & <?= Security::e(rel_path($paths['js'])) ?></div>
        <p class="muted">Custom CSS/JS for this site. No raw HTML allowed.</p>
        <div class="actions">
          <a class="btn" href="<?= $base ?>/admin/site_partials.php?site_id=<?= (int)$site['id'] ?>&create=assets">Ensure assets</a>
          <button class="btn" data-copy="#assetsPath">Copy paths</button>
        </div>
      </div>
    </div>
  </main>
  <script>
    document.querySelectorAll('[data-copy]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const targetSel = btn.getAttribute('data-copy');
        const el = document.querySelector(targetSel);
        if (!el) return;
        const text = el.textContent.trim();
        try {
          await navigator.clipboard.writeText(text);
          btn.textContent = 'Copied';
          setTimeout(() => { btn.textContent = 'Copy path'; }, 1200);
        } catch (e) { btn.textContent = 'Copy failed'; }
      });
    });
  </script>
</body>
</html>
