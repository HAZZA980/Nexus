<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Models\ShellPreset;
use NexusCMS\Core\Security;
use NexusCMS\Support\PartialsManager;

$base = base_path();
$activeNav = 'site_new';
$themeIsLight = ui_theme_is_light();
$errors = [];
$values = [
  'name' => '',
  'slug' => '',
  'description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $errors['form'] = "Security check failed. Please try again.";
  } else {
    $values['name'] = trim((string)($_POST['name'] ?? ''));
    $values['slug'] = trim((string)($_POST['slug'] ?? ''));
    $values['description'] = trim((string)($_POST['description'] ?? ''));

    if ($values['name'] === '') {
      $errors['name'] = "Name is required.";
    }

    if ($values['slug'] === '') {
      $errors['slug'] = "Slug is required.";
    } else {
      $normalized = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $values['slug']));
      $normalized = trim(preg_replace('/-+/', '-', $normalized), '-');
      if ($normalized === '') {
        $errors['slug'] = "Use lowercase letters, numbers, and hyphens.";
      } else {
        $values['slug'] = $normalized;
      }
    }

    if (!$errors) {
      try {
        // Defensive load: ensure ShellPreset class is available before use
        if (!class_exists(ShellPreset::class, false)) {
          spl_autoload_call(ShellPreset::class);
        }

        $existing = Site::findBySlug($values['slug']);
        $siteId = $existing ? (int)$existing['id'] : 0;

        if (!$siteId) {
          $siteId = Site::create($values['name'], $values['slug'], $values['description']);

          // Seed site-specific shell presets from system defaults
          $headerConfig = [
            'preset' => 'nav-left',
            'brandText' => $values['name'] ?: 'Site',
            'logoUrl' => '',
            'cta' => ['label' => '', 'href' => ''],
            'items' => [
              ['label' => 'Home', 'href' => '/'],
            ],
            'style' => ['variant' => 'light', 'sticky' => true],
          ];
          $footerConfig = [
            'preset' => 'footer-minimal',
            'brandText' => $values['name'] ?: 'Site',
            'links' => [
              ['label' => 'About', 'href' => '/about'],
              ['label' => 'Contact', 'href' => '/contact'],
            ],
            'social' => [],
            'legal' => '© ' . date('Y') . ' ' . ($values['name'] ?: 'Site'),
            'style' => ['variant' => 'dark'],
          ];

          ShellPreset::save($siteId, 'header', 'nav-left', 'Logo + nav', $headerConfig, false, true);
          ShellPreset::save($siteId, 'footer', 'footer-minimal', 'Simple footer', $footerConfig, false, true);

          // Create default Home page (blank canvas, draft)
          $homeDoc = ['version'=>1,'rows'=>[ ['cols'=>[['span'=>12,'blocks'=>[]]]] ]];
          $homeId = Page::create($siteId, 'Home', 'home', $homeDoc, 'blank', null);
          PartialsManager::ensurePageDirectory($values['slug'], 'home');
          Site::setHomepage($siteId, $homeId);
        }

        redirect('/admin/');
      } catch (Throwable $e) {
        $errors['form'] = "We couldn’t create the website. Please try again. (" . $e->getMessage() . ")";
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create new website</title>
  <script>
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    body{margin:0;background:var(--admin-bg);color:var(--admin-text);font:14px/1.5 Arial, Helvetica, sans-serif}
    a{color:inherit;text-decoration:none}
    .content{padding:14px;display:grid;gap:12px}
    .panel{background:var(--admin-surface);border:1px solid var(--admin-line);border-radius:4px}
    .panel-head{padding:12px 14px;border-bottom:1px solid var(--admin-line)}
    .panel-title{margin:0;font-size:20px;font-weight:700;color:var(--admin-text-strong)}
    .panel-subtitle{margin:4px 0 0;color:var(--admin-muted)}
    .panel-body{padding:0}
    .form-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,.95fr)}
    .form-main{padding:16px 18px}
    .form-side{padding:16px 18px;border-left:1px solid var(--admin-line);background:color-mix(in srgb, var(--admin-surface-2) 55%, transparent)}
    .grid{display:grid;grid-template-columns:180px minmax(0,1fr);gap:12px 18px;align-items:start}
    .field{display:contents}
    .field label{font-weight:700;color:var(--admin-text-strong)}
    .field-control{display:grid;gap:6px;min-width:0}
    .field input,.field textarea{width:100%;min-height:38px;padding:8px 10px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text);font:inherit}
    .field textarea{min-height:120px;resize:vertical}
    .helper{font-size:12px;color:var(--admin-muted)}
    .inline-errors{display:grid;gap:4px}
    .inline-errors p{margin:0;font-size:12px;color:var(--admin-danger)}
    .error-banner{margin:0;padding:10px 12px;border-radius:4px;border:1px solid color-mix(in srgb, var(--admin-danger) 40%, var(--admin-line));font-size:13px;background:color-mix(in srgb, var(--admin-danger) 14%, transparent);color:var(--admin-danger)}
    .section-title{margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--admin-muted)}
    .info-list{display:grid;gap:10px}
    .info-card{padding:12px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface)}
    .info-card strong{display:block;margin-bottom:4px;color:var(--admin-text-strong)}
    .form-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:14px}
    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border:1px solid var(--admin-line);border-radius:4px;background:var(--admin-surface-2);color:var(--admin-text-strong);font-size:13px;font-weight:600;cursor:pointer}
    .btn.primary{border-color:color-mix(in srgb, var(--admin-accent) 60%, var(--admin-line));background:var(--admin-accent);color:#fff}
    .btn.primary:disabled{opacity:.6;cursor:not-allowed}
    @media (max-width: 900px){
      .form-layout{grid-template-columns:1fr}
      .form-side{border-left:0;border-top:1px solid var(--admin-line)}
      .grid{grid-template-columns:1fr}
      .field{display:grid}
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="content">
    <?php if (!empty($errors['form'])): ?>
      <p class="error-banner" role="alert"><?= Security::e($errors['form']) ?></p>
    <?php endif; ?>

    <section class="panel" aria-labelledby="create-site">
      <div class="panel-head">
        <h1 id="create-site" class="panel-title">Create New Website</h1>
        <p class="panel-subtitle">Set up a new website with its name, slug, and internal description.</p>
      </div>
      <div class="panel-body">
        <form id="createForm" method="post" novalidate class="form-layout">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">

          <div class="form-main">
            <div class="grid">
              <div class="field">
                <label for="name">Name</label>
                <div class="field-control">
                  <input id="name" name="name" type="text" required placeholder="e.g. Productivity Hub" value="<?= Security::e($values['name']) ?>" aria-describedby="name-helper<?= isset($errors['name']) ? ' name-error' : '' ?>">
                  <div id="name-helper" class="helper">Human-friendly title for your team.</div>
                  <?php if (isset($errors['name'])): ?>
                    <div id="name-error" class="inline-errors"><p><?= Security::e($errors['name']) ?></p></div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="field">
                <label for="slug">Slug</label>
                <div class="field-control">
                  <input id="slug" name="slug" type="text" required value="<?= Security::e($values['slug']) ?>" aria-describedby="slug-helper<?= isset($errors['slug']) ? ' slug-error' : '' ?>" inputmode="lowercase">
                  <div id="slug-helper" class="helper">Used in URLs; lowercase letters, numbers, and hyphens only.</div>
                  <?php if (isset($errors['slug'])): ?>
                    <div id="slug-error" class="inline-errors"><p><?= Security::e($errors['slug']) ?></p></div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="field">
                <label for="description">Description</label>
                <div class="field-control">
                  <textarea id="description" name="description" aria-describedby="desc-helper"><?= Security::e($values['description']) ?></textarea>
                  <div id="desc-helper" class="helper">Short internal description for admins.</div>
                </div>
              </div>
            </div>

            <div class="form-actions">
              <a class="btn" href="<?= $base ?>/admin/">Cancel</a>
              <button class="btn primary" type="submit" name="action" value="create_site" id="submitBtn" disabled>Create website</button>
            </div>
          </div>

          <aside class="form-side">
            <h2 class="section-title">What Gets Created</h2>
            <div class="info-list">
              <div class="info-card">
                <strong>Homepage</strong>
                <div class="helper">A default `home` page is created and set as the site homepage.</div>
              </div>
              <div class="info-card">
                <strong>Header and Footer</strong>
                <div class="helper">Default shell presets are seeded automatically so the site renders immediately.</div>
              </div>
              <div class="info-card">
                <strong>Editable Later</strong>
                <div class="helper">You can change the site name, slug, description, and shell setup after creation.</div>
              </div>
            </div>
          </aside>
        </form>
      </div>
    </section>
  </main>

  <script>
    (function() {
      const nameInput = document.getElementById('name');
      const slugInput = document.getElementById('slug');
      const form = document.getElementById('createForm');
      const submitBtn = document.getElementById('submitBtn');

      let slugTouched = slugInput && slugInput.value.trim().length > 0;

      function slugify(value) {
        return value
          .toLowerCase()
          .replace(/[^a-z0-9-]+/g, '-')
          .replace(/-+/g, '-')
          .replace(/^-+|-+$/g, '');
      }

      function validate() {
        const name = (nameInput?.value || '').trim();
        const slug = slugify(slugInput?.value || '');
        if (slugInput) slugInput.value = slug;

        const valid = name.length > 0 && slug.length > 0;
        if (submitBtn) submitBtn.disabled = !valid;
      }

      if (nameInput && slugInput) {
        nameInput.addEventListener('blur', () => {
          if (slugTouched) return;
          const slugged = slugify(nameInput.value || '');
          if (slugged && !slugInput.value) {
            slugInput.value = slugged;
          }
          validate();
        });
        slugInput.addEventListener('input', () => { slugTouched = true; validate(); });
        nameInput.addEventListener('input', validate);
      }

      if (form) {
        form.addEventListener('submit', (e) => {
          validate();
          if (submitBtn && submitBtn.disabled && e.submitter && e.submitter.value === 'create_site') {
            e.preventDefault();
            return;
          }
          if (submitBtn && e.submitter && e.submitter.value === 'create_site') {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating...';
          }
        });
      }

      validate();

    })();
  </script>
</body>
</html>
