<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\Security;

$base = base_path();
$activeNav = 'images';
$themeIsLight = ui_theme_is_light();

// Mock image data until backed by real storage
$images = [
  [
    'id' => 1,
    'filename' => 'library-stack.jpg',
    'alt' => 'Stack of books',
    'format' => 'jpg',
    'width' => 1600,
    'height' => 900,
    'size' => '320 KB',
    'site' => 'cite-them-right',
    'uploaded_by' => 'Admin',
    'uploaded_at' => '2024-12-01 10:00:00',
    'usage' => 3,
    'pages' => [
      ['title' => 'Printed books', 'path' => '/s/cite-them-right/printed-books'],
      ['title' => 'Audiobooks', 'path' => '/s/cite-them-right/audiobooks'],
    ],
    'thumb' => 'https://images.unsplash.com/photo-1457694587812-e8bf29a43845?auto=format&fit=crop&w=400&q=60'
  ],
  [
    'id' => 2,
    'filename' => 'student-desk.png',
    'alt' => 'Student at desk',
    'format' => 'png',
    'width' => 1400,
    'height' => 933,
    'size' => '420 KB',
    'site' => 'skills-for-study',
    'uploaded_by' => 'Editor',
    'uploaded_at' => '2025-01-04 14:20:00',
    'usage' => 0,
    'pages' => [],
    'thumb' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?auto=format&fit=crop&w=400&q=60'
  ],
  [
    'id' => 3,
    'filename' => 'campus.svg',
    'alt' => 'Campus vector',
    'format' => 'svg',
    'width' => 800,
    'height' => 600,
    'size' => '48 KB',
    'site' => 'nexus-hub',
    'uploaded_by' => 'Super Admin',
    'uploaded_at' => '2025-01-10 09:10:00',
    'usage' => 5,
    'pages' => [
      ['title' => 'Home', 'path' => '/s/nexus-hub/home'],
      ['title' => 'About', 'path' => '/s/nexus-hub/about']
    ],
    'thumb' => 'https://dummyimage.com/400x300/111827/ffffff&text=SVG'
  ],
];

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$siteFilter = trim((string)($_GET['site'] ?? 'all'));
$usageFilter = trim((string)($_GET['usage'] ?? 'all'));
$formatFilter = trim((string)($_GET['format'] ?? 'all'));

$filtered = array_filter($images, function($img) use ($q, $siteFilter, $usageFilter, $formatFilter) {
  if ($q !== '') {
    $hay = strtolower($img['filename'] . ' ' . $img['alt']);
    if (strpos($hay, strtolower($q)) === false) return false;
  }
  if ($siteFilter !== 'all' && $siteFilter !== '' && $img['site'] !== $siteFilter) return false;
  if ($usageFilter === 'used' && $img['usage'] < 1) return false;
  if ($usageFilter === 'unused' && $img['usage'] > 0) return false;
  if ($formatFilter !== 'all' && strtolower($img['format']) !== strtolower($formatFilter)) return false;
  return true;
});

$sites = array_values(array_unique(array_map(fn($i) => $i['site'], $images)));
sort($sites);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Images — NexusCMS Admin</title>
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
      --primary: #5b21b6;
      --primary-strong: #4c1d95;
      --shadow: 0 12px 40px rgba(0,0,0,0.25);
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
      --primary: #2563eb;
      --primary-strong: #1d4ed8;
      --shadow: 0 10px 30px rgba(15,23,42,0.08);
      --focus: 0 0 0 3px rgba(37,99,235,0.28);
    }
    *{box-sizing:border-box;}
    body{margin:0;background:var(--bg);color:var(--text);font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;line-height:1.5;}
    a{text-decoration:none;color:inherit;}
    a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:none;box-shadow:var(--focus);border-color:var(--primary);}
    main{max-width:1200px;margin:0 auto;padding:20px 20px 48px;}
    .page-head{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;margin:24px 0 14px;}
    .page-head h1{margin:0;font-size:32px;letter-spacing:-0.02em;}
    .page-head p{margin:6px 0 0;color:var(--muted);}
    .filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:10px 0 18px;}
    .input{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,0.04);}
    .input input, .input select{background:transparent;border:none;color:var(--text);font-weight:600;min-width:180px;}
    .input input::placeholder{color:var(--muted);}
    .gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;}
    .tile{position:relative;border:1px solid var(--border);border-radius:14px;overflow:hidden;background:var(--card);box-shadow:var(--shadow);display:flex;flex-direction:column;}
    .tile img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;}
    .tile-body{padding:10px 12px;display:grid;gap:6px;flex:1;}
    .tile-footer{display:flex;justify-content:space-between;align-items:center;padding:0 12px 12px;}
    .badge{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-weight:700;font-size:12px;background:rgba(255,255,255,0.08);}
    .thumb-actions{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:8px;background:rgba(0,0,0,0.35);opacity:0;transition:opacity .15s ease;}
    .tile:hover .thumb-actions{opacity:1;}
    .thumb-actions button{padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,0.3);background:rgba(0,0,0,0.4);color:#fff;cursor:pointer;font-weight:700;}
    .empty{padding:24px;border:1px dashed var(--border);border-radius:14px;text-align:center;color:var(--muted);}
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <div class="page-head">
      <div>
        <h1>Images</h1>
        <p>Manage images used across your sites.</p>
      </div>
      <button class="btn primary" type="button">Upload image</button>
    </div>

    <form class="filters" method="get">
      <div class="input">
        <label class="sr-only" for="q">Search images</label>
        <input id="q" name="q" type="search" placeholder="Search images…" value="<?= Security::e($q) ?>">
      </div>
      <div class="input">
        <label class="sr-only" for="site">Site</label>
        <select id="site" name="site">
          <option value="all" <?= $siteFilter==='all'?'selected':''; ?>>All sites</option>
          <?php foreach ($sites as $slug): ?>
            <option value="<?= Security::e($slug) ?>" <?= $siteFilter===$slug?'selected':''; ?>><?= Security::e($slug) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="input">
        <label class="sr-only" for="usage">Usage</label>
        <select id="usage" name="usage">
          <option value="all" <?= $usageFilter==='all'?'selected':''; ?>>All</option>
          <option value="used" <?= $usageFilter==='used'?'selected':''; ?>>Used</option>
          <option value="unused" <?= $usageFilter==='unused'?'selected':''; ?>>Unused</option>
        </select>
      </div>
      <div class="input">
        <label class="sr-only" for="format">Format</label>
        <select id="format" name="format">
          <option value="all" <?= $formatFilter==='all'?'selected':''; ?>>All formats</option>
          <?php foreach (['jpg','png','svg','webp'] as $fmt): ?>
            <option value="<?= $fmt ?>" <?= $formatFilter===$fmt?'selected':''; ?>><?= strtoupper($fmt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($q!=='' || $siteFilter!=='all' || $usageFilter!=='all' || $formatFilter!=='all'): ?>
        <button class="btn" type="button" onclick="window.location='<?= $base ?>/admin/images.php';">Reset</button>
      <?php endif; ?>
    </form>

    <?php if (!$filtered): ?>
      <div class="empty">No images found. <button class="btn primary" type="button">Upload image</button></div>
    <?php else: ?>
      <div class="gallery" role="list">
        <?php foreach ($filtered as $img): ?>
          <div class="tile" role="listitem">
            <div class="thumb-actions" aria-hidden="true">
              <button type="button">View</button>
              <button type="button">Replace</button>
              <button type="button" <?= $img['usage']>0 ? 'style="opacity:0.6;cursor:not-allowed;" title="In use; cannot delete"' : '' ?>>Delete</button>
            </div>
            <img src="<?= Security::e($img['thumb']) ?>" alt="">
            <div class="tile-body">
              <div style="font-weight:700;"><?= Security::e(mb_strimwidth($img['filename'],0,30,'…')) ?></div>
              <div class="muted" style="font-size:13px;display:flex;justify-content:space-between;gap:6px;flex-wrap:wrap;">
                <span><?= strtoupper(Security::e($img['format'])) ?> • <?= Security::e($img['size']) ?></span>
                <span><?= Security::e($img['width']) ?>×<?= Security::e($img['height']) ?></span>
              </div>
            </div>
            <div class="tile-footer">
              <span class="badge">Used <?= (int)$img['usage'] ?>×</span>
              <span class="muted" style="font-size:13px;">By <?= Security::e($img['site']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
