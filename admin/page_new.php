<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Core\Security;
use NexusCMS\Support\PartialsManager;
use NexusCMS\Support\PagePath;

$siteId = (int)($_GET['site_id'] ?? 0);
$site = Site::find($siteId);
if (!$site) { http_response_code(404); echo "Site not found"; exit; }
$themeIsLight = ui_theme_is_light();
$styleOptions = ['Harvard','APA 7th','Chicago 18th','Chicago 17th','IEEE','MHRA 4th','MHRA 3rd','MLA 9th','OSCOLA','Vancouver'];
$topicOptions = ['Books','Journals','Digital & Internet','Media & Art','Research','Legal','Governmental','Communications'];

$templates = require __DIR__ . '/../app/templates/page_templates.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) $error = "Security check failed.";
  else {
    $title = trim((string)($_POST['title'] ?? ''));
    $slug  = trim((string)($_POST['slug'] ?? ''));
    $mode  = (string)($_POST['mode'] ?? 'template'); // template|blank
    $tpl   = (string)($_POST['template'] ?? 'home');
    $style = trim((string)($_POST['path_style'] ?? ''));
    $topic = trim((string)($_POST['path_topic'] ?? ''));
    $pathParts = [];
    $stylePart = PagePath::normalizeSegment($style);
    $topicPart = PagePath::normalizeSegment($topic);
    if ($stylePart !== '') $pathParts[] = $stylePart;
    if ($topicPart !== '') $pathParts[] = $topicPart;
    $leafSlug = PagePath::normalizeSegment($slug);
    if ($leafSlug !== '') $pathParts[] = $leafSlug;
    $fullSlug = PagePath::join($pathParts);

    if ($title === '' || $stylePart === '' || $topicPart === '' || $leafSlug === '') $error = "Title, style, topic, and source type are required.";
    elseif (Page::findBySlugAnyStatus($siteId, $fullSlug)) $error = "Page path already exists.";
    else {
      $doc = ['version'=>1,'rows'=>[]];
      if ($mode === 'template') $doc = $templates[$tpl] ?? $doc;
      else $doc = ['version'=>1,'rows'=>[ ['cols'=>[['span'=>12,'blocks'=>[]]]] ]];

      $pageId = Page::create($siteId, $title, $fullSlug, $doc);
      PartialsManager::ensurePageDirectory((string)($site['slug'] ?? ''), $fullSlug);
      redirect('/admin/page_builder.php?id=' . $pageId);
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>New Page — <?= Security::e($site['name']) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script>
    (function() {
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <link rel="stylesheet" href="<?= base_path() ?>/public/assets/admin-shared.css?v=20260322">
  <link rel="stylesheet" href="<?= base_path() ?>/public/assets/page_new.css">
</head>
<body class="page-new">
  <?php include __DIR__ . '/partials/header.php'; ?>
  <div class="shell">
    <header class="topbar">
      <div>
        <div class="crumbs"><a href="<?= base_path() ?>/admin/site.php?id=<?= (int)$site['id'] ?>">Back to site</a></div>
        <h1>New Page</h1>
        <p class="muted">Choose a layout or start blank. When you click a layout, we’ll open the builder with the blocks in place.</p>
      </div>
      <div class="badge">Site: <?= Security::e($site['name']) ?></div>
    </header>

    <?php if ($error): ?>
      <div class="notice error"><?= Security::e($error) ?></div>
    <?php endif; ?>

    <form method="post" id="pageForm" class="panel">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="mode" id="mode" value="template">
      <input type="hidden" name="template" id="template" value="<?= Security::e(array_key_first($templates)) ?>">

      <div class="fieldrow">
        <label>Page title</label>
        <input name="title" id="title" required placeholder="e.g. Landing page">
      </div>
      <div class="fieldrow">
        <label>Source Type</label>
        <input name="slug" id="slug" required placeholder="blogs">
      </div>
      <div class="fieldrow">
        <label>File path</label>
        <div class="path-builder">
          <div class="path-prefix"><?= Security::e(strtolower((string)($site['slug'] ?? 'site'))) ?>/</div>
          <div class="path-grid path-grid-fixed">
            <div>
              <label for="path_style">Style</label>
              <select id="path_style" name="path_style" required>
                <option value="">Select style</option>
                <?php foreach ($styleOptions as $styleOption): ?>
                  <option value="<?= Security::e($styleOption) ?>"><?= Security::e($styleOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="path_topic">Topic</label>
              <select id="path_topic" name="path_topic" required>
                <option value="">Select topic</option>
                <?php foreach ($topicOptions as $topicOption): ?>
                  <option value="<?= Security::e($topicOption) ?>"><?= Security::e($topicOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="path-preview" id="pathPreview">/</div>
        </div>
      </div>

      <div class="layout-grid">
        <?php
          $layoutMeta = [
            'home' => ['name'=>'Simple Home','desc'=>'Heading and two-column content starter.'],
            'title-page' => ['name'=>'Title page','desc'=>'Cite Them Right homepage structure with editable placeholder blocks.'],
            'referencing-browse' => ['name'=>'Referencing Browse','desc'=>'Browse-by-category scaffold with quick links, accordions, and sidebar panels.'],
            'article' => ['name'=>'Article','desc'=>'Article body with related sidebar.'],
            'source-type' => ['name'=>'Source Type','desc'=>'Heading, intro text, citation order, and example block.'],
          ];
        ?>
        <?php foreach ($layoutMeta as $id => $meta): ?>
          <?php if (!isset($templates[$id])) continue; ?>
          <button class="layout-card" type="button" data-layout="<?= Security::e($id) ?>">
            <div class="layout-thumb">
              <div class="thumb-dot"></div>
              <div class="thumb-title"><?= Security::e(strtoupper(substr($meta['name'],0,1))) ?></div>
            </div>
            <div class="layout-body">
              <div class="layout-title"><?= Security::e($meta['name']) ?></div>
              <div class="muted"><?= Security::e($meta['desc']) ?></div>
              <div class="chip">Use layout</div>
            </div>
          </button>
        <?php endforeach; ?>

        <button class="layout-card blank" type="button" data-layout="blank">
          <div class="layout-thumb">
            <div class="thumb-dot"></div>
            <div class="thumb-title">B</div>
          </div>
          <div class="layout-body">
            <div class="layout-title">Blank page</div>
            <div class="muted">Start from scratch with an empty row.</div>
            <div class="chip">Build manually</div>
          </div>
        </button>
      </div>

      <div class="actions">
        <button type="submit" class="primary">Create & open builder</button>
      </div>
    </form>
  </div>

  <script>
    const form = document.getElementById('pageForm');
    const tplInput = document.getElementById('template');
    const modeInput = document.getElementById('mode');
    const titleInput = document.getElementById('title');
    const slugInput = document.getElementById('slug');
    const styleInput = document.getElementById('path_style');
    const topicInput = document.getElementById('path_topic');
    const pathPreview = document.getElementById('pathPreview');

    function slugify(val){return (val||'').toLowerCase().replace(/[^a-z0-9-]+/g,'-').replace(/-+/g,'-').replace(/^-+|-+$/g,'');}
    function updatePathPreview(){
      const parts = [slugify(styleInput?.value || ''), slugify(topicInput?.value || '')].filter(Boolean);
      const leaf = slugify(slugInput?.value || '');
      const full = '<?= Security::e(strtolower((string)($site['slug'] ?? 'site'))) ?>/' + [...parts, leaf].filter(Boolean).join('/');
      if (pathPreview) pathPreview.textContent = full === '/' ? '/' : full;
    }
    styleInput?.addEventListener('change', updatePathPreview);
    topicInput?.addEventListener('change', updatePathPreview);
    slugInput?.addEventListener('input', updatePathPreview);
    updatePathPreview();

    document.querySelectorAll('.layout-card').forEach(card => {
      card.addEventListener('click', () => {
        document.querySelectorAll('.layout-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        const layout = card.dataset.layout;
        if (layout === 'blank') {
          modeInput.value = 'blank';
          tplInput.value = '';
        } else {
          modeInput.value = 'template';
          tplInput.value = layout;
        }

        if (titleInput.value.trim() && slugInput.value.trim()) {
          form.submit();
        } else {
          (titleInput.value.trim() ? slugInput : titleInput).focus();
        }
      });
    });
  </script>
</body>
</html>
            
