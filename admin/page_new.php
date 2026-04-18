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

    $needsPathBuilder = ($mode === 'template' && $tpl === 'source-type');
    if ($title === '' || $leafSlug === '' || ($needsPathBuilder && ($stylePart === '' || $topicPart === ''))) {
      $error = $needsPathBuilder
        ? "Title, style, topic, and source type are required."
        : "Title and source type are required.";
    }
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
      <div class="fieldrow" id="pathBuilderRow" hidden>
        <label>File path</label>
        <div class="path-builder">
          <div class="path-prefix"><?= Security::e(strtolower((string)($site['slug'] ?? 'site'))) ?>/</div>
          <div class="path-grid path-grid-fixed">
            <div>
              <label for="path_style">Style</label>
              <select id="path_style" name="path_style">
                <option value="">Select style</option>
                <?php foreach ($styleOptions as $styleOption): ?>
                  <option value="<?= Security::e($styleOption) ?>"><?= Security::e($styleOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="path_topic">Topic</label>
              <select id="path_topic" name="path_topic">
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
          $renderLayoutThumb = static function(string $id, string $label = ''): string {
            $safeId = Security::e($id);
            $safeLabel = Security::e($label);
            return '<div class="layout-thumb layout-thumb--' . $safeId . '">' .
              '<div class="thumb-blueprint thumb-blueprint--' . $safeId . '" aria-hidden="true"></div>' .
              ($safeLabel !== '' ? '<div class="thumb-title">' . $safeLabel . '</div>' : '') .
            '</div>';
          };
          $layoutMeta = [
            'home' => ['name'=>'Simple Home','desc'=>'Heading and two-column content starter.'],
            'title-page' => ['name'=>'Title page','desc'=>'Cite Them Right home-inspired default with placeholder-only blocks.'],
            'referencing-browse' => ['name'=>'Referencing Browse','desc'=>'Browse-by-category scaffold with quick links, accordions, and sidebar panels.'],
            'source-type' => ['name'=>'Source Type','desc'=>'Two-column source page with stacked link lists and citation content blocks.'],
            'article' => ['name'=>'Article','desc'=>'Article body with related sidebar.'],
          ];
        ?>
        <?php foreach ($layoutMeta as $id => $meta): ?>
          <?php if (!isset($templates[$id])) continue; ?>
          <button class="layout-card" type="button" data-layout="<?= Security::e($id) ?>">
            <?= $renderLayoutThumb($id, strtoupper(substr($meta['name'],0,1))) ?>
            <div class="layout-body">
              <div class="layout-title"><?= Security::e($meta['name']) ?></div>
              <div class="muted"><?= Security::e($meta['desc']) ?></div>
              <div class="chip">Use layout</div>
            </div>
          </button>
        <?php endforeach; ?>

        <button class="layout-card blank" type="button" data-layout="blank">
          <?= $renderLayoutThumb('blank', 'B') ?>
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
    const pathBuilderRow = document.getElementById('pathBuilderRow');

    function slugify(val){return (val||'').toLowerCase().replace(/[^a-z0-9-]+/g,'-').replace(/-+/g,'-').replace(/^-+|-+$/g,'');}
    function togglePathBuilder(){
      const selectedTemplate = tplInput.value || '';
      const show = modeInput.value === 'template' && selectedTemplate === 'source-type';
      if (pathBuilderRow) pathBuilderRow.hidden = !show;
      if (styleInput) styleInput.required = show;
      if (topicInput) topicInput.required = show;
    }
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
    togglePathBuilder();

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
        togglePathBuilder();
        const needsPathBuilder = modeInput.value === 'template' && layout === 'source-type';
        const hasPathParts = !needsPathBuilder || (styleInput?.value.trim() && topicInput?.value.trim());

        if (titleInput.value.trim() && slugInput.value.trim() && hasPathParts) {
          form.submit();
        } else {
          if (!titleInput.value.trim()) {
            titleInput.focus();
          } else if (!slugInput.value.trim()) {
            slugInput.focus();
          } else if (needsPathBuilder && !(styleInput?.value.trim())) {
            styleInput?.focus();
          } else if (needsPathBuilder && !(topicInput?.value.trim())) {
            topicInput?.focus();
          }
        }
      });
    });
  </script>
</body>
</html>
            
