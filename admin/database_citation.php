<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Site;

$base = base_path();
$activeNav = 'databases';
$themeIsLight = ui_theme_is_light();

$site = Site::findBySlug('cite-them-right');
if (!$site) {
  http_response_code(404);
  echo 'Citation DB source site not found.';
  exit;
}

$src = $base . '/admin/site.php?id=' . (int)$site['id'] . '&view=citations';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Citation DB — NexusCMS Admin</title>
  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    :root {
      --bg: #0f172a;
      --panel: #111827;
      --border: #1f2937;
      --muted: #9ca3af;
      --text: #e5e7eb;
    }
    .theme-light {
      --bg: #f8fafc;
      --panel: #ffffff;
      --border: #e2e8f0;
      --muted: #475569;
      --text: #0f172a;
    }
    *{box-sizing:border-box;}
    body{margin:0;background:var(--bg);color:var(--text);font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;line-height:1.5;}
    a{text-decoration:none;color:inherit;}
    main{padding:0 18px 24px;}
    .frame{
      width:100%;
      height:calc(100vh - var(--admin-top-h, 48px) - 24px);
      height:calc(100dvh - var(--admin-top-h, 48px) - 24px);
      min-height:0;
      border:0;
      border-radius:0;
      background:transparent;
      display:block;
      overflow:auto;
      position:relative;
      z-index:1;
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <iframe id="citationDbFrame" class="frame" src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" title="Citation DB"></iframe>
  </main>
  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function () {
      var frame = document.getElementById('citationDbFrame');
      if (!frame) return;
      var userMenuDetails = document.querySelector('.user-menu details');

      function frameDoc() {
        try {
          return frame.contentDocument || (frame.contentWindow && frame.contentWindow.document) || null;
        } catch (e) {
          return null;
        }
      }

      function closeUserMenu() {
        if (userMenuDetails && userMenuDetails.open) userMenuDetails.open = false;
      }

      function bindIframeMenuClose() {
        frame.addEventListener('pointerdown', closeUserMenu, true);
        frame.addEventListener('focus', closeUserMenu, true);
        var doc = frameDoc();
        if (!doc) return;
        try {
          doc.addEventListener('pointerdown', closeUserMenu, true);
          doc.addEventListener('focusin', closeUserMenu, true);
        } catch (e) {}
      }

      function syncFrameTheme() {
        var doc = frameDoc();
        if (!doc || !doc.documentElement) return;
        var isLight = document.documentElement.classList.contains('theme-light');
        doc.documentElement.classList.toggle('theme-light', isLight);
      }

      frame.addEventListener('load', function () {
        syncFrameTheme();
        bindIframeMenuClose();
      });

      new MutationObserver(syncFrameTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
      bindIframeMenuClose();
    })();
  </script>
</body>
</html>
