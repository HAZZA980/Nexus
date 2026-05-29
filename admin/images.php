<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\Security;
use NexusCMS\Models\Site;

$base = base_path();
$activeNav = 'images';
$themeIsLight = ui_theme_is_light();
$csrfToken = Security::csrfToken();

function media_default_items(): array {
  return [
    [
      'id' => 1,
      'filename' => 'library-stack.jpg',
      'alt' => 'Stack of books',
      'format' => 'jpg',
      'width' => 1600,
      'height' => 900,
      'size_bytes' => 327680,
      'site' => 'cite-them-right',
      'uploaded_by' => 'Admin',
      'uploaded_at' => '2026-01-12 10:00:00',
      'last_used_at' => '2026-03-17 16:10:00',
      'usage' => 3,
      'pages' => [
        ['title' => 'Printed books', 'path' => '/s/cite-them-right/printed-books'],
        ['title' => 'Audiobooks', 'path' => '/s/cite-them-right/audiobooks'],
        ['title' => 'Home', 'path' => '/s/cite-them-right/home'],
      ],
      'thumb' => 'https://images.unsplash.com/photo-1457694587812-e8bf29a43845?auto=format&fit=crop&w=240&q=70',
    ],
    [
      'id' => 2,
      'filename' => 'student-desk.png',
      'alt' => 'Student at desk',
      'format' => 'png',
      'width' => 1400,
      'height' => 933,
      'size_bytes' => 430080,
      'site' => 'skills-for-study',
      'uploaded_by' => 'Editor',
      'uploaded_at' => '2026-02-04 14:20:00',
      'last_used_at' => null,
      'usage' => 0,
      'pages' => [],
      'thumb' => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?auto=format&fit=crop&w=240&q=70',
    ],
    [
      'id' => 3,
      'filename' => 'campus.svg',
      'alt' => 'Campus vector',
      'format' => 'svg',
      'width' => 800,
      'height' => 600,
      'size_bytes' => 49152,
      'site' => 'nexus-hub',
      'uploaded_by' => 'Super Admin',
      'uploaded_at' => '2026-02-10 09:10:00',
      'last_used_at' => '2026-03-20 08:40:00',
      'usage' => 5,
      'pages' => [
        ['title' => 'Home', 'path' => '/s/nexus-hub/home'],
        ['title' => 'About', 'path' => '/s/nexus-hub/about'],
      ],
      'thumb' => 'https://dummyimage.com/240x160/111827/ffffff&text=SVG',
    ],
    [
      'id' => 4,
      'filename' => 'lecture-room.webp',
      'alt' => 'Lecture room',
      'format' => 'webp',
      'width' => 1800,
      'height' => 1200,
      'size_bytes' => 716800,
      'site' => 'cite-them-right',
      'uploaded_by' => 'Website Admin',
      'uploaded_at' => '2026-03-08 11:25:00',
      'last_used_at' => '2026-03-19 12:30:00',
      'usage' => 2,
      'pages' => [
        ['title' => 'Lectures', 'path' => '/s/cite-them-right/lectures'],
        ['title' => 'Web pages', 'path' => '/s/cite-them-right/web-pages'],
      ],
      'thumb' => 'https://images.unsplash.com/photo-1513258496099-48168024aec0?auto=format&fit=crop&w=240&q=70',
    ],
  ];
}

function media_format_bytes(int $bytes): string {
  if ($bytes < 1024) return $bytes . ' B';
  if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
  return round($bytes / 1048576, 1) . ' MB';
}

function media_relative_time(?string $ts): string {
  if (!$ts) return '—';
  $time = strtotime($ts);
  if (!$time) return '—';
  $diff = max(0, time() - $time);
  if ($diff < 60) return 'Just now';
  $units = [31536000 => 'year', 2592000 => 'month', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
  foreach ($units as $secs => $label) {
    if ($diff >= $secs) {
      $val = (int)floor($diff / $secs);
      return $val . ' ' . $label . ($val === 1 ? '' : 's') . ' ago';
    }
  }
  return '—';
}

function media_dimension_bucket(int $w, int $h): string {
  if ($h <= 0) return 'other';
  $ratio = $w / $h;
  if (abs($ratio - 1) <= 0.08) return 'square';
  return ($ratio > 1) ? 'landscape' : 'portrait';
}

function media_find_index(array $items, int $id): int {
  foreach ($items as $idx => $it) {
    if ((int)($it['id'] ?? 0) === $id) return (int)$idx;
  }
  return -1;
}

if (!isset($_SESSION['admin_media_items']) || !is_array($_SESSION['admin_media_items'])) {
  $_SESSION['admin_media_items'] = media_default_items();
}
$items = $_SESSION['admin_media_items'];

$flash = $_SESSION['admin_media_flash'] ?? null;
unset($_SESSION['admin_media_flash']);

$siteSlugs = array_map(fn($s) => (string)($s['slug'] ?? ''), Site::all());
$siteSlugs = array_values(array_filter(array_unique($siteSlugs), fn($s) => $s !== ''));
foreach ($items as $it) {
  $slug = (string)($it['site'] ?? '');
  if ($slug !== '' && !in_array($slug, $siteSlugs, true)) $siteSlugs[] = $slug;
}
sort($siteSlugs, SORT_NATURAL | SORT_FLAG_CASE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $_SESSION['admin_media_flash'] = ['type' => 'error', 'message' => 'Security check failed.'];
    header('Location: ' . $base . '/admin/images.php');
    exit;
  }

  try {
    $mode = (string)($_POST['mode'] ?? '');

    if ($mode === 'bulk_action') {
      $action = strtolower(trim((string)($_POST['bulk_action'] ?? '')));
      $ids = array_values(array_filter(array_map('intval', (array)($_POST['media_ids'] ?? [])), fn($v) => $v > 0));
      if (!$ids) throw new RuntimeException('Select at least one asset.');

      if ($action === 'delete') {
        $items = array_values(array_filter($items, fn($it) => !in_array((int)$it['id'], $ids, true)));
        $_SESSION['admin_media_flash'] = ['type' => 'notice', 'message' => 'Selected assets deleted.'];
      } elseif ($action === 'mark_unused') {
        foreach ($items as &$it) {
          if (!in_array((int)$it['id'], $ids, true)) continue;
          $it['usage'] = 0;
          $it['pages'] = [];
          $it['last_used_at'] = null;
        }
        unset($it);
        $_SESSION['admin_media_flash'] = ['type' => 'notice', 'message' => 'Selected assets marked unused.'];
      } elseif ($action === 'move_site') {
        $target = trim((string)($_POST['bulk_target_site'] ?? ''));
        if ($target === '') throw new RuntimeException('Choose a destination site.');
        foreach ($items as &$it) {
          if (!in_array((int)$it['id'], $ids, true)) continue;
          $it['site'] = $target;
        }
        unset($it);
        $_SESSION['admin_media_flash'] = ['type' => 'notice', 'message' => 'Selected assets moved to site.'];
      } elseif ($action === 'download') {
        $_SESSION['admin_media_flash'] = ['type' => 'notice', 'message' => 'Download queued for selected assets.'];
      } else {
        throw new RuntimeException('Choose a valid bulk action.');
      }
    }

    if ($mode === 'replace_file') {
      $id = (int)($_POST['media_id'] ?? 0);
      $newName = trim((string)($_POST['replacement_name'] ?? ''));
      if ($id <= 0) throw new RuntimeException('Invalid file selection.');
      if ($newName === '') throw new RuntimeException('Replacement filename is required.');
      $idx = media_find_index($items, $id);
      if ($idx < 0) throw new RuntimeException('File not found.');
      $items[$idx]['filename'] = $newName;
      $ext = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
      if ($ext !== '') $items[$idx]['format'] = $ext;
      $items[$idx]['uploaded_at'] = date('Y-m-d H:i:s');
      $_SESSION['admin_media_flash'] = ['type' => 'notice', 'message' => 'Asset replaced (new version saved).'];
    }

    if ($mode === 'row_action') {
      $id = (int)($_POST['media_id'] ?? 0);
      $action = strtolower(trim((string)($_POST['action'] ?? '')));
      if ($id <= 0) throw new RuntimeException('Invalid media action.');
      $idx = media_find_index($items, $id);
      if ($idx < 0) throw new RuntimeException('Asset not found.');

      if ($action === 'delete') {
        array_splice($items, $idx, 1);
        $_SESSION['admin_media_flash'] = ['type' => 'notice', 'message' => 'Asset deleted.'];
      } elseif ($action === 'mark_unused') {
        $items[$idx]['usage'] = 0;
        $items[$idx]['pages'] = [];
        $items[$idx]['last_used_at'] = null;
        $_SESSION['admin_media_flash'] = ['type' => 'notice', 'message' => 'Asset marked unused.'];
      } else {
        throw new RuntimeException('Unknown asset action.');
      }
    }
  } catch (\Throwable $e) {
    $_SESSION['admin_media_flash'] = ['type' => 'error', 'message' => (string)($e->getMessage() ?: 'Action failed.')];
  }

  $_SESSION['admin_media_items'] = $items;
  header('Location: ' . $base . '/admin/images.php');
  exit;
}

$stats = [
  'total' => count($items),
  'images' => 0,
  'videos' => 0,
  'pdfs' => 0,
  'docx' => 0,
  'used' => 0,
  'unused' => 0,
];

$formatSet = [];
foreach ($items as &$it) {
  $it['size_bytes'] = (int)($it['size_bytes'] ?? 0);
  $it['size_label'] = media_format_bytes($it['size_bytes']);
  $it['usage'] = (int)($it['usage'] ?? 0);
  $it['uploaded_ts'] = strtotime((string)($it['uploaded_at'] ?? '')) ?: 0;
  $it['last_used_ts'] = strtotime((string)($it['last_used_at'] ?? '')) ?: 0;
  $it['dimension_bucket'] = media_dimension_bucket((int)($it['width'] ?? 0), (int)($it['height'] ?? 0));

  $fmt = strtolower((string)($it['format'] ?? ''));
  if ($fmt !== '') $formatSet[$fmt] = true;
  if (in_array($fmt, ['jpg','jpeg','png','svg','webp','gif','bmp','avif'], true)) $stats['images']++;
  if (in_array($fmt, ['mp4','mov','webm'], true)) $stats['videos']++;
  if ($fmt === 'pdf') $stats['pdfs']++;
  if ($fmt === 'docx') $stats['docx']++;
  if ($it['usage'] > 0) $stats['used']++; else $stats['unused']++;
}
unset($it);

$formats = array_keys($formatSet);
sort($formats, SORT_NATURAL | SORT_FLAG_CASE);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Media — NexusCMS Admin</title>
  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    body{margin:0;background:var(--admin-bg);color:var(--admin-text);font:14px/1.4 Arial, Helvetica, sans-serif;}
    a{color:inherit;text-decoration:none}
    main{max-width:none;margin:0;padding:14px;display:grid;gap:12px;min-width:0;}
    .panel{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;min-width:0;}
    .panel-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid var(--admin-line);}
    .panel-title{margin:0;font-size:16px;font-weight:700;color:var(--admin-text-strong)}

    .notice,.error-banner{margin:0;padding:10px 12px;border-radius:4px;border:1px solid transparent;font-size:13px}
    .notice{border-color:color-mix(in srgb, var(--admin-success) 38%, var(--admin-line));background:color-mix(in srgb, var(--admin-success) 14%, transparent);color:var(--admin-success)}
    .error-banner{border-color:color-mix(in srgb, var(--admin-danger) 42%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 14%, transparent);color:var(--admin-danger)}

    .summary{display:grid;grid-template-columns:repeat(7,minmax(110px,1fr));gap:8px;padding:10px 12px;background:var(--admin-surface-2)}
    .metric{border:1px solid var(--admin-line);border-radius:4px;padding:8px;background:var(--admin-surface)}
    .metric-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--admin-muted)}
    .metric-value{margin-top:2px;font-size:20px;font-weight:700;color:var(--admin-text-strong)}
    .metric.active .metric-value{color:var(--admin-success)}
    .metric.warn .metric-value{color:var(--admin-warn)}

    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:0 10px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text-strong);font-size:13px;font-weight:600;cursor:pointer}
    .btn.primary{border-color:color-mix(in srgb, var(--admin-accent) 60%, var(--admin-line));background:var(--admin-accent);color:#fff}
    .btn.small{min-height:28px;padding:0 8px;font-size:12px}
    .btn.ghost{background:transparent}
    .btn.danger{border-color:color-mix(in srgb, var(--admin-danger) 56%, var(--admin-line));background:color-mix(in srgb, var(--admin-danger) 8%, transparent);color:var(--admin-danger)}
    .btn:disabled{opacity:.55;cursor:not-allowed}

    .filters{display:flex;gap:8px;flex-wrap:wrap;padding:8px 12px;border-top:1px solid var(--admin-line);border-bottom:1px solid var(--admin-line);background:var(--admin-surface-2)}
    .field{display:flex;align-items:center;gap:8px;padding:0 8px;height:32px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface)}
    .field input,.field select{border:0;outline:0;background:transparent;font:inherit;color:inherit}
    .field input{min-width:180px}

    .bulkbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid var(--admin-line);background:color-mix(in srgb, var(--admin-accent) 8%, transparent)}
    .bulk-count{font-size:12px;color:var(--admin-muted);font-weight:600}

    .table-wrap{overflow:auto;max-width:100%}
    table{width:100%;border-collapse:collapse;font-size:13px;background:var(--admin-surface);min-width:1080px}
    thead th{text-align:left;background:var(--admin-surface-2);border-top:1px solid var(--admin-line);border-bottom:1px solid var(--admin-line);padding:7px 8px;font-weight:700;white-space:nowrap}
    tbody tr{cursor:pointer}
    tbody td{padding:7px 8px;border-bottom:1px solid var(--admin-line);vertical-align:middle}
    tbody tr:hover{background:color-mix(in srgb, var(--admin-accent) 10%, transparent)}
    tbody tr.selected{background:color-mix(in srgb, var(--admin-accent) 14%, transparent)}

    .check-col{width:34px}
    .thumb{width:56px;height:40px;object-fit:cover;border:1px solid var(--admin-line);border-radius:4px;display:block;background:var(--admin-surface-2)}
    .file-link{font-weight:700;color:var(--admin-text-strong);text-decoration:none}
    .file-link:hover{text-decoration:underline}
    .meta{font-size:12px;color:var(--admin-muted)}
    .usage-link{font-size:12px;font-weight:700;color:var(--admin-accent);text-decoration:underline;text-underline-offset:2px}

    .row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end}
    .row-menu{position:relative}
    .row-menu summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);font-size:15px;line-height:1}
    .row-menu summary::-webkit-details-marker{display:none}
    .row-menu-list{position:absolute;right:0;top:calc(100% + 4px);min-width:160px;padding:6px;background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;display:grid;gap:4px;z-index:45}
    .row-menu-list button{display:block;width:100%;border:0;background:transparent;color:var(--admin-text);font:inherit;font-size:12px;font-weight:600;text-align:left;padding:7px 8px;border-radius:4px;cursor:pointer}
    .row-menu-list button:hover{background:color-mix(in srgb, var(--admin-accent) 12%, transparent)}
    .row-menu-list button.danger{color:var(--admin-danger)}

    .sort-btn{border:0;background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:6px}
    .sort-btn .arrow{font-size:10px;color:var(--admin-muted)}
    .sort-btn.active .arrow{color:var(--admin-text-strong)}

    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:50}
    .modal{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px;padding:16px;min-width:320px;max-width:680px;width:min(680px, calc(100vw - 24px));max-height:calc(100vh - 36px);overflow:auto}
    .modal h3{margin:0 0 8px;color:var(--admin-text-strong)}
    .modal p{margin:0;color:var(--admin-muted)}
    .modal-grid{display:grid;grid-template-columns:180px 1fr;gap:7px 10px;margin-top:12px}
    .label{font-size:12px;color:var(--admin-muted);font-weight:700}
    .value{font-size:13px;color:var(--admin-text)}
    .usage-list{margin:8px 0 0;padding-left:18px}
    .usage-list li{margin:3px 0}
    .empty{padding:14px;color:var(--admin-muted)}

    @media (max-width:1100px){.summary{grid-template-columns:repeat(4,minmax(110px,1fr));}}
    @media (max-width:960px){
      .summary{grid-template-columns:repeat(2,minmax(110px,1fr));}
      .field input{min-width:130px}
      .modal-grid{grid-template-columns:1fr}
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <p class="<?= ($flash['type'] ?? 'notice') === 'error' ? 'error-banner' : 'notice' ?>"><?= Security::e((string)$flash['message']) ?></p>
    <?php endif; ?>

    <section class="panel" aria-label="Media overview">
      <div class="panel-head">
        <h1 class="panel-title">Media Overview</h1>
        <button class="btn primary" type="button" id="mockUploadBtn">Upload Media</button>
      </div>
      <div class="summary">
        <div class="metric"><div class="metric-label">Total Files</div><div class="metric-value"><?= (int)$stats['total'] ?></div></div>
        <div class="metric active"><div class="metric-label">Images</div><div class="metric-value"><?= (int)$stats['images'] ?></div></div>
        <div class="metric"><div class="metric-label">Videos</div><div class="metric-value"><?= (int)$stats['videos'] ?></div></div>
        <div class="metric"><div class="metric-label">PDFs</div><div class="metric-value"><?= (int)$stats['pdfs'] ?></div></div>
        <div class="metric"><div class="metric-label">DOCX</div><div class="metric-value"><?= (int)$stats['docx'] ?></div></div>
        <div class="metric"><div class="metric-label">Used</div><div class="metric-value"><?= (int)$stats['used'] ?></div></div>
        <div class="metric warn"><div class="metric-label">Unused</div><div class="metric-value"><?= (int)$stats['unused'] ?></div></div>
      </div>
    </section>

    <section class="panel" aria-label="All media">
      <div class="panel-head">
        <h2 class="panel-title">All Media</h2>
      </div>

      <div class="filters">
        <label class="field"><span>Search</span><input id="mediaSearch" type="search" placeholder="Filename, alt text, site"></label>
        <label class="field"><span>Site</span><select id="mediaSiteFilter"><option value="">All</option><?php foreach ($siteSlugs as $slug): ?><option value="<?= Security::e($slug) ?>"><?= Security::e($slug) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Usage</span><select id="mediaUsageFilter"><option value="">All</option><option value="used">Used</option><option value="unused">Unused</option></select></label>
        <label class="field"><span>Format</span><select id="mediaFormatFilter"><option value="">All</option><?php foreach ($formats as $fmt): ?><option value="<?= Security::e($fmt) ?>"><?= Security::e(strtoupper($fmt)) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Size</span><select id="mediaSizeFilter"><option value="">Any</option><option value="small">< 100 KB</option><option value="medium">100 KB - 1 MB</option><option value="large">> 1 MB</option></select></label>
        <label class="field"><span>Dimensions</span><select id="mediaDimensionFilter"><option value="">Any</option><option value="landscape">Landscape</option><option value="portrait">Portrait</option><option value="square">Square</option></select></label>
        <label class="field"><span>Uploaded</span><select id="mediaUploadedFilter"><option value="">Any</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option><option value="365">Last year</option></select></label>
        <button class="btn" type="button" id="reviewUnusedBtn">Review unused</button>
        <button class="btn" type="button" id="resetMediaFilters">Reset</button>
      </div>

      <form id="mediaBulkForm" method="post" class="bulkbar">
        <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="mode" value="bulk_action">
        <span class="bulk-count" id="mediaBulkCount">0 selected</span>
        <label class="field" style="min-width:260px;">
          <span>Bulk action</span>
          <select id="mediaBulkAction" name="bulk_action">
            <option value="">Choose action</option>
            <option value="download">Download selected</option>
            <option value="mark_unused">Mark unused</option>
            <option value="move_site">Move to site</option>
            <option value="delete">Delete selected</option>
          </select>
        </label>
        <label class="field" id="bulkSiteWrap" style="display:none;min-width:220px;"><span>Destination</span><select name="bulk_target_site" id="bulkTargetSite"><option value="">Select site</option><?php foreach ($siteSlugs as $slug): ?><option value="<?= Security::e($slug) ?>"><?= Security::e($slug) ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="btn small" id="applyMediaBulk" disabled>Apply</button>
      </form>

      <?php if (!$items): ?>
        <div class="empty">No media found.</div>
      <?php else: ?>
      <div class="table-wrap">
        <table id="mediaTable" aria-label="Media assets">
          <thead>
            <tr>
              <th class="check-col"><input type="checkbox" id="selectAllMedia" aria-label="Select all media"></th>
              <th>Preview</th>
              <th style="min-width:230px;"><button type="button" class="sort-btn" data-sort="name">File <span class="arrow">↕</span></button></th>
              <th style="min-width:120px;">Site</th>
              <th style="min-width:100px;"><button type="button" class="sort-btn" data-sort="format">Format <span class="arrow">↕</span></button></th>
              <th style="min-width:120px;">Dimensions</th>
              <th style="min-width:100px;"><button type="button" class="sort-btn" data-sort="size">Size <span class="arrow">↕</span></button></th>
              <th style="min-width:100px;"><button type="button" class="sort-btn" data-sort="usage">Usage <span class="arrow">↕</span></button></th>
              <th style="min-width:140px;"><button type="button" class="sort-btn active" data-sort="uploaded">Uploaded <span class="arrow">↓</span></button></th>
              <th style="min-width:120px;">Uploaded By</th>
              <th style="min-width:140px;">Last Used</th>
              <th style="min-width:210px;text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <?php
                $usagePages = (array)($it['pages'] ?? []);
                $usageLabel = ((int)$it['usage'] > 0 ? 'Used' : 'Unused') . ' (' . (int)$it['usage'] . ')';
                $usageJson = json_encode($usagePages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $details = [
                  'id' => (int)$it['id'],
                  'filename' => (string)$it['filename'],
                  'alt' => (string)($it['alt'] ?? ''),
                  'format' => strtoupper((string)($it['format'] ?? '')),
                  'dimensions' => ((int)$it['width']) . 'x' . ((int)$it['height']),
                  'size' => (string)$it['size_label'],
                  'site' => (string)$it['site'],
                  'uploaded_by' => (string)$it['uploaded_by'],
                  'uploaded_at' => (string)$it['uploaded_at'],
                  'last_used_at' => (string)($it['last_used_at'] ?? ''),
                  'usage' => (int)$it['usage'],
                  'thumb' => (string)$it['thumb'],
                ];
              ?>
              <tr
                data-id="<?= (int)$it['id'] ?>"
                data-search="<?= Security::e(strtolower((string)$it['filename'] . ' ' . (string)($it['alt'] ?? '') . ' ' . (string)$it['site'])) ?>"
                data-site="<?= Security::e(strtolower((string)$it['site'])) ?>"
                data-format="<?= Security::e(strtolower((string)$it['format'])) ?>"
                data-usage="<?= (int)$it['usage'] ?>"
                data-size-bytes="<?= (int)$it['size_bytes'] ?>"
                data-dimension="<?= Security::e((string)$it['dimension_bucket']) ?>"
                data-uploaded-ts="<?= (int)$it['uploaded_ts'] ?>"
                data-last-used-ts="<?= (int)$it['last_used_ts'] ?>"
                data-detail='<?= Security::e(json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'
                data-pages='<?= Security::e((string)$usageJson) ?>'
              >
                <td class="check-col"><input type="checkbox" class="media-check" name="media_ids[]" value="<?= (int)$it['id'] ?>" form="mediaBulkForm" aria-label="Select <?= Security::e((string)$it['filename']) ?>"></td>
                <td><img class="thumb" src="<?= Security::e((string)$it['thumb']) ?>" alt=""></td>
                <td>
                  <a href="#" class="file-link" data-open-detail onclick="return false;"><?= Security::e((string)$it['filename']) ?></a>
                  <div class="meta"><?= Security::e((string)($it['alt'] ?: 'No alt text')) ?></div>
                </td>
                <td><?= Security::e((string)$it['site']) ?></td>
                <td><?= Security::e(strtoupper((string)$it['format'])) ?></td>
                <td><?= (int)$it['width'] ?>x<?= (int)$it['height'] ?></td>
                <td><?= Security::e((string)$it['size_label']) ?></td>
                <td><a href="#" class="usage-link" data-open-usage onclick="return false;"><?= Security::e($usageLabel) ?></a></td>
                <td>
                  <div title="<?= Security::e((string)$it['uploaded_at']) ?>"><?= Security::e(media_relative_time((string)$it['uploaded_at'])) ?></div>
                  <div class="meta"><?= Security::e(substr((string)$it['uploaded_at'], 0, 10)) ?></div>
                </td>
                <td><?= Security::e((string)$it['uploaded_by']) ?></td>
                <td>
                  <div title="<?= Security::e((string)($it['last_used_at'] ?? '')) ?>"><?= Security::e(media_relative_time((string)($it['last_used_at'] ?? null))) ?></div>
                </td>
                <td>
                  <div class="row-actions">
                    <button type="button" class="btn small primary" data-open-detail>View</button>
                    <button type="button" class="btn small ghost" data-open-replace>Replace</button>
                    <details class="row-menu">
                      <summary aria-label="More actions">⋮</summary>
                      <div class="row-menu-list">
                        <form method="post">
                          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                          <input type="hidden" name="mode" value="row_action">
                          <input type="hidden" name="media_id" value="<?= (int)$it['id'] ?>">
                          <button type="submit" name="action" value="mark_unused">Mark unused</button>
                        </form>
                        <form method="post">
                          <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
                          <input type="hidden" name="mode" value="row_action">
                          <input type="hidden" name="media_id" value="<?= (int)$it['id'] ?>">
                          <button class="danger" type="submit" name="action" value="delete">Delete</button>
                        </form>
                      </div>
                    </details>
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

  <div class="modal-backdrop" id="mediaDetailModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mediaDetailTitle">
      <h3 id="mediaDetailTitle">Media details</h3>
      <p id="mediaDetailSub"></p>
      <img id="mediaDetailThumb" class="thumb" style="width:220px;height:140px;margin-top:10px;" alt="">
      <div class="modal-grid">
        <div class="label">Filename</div><div class="value" id="mdFilename">—</div>
        <div class="label">Alt text</div><div class="value" id="mdAlt">—</div>
        <div class="label">Format</div><div class="value" id="mdFormat">—</div>
        <div class="label">Dimensions</div><div class="value" id="mdDimensions">—</div>
        <div class="label">Size</div><div class="value" id="mdSize">—</div>
        <div class="label">Site</div><div class="value" id="mdSite">—</div>
        <div class="label">Uploaded</div><div class="value" id="mdUploaded">—</div>
        <div class="label">Uploaded by</div><div class="value" id="mdBy">—</div>
        <div class="label">Last used</div><div class="value" id="mdLastUsed">—</div>
        <div class="label">Usage</div><div class="value" id="mdUsage">—</div>
      </div>
      <div class="modal-actions"><button type="button" class="btn" data-close-modal="mediaDetailModal">Close</button></div>
    </div>
  </div>

  <div class="modal-backdrop" id="mediaUsageModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mediaUsageTitle">
      <h3 id="mediaUsageTitle">Usage locations</h3>
      <p id="mediaUsageSub"></p>
      <ul id="mediaUsageList" class="usage-list"></ul>
      <div class="modal-actions"><button type="button" class="btn" data-close-modal="mediaUsageModal">Close</button></div>
    </div>
  </div>

  <div class="modal-backdrop" id="mediaReplaceModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="mediaReplaceTitle">
      <h3 id="mediaReplaceTitle">Replace asset</h3>
      <p>Swap this file version while keeping references intact.</p>
      <form method="post" id="replaceForm" style="display:grid;gap:10px;margin-top:12px;">
        <input type="hidden" name="_csrf" value="<?= Security::e($csrfToken) ?>">
        <input type="hidden" name="mode" value="replace_file">
        <input type="hidden" name="media_id" id="replaceMediaId" value="0">
        <label class="label" for="replacementName">New filename</label>
        <input id="replacementName" name="replacement_name" type="text" required style="width:100%;padding:8px;border-radius:4px;border:1px solid var(--admin-line);background:var(--admin-surface-2);color:var(--admin-text)">
        <div class="modal-actions">
          <button type="button" class="btn" data-close-modal="mediaReplaceModal">Cancel</button>
          <button type="submit" class="btn primary">Replace</button>
        </div>
      </form>
    </div>
  </div>

  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
      const rows = Array.from(document.querySelectorAll('#mediaTable tbody tr'));
      const searchEl = document.getElementById('mediaSearch');
      const siteEl = document.getElementById('mediaSiteFilter');
      const usageEl = document.getElementById('mediaUsageFilter');
      const formatEl = document.getElementById('mediaFormatFilter');
      const sizeEl = document.getElementById('mediaSizeFilter');
      const dimEl = document.getElementById('mediaDimensionFilter');
      const uploadedEl = document.getElementById('mediaUploadedFilter');
      const resetBtn = document.getElementById('resetMediaFilters');
      const reviewUnusedBtn = document.getElementById('reviewUnusedBtn');

      const sortButtons = Array.from(document.querySelectorAll('.sort-btn'));
      let sortKey = 'uploaded';
      let sortDir = 'desc';

      const selectAll = document.getElementById('selectAllMedia');
      const checks = Array.from(document.querySelectorAll('.media-check'));
      const bulkCount = document.getElementById('mediaBulkCount');
      const bulkAction = document.getElementById('mediaBulkAction');
      const bulkForm = document.getElementById('mediaBulkForm');
      const applyBulk = document.getElementById('applyMediaBulk');
      const bulkSiteWrap = document.getElementById('bulkSiteWrap');
      const bulkTargetSite = document.getElementById('bulkTargetSite');

      function activeRows() {
        return rows.filter((r) => r.style.display !== 'none');
      }

      function updateBulkState() {
        const selected = checks.filter((c) => c.checked).length;
        if (bulkCount) bulkCount.textContent = selected + ' selected';
        const action = (bulkAction?.value || '').trim();
        const needsSite = action === 'move_site';
        if (bulkSiteWrap) bulkSiteWrap.style.display = needsSite ? '' : 'none';
        const ready = selected > 0 && action !== '' && (!needsSite || (bulkTargetSite?.value || '').trim() !== '');
        if (applyBulk) applyBulk.disabled = !ready;

        const visibleChecks = checks.filter((c) => c.closest('tr')?.style.display !== 'none');
        const visibleSelected = visibleChecks.filter((c) => c.checked).length;
        if (selectAll) {
          selectAll.checked = visibleChecks.length > 0 && visibleSelected === visibleChecks.length;
          selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleChecks.length;
        }
        rows.forEach((row) => row.classList.toggle('selected', !!row.querySelector('.media-check:checked')));
      }

      function applyFilters() {
        const q = (searchEl?.value || '').trim().toLowerCase();
        const site = (siteEl?.value || '').trim().toLowerCase();
        const usage = (usageEl?.value || '').trim().toLowerCase();
        const format = (formatEl?.value || '').trim().toLowerCase();
        const sizeRange = (sizeEl?.value || '').trim().toLowerCase();
        const dim = (dimEl?.value || '').trim().toLowerCase();
        const uploadedDays = parseInt((uploadedEl?.value || '').trim(), 10);
        const now = Math.floor(Date.now() / 1000);

        rows.forEach((row) => {
          const hay = row.getAttribute('data-search') || '';
          const rowSite = row.getAttribute('data-site') || '';
          const rowUsage = parseInt(row.getAttribute('data-usage') || '0', 10);
          const rowFmt = row.getAttribute('data-format') || '';
          const rowSize = parseInt(row.getAttribute('data-size-bytes') || '0', 10);
          const rowDim = row.getAttribute('data-dimension') || '';
          const rowUploaded = parseInt(row.getAttribute('data-uploaded-ts') || '0', 10);

          let ok = true;
          if (q && !hay.includes(q)) ok = false;
          if (ok && site && rowSite !== site) ok = false;
          if (ok && usage === 'used' && rowUsage < 1) ok = false;
          if (ok && usage === 'unused' && rowUsage > 0) ok = false;
          if (ok && format && rowFmt !== format) ok = false;
          if (ok && dim && rowDim !== dim) ok = false;
          if (ok && sizeRange === 'small' && rowSize >= 102400) ok = false;
          if (ok && sizeRange === 'medium' && (rowSize < 102400 || rowSize > 1048576)) ok = false;
          if (ok && sizeRange === 'large' && rowSize <= 1048576) ok = false;
          if (ok && Number.isFinite(uploadedDays) && uploadedDays > 0) {
            const age = now - rowUploaded;
            if (age > uploadedDays * 86400) ok = false;
          }

          row.style.display = ok ? '' : 'none';
        });

        updateBulkState();
      }

      function sortRows() {
        const tbody = document.querySelector('#mediaTable tbody');
        if (!tbody) return;
        const sorted = [...rows].sort((a, b) => {
          if (sortKey === 'name') {
            const av = (a.querySelector('.file-link')?.textContent || '').toLowerCase();
            const bv = (b.querySelector('.file-link')?.textContent || '').toLowerCase();
            return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
          }
          if (sortKey === 'format') {
            const av = (a.getAttribute('data-format') || '').toLowerCase();
            const bv = (b.getAttribute('data-format') || '').toLowerCase();
            return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
          }
          if (sortKey === 'size') {
            const av = parseInt(a.getAttribute('data-size-bytes') || '0', 10);
            const bv = parseInt(b.getAttribute('data-size-bytes') || '0', 10);
            return sortDir === 'asc' ? av - bv : bv - av;
          }
          if (sortKey === 'usage') {
            const av = parseInt(a.getAttribute('data-usage') || '0', 10);
            const bv = parseInt(b.getAttribute('data-usage') || '0', 10);
            return sortDir === 'asc' ? av - bv : bv - av;
          }
          const av = parseInt(a.getAttribute('data-uploaded-ts') || '0', 10);
          const bv = parseInt(b.getAttribute('data-uploaded-ts') || '0', 10);
          return sortDir === 'asc' ? av - bv : bv - av;
        });
        sorted.forEach((r) => tbody.appendChild(r));
      }

      sortButtons.forEach((btn) => {
        btn.addEventListener('click', function(){
          const key = btn.getAttribute('data-sort') || 'uploaded';
          if (sortKey === key) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
          else {
            sortKey = key;
            sortDir = key === 'name' || key === 'format' ? 'asc' : 'desc';
          }
          sortButtons.forEach((b) => {
            b.classList.toggle('active', b === btn);
            const arrow = b.querySelector('.arrow');
            if (!arrow) return;
            arrow.textContent = b === btn ? (sortDir === 'asc' ? '↑' : '↓') : '↕';
          });
          sortRows();
        });
      });

      [searchEl, siteEl, usageEl, formatEl, sizeEl, dimEl, uploadedEl].forEach((el) => {
        el?.addEventListener('input', applyFilters);
        el?.addEventListener('change', applyFilters);
      });

      reviewUnusedBtn?.addEventListener('click', function(){
        if (usageEl) usageEl.value = 'unused';
        applyFilters();
      });
      resetBtn?.addEventListener('click', function(){
        if (searchEl) searchEl.value = '';
        if (siteEl) siteEl.value = '';
        if (usageEl) usageEl.value = '';
        if (formatEl) formatEl.value = '';
        if (sizeEl) sizeEl.value = '';
        if (dimEl) dimEl.value = '';
        if (uploadedEl) uploadedEl.value = '';
        applyFilters();
      });

      checks.forEach((c) => c.addEventListener('change', updateBulkState));
      selectAll?.addEventListener('change', function(){
        const checked = !!selectAll.checked;
        checks.forEach((c) => {
          if (c.closest('tr')?.style.display === 'none') return;
          c.checked = checked;
        });
        updateBulkState();
      });
      bulkAction?.addEventListener('change', updateBulkState);
      bulkTargetSite?.addEventListener('change', updateBulkState);
      bulkForm?.addEventListener('submit', function(e){
        const action = (bulkAction?.value || '').trim();
        if (!action) { e.preventDefault(); return; }
        if (action === 'delete' && !confirm('Delete selected assets? This cannot be undone.')) e.preventDefault();
      });

      document.querySelectorAll('.row-menu-list form').forEach((form) => {
        form.addEventListener('submit', function(e){
          const action = (form.querySelector('button[type="submit"]')?.value || '').toLowerCase();
          if (action === 'delete' && !confirm('Delete this asset? This cannot be undone.')) e.preventDefault();
        });
      });

      document.addEventListener('click', function(e){
        document.querySelectorAll('.row-menu[open]').forEach((menu) => {
          if (!menu.contains(e.target)) menu.open = false;
        });
      });

      const detailModal = document.getElementById('mediaDetailModal');
      const usageModal = document.getElementById('mediaUsageModal');
      const replaceModal = document.getElementById('mediaReplaceModal');
      const replaceId = document.getElementById('replaceMediaId');
      const replaceName = document.getElementById('replacementName');

      function parseDetail(row) {
        try {
          return JSON.parse(row.getAttribute('data-detail') || '{}');
        } catch (_) {
          return {};
        }
      }

      function parsePages(row) {
        try {
          return JSON.parse(row.getAttribute('data-pages') || '[]');
        } catch (_) {
          return [];
        }
      }

      function openDetail(row) {
        const d = parseDetail(row);
        document.getElementById('mediaDetailSub').textContent = d.site || '';
        document.getElementById('mediaDetailThumb').src = d.thumb || '';
        document.getElementById('mdFilename').textContent = d.filename || '—';
        document.getElementById('mdAlt').textContent = d.alt || 'No alt text';
        document.getElementById('mdFormat').textContent = d.format || '—';
        document.getElementById('mdDimensions').textContent = d.dimensions || '—';
        document.getElementById('mdSize').textContent = d.size || '—';
        document.getElementById('mdSite').textContent = d.site || '—';
        document.getElementById('mdUploaded').textContent = d.uploaded_at || '—';
        document.getElementById('mdBy').textContent = d.uploaded_by || '—';
        document.getElementById('mdLastUsed').textContent = d.last_used_at || '—';
        document.getElementById('mdUsage').textContent = String(d.usage ?? 0);
        detailModal.style.display = 'flex';
      }

      function openUsage(row) {
        const d = parseDetail(row);
        const pages = parsePages(row);
        document.getElementById('mediaUsageSub').textContent = d.filename || '';
        const list = document.getElementById('mediaUsageList');
        list.innerHTML = '';
        if (!pages.length) {
          const li = document.createElement('li');
          li.textContent = 'No usage locations found.';
          list.appendChild(li);
        } else {
          pages.forEach((p) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = (p.path || '#');
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = (p.title || 'Page') + ' (' + (p.path || '') + ')';
            li.appendChild(a);
            list.appendChild(li);
          });
        }
        usageModal.style.display = 'flex';
      }

      function openReplace(row) {
        const d = parseDetail(row);
        if (replaceId) replaceId.value = String(d.id || 0);
        if (replaceName) replaceName.value = d.filename || '';
        replaceModal.style.display = 'flex';
      }

      rows.forEach((row) => {
        row.querySelectorAll('[data-open-detail]').forEach((el) => {
          el.addEventListener('click', () => openDetail(row));
        });
        row.querySelectorAll('[data-open-usage]').forEach((el) => {
          el.addEventListener('click', () => openUsage(row));
        });
        row.querySelectorAll('[data-open-replace]').forEach((el) => {
          el.addEventListener('click', () => openReplace(row));
        });
        row.addEventListener('click', function(e){
          if (e.target.closest('a,button,input,select,summary,details,form,label')) return;
          openDetail(row);
        });
      });

      document.querySelectorAll('[data-close-modal]').forEach((btn) => {
        btn.addEventListener('click', function(){
          const id = btn.getAttribute('data-close-modal');
          const modal = id ? document.getElementById(id) : null;
          if (modal) modal.style.display = 'none';
        });
      });

      [detailModal, usageModal, replaceModal].forEach((m) => {
        m?.addEventListener('click', (e) => {
          if (e.target === m) m.style.display = 'none';
        });
      });

      document.getElementById('mockUploadBtn')?.addEventListener('click', function(){
        alert('Upload flow placeholder. Connect this button to your storage uploader when ready.');
      });

      sortRows();
      applyFilters();
      updateBulkState();
    })();
  </script>
</body>
</html>
