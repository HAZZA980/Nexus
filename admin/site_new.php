<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Models\ShellPreset;
use NexusCMS\Core\Security;

$base = base_path();
$activeNav = 'sites';
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
    :root {
      --bg: #0f172a;
      --panel: #111827;
      --card: #111827;
      --border: #1f2937;
      --muted: #9ca3af;
      --text: #e5e7eb;
      --primary: #5b21b6;
      --primary-strong: #4c1d95;
      --radius: 16px;
      --shadow: 0 10px 40px rgba(0,0,0,0.28);
      --focus: 0 0 0 3px rgba(91,33,182,0.35);
      --error: #f87171;
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
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
      transition: background .2s ease, color .2s ease;
    }
    a { color: inherit; text-decoration: none; }
    a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, summary:focus-visible {
      outline: none;
      box-shadow: var(--focus);
      border-color: var(--primary);
    }

    main { max-width: 1200px; margin: 0 auto; padding: 24px 20px 48px; }
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      text-decoration: none;
      min-height: 44px;
      padding: 8px 0;
      font-weight: 600;
    }
    .back-link:hover { color: var(--text); }

    .page-head { margin: 10px 0 18px; }
    .page-head h1 { margin: 0; font-size: 32px; letter-spacing: -0.02em; }
    .page-head p { margin: 6px 0 0; color: var(--muted); }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 22px 24px;
      max-width: 680px;
    }
    form { display: grid; gap: 18px; }
    label { display: block; font-weight: 600; margin-bottom: 6px; }
    .required { color: #fbbf24; font-weight: 700; margin-left: 4px; }
    .input-wrap { display: grid; gap: 6px; }
    input, textarea {
      width: 100%;
      padding: 12px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      min-height: 44px;
      font-size: 15px;
    }
    textarea { resize: vertical; min-height: 110px; }
    .helper { color: var(--muted); font-size: 14px; }
    .error {
      color: var(--error);
      font-size: 14px;
      margin: 2px 0 0;
    }
    .actions {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      margin-top: 4px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 12px 14px;
      min-height: 44px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.06);
      color: var(--text);
      cursor: pointer;
      font-weight: 600;
      transition: transform 0.15s ease, background 0.15s ease, border 0.15s ease;
      text-decoration: none;
    }
    .btn:hover { background: rgba(255,255,255,0.1); transform: translateY(-1px); }
    .btn.primary {
      background: linear-gradient(120deg, var(--primary), var(--primary-strong));
      border-color: rgba(91,33,182,0.25);
      color: #f8fbff;
      box-shadow: 0 8px 24px rgba(91,33,182,0.25);
    }
    .btn.primary[disabled] {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }
    .btn.text {
      background: transparent;
      border-color: transparent;
      color: var(--muted);
    }
    .btn.text:hover { color: var(--text); background: rgba(255,255,255,0.04); }
    .form-error {
      padding: 12px;
      border: 1px solid rgba(248,113,113,0.5);
      background: rgba(248,113,113,0.12);
      color: var(--error);
      border-radius: 12px;
      font-weight: 600;
    }
    @media (max-width: 720px) {
      .card { padding: 18px; }
      .page-head h1 { font-size: 26px; }
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
</head>
  <body>
    <?php include __DIR__ . '/partials/header.php'; ?>

  <main>
    <div class="page-head">
      <h1>Create new website</h1>
      <p>Set up a new website. You can change all of these details later.</p>
    </div>

    <section class="card" aria-labelledby="create-site">
      <?php if (!empty($errors['form'])): ?>
        <div class="form-error" role="alert"><?= Security::e($errors['form']) ?></div>
      <?php endif; ?>
      <form id="createForm" method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">

        <div class="input-wrap">
          <label for="name">Name<span class="required" aria-hidden="true">*</span></label>
          <input id="name" name="name" type="text" required placeholder="e.g. Productivity Hub" value="<?= Security::e($values['name']) ?>" aria-describedby="name-helper<?= isset($errors['name']) ? ' name-error' : '' ?>">
          <div id="name-helper" class="helper">Human-friendly title for your team.</div>
          <?php if (isset($errors['name'])): ?>
            <div id="name-error" class="error"><?= Security::e($errors['name']) ?></div>
          <?php endif; ?>
        </div>

        <div class="input-wrap">
          <label for="slug">Slug<span class="required" aria-hidden="true">*</span></label>
          <input id="slug" name="slug" type="text" required value="<?= Security::e($values['slug']) ?>" aria-describedby="slug-helper<?= isset($errors['slug']) ? ' slug-error' : '' ?>" inputmode="lowercase">
          <div id="slug-helper" class="helper">Used in URLs; you can change this later.</div>
          <?php if (isset($errors['slug'])): ?>
            <div id="slug-error" class="error"><?= Security::e($errors['slug']) ?></div>
          <?php endif; ?>
        </div>

        <div class="input-wrap">
          <label for="description">Description</label>
          <textarea id="description" name="description" aria-describedby="desc-helper"><?= Security::e($values['description']) ?></textarea>
          <div id="desc-helper" class="helper">Short internal description for admins.</div>
        </div>

        <div class="actions">
          <button class="btn primary" type="submit" name="action" value="create_site" id="submitBtn" disabled>Create website</button>
          <a class="btn text" href="<?= $base ?>/admin/">Cancel</a>
        </div>
      </form>
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
