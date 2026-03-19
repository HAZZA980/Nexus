<?php
use NexusCMS\Core\Security;
use NexusCMS\Models\ShellPreset;
use NexusCMS\Support\PartialsManager;

$base = base_path();

// Logged-in check (simple RBAC for now)
$isAdmin = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;

// Preview flag passed in by router
$isPreview = !empty($is_preview);

// -----------------------------
// Theme (site-wide)
// -----------------------------
$theme  = json_decode($site['theme_json'] ?? '', true) ?: [];
$colors = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];
$typo   = is_array($theme['typography'] ?? null) ? $theme['typography'] : [];
$layout = is_array($theme['layout'] ?? null) ? $theme['layout'] : [];
$shape  = is_array($theme['shape'] ?? null) ? $theme['shape'] : [];
$media  = is_array($theme['media'] ?? null) ? $theme['media'] : [];
$chrome = is_array($theme['chrome'] ?? null) ? $theme['chrome'] : [];
$motion = is_array($theme['motion'] ?? null) ? $theme['motion'] : [];

$pageBg  = $colors['pageBg']  ?? '#f7f7f3';
$surface = $colors['surface'] ?? '#ffffff';
$primary = $colors['primary'] ?? '#2563eb';
$secondary = $colors['secondary'] ?? '#14b8a6';
$muted   = $colors['muted']   ?? '#6b7280';
$text    = $colors['text']    ?? '#111827';
$border  = $colors['border']  ?? 'rgba(17,24,39,.12)';
$divider = $colors['divider'] ?? $border;
$focus   = $colors['focus'] ?? $primary;
$hover   = $colors['hover'] ?? 'rgba(0,0,0,.06)';
$radius  = (int)($shape['radius'] ?? ($theme['radius'] ?? 16));
$shadow  = $shape['shadow'] ?? '0 10px 30px rgba(0,0,0,.18)';

$fontFamily = $typo['fontFamily'] ?? 'system-ui,-apple-system,Segoe UI,Roboto,Arial';
$baseSize   = (int)($typo['baseSize'] ?? 16);
$headingScale = (float)($typo['headingScale'] ?? 1.35);
$fontWeight = (int)($typo['fontWeight'] ?? 500);
$lineHeight = (float)($typo['lineHeight'] ?? 1.55);
$letterSpacing = (string)($typo['letterSpacing'] ?? '0px');
$rendering = $typo['rendering'] ?? 'auto';

$paddingPreset = $layout['padding'] ?? 'medium';
$maxWidthPreset = $layout['maxWidth'] ?? 'standard';
$paddingMap = ['small'=>'12px 14px','medium'=>'18px 22px','large'=>'26px 28px'];
// Adjusted widths: narrow now matches old standard, standard sits between new narrow and wide
$widthMap = ['narrow'=>'1024px','standard'=>'1180px','wide'=>'1280px'];
$pagePadding = $paddingMap[$paddingPreset] ?? $paddingMap['medium'];
$maxWidth = $widthMap[$maxWidthPreset] ?? $widthMap['standard'];
$sectionSpacing = (int)($layout['sectionSpacing'] ?? 20);
$gridGap = (int)($layout['gridGap'] ?? 16);
$alignment = $layout['alignment'] ?? 'left';
$breakpoint = (int)($layout['breakpoint'] ?? 1200);

$buttonStyle = $shape['buttonStyle'] ?? 'pill';
$inputStyle = $shape['inputStyle'] ?? 'rounded';

// Respect global radius; only keep pill rounding when explicitly chosen
if ($buttonStyle === 'pill') {
  $buttonRadius = $radius === 0 ? 0 : 9999;
} elseif ($buttonStyle === 'rounded') {
  $buttonRadius = $radius === 0 ? 0 : max(10, $radius);
} else { // square or fallback
  $buttonRadius = $radius;
}

$inputRadius = $inputStyle === 'square' ? $radius : ($radius === 0 ? 0 : max(10, $radius));

$imageRatio = $media['imageRatio'] ?? '16:9';
$imageRadius = (int)($media['imageRadius'] ?? 12);
if ($radius === 0) $imageRadius = 0;
$videoStyle = $media['videoStyle'] ?? 'shadow';
$mediaMaxWidth = $media['mediaMaxWidth'] ?? '1200px';

$headerDensity = $chrome['headerDensity'] ?? 'roomy';
$footerSpacing = $chrome['footerSpacing'] ?? 'normal';
$navStyle = $chrome['navStyle'] ?? 'horizontal';
$logoSize = $chrome['logoSize'] ?? 'medium';
$iconStroke = $chrome['iconStroke'] ?? 'regular';

$motionDuration = (int)($motion['duration'] ?? 220);
$motionEasing = $motion['easing'] ?? 'ease-in-out';
$motionReduced = $motion['reduced'] ?? 'auto';

// -----------------------------
// Shell selection (site defaults + per-page override)
// -----------------------------
$pageShell = json_decode($page['shell_override_json'] ?? '', true) ?: [];
$headerKey = preg_replace('/[^a-z0-9_\-]/i', '', (string)($pageShell['header_key'] ?? ($site['header_default_key'] ?? 'nav-left')));
$footerKey = preg_replace('/[^a-z0-9_\-]/i', '', (string)($pageShell['footer_key'] ?? ($site['footer_default_key'] ?? 'footer-minimal')));
if ($headerKey === '') $headerKey = 'nav-left';
if ($footerKey === '') $footerKey = 'footer-minimal';

$headerPreset = ShellPreset::findByKey((int)$site['id'], 'header', $headerKey);
$footerPreset = ShellPreset::findByKey((int)$site['id'], 'footer', $footerKey);

$headerTemplate = __DIR__ . '/headers/' . $headerKey . '.php';
$footerTemplate = __DIR__ . '/footers/' . $footerKey . '.php';

// Optional CSS/JS per header + site assets
$headerCssPath = __DIR__ . '/../assets/headers/' . $headerKey . '.css';
$headerCssUrl  = $base . '/public/assets/headers/' . $headerKey . '.css';

$safeSlug = PartialsManager::safeSlug($site['slug'] ?? '');
$sitePaths = PartialsManager::paths($safeSlug);
$partialHeader = $sitePaths['header'];
$partialFooter = $sitePaths['footer'];
$siteCssUrl = $base . '/sites/' . $safeSlug . '/assets/site.css';
$siteJsUrl  = $base . '/sites/' . $safeSlug . '/assets/site.js';

// Config merge (preset + site config)
$headerConfig = $headerPreset ? json_decode($headerPreset['config_json'] ?? '', true) ?: [] : [];
$siteHeaderConfig = json_decode($site['header_json'] ?? '', true) ?: [];
$headerConfig = array_replace_recursive($headerConfig, $siteHeaderConfig);
$brand = $headerConfig['brandText'] ?? ($site['name'] ?? 'Site');
$headerItems = $headerConfig['items'] ?? [];
$headerCta = $headerConfig['cta'] ?? null;

$footerConfig = $footerPreset ? json_decode($footerPreset['config_json'] ?? '', true) ?: [] : [];
$siteFooterConfig = json_decode($site['footer_json'] ?? '', true) ?: [];
$footerConfig = array_replace_recursive($footerConfig, $siteFooterConfig);

function safe_include(string $path, string $root): bool {
  $pathNorm = str_replace('\\', '/', $path);
  $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
  if (strpos($pathNorm, '..') !== false) return false;
  $real = realpath($pathNorm);
  if ($real === false) return false;
  $real = str_replace('\\', '/', $real);
  if (strncmp($real, $rootNorm, strlen($rootNorm)) !== 0) return false;
  include $real;
  return true;
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= Security::e($site['name']) ?> — <?= Security::e($page['title']) ?></title>

  <link rel="stylesheet" href="<?= $base ?>/public/assets/site.css">
  <link rel="stylesheet" href="<?= $base ?>/public/assets/nexus-page.css">

  <?php if (is_file($headerCssPath)): ?>
    <link rel="stylesheet" href="<?= $headerCssUrl ?>">
  <?php endif; ?>
  <?php if (is_file($sitePaths['css'] ?? '')): ?>
    <link rel="stylesheet" href="<?= Security::e($siteCssUrl) ?>">
  <?php endif; ?>

  <!-- Only needed if your header uses Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
    crossorigin="anonymous" referrerpolicy="no-referrer">

  <style>
    :root{
      --nexus-page-bg: <?= Security::e($pageBg) ?>;
      --nexus-text: <?= Security::e($text) ?>;
      --nexus-primary: <?= Security::e($primary) ?>;
      --nexus-secondary: <?= Security::e($secondary) ?>;
      --nexus-muted: <?= Security::e($muted) ?>;
      --nexus-surface: <?= Security::e($surface) ?>;
      --nexus-border: <?= Security::e($border) ?>;
      --nexus-divider: <?= Security::e($divider) ?>;
      --nexus-focus: <?= Security::e($focus) ?>;
      --nexus-hover: <?= Security::e($hover) ?>;
      --nexus-radius: <?= (int)$radius ?>px;
      --nexus-shadow: <?= Security::e($shadow) ?>;
      --nexus-font: <?= Security::e($fontFamily) ?>;
      --nexus-font-size: <?= (int)$baseSize ?>px;
      --nexus-font-weight: <?= (int)$fontWeight ?>;
      --nexus-line-height: <?= Security::e($lineHeight) ?>;
      --nexus-letter-spacing: <?= Security::e($letterSpacing) ?>;
      --nexus-heading-scale: <?= Security::e($headingScale) ?>;
      --nexus-padding: <?= Security::e($pagePadding) ?>;
      --nexus-max-width: <?= Security::e($maxWidth) ?>;
      --nexus-section-spacing: <?= (int)$sectionSpacing ?>px;
      --nexus-grid-gap: <?= (int)$gridGap ?>px;
      --nexus-align: <?= $alignment === 'center' ? 'center' : 'flex-start' ?>;
      --nexus-breakpoint: <?= (int)$breakpoint ?>px;
      --nexus-button-radius: <?= (int)$buttonRadius ?>px;
      --nexus-input-radius: <?= (int)$inputRadius ?>px;
      --nexus-image-radius: <?= (int)$imageRadius ?>px;
      --nexus-media-max: <?= Security::e($mediaMaxWidth) ?>;
      --nexus-header-density: <?= Security::e($headerDensity) ?>;
      --nexus-footer-spacing: <?= Security::e($footerSpacing) ?>;
      --nexus-nav-style: <?= Security::e($navStyle) ?>;
      --nexus-logo-size: <?= Security::e($logoSize) ?>;
      --nexus-icon-stroke: <?= Security::e($iconStroke) ?>;
      --nexus-motion-duration: <?= (int)$motionDuration ?>ms;
      --nexus-motion-easing: <?= Security::e($motionEasing) ?>;
      --nexus-motion-reduced: <?= Security::e($motionReduced) ?>;
    }

    /* Full-bleed public page */
    html,body{height:100%}
    body{
      margin:0;
      background:var(--nexus-page-bg);
      color:var(--nexus-text);
      font-family:var(--nexus-font);
      font-size:var(--nexus-font-size);
      line-height:var(--nexus-line-height,1.55);
      letter-spacing:var(--nexus-letter-spacing,0px);
      font-weight:var(--nexus-font-weight,500);
      text-rendering: <?= $rendering === 'optimizeLegibility' ? 'optimizeLegibility' : 'auto' ?>;
      -webkit-font-smoothing: <?= $rendering === 'antialiased' ? 'antialiased' : 'auto' ?>;
    }

    /* Main content should NOT be boxed */
    .nx-site-main{padding:0;margin:0}
    .nexus-page{
      width:100%;
      min-height:100vh;
      padding:var(--nexus-padding);
      background:var(--nexus-page-bg);
      color:var(--nexus-text);
    }
    .nexus-inner{width:100%;max-width:var(--nexus-max-width);margin:0 auto;}
    h1,h2,h3,h4,h5,h6{
      letter-spacing:var(--nexus-letter-spacing,0px);
      font-weight:calc(var(--nexus-font-weight,600));
      line-height:calc(var(--nexus-line-height,1.55) * 0.9);
    }
    h1{font-size:28px;}
    h2{font-size:24px;}
    h3{font-size:20px;}
    h4{font-size:18px;}
    h5{font-size:16px;}
    h6{font-size:14px;}

    /* Preview admin bar only */
    .nx-adminbar{
      position:sticky;
      top:0;
      z-index:999;
      background:rgba(15,23,42,.92);
      color:#e6eaf2;
      border-bottom:1px solid rgba(255,255,255,.12);
      backdrop-filter:saturate(140%) blur(8px);
    }
    .nx-adminbar-inner{
      display:flex;
      justify-content:space-between;
      gap:14px;
      padding:12px 16px;
      align-items:center;
    }
    .nx-adminbar a{
      color:#e6eaf2;
      text-decoration:underline;
      text-underline-offset:3px;
    }
    .nx-row{
      gap:var(--nexus-grid-gap,14px);
      row-gap:var(--nexus-grid-gap,14px);
      column-gap:var(--nexus-grid-gap,14px);
      margin:var(--nexus-section-spacing,14px) 0;
      justify-content:var(--nexus-align,flex-start);
    }
    .nx-card{
      background:var(--nexus-surface, #ffffff);
      border:1px solid var(--nexus-border, rgba(17,24,39,.12));
      box-shadow:var(--nexus-shadow, none);
    }
    .nx-btn{
      border-radius:var(--nexus-button-radius, var(--nexus-radius));
      transition:all var(--nexus-motion-duration,200ms) var(--nexus-motion-easing,ease-in-out);
    }
    .nx-card, .nx-img, .nx-video iframe{
      transition:all var(--nexus-motion-duration,200ms) var(--nexus-motion-easing,ease-in-out);
    }
    input, textarea, select{
      border-radius:var(--nexus-input-radius, var(--nexus-radius));
    }
    .nx-img{
      border-radius:var(--nexus-image-radius, var(--nexus-radius));
      max-width:var(--nexus-media-max, 1200px);
    }
    @media (prefers-reduced-motion: reduce){
      .nx-btn, .nx-card, .nx-img, .nx-video iframe, *{
        transition-duration:0ms !important;
        animation-duration:0ms !important;
      }
    }
    .motion-reduce *, .motion-reduce *::before, .motion-reduce *::after{
      transition-duration:0ms !important;
      animation-duration:0ms !important;
    }
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
  </style>
</head>

<?php $pageSlugClass = PartialsManager::safeSlug((string)($page['slug'] ?? '')); ?>
<body class="<?= $motionReduced === 'reduce' ? 'motion-reduce ' : '' ?>page-<?= Security::e($pageSlugClass) ?>">

<?php if ($isPreview): ?>
  <div class="nx-adminbar">
    <div class="nx-adminbar-inner">
      <div style="font-weight:900"><?= Security::e($site['name']) ?> (Preview)</div>
      <div style="display:flex;gap:14px;flex-wrap:wrap;justify-content:flex-end">
        <a href="<?= $base ?>/">All Websites</a>
        <a href="<?= $base ?>/admin/">Admin</a>
        <a href="<?= $base ?>/admin/page_builder.php?id=<?= (int)$page['id'] ?>">Back to editor</a>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php
$root = PartialsManager::projectRoot();
$usedPartialHeader = false;
if (is_file($partialHeader) && safe_include($partialHeader, $root)) {
  $usedPartialHeader = true;
}
// If no filesystem header, fall back to preset-driven header template
if (!$usedPartialHeader) {
  if (is_string($headerTemplate) && $headerTemplate !== '' && is_file($headerTemplate)) {
    // $base, $site, $page, $headerConfig, $brand, $headerItems, $headerCta, $isPreview, $isAdmin
    require $headerTemplate;
  } else {
    ?>
    <header style="padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(0,0,0,.08);background:#fff;">
      <div style="font-weight:700"><?= Security::e($brand) ?></div>
      <nav style="display:flex;gap:14px;align-items:center">
        <?php foreach ($headerItems as $item): ?>
          <a href="<?= Security::e($item['href'] ?? '#') ?>" style="text-decoration:none;color:#111827"><?= Security::e($item['label'] ?? '') ?></a>
        <?php endforeach; ?>
        <?php if (!empty($headerCta['label'])): ?>
          <a href="<?= Security::e($headerCta['href'] ?? '#') ?>" style="padding:10px 12px;border-radius:var(--nexus-radius, 0px);background:#2563eb;color:#fff;text-decoration:none;"><?= Security::e($headerCta['label']) ?></a>
        <?php endif; ?>
      </nav>
    </header>
    <?php
  }
}
?>

<main class="nx-site-main">
  <div class="nexus-page">
    <div class="nexus-inner">
      <?= $content ?>
    </div>
  </div>
</main>

<?php
$usedPartialFooter = false;
if (is_file($partialFooter) && safe_include($partialFooter, $root)) {
  $usedPartialFooter = true;
}
if (!$usedPartialFooter) {
  if (is_string($footerTemplate) && $footerTemplate !== '' && is_file($footerTemplate)) {
    // vars: $base, $site, $page, $footerConfig, $isPreview, $isAdmin
    require $footerTemplate;
  } else {
    ?>
    <footer style="padding:18px;border-top:1px solid rgba(0,0,0,.08);background:#0f172a;color:#e7ecf4">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
          <div style="font-weight:700"><?= Security::e($footerConfig['brandText'] ?? ($site['name'] ?? '')) ?></div>
          <div style="color:rgba(231,236,244,.7);margin-top:4px"><?= Security::e($footerConfig['legal'] ?? '') ?></div>
        </div>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
          <?php foreach (($footerConfig['links'] ?? []) as $link): ?>
            <a href="<?= Security::e($link['href'] ?? '#') ?>" style="color:#cbd5f5;text-decoration:none"><?= Security::e($link['label'] ?? '') ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </footer>
    <?php
  }
}
?>

<?php if (is_file($sitePaths['js'] ?? '')): ?>
  <script src="<?= Security::e($siteJsUrl) ?>" defer></script>
<?php endif; ?>

<?php if (!empty($site['analytics_enabled']) && !$isPreview): ?>
<script>
(function(){
  // Skip analytics for admins to avoid contaminating data
  const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
  if (isAdmin) return;

  if (navigator.doNotTrack === '1' || window.doNotTrack === '1') return;
  const siteId = <?= (int)$site['id'] ?>;
  const privacy = <?= !empty($site['analytics_privacy_mode']) ? 'true' : 'false' ?>;
  const basePath = <?= json_encode(rtrim($base, '/')) ?>;
  const endpoint = (basePath || '') + '/api/analytics/collect';
  const vidKey = 'nx_vid_' + siteId;
  const sidKey = 'nx_sid_' + siteId;
  const now = Date.now();

  const randHex = (len=32) => {
    const arr = new Uint8Array(len/2);
    crypto.getRandomValues(arr);
    return Array.from(arr, b => ('0'+b.toString(16)).slice(-2)).join('');
  };
  const getCookie = (name) => {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([$?*|{}\(\)\[\]\\\/\+^])/g,'\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  };
  const setCookie = (name, value, days) => {
    const expires = new Date(Date.now() + days*864e5).toUTCString();
    const path = basePath || '/';
    document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=${path}; SameSite=Lax`;
  };

  let visitor = getCookie(vidKey);
  if (!visitor) {
    visitor = randHex(32);
    setCookie(vidKey, visitor, 730);
  }

  let sessionKey = '';
  let sessionStarted = 0;
  const rawSession = getCookie(sidKey);
  if (rawSession) {
    const parts = rawSession.split('.');
    sessionKey = parts[0] || '';
    sessionStarted = parseInt(parts[1] || '0', 10);
  }
  const sessionAge = now - (sessionStarted || 0);
  if (!sessionKey || !sessionStarted || sessionAge > 30*60*1000) {
    sessionKey = randHex(24);
    sessionStarted = now;
    setCookie(sidKey, `${sessionKey}.${sessionStarted}`, 1);
  } else {
    // Refresh expiry
    setCookie(sidKey, `${sessionKey}.${sessionStarted}`, 1);
  }

  const url = new URL(window.location.href);
  const params = url.searchParams;
  const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
  const payload = {
    site_id: siteId,
    visitor_key: visitor,
    session_key: sessionKey,
    path: url.pathname + url.search,
    title: document.title || '',
    referrer: document.referrer || '',
    utm_source: params.get('utm_source') || '',
    utm_medium: params.get('utm_medium') || '',
    utm_campaign: params.get('utm_campaign') || '',
    load_ms: nav ? Math.round(nav.loadEventEnd || 0) : null,
    ttfb_ms: nav ? Math.round(nav.responseStart || 0) : null,
    dnt: false,
  };

  if (privacy) payload.privacy = true;

  const send = () => {
    const body = JSON.stringify(payload);
    if (navigator.sendBeacon) {
      const blob = new Blob([body], {type:'application/json'});
      navigator.sendBeacon(endpoint, blob);
    } else {
      fetch(endpoint, {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body,
        keepalive:true
      }).catch(()=>{});
    }
  };

  if (document.readyState === 'complete') send();
  else window.addEventListener('load', () => send(), {once:true});
})();
</script>
<?php endif; ?>

</body>
</html>
