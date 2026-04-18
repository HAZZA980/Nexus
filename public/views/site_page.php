<?php
use NexusCMS\Core\Security;
use NexusCMS\Models\PageFlag;
use NexusCMS\Models\ShellPreset;
use NexusCMS\Support\PartialsManager;

$base = base_path();

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

$fontFamily = $typo['fontFamily'] ?? (($site['slug'] ?? '') === 'cite-them-right' ? 'Georgia,"Times New Roman",serif' : 'system-ui,-apple-system,Segoe UI,Roboto,Arial');
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
$gridGapRaw = (int)($layout['gridGap'] ?? 16);
$gridGapMap = [
  10 => 18, // Tight
  16 => 30, // Regular
  24 => 42, // Roomy
];
$gridGap = $gridGapMap[$gridGapRaw] ?? max(18, $gridGapRaw);
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
$adminRoles = ['super_admin', 'website_admin', 'editor', 'institution_admin', 'admin', 'staff_admin', 'user_admin'];
$reportRoles = ['super_admin', 'website_admin', 'editor', 'institution_admin', 'student', 'admin', 'staff_admin', 'user_admin', 'viewer'];
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = (string)($_SESSION['user_role'] ?? '');
$sessionSiteAccess = array_map('strval', (array)($_SESSION['site_access'] ?? []));
$canFlagUser = $sessionUserId > 0
  && in_array($sessionRole, $reportRoles, true)
  && (in_array('*', $sessionSiteAccess, true) || in_array($safeSlug, $sessionSiteAccess, true));
$isAdmin = $sessionUserId > 0
  && in_array($sessionRole, $adminRoles, true)
  && (in_array('*', $sessionSiteAccess, true) || in_array($safeSlug, $sessionSiteAccess, true));
$adminUserLabel = trim((string)($_SESSION['user_name'] ?? $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Administrator'));
$adminEditUrl = $base . '/admin/page_builder.php?id=' . (int)($page['id'] ?? 0);
$flagSubmitUrl = $base . '/report/page-flag';
$flagCsrfToken = Security::csrfToken();
$flagFlash = $_SESSION['page_flag_flash'] ?? null;
unset($_SESSION['page_flag_flash']);
$currentRequestUri = (string)($_SERVER['REQUEST_URI'] ?? ($base . '/s/' . $safeSlug . '/' . trim((string)($page['slug'] ?? 'home'), '/')));
$currentPagePath = (string)(parse_url($currentRequestUri, PHP_URL_PATH) ?: $currentRequestUri);
$sitePaths = PartialsManager::paths($safeSlug);
$partialHeader = $sitePaths['header'];
$partialFooter = $sitePaths['footer'];
$siteCssVersion = is_file($sitePaths['css'] ?? '') ? (string)@filemtime($sitePaths['css']) : '';
$siteJsVersion = is_file($sitePaths['js'] ?? '') ? (string)@filemtime($sitePaths['js']) : '';
$siteCssUrl = $base . '/sites/' . $safeSlug . '/assets/site.css' . ($siteCssVersion !== '' ? '?v=' . rawurlencode($siteCssVersion) : '');
$siteJsUrl  = $base . '/sites/' . $safeSlug . '/assets/site.js' . ($siteJsVersion !== '' ? '?v=' . rawurlencode($siteJsVersion) : '');

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
    .nx-adminbar-meta{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .nx-adminbar-badge{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:5px 10px;
      border:1px solid rgba(255,255,255,.16);
      border-radius:999px;
      background:rgba(255,255,255,.06);
      font-size:13px;
      line-height:1;
    }
    .nx-adminbar-actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      justify-content:flex-end;
      align-items:center;
    }
    .nx-adminbar a,
    .nx-adminbar button{
      color:#e6eaf2;
      text-decoration:none;
    }
    .nx-adminbar-action{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(255,255,255,.08);
      font-size:14px;
      line-height:1;
      font-weight:700;
      cursor:pointer;
      font-family:inherit;
    }
    .nx-adminbar-action:hover{
      background:rgba(255,255,255,.14);
    }
    .nx-adminbar-icon{
      width:14px;
      height:14px;
      display:inline-block;
      flex:0 0 auto;
    }
    .nx-adminbar-note{
      padding:0 16px 12px;
      font-size:13px;
      color:#d7f7e6;
    }
    .nx-adminbar-note.error{color:#ffd6d6;}
    .nx-report-modal{
      position:fixed;
      inset:0;
      background:rgba(2,6,23,.55);
      display:none;
      align-items:center;
      justify-content:center;
      padding:18px;
      z-index:1200;
    }
    .nx-report-modal.open{display:flex;}
    .nx-report-panel{
      width:min(560px, 100%);
      background:#fff;
      color:#111827;
      border-radius:16px;
      box-shadow:0 24px 60px rgba(0,0,0,.24);
      overflow:hidden;
    }
    .nx-report-head{
      padding:16px 18px;
      border-bottom:1px solid rgba(17,24,39,.12);
      display:flex;
      justify-content:space-between;
      gap:12px;
      align-items:center;
    }
    .nx-report-head h3{margin:0;font-size:18px;}
    .nx-report-body{padding:16px 18px;display:grid;gap:12px;}
    .nx-report-body p{margin:0;color:#4b5563;}
    .nx-report-body textarea{
      width:100%;
      min-height:150px;
      border:1px solid rgba(17,24,39,.16);
      border-radius:12px;
      padding:12px 14px;
      font:inherit;
      resize:vertical;
    }
    .nx-report-actions{
      display:flex;
      justify-content:flex-end;
      gap:10px;
      padding:0 18px 18px;
    }
    .nx-report-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:40px;
      padding:0 14px;
      border-radius:999px;
      border:1px solid rgba(17,24,39,.16);
      background:#fff;
      color:#111827;
      font:inherit;
      font-weight:700;
      cursor:pointer;
    }
    .nx-report-btn.primary{
      background:#1d4ed8;
      border-color:#1d4ed8;
      color:#fff;
    }
    .nx-row{
      display:grid;
      grid-template-columns:repeat(12,minmax(0,1fr));
      align-items:start;
      gap:var(--nexus-grid-gap,14px);
      row-gap:var(--nexus-grid-gap,14px);
      column-gap:var(--nexus-grid-gap,14px);
      margin:var(--nexus-section-spacing,14px) 0;
      justify-content:var(--nexus-align,flex-start);
    }
    .nx-col{
      min-width:0;
      grid-column:span 12;
    }
    .nx-col-1{grid-column:span 1;}
    .nx-col-2{grid-column:span 2;}
    .nx-col-3{grid-column:span 3;}
    .nx-col-4{grid-column:span 4;}
    .nx-col-5{grid-column:span 5;}
    .nx-col-6{grid-column:span 6;}
    .nx-col-7{grid-column:span 7;}
    .nx-col-8{grid-column:span 8;}
    .nx-col-9{grid-column:span 9;}
    .nx-col-10{grid-column:span 10;}
    .nx-col-11{grid-column:span 11;}
    .nx-col-12{grid-column:span 12;}
    @media (max-width: 768px){
      .nx-row{
        grid-template-columns:1fr;
      }
      .nx-col,
      .nx-col-1,
      .nx-col-2,
      .nx-col-3,
      .nx-col-4,
      .nx-col-5,
      .nx-col-6,
      .nx-col-7,
      .nx-col-8,
      .nx-col-9,
      .nx-col-10,
      .nx-col-11,
      .nx-col-12{
        grid-column:span 1;
      }
    }
    .nx-card{
      background:var(--nexus-surface, #ffffff);
      border:1px solid var(--nexus-border, rgba(17,24,39,.12));
      box-shadow:none;
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

<?php
$pageSlugClass = PartialsManager::safeSlug((string)($page['slug'] ?? ''));
$templateKeyClass = PartialsManager::safeSlug((string)($page['template_key'] ?? ''));
?>
<body class="<?= $motionReduced === 'reduce' ? 'motion-reduce ' : '' ?><?= $isPreview ? 'is-preview ' : '' ?>page-<?= Security::e($pageSlugClass) ?><?= $templateKeyClass !== '' ? ' template-' . Security::e($templateKeyClass) : '' ?>">

<?php if ($canFlagUser || $isPreview): ?>
  <div class="nx-adminbar">
    <div class="nx-adminbar-inner">
      <div class="nx-adminbar-meta">
        <div style="font-weight:900"><?= Security::e($site['name']) ?></div>
        <?php if ($isPreview): ?>
          <span class="nx-adminbar-badge">Preview</span>
        <?php endif; ?>
        <?php if ($canFlagUser): ?>
          <span class="nx-adminbar-badge"><?= Security::e($adminUserLabel) ?></span>
        <?php endif; ?>
      </div>
      <div class="nx-adminbar-actions">
        <?php if ($isAdmin): ?>
          <a class="nx-adminbar-action" href="<?= $adminEditUrl ?>">
            <svg class="nx-adminbar-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg>
            <span>Edit</span>
          </a>
        <?php endif; ?>
        <?php if ($canFlagUser): ?>
          <button type="button" class="nx-adminbar-action" id="nxOpenFlagModal">
            <svg class="nx-adminbar-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22V4"/><path d="m4 4 6-2 4 2 6-2v12l-6 2-4-2-6 2"/></svg>
            <span>Flag</span>
          </button>
        <?php endif; ?>
      </div>
    </div>
    <?php if (is_array($flagFlash) && trim((string)($flagFlash['message'] ?? '')) !== ''): ?>
      <div class="nx-adminbar-note <?= ($flagFlash['type'] ?? 'notice') === 'error' ? 'error' : '' ?>"><?= Security::e((string)$flagFlash['message']) ?></div>
    <?php endif; ?>
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

<?php if ($canFlagUser): ?>
  <div class="nx-report-modal" id="nxFlagModal" aria-hidden="true">
    <div class="nx-report-panel" role="dialog" aria-modal="true" aria-labelledby="nxFlagTitle">
      <div class="nx-report-head">
        <h3 id="nxFlagTitle">Flag This Page</h3>
        <button type="button" class="nx-report-btn" id="nxCloseFlagModal">Close</button>
      </div>
      <form method="post" action="<?= Security::e($flagSubmitUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= Security::e($flagCsrfToken) ?>">
        <input type="hidden" name="site_slug" value="<?= Security::e($safeSlug) ?>">
        <input type="hidden" name="page_id" value="<?= (int)($page['id'] ?? 0) ?>">
        <input type="hidden" name="page_title" value="<?= Security::e((string)($page['title'] ?? 'Untitled page')) ?>">
        <input type="hidden" name="page_path" value="<?= Security::e($currentPagePath) ?>">
        <input type="hidden" name="return_url" value="<?= Security::e($currentRequestUri) ?>">
        <div class="nx-report-body">
          <p>Describe what is wrong with this page. Your report will be sent to <?= Security::e(PageFlag::roleLabel(PageFlag::nextOwnerRole($sessionRole))) ?> with your name and email so they can follow up if needed.</p>
          <textarea name="description" required placeholder="What is wrong with this page?"></textarea>
        </div>
        <div class="nx-report-actions">
          <button type="button" class="nx-report-btn" id="nxCancelFlagModal">Cancel</button>
          <button type="submit" class="nx-report-btn primary">Send Flag</button>
        </div>
      </form>
    </div>
  </div>
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

<?php if ($canFlagUser): ?>
<script>
(function(){
  const modal = document.getElementById('nxFlagModal');
  const openBtn = document.getElementById('nxOpenFlagModal');
  const closeBtn = document.getElementById('nxCloseFlagModal');
  const cancelBtn = document.getElementById('nxCancelFlagModal');
  function closeModal() {
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
  }
  openBtn?.addEventListener('click', function () {
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  });
  closeBtn?.addEventListener('click', closeModal);
  cancelBtn?.addEventListener('click', closeModal);
  modal?.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
  });
})();
</script>
<?php endif; ?>

</body>
</html>
