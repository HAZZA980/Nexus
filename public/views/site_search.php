<?php
use NexusCMS\Core\Security;
use NexusCMS\Support\PartialsManager;
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

$fontFamily = $typo['fontFamily'] ?? 'system-ui,-apple-system,Segoe UI,Roboto,Arial';
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
$sitePaths = PartialsManager::paths($safeSlug);
$partialHeader = $sitePaths['header'];
$partialFooter = $sitePaths['footer'];
$siteCssUrl = '/sites/' . $safeSlug . '/assets/site.css';
$siteJsUrl  = '/sites/' . $safeSlug . '/assets/site.js';

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
    .wrap{max-width:1100px;margin:0 auto;padding:22px;}
    .result{padding:14px;border:1px solid var(--nexus-border);border-radius:12px;background:var(--nexus-surface);box-shadow:0 10px 26px rgba(0,0,0,.08);}
    .result-title{font-size:18px;font-weight:700;margin:0;}
    .result-url{color:var(--primary);font-size:14px;}
    .result-snippet{color:var(--muted);font-size:14px;}
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
  </style>
</head>
<body>
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
    <input id="q" name="q" value="<?= Security::e($query) ?>" style="padding:10px 12px;border-radius:10px;border:1px solid var(--nexus-border);min-width:240px">
    <button type="submit" style="padding:10px 12px;border-radius:10px;border:1px solid var(--nexus-border);background:var(--primary);color:#fff;">Search</button>
  </form>
  <?php if ($query === ''): ?>
    <p>Enter a search term to find pages.</p>
  <?php elseif (!$results): ?>
    <p>No results for “<?= Security::e($query) ?>”.</p>
  <?php else: ?>
    <div style="display:grid;gap:12px">
      <?php foreach ($results as $row): ?>
        <article class="result">
          <a class="result-title" href="<?= $base ?>/s/<?= Security::e($safeSlug) ?>/<?= Security::e($row['slug']) ?>"><?= Security::e($row['title']) ?></a>
          <div class="result-url"><?= $base ?>/s/<?= Security::e($safeSlug) ?>/<?= Security::e($row['slug']) ?></div>
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
</body>
</html>
