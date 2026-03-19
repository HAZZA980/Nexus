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
  <script>
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
      min-height:calc(100vh - 116px);
      border:0;
      border-radius:0;
      background:transparent;
      display:block;
      overflow:hidden;
      position:relative;
      z-index:1;
    }
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css">
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <iframe id="citationDbFrame" class="frame" src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" title="Citation DB" scrolling="no"></iframe>
  </main>
  <script>
    (function () {
      var frame = document.getElementById('citationDbFrame');
      if (!frame) return;
      var userMenuDetails = document.querySelector('.user-menu details');

      var observer = null;
      var raf = null;
      var drawerOpen = false;

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

      function resizeFrame() {
        var doc = frameDoc();
        if (!doc) return;
        var body = doc.body;
        var html = doc.documentElement;
        if (!body || !html) return;
        var height = Math.max(
          body.scrollHeight, body.offsetHeight, body.clientHeight,
          html.scrollHeight, html.offsetHeight, html.clientHeight
        );
        frame.style.height = height + 'px';
      }

      function syncFrameTheme() {
        var doc = frameDoc();
        if (!doc || !doc.documentElement) return;
        var isLight = document.documentElement.classList.contains('theme-light');
        doc.documentElement.classList.toggle('theme-light', isLight);
      }

      function syncFrameDrawerPosition() {
        var doc = frameDoc();
        if (!doc) return;
        var rect = frame.getBoundingClientRect();
        var frameTopInViewport = rect.top;
        var frameBottomInViewport = rect.bottom;
        var header = document.querySelector('.top-bar');
        var headerBottomInViewport = 0;
        if (header) {
          var headerRect = header.getBoundingClientRect();
          headerBottomInViewport = Math.max(0, Math.round(headerRect.bottom));
        }
        var visibleTop = Math.max(0, frameTopInViewport, headerBottomInViewport);
        var visibleBottom = Math.min(window.innerHeight, frameBottomInViewport);
        var visibleHeight = Math.max(0, visibleBottom - visibleTop);
        var topPx = Math.max(0, visibleTop - frameTopInViewport);
        var remainingFrameHeight = Math.max(0, frame.clientHeight - topPx);
        var heightPx = visibleHeight;
        if (remainingFrameHeight > 0) heightPx = Math.min(heightPx, remainingFrameHeight);
        var drawers = doc.querySelectorAll('.cite-viewer');
        if (!drawers.length) return;
        drawers.forEach(function (drawer) {
          drawer.style.zIndex = '2600';
          drawer.style.position = 'absolute';
          drawer.style.top = topPx + 'px';
          drawer.style.height = heightPx + 'px';
          drawer.style.maxHeight = heightPx + 'px';
          drawer.style.overflowY = 'auto';
          drawer.style.width = '';
          drawer.style.right = '0';
          drawer.style.maxWidth = '';
        });
      }

      function findScrollableAncestor(startNode, stopNode) {
        var node = startNode;
        while (node && node !== stopNode && node.nodeType === 1) {
          var style = window.getComputedStyle(node);
          var overflowY = style ? style.overflowY : '';
          var canScroll = (overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > node.clientHeight;
          if (canScroll) return node;
          node = node.parentElement;
        }
        return stopNode;
      }

      function installFrameScrollRouting() {
        var doc = frameDoc();
        if (!doc || doc.__nexusWheelRoutingBound) return;
        doc.__nexusWheelRoutingBound = true;

        doc.addEventListener('wheel', function (event) {
          var target = event.target && event.target.closest ? event.target : null;
          var activeDrawer = target && target.closest ? target.closest('.cite-viewer.active') : null;

          if (!activeDrawer) {
            event.preventDefault();
            window.scrollBy(0, event.deltaY);
            return;
          }

          var scroller = findScrollableAncestor(target, activeDrawer);
          var atTop = scroller.scrollTop <= 0;
          var atBottom = Math.ceil(scroller.scrollTop + scroller.clientHeight) >= scroller.scrollHeight;
          var scrollingUp = event.deltaY < 0;
          var scrollingDown = event.deltaY > 0;
          var drawerCanConsume = (scrollingUp && !atTop) || (scrollingDown && !atBottom);

          if (!drawerCanConsume) {
            event.preventDefault();
            window.scrollBy(0, event.deltaY);
          }
        }, { passive: false });
      }

      function syncParentScrollLock() {
        var doc = frameDoc();
        if (!doc) return;
        var isOpen = !!doc.querySelector('.cite-viewer.active');
        if (isOpen === drawerOpen) return;
        drawerOpen = isOpen;
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
      }

      function scheduleResize() {
        if (raf) cancelAnimationFrame(raf);
        raf = requestAnimationFrame(resizeFrame);
      }

      frame.addEventListener('load', function () {
        scheduleResize();
        syncFrameTheme();
        syncFrameDrawerPosition();
        syncParentScrollLock();
        installFrameScrollRouting();
        bindIframeMenuClose();
        var doc = frameDoc();
        if (!doc) return;
        if (observer) observer.disconnect();
        observer = new MutationObserver(function () {
          scheduleResize();
          syncFrameDrawerPosition();
          syncParentScrollLock();
        });
        observer.observe(doc.documentElement, { childList: true, subtree: true, attributes: true, characterData: true });
      });

      window.addEventListener('resize', scheduleResize);
      window.addEventListener('resize', syncFrameDrawerPosition);
      window.addEventListener('scroll', syncFrameDrawerPosition, { passive: true });
      new MutationObserver(syncFrameTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
      setInterval(syncParentScrollLock, 200);
      setInterval(syncFrameDrawerPosition, 600);
      setInterval(scheduleResize, 600);
      bindIframeMenuClose();
    })();
  </script>
</body>
</html>
