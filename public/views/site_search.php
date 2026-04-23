<?php
use NexusCMS\Core\Security;
use NexusCMS\Models\PageFlag;
use NexusCMS\Support\PartialsManager;
use NexusCMS\Support\PagePath;
use NexusCMS\Models\ShellPreset;

$base = base_path();
$query = $query ?? '';
$results = $results ?? [];

$theme  = json_decode($site['theme_json'] ?? '', true) ?: [];
$colors = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];
$typo   = is_array($theme['typography'] ?? null) ? $theme['typography'] : [];

$pageBg  = $colors['pageBg']  ?? '#ffffff';
$text    = $colors['text']    ?? '#111827';
$primary = $colors['primary'] ?? '#2563eb';
$muted   = $colors['muted']   ?? '#6b7280';
$surface = $colors['surface'] ?? '#ffffff';
$border  = $colors['border']  ?? 'rgba(17,24,39,.12)';
$radius  = (int)($theme['radius'] ?? 16);

$fontFamily = $typo['fontFamily'] ?? (($site['slug'] ?? '') === 'cite-them-right' ? 'Georgia,"Times New Roman",serif' : 'system-ui,-apple-system,Segoe UI,Roboto,Arial');
$baseSize   = (int)($typo['baseSize'] ?? 16);

$pageShell = [];
$headerKey = preg_replace('/[^a-z0-9_\-]/i', '', (string)($site['header_default_key'] ?? 'nav-left'));
$footerKey = preg_replace('/[^a-z0-9_\-]/i', '', (string)($site['footer_default_key'] ?? 'footer-minimal'));
$headerPreset = ShellPreset::findByKey((int)$site['id'], 'header', $headerKey);
$footerPreset = ShellPreset::findByKey((int)$site['id'], 'footer', $footerKey);

$headerTemplate = __DIR__ . '/headers/' . $headerKey . '.php';
$footerTemplate = __DIR__ . '/footers/' . $footerKey . '.php';
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
$adminEditUrl = $base . '/admin/site.php?id=' . (int)($site['id'] ?? 0);
$adminSiteUrl = $base . '/admin/site.php?id=' . (int)($site['id'] ?? 0);
$flagSubmitUrl = $base . '/report/page-flag';
$flagCsrfToken = Security::csrfToken();
$flagFlash = $_SESSION['page_flag_flash'] ?? null;
unset($_SESSION['page_flag_flash']);
$currentRequestUri = (string)($_SERVER['REQUEST_URI'] ?? ($base . '/s/' . $safeSlug . '/search' . ($query !== '' ? '?q=' . urlencode($query) : '')));
$currentPagePath = (string)(parse_url($currentRequestUri, PHP_URL_PATH) ?: $currentRequestUri);
$sitePaths = PartialsManager::paths($safeSlug);
$partialHeader = $sitePaths['header'];
$partialFooter = $sitePaths['footer'];
$siteCssVersion = is_file($sitePaths['css'] ?? '') ? (string)@filemtime($sitePaths['css']) : '';
$siteJsVersion = is_file($sitePaths['js'] ?? '') ? (string)@filemtime($sitePaths['js']) : '';
$siteCssUrl = '/sites/' . $safeSlug . '/assets/site.css' . ($siteCssVersion !== '' ? '?v=' . rawurlencode($siteCssVersion) : '');
$siteJsUrl  = '/sites/' . $safeSlug . '/assets/site.js' . ($siteJsVersion !== '' ? '?v=' . rawurlencode($siteJsVersion) : '');

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

function snippet(string $text, string $q): string {
  $text = trim(preg_replace('/\s+/', ' ', $text));
  $pos = stripos($text, $q);
  if ($pos === false) return substr($text, 0, 160);
  $start = max(0, $pos - 40);
  return substr($text, $start, 160);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Search — <?= Security::e($site['name']) ?></title>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/site.css">
  <link rel="stylesheet" href="<?= $base ?>/public/assets/nexus-page.css">
  <?php if (is_file($headerCssPath)): ?>
    <link rel="stylesheet" href="<?= $headerCssUrl ?>">
  <?php endif; ?>
  <?php if (is_file($sitePaths['css'] ?? '')): ?>
    <link rel="stylesheet" href="<?= Security::e($siteCssUrl) ?>">
  <?php endif; ?>
  <style>
    :root{
      --nexus-page-bg: <?= Security::e($pageBg) ?>;
      --nexus-text: <?= Security::e($text) ?>;
      --nexus-primary: <?= Security::e($primary) ?>;
      --nexus-muted: <?= Security::e($muted) ?>;
      --nexus-surface: <?= Security::e($surface) ?>;
      --nexus-border: <?= Security::e($border) ?>;
      --nexus-radius: <?= (int)$radius ?>px;
      --nexus-font: <?= Security::e($fontFamily) ?>;
      --nexus-font-size: <?= (int)$baseSize ?>px;
    }
    body{margin:0;background:var(--nexus-page-bg);color:var(--nexus-text);font-family:var(--nexus-font);font-size:var(--nexus-font-size);}
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
    .nx-adminbar a,.nx-adminbar button{color:#e6eaf2;text-decoration:none;}
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
    .nx-adminbar-action:hover{background:rgba(255,255,255,.14);}
    .nx-adminbar-icon{width:14px;height:14px;display:inline-block;flex:0 0 auto;}
    .nx-adminbar-note{padding:0 16px 12px;font-size:13px;color:#d7f7e6;}
    .nx-adminbar-note.error{color:#ffd6d6;}
    .nx-report-modal{position:fixed;inset:0;background:rgba(2,6,23,.55);display:none;align-items:center;justify-content:center;padding:18px;z-index:1200;}
    .nx-report-modal.open{display:flex;}
    .nx-report-panel{width:min(560px,100%);background:#fff;color:#111827;border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.24);overflow:hidden;}
    .nx-report-head{padding:16px 18px;border-bottom:1px solid rgba(17,24,39,.12);display:flex;justify-content:space-between;gap:12px;align-items:center;}
    .nx-report-head h3{margin:0;font-size:18px;}
    .nx-report-body{padding:16px 18px;display:grid;gap:12px;}
    .nx-report-body p{margin:0;color:#4b5563;}
    .nx-report-body textarea{width:100%;min-height:150px;border:1px solid rgba(17,24,39,.16);border-radius:12px;padding:12px 14px;font:inherit;resize:vertical;}
    .nx-report-actions{display:flex;justify-content:flex-end;gap:10px;padding:0 18px 18px;}
    .nx-report-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border-radius:999px;border:1px solid rgba(17,24,39,.16);background:#fff;color:#111827;font:inherit;font-weight:700;cursor:pointer;}
    .nx-report-btn.primary{background:#1d4ed8;border-color:#1d4ed8;color:#fff;}
    .wrap{max-width:1100px;margin:0 auto;padding:22px;}
    .result{padding:14px;border:1px solid var(--nexus-border);border-radius:var(--nexus-radius);background:var(--nexus-surface);box-shadow:0 10px 26px rgba(0,0,0,.08);}
    .result-title{font-size:18px;font-weight:700;margin:0;}
    .result-url{color:var(--primary);font-size:14px;}
    .result-snippet{color:var(--muted);font-size:14px;}
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
  </style>
</head>
<body>
<?php if ($canFlagUser): ?>
  <div class="nx-adminbar">
    <div class="nx-adminbar-inner">
      <div class="nx-adminbar-meta">
        <div style="font-weight:900">
          <a href="<?= Security::e($adminSiteUrl) ?>" style="color:inherit;text-decoration:none">
            <?= Security::e($site['name']) ?>
          </a>
        </div>
        <span class="nx-adminbar-badge"><?= Security::e($adminUserLabel) ?></span>
      </div>
      <div class="nx-adminbar-actions">
        <?php if ($isAdmin): ?>
          <a class="nx-adminbar-action" href="<?= $adminEditUrl ?>">
            <svg class="nx-adminbar-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg>
            <span>Edit</span>
          </a>
        <?php endif; ?>
        <button type="button" class="nx-adminbar-action" id="nxOpenFlagModal">
          <svg class="nx-adminbar-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22V4"/><path d="m4 4 6-2 4 2 6-2v12l-6 2-4-2-6 2"/></svg>
          <span>Flag</span>
        </button>
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
if (!$usedPartialHeader) {
  if (is_file($headerTemplate)) {
    require $headerTemplate;
  }
}
?>
<main class="wrap">
  <h1>Search results</h1>
  <form action="<?= $base ?>/s/<?= Security::e($safeSlug) ?>/search" method="get" role="search" style="margin:10px 0 20px">
    <label class="sr-only" for="q">Search</label>
    <input id="q" name="q" value="<?= Security::e($query) ?>" style="padding:10px 12px;border-radius:var(--nexus-radius);border:1px solid var(--nexus-border);min-width:240px">
    <button type="submit" style="padding:10px 12px;border-radius:var(--nexus-radius);border:1px solid var(--nexus-border);background:var(--primary);color:#fff;">Search</button>
  </form>
  <?php if ($query === ''): ?>
    <p>Enter a search term to find pages.</p>
  <?php elseif (!$results): ?>
    <p>No results for “<?= Security::e($query) ?>”.</p>
  <?php else: ?>
    <div style="display:grid;gap:12px">
      <?php foreach ($results as $row): ?>
        <article class="result">
          <a class="result-title" href="<?= Security::e(PagePath::publicUrl($base, $safeSlug, (string)($row['slug'] ?? ''))) ?>"><?= Security::e($row['title']) ?></a>
          <div class="result-url"><?= Security::e(PagePath::publicUrl($base, $safeSlug, (string)($row['slug'] ?? ''))) ?></div>
          <p class="result-snippet"><?= Security::e(snippet($row['search_text'] ?? '', $query)) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php
$usedPartialFooter = false;
if (is_file($partialFooter) && safe_include($partialFooter, $root)) {
  $usedPartialFooter = true;
}
if (!$usedPartialFooter && is_file($footerTemplate)) require $footerTemplate;
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
        <input type="hidden" name="page_id" value="0">
        <input type="hidden" name="page_title" value="<?= Security::e($query !== '' ? 'Search results for ' . $query : 'Search results') ?>">
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

<?php if (!empty($site['analytics_enabled'])): ?>
<script>
(function(){
  if (<?= $isAdmin ? 'true' : 'false' ?>) return;
  if (navigator.doNotTrack === '1' || window.doNotTrack === '1') return;
  const siteId = <?= (int)$site['id'] ?>;
  const basePath = <?= json_encode(rtrim($base, '/')) ?>;
  const endpoint = (basePath || '') + '/api/analytics/collect';
  const vidKey = 'nx_vid_' + siteId;
  const sidKey = 'nx_sid_' + siteId;
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
  if (!visitor) { visitor = randHex(32); setCookie(vidKey, visitor, 730); }
  let sessionKey = '';
  let sessionStarted = 0;
  const rawSession = getCookie(sidKey);
  if (rawSession) {
    const parts = rawSession.split('.');
    sessionKey = parts[0] || '';
    sessionStarted = parseInt(parts[1] || '0', 10);
  }
  const now = Date.now();
  if (!sessionKey || !sessionStarted || now - sessionStarted > 30*60*1000) {
    sessionKey = randHex(24);
    sessionStarted = now;
  }
  setCookie(sidKey, `${sessionKey}.${sessionStarted}`, 1);

  const url = new URL(window.location.href);
  const params = url.searchParams;
  const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
  const payload = {
    site_id: siteId,
    visitor_key: visitor,
    session_key: sessionKey,
    path: url.pathname + url.search,
    title: document.title || 'Search',
    referrer: document.referrer || '',
    utm_source: params.get('utm_source') || '',
    utm_medium: params.get('utm_medium') || '',
    utm_campaign: params.get('utm_campaign') || '',
    load_ms: nav ? Math.round(nav.loadEventEnd || 0) : null,
    ttfb_ms: nav ? Math.round(nav.responseStart || 0) : null,
  };
  const body = JSON.stringify(payload);
  if (navigator.sendBeacon) {
    const blob = new Blob([body], {type:'application/json'});
    navigator.sendBeacon(endpoint, blob);
  } else {
    fetch(endpoint, {method:'POST',headers:{'Content-Type':'application/json'},body,keepalive:true}).catch(()=>{});
  }
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
