<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Core\Security;

$siteId = (int)($_GET['site_id'] ?? 0);
$site = Site::find($siteId);
if (!$site) { http_response_code(404); echo "Site not found"; exit; }
$themeIsLight = ui_theme_is_light();

$templates = require __DIR__ . '/../app/templates/page_templates.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) $error = "Security check failed.";
  else {
    $title = trim((string)($_POST['title'] ?? ''));
    $slug  = trim((string)($_POST['slug'] ?? ''));
    $mode  = (string)($_POST['mode'] ?? 'template'); // template|blank
    $tpl   = (string)($_POST['template'] ?? 'home');

    if ($title === '' || $slug === '') $error = "Title and slug are required.";
    else {
      $doc = ['version'=>1,'rows'=>[]];
      if ($mode === 'template') $doc = $templates[$tpl] ?? $doc;
      else $doc = ['version'=>1,'rows'=>[ ['cols'=>[['span'=>12,'blocks'=>[]]]] ]];

      $pageId = Page::create($siteId, $title, $slug, $doc);
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
  <link rel="stylesheet" href="<?= base_path() ?>/public/assets/admin-shared.css">
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
        <label>Slug</label>
        <input name="slug" id="slug" required placeholder="home">
      </div>

      <div class="layout-grid">
        <?php
          $layoutMeta = [
            'landing-lite' => ['name'=>'Landing (hero + cards)','desc'=>'Hero headline with CTA and feature cards.'],
            'resource-library' => ['name'=>'Resource Library','desc'=>'Intro text plus a grid of resource cards and a divider.'],
            'about-profile' => ['name'=>'About / Profile','desc'=>'Profile hero with body text and key facts.'],
            'home' => ['name'=>'Simple Home','desc'=>'Heading and two-column content starter.'],
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
            
