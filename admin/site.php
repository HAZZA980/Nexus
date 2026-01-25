<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Core\Security;
use NexusCMS\Core\DB;
use NexusCMS\Support\PartialsManager;
use NexusCMS\Models\CitationExample;
use NexusCMS\Models\CitationRevision;
use NexusCMS\Models\CitationRelease;

// -----------------------------
// Site loading
// -----------------------------
$siteId = (int)($_GET['id'] ?? 0);
$site = Site::find($siteId);
if (!$site) { http_response_code(404); echo "Site not found"; exit; }

// -----------------------------
// Small DB helper (no rewrites)
// Uses PDO if bootstrap exposes $pdo, otherwise tries NexusCMS\Core\Database::instance()
// -----------------------------
function nx_db() {
  // Prefer the shared DB wrapper
  if (class_exists('\NexusCMS\Core\DB') && method_exists('\NexusCMS\Core\DB', 'pdo')) {
    return \NexusCMS\Core\DB::pdo();
  }
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  if (class_exists('\NexusCMS\Core\Database') && method_exists('\NexusCMS\Core\Database', 'instance')) {
    return \NexusCMS\Core\Database::instance();
  }
  throw new Exception('Database connection not found. Ensure bootstrap.php exposes DB::pdo().');
}

function nx_update_site_json(int $siteId, string $col, array $payload): void {
  $allowed = ['theme_json','header_json','footer_json'];
  if (!in_array($col, $allowed, true)) throw new Exception('Invalid settings column');

  $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

  $db = nx_db();
  $stmt = $db->prepare("UPDATE sites SET {$col} = :json WHERE id = :id LIMIT 1");
  $stmt->execute([':json' => $json, ':id' => $siteId]);
}

function nx_safe_rollback($pdo): void {
  if ($pdo instanceof \PDO && $pdo->inTransaction()) {
    try { $pdo->rollBack(); } catch (\Throwable $e) {}
  }
}

// Simple semantic tag bump: returns next patch version (e.g., 1.0.0 -> 1.0.1)
function nx_next_release_tag(array $tags): string {
  $latest = '1.0.0';
  $versionTags = array_filter(array_column($tags, 'tag' ?? ''), fn($t) => is_string($t) && preg_match('/^\d+\.\d+\.\d+$/', $t));
  if ($versionTags) {
    usort($versionTags, 'version_compare');
    $latest = end($versionTags);
  }
  if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $latest, $m)) return '1.0.0';
  return $m[1] . '.' . $m[2] . '.' . ((int)$m[3] + 1);
}

// -----------------------------
// Defaults
// -----------------------------
$themeDefaults = [
  'colors' => [
    'pageBg'  => '#f7f7f3',
    'surface' => '#ffffff',
    'primary' => '#2563eb',
    'secondary' => '#14b8a6',
    'muted'   => '#6b7280',
    'text'    => '#111827',
    'border'  => 'rgba(17,24,39,.12)',
    'divider' => 'rgba(17,24,39,.12)',
    'focus'   => '#2563eb',
    'hover'   => 'rgba(0,0,0,.06)',
  ],
  'typography' => [
    'fontFamily' => 'system-ui,-apple-system,Segoe UI,Roboto,Arial',
    'baseSize'   => 16,
    'headingScale' => 1.35,
    'fontWeight' => 500,
    'lineHeight' => 1.55,
    'letterSpacing' => '0px',
    'rendering' => 'auto',
  ],
  'layout' => [
    'padding' => 'medium',
    'maxWidth' => 'standard',
    'sectionSpacing' => 20,
    'gridGap' => 16,
    'alignment' => 'left',
    'breakpoint' => 1200,
  ],
  'shape' => [
    'radius' => 16,
    'shadow' => '0 10px 30px rgba(0,0,0,.18)',
    'buttonStyle' => 'pill',
    'inputStyle' => 'rounded',
  ],
  'media' => [
    'imageRatio' => '16:9',
    'imageRadius' => 12,
    'videoStyle' => 'shadow',
    'mediaMaxWidth' => '1200px',
  ],
  'chrome' => [
    'headerDensity' => 'roomy',
    'footerSpacing' => 'normal',
    'navStyle' => 'horizontal',
    'logoSize' => 'medium',
    'iconStroke' => 'regular',
  ],
  'motion' => [
    'duration' => 220,
    'easing' => 'ease-in-out',
    'reduced' => 'auto',
  ],
  'radius' => 16
];

$theme = json_decode($site['theme_json'] ?? '', true) ?: [];
$theme['colors'] = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];
$theme['typography'] = is_array($theme['typography'] ?? null) ? $theme['typography'] : [];
$theme['layout'] = is_array($theme['layout'] ?? null) ? $theme['layout'] : [];
$theme['shape'] = is_array($theme['shape'] ?? null) ? $theme['shape'] : [];
$theme['media'] = is_array($theme['media'] ?? null) ? $theme['media'] : [];
$theme['chrome'] = is_array($theme['chrome'] ?? null) ? $theme['chrome'] : [];
$theme['motion'] = is_array($theme['motion'] ?? null) ? $theme['motion'] : [];

$theme = array_replace_recursive($themeDefaults, $theme);

$header = json_decode($site['header_json'] ?? '', true) ?: [];
$headerDefaults = [
  'preset' => 'nav-left',
  'brandText' => $site['name'] ?? 'Site',
  'logoUrl' => '',
  'cta' => ['label' => '', 'href' => ''],
  'items' => [
    ['label' => 'Home', 'href' => '/'],
  ],
  'style' => ['variant' => 'light', 'sticky' => true],
];
$header = array_replace_recursive($headerDefaults, $header);

$footer = json_decode($site['footer_json'] ?? '', true) ?: [];
$footerDefaults = [
  'preset' => 'footer-minimal',
  'brandText' => $site['name'] ?? 'Site',
  'links' => [
    ['label' => 'About', 'href' => '/about'],
    ['label' => 'Contact', 'href' => '/contact'],
  ],
  'social' => [],
  'legal' => '© ' . date('Y') . ' ' . ($site['name'] ?? 'Site'),
  'style' => ['variant' => 'dark'],
];
$footer = array_replace_recursive($footerDefaults, $footer);

// Collections schema helper
function nx_collections_ensure(): void {
  try {
    $db = nx_db();
    $db->exec("CREATE TABLE IF NOT EXISTS site_collections (
      id INT AUTO_INCREMENT PRIMARY KEY,
      site_id INT NOT NULL,
      name VARCHAR(255) NOT NULL,
      slug VARCHAR(255) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_site_slug (site_id, slug),
      INDEX idx_site (site_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (\Throwable $e) {
    // ignore; fallback handled elsewhere
  }
}
nx_collections_ensure();

$colorOpts = [
  'pageBg' => [
    '#f7f7f3' => 'Warm light',
    '#ffffff' => 'White',
    '#0f172a' => 'Midnight',
  ],
  'surface' => [
    '#ffffff' => 'Bright surface',
    '#f8fafc' => 'Soft shell',
    '#0f172a' => 'Glass dark',
  ],
  'primary' => [
    '#2563eb' => 'Blue',
    '#6366f1' => 'Indigo',
    '#10b981' => 'Emerald',
  ],
  'secondary' => [
    '#14b8a6' => 'Teal',
    '#f59e0b' => 'Amber',
    '#6b7280' => 'Slate',
  ],
  'muted' => [
    '#6b7280' => 'Dim slate',
    '#94a3b8' => 'Frost',
    '#9aa4b5' => 'Cool grey',
  ],
  'text' => [
    '#111827' => 'Ink',
    '#0b1020' => 'Deep ink',
    '#e7ecf4' => 'Soft light',
  ],
  'border' => [
    'rgba(17,24,39,.12)' => 'Neutral',
    'rgba(17,24,39,.18)' => 'Stronger',
    'rgba(255,255,255,.14)' => 'Light over dark',
  ],
  'divider' => [
    'rgba(17,24,39,.12)' => 'Fine line',
    'rgba(17,24,39,.18)' => 'Bold line',
    'rgba(255,255,255,.14)' => 'Light over dark',
  ],
  'focus' => [
    '#2563eb' => 'Blue ring',
    '#22c55e' => 'Green ring',
    '#f97316' => 'Warm ring',
  ],
  'hover' => [
    'rgba(0,0,0,.06)' => 'Soft shadow',
    'rgba(37,99,235,.12)' => 'Primary tint',
    'rgba(255,255,255,.08)' => 'Subtle glow',
  ],
];

$typoOpts = [
  'fontFamily' => [
    'system-ui,-apple-system,Segoe UI,Roboto,Arial' => 'System sans',
    '"Inter",system-ui,-apple-system,Segoe UI,Roboto,Arial' => 'Inter',
    '"Helvetica Neue",Helvetica,Arial,sans-serif' => 'Helvetica',
    '"Source Serif Pro",Georgia,serif' => 'Serif',
  ],
  'baseSize' => [
    15 => '15px',
    16 => '16px',
    18 => '18px',
  ],
  'headingScale' => [
    1.25 => 'Compact',
    1.35 => 'Standard',
    1.5 => 'Large',
  ],
  'fontWeight' => [
    400 => 'Regular',
    500 => 'Medium',
    600 => 'Semibold',
  ],
  'lineHeight' => [
    1.45 => 'Tight',
    1.55 => 'Comfortable',
    1.7  => 'Roomy',
  ],
  'letterSpacing' => [
    '-0.1px' => 'Tight',
    '0px'    => 'Normal',
    '0.15px' => 'Wide',
  ],
  'rendering' => [
    'auto' => 'Auto',
    'optimizeLegibility' => 'Legibility',
    'antialiased' => 'Antialiased',
  ],
];

$layoutOpts = [
  'padding' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
  'maxWidth' => ['narrow' => 'Narrow', 'standard' => 'Standard', 'wide' => 'Wide'],
  'sectionSpacing' => [12 => 'Compact', 20 => 'Comfortable', 28 => 'Spacious'],
  'gridGap' => [10 => 'Tight', 16 => 'Regular', 24 => 'Roomy'],
  'alignment' => ['left' => 'Left-aligned', 'center' => 'Centered'],
  'breakpoint' => [960 => 'Tablet (960px)', 1200 => 'Desktop (1200px)', 1440 => 'Wide (1440px)'],
];

$shapeOpts = [
  'radius' => [10 => 'Subtle', 14 => 'Rounded', 16 => 'Soft', 22 => 'Pillowy'],
  'shadow' => [
    'none' => 'Flat',
    '0 8px 22px rgba(0,0,0,.18)' => 'Soft lift',
    '0 14px 36px rgba(0,0,0,.24)' => 'Layered',
  ],
  'buttonStyle' => [
    'pill' => 'Pill',
    'rounded' => 'Rounded',
    'square' => 'Square',
  ],
  'inputStyle' => [
    'rounded' => 'Rounded',
    'square' => 'Square',
  ],
];

$mediaOpts = [
  'imageRatio' => [
    '16:9' => '16:9',
    '4:3' => '4:3',
    '1:1' => '1:1',
    'auto' => 'Auto',
  ],
  'imageRadius' => [0 => 'Square', 12 => 'Rounded', 16 => 'Soft', 24 => 'Pill'],
  'videoStyle' => [
    'shadow' => 'Shadowed card',
    'flat' => 'Flat embed',
    'frame' => 'Framed',
  ],
  'mediaMaxWidth' => [
    '720px' => 'Narrow',
    '960px' => 'Standard',
    '1200px' => 'Wide',
  ],
];

$chromeOpts = [
  'headerDensity' => [
    'roomy' => 'Roomy',
    'compact' => 'Compact',
    'minimal' => 'Minimal',
  ],
  'footerSpacing' => [
    'tight' => 'Tight',
    'normal' => 'Normal',
    'wide' => 'Wide',
  ],
  'navStyle' => [
    'horizontal' => 'Horizontal',
    'condensed' => 'Condensed',
    'stacked' => 'Stacked',
  ],
  'logoSize' => [
    'small' => 'Small',
    'medium' => 'Medium',
    'large' => 'Large',
  ],
  'iconStroke' => [
    'light' => 'Light',
    'regular' => 'Regular',
    'bold' => 'Bold',
  ],
];

$motionOpts = [
  'duration' => [150 => 'Snappy', 220 => 'Standard', 320 => 'Gentle'],
  'easing' => [
    'ease' => 'Ease',
    'ease-in-out' => 'Ease in-out',
    'cubic-bezier(0.4,0.14,0.3,1)' => 'Soft',
  ],
  'reduced' => [
    'auto' => 'Respect system',
    'reduce' => 'Reduce motion',
    'off' => 'Full motion',
  ],
];
$siteSlug = PartialsManager::safeSlug($site['slug'] ?? '');
$partialPaths = PartialsManager::paths($siteSlug);
$partialStatus = [
  'header' => file_exists($partialPaths['header']) ? 'exists' : 'missing',
  'footer' => file_exists($partialPaths['footer']) ? 'exists' : 'missing',
  'css'    => file_exists($partialPaths['css']) ? 'exists' : 'missing',
  'js'     => file_exists($partialPaths['js']) ? 'exists' : 'missing',
];
$citationStyles = [
  'Harvard',
  'APA',
  'MLA',
  'Chicago Author-Date',
  'Chicago Notes & Bibliography',
  'Vancouver',
  'IEEE',
  'OSCOLA',
  'AMA',
  'Bluebook'
];

// Citation release context (needed before POST handlers)
$citationReleases = [];
$currentReleaseTag = '';
if ($siteSlug === 'cite-them-right') {
  $citationReleases = CitationRelease::listAll($siteSlug);
  $latestTag = $citationReleases ? nx_next_release_tag($citationReleases) : '1.0.0';
  $currentReleaseTag = $_SESSION['citation_release_tag_'.$siteSlug] ?? $latestTag;
  if ($currentReleaseTag === '') $currentReleaseTag = $latestTag;
}

// -----------------------------
// Handle POST saves
// -----------------------------
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  Security::checkCsrf($_POST['_csrf'] ?? '');

  // Duplicate site
  if (isset($_POST['duplicate_site'])) {
    try {
      $pdo = nx_db();
      $pdo->beginTransaction();

      $orig = Site::find($siteId);
      if (!$orig) throw new Exception('Site not found');

      $baseSlug = $orig['slug'] . '-copy';
      $newSlug = $baseSlug;
      $i = 2;
      while (Site::findBySlug($newSlug)) {
        $newSlug = $baseSlug . $i;
        $i++;
      }
      $newName = $orig['name'] . ' Copy';

      $stmt = $pdo->prepare("INSERT INTO sites (name, slug, description, theme_json, header_json, footer_json, header_default_key, footer_default_key, homepage_page_id, created_at) VALUES (?,?,?,?,?,?,?,?,NULL,NOW())");
      $stmt->execute([
        $newName,
        $newSlug,
        $orig['description'] ?? '',
        $orig['theme_json'] ?? null,
        $orig['header_json'] ?? null,
        $orig['footer_json'] ?? null,
        $orig['header_default_key'] ?? 'nav-left',
        $orig['footer_default_key'] ?? 'footer-minimal',
      ]);
      $newSiteId = (int)$pdo->lastInsertId();

      try {
        $presets = $pdo->prepare("SELECT * FROM shell_presets WHERE site_id=?");
        $presets->execute([$siteId]);
        $presetRows = $presets->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($presetRows) {
          $ins = $pdo->prepare("INSERT INTO shell_presets (site_id,type,preset_key,name,config_json,is_default,is_system,created_at) VALUES (?,?,?,?,?,?,?,NOW())");
          foreach ($presetRows as $row) {
            $ins->execute([$newSiteId, $row['type'], $row['preset_key'], $row['name'], $row['config_json'], (int)$row['is_default'], (int)$row['is_system']]);
          }
        }
      } catch (\Throwable $e) {}

      $pagesStmt = $pdo->prepare("SELECT * FROM pages WHERE site_id=?");
      $pagesStmt->execute([$siteId]);
      $pageRows = $pagesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $idMap = [];
      foreach ($pageRows as $row) {
        $ins = $pdo->prepare("INSERT INTO pages (site_id, title, slug, status, template_key, shell_override_json, builder_json, search_text, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");
        $ins->execute([
          $newSiteId,
          $row['title'],
          $row['slug'],
          $row['status'],
          $row['template_key'] ?? 'landing',
          $row['shell_override_json'] ?? null,
          $row['builder_json'] ?? null,
          $row['search_text'] ?? null,
        ]);
        $idMap[(int)$row['id']] = (int)$pdo->lastInsertId();
      }

      if (!empty($orig['homepage_page_id']) && isset($idMap[(int)$orig['homepage_page_id']])) {
        $stmt = $pdo->prepare("UPDATE sites SET homepage_page_id=? WHERE id=? LIMIT 1");
        $stmt->execute([$idMap[(int)$orig['homepage_page_id']], $newSiteId]);
      }

      $pdo->commit();
      header('Location: ' . rtrim(base_path(), '/') . '/admin/site.php?id=' . $newSiteId);
      exit;
    } catch (\Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
      $notice = 'Duplicate failed. Please try again.';
    }
  }

  // Rename site
  if (isset($_POST['rename_site'])) {
    // Placeholder; no-op as rename UI removed
  }

  // Delete site
  if (isset($_POST['delete_site'])) {
    try {
      $pdo = nx_db();
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM pages WHERE site_id=?")->execute([$siteId]);
      try {
        $pdo->prepare("DELETE FROM shell_presets WHERE site_id=?")->execute([$siteId]);
      } catch (\Throwable $e) {}
      $pdo->prepare("DELETE FROM sites WHERE id=? LIMIT 1")->execute([$siteId]);
      $pdo->commit();
      header('Location: ' . rtrim(base_path(), '/') . '/admin/');
      exit;
    } catch (\Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
      $notice = 'Delete failed. Please try again.';
    }
  }

  // Delete page (from actions modal)
  if (isset($_POST['delete_page'])) {
    $pageId = (int)($_POST['page_id'] ?? 0);
    try {
      $page = Page::find($pageId);
      if ($page && (int)$page['site_id'] === $siteId) {
        $db = nx_db();
        $stmt = $db->prepare("DELETE FROM pages WHERE id=? AND site_id=? LIMIT 1");
        $stmt->execute([$pageId, $siteId]);
        header('Location: site.php?id=' . $siteId . '&saved=deleted');
        exit;
      } else {
        $notice = 'Page not found for this site.';
      }
    } catch (\Throwable $e) {
      $notice = 'Delete failed. Please try again.';
    }
  }

  // Duplicate page
  if (isset($_POST['duplicate_page'])) {
    $pageId = (int)($_POST['duplicate_page'] ?? 0);
    try {
      $page = Page::find($pageId);
      if ($page && (int)$page['site_id'] === $siteId) {
        $slugBase = $page['slug'] . '-copy';
        $slugCandidate = $slugBase;
        $n = 1;
        while (Page::findBySlugAnyStatus($siteId, $slugCandidate)) {
          $n++;
          $slugCandidate = $slugBase . '-' . $n;
        }
        $doc = json_decode($page['builder_json'] ?? '[]', true) ?: ['version'=>1,'rows'=>[ ['cols'=>[['span'=>12,'blocks'=>[]]]] ]];
        $newId = Page::create(
          $siteId,
          $page['title'] . ' (Copy)',
          $slugCandidate,
          $doc,
          $page['template_key'] ?? 'landing',
          $page['shell_override_json'] ? json_decode($page['shell_override_json'], true) : null,
          $page['collection_id'] ?? null
        );
        header('Location: site.php?id=' . $siteId . '&saved=page');
        exit;
      } else {
        $notice = 'Page not found for this site.';
      }
    } catch (\Throwable $e) {
      $notice = 'Duplicate failed. Please try again.';
    }
  }

  // Modal create page
  if (isset($_POST['create_modal_page'])) {
    $title = trim((string)($_POST['modal_title'] ?? ''));
    $slug  = trim((string)($_POST['modal_slug'] ?? ''));
    $layout = trim((string)($_POST['modal_layout'] ?? 'blank'));
    $collectionId = (int)($_POST['modal_collection'] ?? 0);
    $normalizedSlug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $slug));
    $normalizedSlug = trim(preg_replace('/-+/', '-', $normalizedSlug), '-');

    if ($title === '' || $normalizedSlug === '') {
      $notice = 'Title and slug are required.';
    } elseif (Page::findBySlugAnyStatus($siteId, $normalizedSlug)) {
      $notice = 'Slug already exists. Choose another.';
    } else {
      $templates = require __DIR__ . '/../app/templates/page_templates.php';
      $doc = ['version'=>1,'rows'=>[ ['cols'=>[['span'=>12,'blocks'=>[]]]] ]];
      if ($layout !== 'blank' && isset($templates[$layout])) {
        $doc = $templates[$layout];
      }
      $pageId = Page::create($siteId, $title, $normalizedSlug, $doc, $layout ?: 'blank', null, $collectionId ?: null);
      $redirectBase = rtrim(base_path(), '/');
      if ($redirectBase === '') {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '') ?: '';
        $redirectBase = rtrim(dirname($scriptDir), '/');
      }
      header('Location: ' . $redirectBase . '/admin/page_builder.php?id=' . $pageId);
      exit;
    }
  }

  // Ensure partials
  if (isset($_POST['ensure_partial'])) {
    $type = $_POST['ensure_partial'];
    try {
      if ($type === 'header') {
        PartialsManager::ensureFile($partialPaths['header'], $partialPaths['root'], PartialsManager::boilerplateHeader($siteSlug, $site['name'] ?? 'Site'));
        $notice = 'Header partial ensured.';
      } elseif ($type === 'footer') {
        PartialsManager::ensureFile($partialPaths['footer'], $partialPaths['root'], PartialsManager::boilerplateFooter($siteSlug, $site['name'] ?? 'Site'));
        $notice = 'Footer partial ensured.';
      } elseif ($type === 'assets') {
        PartialsManager::ensureFile($partialPaths['css'], $partialPaths['root'], PartialsManager::boilerplateCss());
        PartialsManager::ensureFile($partialPaths['js'], $partialPaths['root'], PartialsManager::boilerplateJs());
        $notice = 'Assets ensured.';
      }
      // Refresh status
      $partialStatus = [
        'header' => file_exists($partialPaths['header']) ? 'exists' : 'missing',
        'footer' => file_exists($partialPaths['footer']) ? 'exists' : 'missing',
        'css'    => file_exists($partialPaths['css']) ? 'exists' : 'missing',
        'js'     => file_exists($partialPaths['js']) ? 'exists' : 'missing',
      ];
    } catch (\Throwable $e) {
      $notice = 'Unable to ensure files. Check permissions.';
    }
  }

  // Appearance save
  if (isset($_POST['save_theme'])) {
    $pick = function(string $name, array $options, $default) {
      $val = $_POST[$name] ?? $default;
      foreach ($options as $key => $label) {
        if ((string)$val === (string)$key) return is_numeric($key) ? $key : $key;
      }
      return $default;
    };

    $newTheme = [
      'colors' => [
        'pageBg'  => $pick('c_pageBg', $colorOpts['pageBg'], $themeDefaults['colors']['pageBg']),
        'surface' => $pick('c_surface', $colorOpts['surface'], $themeDefaults['colors']['surface']),
        'primary' => $pick('c_primary', $colorOpts['primary'], $themeDefaults['colors']['primary']),
        'secondary' => $pick('c_secondary', $colorOpts['secondary'], $themeDefaults['colors']['secondary']),
        'muted'   => $pick('c_muted', $colorOpts['muted'], $themeDefaults['colors']['muted']),
        'text'    => $pick('c_text', $colorOpts['text'], $themeDefaults['colors']['text']),
        'border'  => $pick('c_border', $colorOpts['border'], $themeDefaults['colors']['border']),
        'divider' => $pick('c_divider', $colorOpts['divider'], $themeDefaults['colors']['divider']),
        'focus'   => $pick('c_focus', $colorOpts['focus'], $themeDefaults['colors']['focus']),
        'hover'   => $pick('c_hover', $colorOpts['hover'], $themeDefaults['colors']['hover']),
      ],
      'typography' => [
        'fontFamily'   => $pick('t_fontFamily', $typoOpts['fontFamily'], $themeDefaults['typography']['fontFamily']),
        'baseSize'     => (int)$pick('t_baseSize', $typoOpts['baseSize'], $themeDefaults['typography']['baseSize']),
        'headingScale' => (float)$pick('t_headingScale', $typoOpts['headingScale'], $themeDefaults['typography']['headingScale']),
        'fontWeight'   => (int)$pick('t_fontWeight', $typoOpts['fontWeight'], $themeDefaults['typography']['fontWeight']),
        'lineHeight'   => (float)$pick('t_lineHeight', $typoOpts['lineHeight'], $themeDefaults['typography']['lineHeight']),
        'letterSpacing'=> (string)$pick('t_letterSpacing', $typoOpts['letterSpacing'], $themeDefaults['typography']['letterSpacing']),
        'rendering'    => $pick('t_rendering', $typoOpts['rendering'], $themeDefaults['typography']['rendering']),
      ],
      'layout' => [
        'padding' => $pick('layout_padding', $layoutOpts['padding'], $themeDefaults['layout']['padding']),
        'maxWidth' => $pick('layout_maxwidth', $layoutOpts['maxWidth'], $themeDefaults['layout']['maxWidth']),
        'sectionSpacing' => (int)$pick('layout_section', $layoutOpts['sectionSpacing'], $themeDefaults['layout']['sectionSpacing']),
        'gridGap' => (int)$pick('layout_gridgap', $layoutOpts['gridGap'], $themeDefaults['layout']['gridGap']),
        'alignment' => $pick('layout_align', $layoutOpts['alignment'], $themeDefaults['layout']['alignment']),
        'breakpoint' => (int)$pick('layout_breakpoint', $layoutOpts['breakpoint'], $themeDefaults['layout']['breakpoint']),
      ],
      'shape' => [
        'radius' => (int)$pick('shape_radius', $shapeOpts['radius'], $themeDefaults['shape']['radius']),
        'shadow' => $pick('shape_shadow', $shapeOpts['shadow'], $themeDefaults['shape']['shadow']),
        'buttonStyle' => $pick('shape_button', $shapeOpts['buttonStyle'], $themeDefaults['shape']['buttonStyle']),
        'inputStyle' => $pick('shape_input', $shapeOpts['inputStyle'], $themeDefaults['shape']['inputStyle']),
      ],
      'media' => [
        'imageRatio' => $pick('media_ratio', $mediaOpts['imageRatio'], $themeDefaults['media']['imageRatio']),
        'imageRadius' => (int)$pick('media_radius', $mediaOpts['imageRadius'], $themeDefaults['media']['imageRadius']),
        'videoStyle' => $pick('media_video', $mediaOpts['videoStyle'], $themeDefaults['media']['videoStyle']),
        'mediaMaxWidth' => $pick('media_maxwidth', $mediaOpts['mediaMaxWidth'], $themeDefaults['media']['mediaMaxWidth']),
      ],
      'chrome' => [
        'headerDensity' => $pick('chrome_header', $chromeOpts['headerDensity'], $themeDefaults['chrome']['headerDensity']),
        'footerSpacing' => $pick('chrome_footer', $chromeOpts['footerSpacing'], $themeDefaults['chrome']['footerSpacing']),
        'navStyle' => $pick('chrome_nav', $chromeOpts['navStyle'], $themeDefaults['chrome']['navStyle']),
        'logoSize' => $pick('chrome_logo', $chromeOpts['logoSize'], $themeDefaults['chrome']['logoSize']),
        'iconStroke' => $pick('chrome_icon', $chromeOpts['iconStroke'], $themeDefaults['chrome']['iconStroke']),
      ],
      'motion' => [
        'duration' => (int)$pick('motion_duration', $motionOpts['duration'], $themeDefaults['motion']['duration']),
        'easing' => $pick('motion_easing', $motionOpts['easing'], $themeDefaults['motion']['easing']),
        'reduced' => $pick('motion_reduced', $motionOpts['reduced'], $themeDefaults['motion']['reduced']),
      ],
      'radius' => 0,
    ];

    $newTheme['radius'] = $newTheme['shape']['radius'];

    nx_update_site_json($siteId, 'theme_json', $newTheme);
    header('Location: site.php?id=' . $siteId . '&saved=theme');
    exit;
  }

  // Analytics settings
  if (isset($_POST['save_analytics_settings'])) {
    if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
      $notice = 'CSRF failed.';
    } else {
      $enabled = !empty($_POST['analytics_enabled']) ? 1 : 0;
      $privacy = !empty($_POST['analytics_privacy_mode']) ? 1 : 0;
      $retention = (int)($_POST['analytics_retention_days'] ?? 180);
      if ($retention < 30) $retention = 30;
      if ($retention > 720) $retention = 720;
      try {
        $stmt = nx_db()->prepare("UPDATE sites SET analytics_enabled=?, analytics_privacy_mode=?, analytics_retention_days=? WHERE id=? LIMIT 1");
        $stmt->execute([$enabled, $privacy, $retention, $siteId]);
        $site['analytics_enabled'] = $enabled;
        $site['analytics_privacy_mode'] = $privacy;
        $site['analytics_retention_days'] = $retention;
        $notice = 'Analytics settings saved.';
      } catch (\Throwable $e) {
        $notice = 'Could not save analytics settings.';
      }
    }
  }

  // Collections add
  if (isset($_POST['add_collection'])) {
    $name = trim((string)($_POST['collection_name'] ?? ''));
    $slug = strtolower(preg_replace('/[^a-z0-9-]+/', '-', trim((string)($_POST['collection_slug'] ?? $name))));
    $slug = trim(preg_replace('/-+/', '-', $slug), '-');
    if ($name === '' || $slug === '') {
      $notice = 'Collection name and slug are required.';
    } else {
      try {
        $db = nx_db();
        $stmt = $db->prepare("INSERT INTO site_collections (site_id,name,slug,created_at) VALUES (?,?,?,NOW())");
        $stmt->execute([$siteId, $name, $slug]);
        header('Location: site.php?id=' . $siteId . '&saved=collection');
        exit;
      } catch (\Throwable $e) {
        $notice = 'Could not add collection (maybe slug already exists).';
      }
    }
  }

  // Collections delete
  if (isset($_POST['delete_collection'])) {
    $cid = (int)($_POST['collection_id'] ?? 0);
    try {
      $db = nx_db();
      $db->prepare("DELETE FROM site_collections WHERE id=? AND site_id=? LIMIT 1")->execute([$cid, $siteId]);
      header('Location: site.php?id=' . $siteId . '&saved=collection');
      exit;
    } catch (\Throwable $e) {
      $notice = 'Delete failed. Please try again.';
    }
  }

  // Header save
  if (isset($_POST['save_header'])) {
    $items = [];
    $labels = $_POST['nav_label'] ?? [];
    $hrefs  = $_POST['nav_href'] ?? [];
    if (is_array($labels) && is_array($hrefs)) {
      foreach ($labels as $i => $lab) {
        $lab = trim((string)$lab);
        $href = trim((string)($hrefs[$i] ?? ''));
        if ($lab !== '' && $href !== '') {
          $items[] = ['label' => $lab, 'href' => $href];
        }
      }
    }
    if (!$items) $items = [['label' => 'Home', 'href' => '/']];

    $newHeader = [
      'preset' => trim($_POST['preset'] ?? 'nav-left'),
      'brandText' => trim($_POST['brandText'] ?? ($site['name'] ?? 'Site')),
      'logoUrl' => trim($_POST['logoUrl'] ?? ''),
      'cta' => [
        'label' => trim($_POST['cta_label'] ?? ''),
        'href'  => trim($_POST['cta_href'] ?? ''),
      ],
      'items' => $items,
      'style' => [
        'variant' => in_array($_POST['variant'] ?? 'light', ['light','dark','transparent'], true) ? $_POST['variant'] : 'light',
        'sticky'  => !empty($_POST['sticky']),
      ],
    ];

    nx_update_site_json($siteId, 'header_json', $newHeader);
    header('Location: site.php?id=' . $siteId . '&saved=header');
    exit;
  }

  // Footer save
  if (isset($_POST['save_footer'])) {
    $links = [];
    $linkLabels = $_POST['footer_label'] ?? [];
    $linkHrefs  = $_POST['footer_href'] ?? [];
    if (is_array($linkLabels) && is_array($linkHrefs)) {
      foreach ($linkLabels as $i => $lab) {
        $lab = trim((string)$lab);
        $href = trim((string)($linkHrefs[$i] ?? ''));
        if ($lab !== '' && $href !== '') {
          $links[] = ['label' => $lab, 'href' => $href];
        }
      }
    }
    if (!$links) $links = [['label' => 'About', 'href' => '/about']];

    $newFooter = [
      'preset' => trim($_POST['footer_preset'] ?? 'footer-minimal'),
      'brandText' => trim($_POST['footer_brand'] ?? ($site['name'] ?? 'Site')),
      'links' => $links,
      'social' => [],
      'legal' => trim($_POST['footer_legal'] ?? ($footerDefaults['legal'])),
      'style' => [
        'variant' => in_array($_POST['footer_variant'] ?? 'dark', ['light','dark'], true) ? $_POST['footer_variant'] : 'dark',
      ],
    ];

    nx_update_site_json($siteId, 'footer_json', $newFooter);
    header('Location: site.php?id=' . $siteId . '&saved=footer');
    exit;
  }

  // Change page collection (from kebab menu)
  if (isset($_POST['change_collection'])) {
    try {
      $pageId = (int)($_POST['page_id'] ?? 0);
      $collectionId = (int)($_POST['collection_id'] ?? 0);
      $cid = $collectionId > 0 ? $collectionId : null;
      $stmt = nx_db()->prepare("UPDATE pages SET collection_id = :cid WHERE id = :pid AND site_id = :sid LIMIT 1");
      $stmt->execute([':cid' => $cid, ':pid' => $pageId, ':sid' => $siteId]);
      $notice = 'Collection updated.';
      $pages = Page::listBySite($siteId); // refresh
    } catch (\Throwable $e) {
      $notice = 'Error updating collection: ' . $e->getMessage();
    }
  }

  // Citation database CRUD + revisions (Cite Them Right only)
  if ($siteSlug === 'cite-them-right') {
    $currentReleaseTag = $_SESSION['citation_release_tag_'.$siteSlug] ?? $currentReleaseTag;
    $currentUserId = $_SESSION['user_id'] ?? null;
    $currentUserEmail = $currentUser['email'] ?? null;

    $diffFn = function(array $before, array $after): array {
      $fields = ['example_key','label','referencing_style','citation_order','example_heading','example_body','you_try','notes'];
      $diff = [];
      foreach ($fields as $f) {
        $b = $before[$f] ?? null;
        $a = $after[$f] ?? null;
        if ($b !== $a) $diff[] = ['field'=>$f,'before'=>$b,'after'=>$a];
      }
      return $diff;
    };
    $recordRevision = function($action, $before, $after, $releaseTag) use ($siteSlug, $currentUserId, $currentUserEmail, $diffFn) {
      $diff = $diffFn($before ?? [], $after ?? []);
      CitationRevision::record([
        'site_slug' => $siteSlug,
        'citation_id' => $after['id'] ?? $before['id'] ?? null,
        'citation_key' => $after['example_key'] ?? $before['example_key'] ?? '',
        'action' => $action,
        'user_id' => $currentUserId,
        'user_email' => $currentUserEmail,
        'release_tag' => $releaseTag ?: null,
        'before_json' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'after_json' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'diff_json' => $diff ? json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
      ]);
    };
    $applySnapshot = function(array $snapshot) use ($siteSlug) {
      if (!$snapshot) return null;
      $row = CitationExample::find($siteSlug, $snapshot['example_key']);
      if ($row) {
        CitationExample::update((int)$row['id'], [
          'referencing_style' => $snapshot['referencing_style'],
          'example_key' => $snapshot['example_key'],
          'label' => $snapshot['label'],
          'citation_order' => $snapshot['citation_order'],
          'example_heading' => $snapshot['example_heading'],
          'example_body' => $snapshot['example_body'],
          'you_try' => $snapshot['you_try'],
          'notes' => $snapshot['notes'] ?? null,
        ]);
        $snapshot['id'] = $row['id'];
      } else {
        $newId = CitationExample::create([
          'site_slug' => $siteSlug,
          'referencing_style' => $snapshot['referencing_style'],
          'example_key' => $snapshot['example_key'],
          'label' => $snapshot['label'],
          'citation_order' => $snapshot['citation_order'],
          'example_heading' => $snapshot['example_heading'],
          'example_body' => $snapshot['example_body'],
          'you_try' => $snapshot['you_try'],
          'notes' => $snapshot['notes'] ?? null,
        ]);
        $snapshot['id'] = $newId;
      }
      return $snapshot;
    };

    if (isset($_POST['add_citation'])) {
      try {
        $pdo = nx_db();
        $pdo->beginTransaction();
        $style = trim((string)($_POST['citation_style'] ?? ''));
        if (!in_array($style, $citationStyles, true)) $style = $citationStyles[0];
        $data = [
          'site_slug' => $siteSlug,
          'referencing_style' => $style,
          'example_key' => trim((string)($_POST['citation_key'] ?? '')),
          'label' => trim((string)($_POST['citation_label'] ?? '')),
          'citation_order' => trim((string)($_POST['citation_order'] ?? '')),
          'example_heading' => trim((string)($_POST['citation_heading'] ?? '')),
          'example_body' => trim((string)($_POST['citation_body'] ?? '')),
          'you_try' => trim((string)($_POST['citation_youtry'] ?? '')),
          'notes' => trim((string)($_POST['citation_notes'] ?? ''))
        ];
        if ($data['example_key'] === '' || $data['label'] === '') throw new Exception('Key and label are required');
        $newId = CitationExample::create($data);
        $after = array_merge($data, ['id'=>$newId]);
        $recordRevision('create', null, $after, $currentReleaseTag);
        if ($pdo->inTransaction()) $pdo->commit();
        $notice = 'Citation saved.';
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Error saving citation: ' . $e->getMessage();
      }
    }
    if (isset($_POST['update_citation'])) {
      try {
        $pdo = nx_db();
        $pdo->beginTransaction();
        $id = (int)($_POST['citation_id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid citation ID');
        $style = trim((string)($_POST['citation_style'] ?? ''));
        if (!in_array($style, $citationStyles, true)) $style = $citationStyles[0];
        $before = CitationExample::findById($id);
        $data = [
          'referencing_style' => $style,
          'example_key' => trim((string)($_POST['citation_key'] ?? '')),
          'label' => trim((string)($_POST['citation_label'] ?? '')),
          'citation_order' => trim((string)($_POST['citation_order'] ?? '')),
          'example_heading' => trim((string)($_POST['citation_heading'] ?? '')),
          'example_body' => trim((string)($_POST['citation_body'] ?? '')),
          'you_try' => trim((string)($_POST['citation_youtry'] ?? '')),
          'notes' => trim((string)($_POST['citation_notes'] ?? ''))
        ];
        CitationExample::update($id, $data);
        $after = array_merge($data, ['id'=>$id, 'site_slug'=>$siteSlug]);
        $recordRevision('update', $before ?? [], $after, $currentReleaseTag);
        if ($pdo->inTransaction()) $pdo->commit();
        $notice = 'Citation updated.';
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Error updating citation: ' . $e->getMessage();
      }
    }
    if (isset($_POST['delete_citation'])) {
      try {
        $pdo = nx_db();
        $pdo->beginTransaction();
        $id = (int)($_POST['citation_id'] ?? 0);
        if ($id > 0) {
          $before = CitationExample::findById($id);
          CitationExample::delete($id, $siteSlug);
          $recordRevision('delete', $before ?? [], null, $currentReleaseTag);
          if ($pdo->inTransaction()) $pdo->commit();
          $notice = 'Citation deleted.';
        }
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Error deleting citation: ' . $e->getMessage();
      }
    }

    if (isset($_POST['rollback_citation'])) {
      try {
        $pdo = nx_db();
        $pdo->beginTransaction();
        $revId = (int)($_POST['revision_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM citation_revisions WHERE id=? AND site_slug=? LIMIT 1");
        $stmt->execute([$revId, $siteSlug]);
        $rev = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rev) throw new Exception('Revision not found');
        $before = CitationExample::find($siteSlug, $rev['citation_key']);
        $target = json_decode($rev['before_json'] ?? 'null', true);
        if ($target) {
          $after = $applySnapshot($target);
          $recordRevision('rollback', $before ?? [], $after ?? [], $currentReleaseTag);
        } else {
          if ($before && isset($before['id'])) {
            CitationExample::delete((int)$before['id'], $siteSlug);
          }
          $recordRevision('rollback', $before ?? [], null, $currentReleaseTag);
        }
        $pdo->commit();
        $notice = 'Rolled back.';
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Error during rollback: ' . $e->getMessage();
      }
    }

    if (isset($_POST['export_release'])) {
      try {
        $tag = trim((string)($_POST['release_tag'] ?? ''));
        if ($tag === '') $tag = $currentReleaseTag ?: '1.0.0';
        $revs = CitationRevision::listByRelease($siteSlug, $tag);
        if (!$revs) {
          // Auto-stage unstaged revisions to this tag
          $pdo = nx_db();
          $pdo->prepare("UPDATE citation_revisions SET release_tag=? WHERE site_slug=? AND (release_tag IS NULL OR release_tag='')")->execute([$tag, $siteSlug]);
          $revs = CitationRevision::listByRelease($siteSlug, $tag);
        }
        if (!$revs) throw new Exception('No revisions available to export.');
        $final = [];
        foreach ($revs as $r) {
          $key = $r['citation_key'];
          $after = json_decode($r['after_json'] ?? 'null', true);
          $final[$key] = $after;
        }
        $root = PartialsManager::projectRoot();
        $updatesRoot = $root . '/updates';
        if (!is_dir($updatesRoot)) mkdir($updatesRoot, 0777, true);
        $dir = $updatesRoot . '/' . $tag;
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $manifest = [
          'tag' => $tag,
          'exported_at' => date('c'),
          'exported_by' => $currentUserEmail,
          'revision_count' => count($revs),
          'citation_count' => count($final),
          'site_slug' => $siteSlug,
        ];
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $ndjson = '';
        foreach ($revs as $r) {
          $ndjson .= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
        file_put_contents($dir . '/revisions.ndjson', $ndjson);
        file_put_contents($dir . '/final_state.json', json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        CitationRelease::markExported($siteSlug, $tag, $currentUserEmail ?? 'unknown');
        $notice = "Exported release {$tag}.";
        $_SESSION['citation_release_tag_'.$siteSlug] = nx_next_release_tag(array_merge($citationReleases, [['tag'=>$tag]]));
        $currentReleaseTag = $_SESSION['citation_release_tag_'.$siteSlug];
      } catch (\Throwable $e) {
        $notice = 'Export failed: ' . $e->getMessage();
      }
    }
  }
}

if (!empty($_GET['saved'])) {
  $notice = $_GET['saved'] === 'theme' ? 'Theme saved.' : ($_GET['saved'] === 'header' ? 'Header saved.' : 'Saved.');
}

$pages = Page::listBySite($siteId);

// Ensure a default Home page exists (blank draft)
$homeExisting = Page::findBySlugAnyStatus($siteId, 'home');
if (!$homeExisting) {
  $homeDoc = ['version'=>1,'rows'=>[ ['cols'=>[['span'=>12,'blocks'=>[]]]] ]];
  $homeId = Page::create($siteId, 'Home', 'home', $homeDoc, 'blank', null);
  Site::setHomepage($siteId, $homeId);
  $pages = Page::listBySite($siteId); // refresh
}

$collections = [];
try {
  $stmt = nx_db()->prepare("SELECT * FROM site_collections WHERE site_id=? ORDER BY created_at DESC");
  $stmt->execute([$siteId]);
  $collections = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $usageStmt = nx_db()->prepare("SELECT collection_id, COUNT(*) as cnt FROM pages WHERE site_id=? GROUP BY collection_id");
  $usageStmt->execute([$siteId]);
  $usageRows = $usageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $collectionUsage = [];
  foreach ($usageRows as $ur) {
    $collectionUsage[(int)$ur['collection_id']] = (int)$ur['cnt'];
  }
} catch (\Throwable $e) {
  $collections = [];
  $collectionUsage = [];
}

$citationExamples = [];
if ($siteSlug === 'cite-them-right') {
  try {
    $citationExamples = CitationExample::listForSiteSlug($siteSlug);
  } catch (\Throwable $e) {
    $citationExamples = [];
  }
}
$citationRevisions = [];
$citationReleases = [];
if ($siteSlug === 'cite-them-right') {
  $citationRevisions = CitationRevision::recent($siteSlug, 100);
  $citationReleases = CitationRelease::listAll($siteSlug);
}
$currentReleaseTag = '';
if ($siteSlug === 'cite-them-right') {
  $latestTag = $citationReleases ? nx_next_release_tag($citationReleases) : '1.0.0';
  $currentReleaseTag = $_SESSION['citation_release_tag_'.$siteSlug] ?? $latestTag;
  if ($currentReleaseTag === '') $currentReleaseTag = $latestTag;
}
$stagedCount = 0;
$stagedByTag = [];
$netEffects = [];
$latestByKey = [];
$stagedKeys = [];
if ($siteSlug === 'cite-them-right') {
  foreach ($citationRevisions as $rev) {
    $tag = $rev['release_tag'] ?? '';
    if ($tag) {
      $stagedByTag[$tag] = ($stagedByTag[$tag] ?? 0) + 1;
      if ($tag === $currentReleaseTag) $stagedCount++;
    }
    $key = $rev['citation_key'];
    if (!isset($latestByKey[$key])) {
      $latestByKey[$key] = $rev;
      if ($tag === $currentReleaseTag) $stagedKeys[$key] = true;
    }
  }
  if ($currentReleaseTag) {
    $currentTagRevs = CitationRevision::listByRelease($siteSlug, $currentReleaseTag);
    foreach ($currentTagRevs as $r) {
      $key = $r['citation_key'];
      $after = json_decode($r['after_json'] ?? 'null', true);
      $netEffects[$key] = $after;
    }
  }
}

$base = base_path();
$activeNav = 'sites';

// Fetch current user for header menu
$currentUser = null;
if (isset($_SESSION['user_id'])) {
  try {
    $stmt = DB::pdo()->prepare("SELECT id, email, display_name, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();
  } catch (\Throwable $e) {
    $currentUser = null;
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Site — <?= Security::e($site['name']) ?></title>
  <script>
    (function() {
      try {
        const stored = localStorage.getItem('nexusTheme');
        const theme = stored === 'light' ? 'light' : 'dark';
        localStorage.setItem('nexusTheme', theme);
        document.documentElement.classList.toggle('theme-light', theme === 'light');
      } catch(e) {}
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
      --field-bg: rgba(255,255,255,0.06);
      --field-border: var(--border);
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
      --field-bg: #cbd5e1;
      --field-border: #cbd5e1;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background: var(--bg);
      color:var(--text);
      font-family:"Inter","Helvetica Neue",system-ui,-apple-system,sans-serif;
      line-height:1.5;
      transition:background .2s ease,color .2s ease;
    }
    a{color:inherit;text-decoration:none}
    a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline:none; box-shadow:var(--focus); border-color:var(--primary); }
    .top-bar {
      display:flex; align-items:center; gap:16px;
      padding:14px 18px;
      background: linear-gradient(90deg, rgba(91,33,182,0.12), rgba(91,33,182,0));
      border-bottom:1px solid var(--border);
      position:sticky; top:0; backdrop-filter:blur(10px); z-index:10;
    }
    .brand { display:inline-flex; align-items:center; gap:10px; font-weight:600; }
    .brand-mark {
      width:36px; height:36px;
      border-radius:10px;
      background:linear-gradient(135deg, var(--primary), #22c55e);
      display:grid; place-items:center;
      font-weight:700; letter-spacing:-0.02em;
      box-shadow:var(--shadow);
    }
    .brand-text { display:flex; flex-direction:column; line-height:1.2; }
    .brand-text small { color:var(--muted); font-weight:500; }
    .top-nav{
      display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;
    }
    .top-nav .nav-link{
      display:inline-flex;align-items:center;justify-content:center;
      padding:10px 12px;border-radius:10px;border:1px solid var(--border);
      background:rgba(255,255,255,0.05);color:var(--text);font-weight:700;text-decoration:none;min-height:40px;
    }
    .top-nav .nav-link:hover{background:rgba(255,255,255,0.1);}
    .top-nav .nav-link.active{
      background:linear-gradient(135deg, var(--primary), var(--primary-strong));
      color:#fff;border-color:transparent;box-shadow:var(--shadow);
    }
    .top-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);
      background:rgba(255,255,255,.06);text-decoration:none;font-weight:700;cursor:pointer;min-height:44px}
    .btn:hover{background:rgba(255,255,255,.10);transform:translateY(-1px);}
    .btn.primary {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      border: none;
      color: #f8fbff;
      box-shadow: 0 10px 30px rgba(37,99,235,0.35);
    }
    .user-menu { position:relative; min-width:180px; }
    .user-menu summary { list-style:none; cursor:pointer; display:inline-flex; align-items:center; gap:10px; padding:10px 12px; min-height:44px; border-radius:12px; border:1px solid var(--border); background:rgba(255,255,255,0.05); font-weight:600; }
    .user-menu summary::-webkit-details-marker { display:none; }
    .user-avatar { width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#22c55e,#3b82f6);display:grid;place-items:center;font-weight:700;color:#0b1224; }
    .user-menu .menu { position:absolute; right:0; top:calc(100% + 6px); background:var(--card); border:1px solid var(--border); border-radius:14px; padding:10px; min-width:220px; box-shadow:var(--shadow); z-index:5; }
    .user-menu .menu a, .user-menu .menu button { display:block; padding:10px 10px; border-radius:10px; text-decoration:none; background:transparent; border:none; color:var(--text); width:100%; text-align:left; cursor:pointer; }
    .user-menu .menu a:hover, .user-menu .menu button:hover { background:rgba(255,255,255,0.06); }
    .user-meta { color:var(--muted); font-size:14px; padding:6px 10px 10px; }
    main { max-width:1200px; margin:0 auto; padding:24px 20px 48px; }
    .wrap{max-width:1100px;margin:0 auto;padding:0}
    .top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:12px}
    .crumbs{color:var(--muted);font-size:14px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
    .card{border:1px solid var(--border);border-radius:18px;background:var(--card);padding:16px;box-shadow:var(--shadow)}
    .card h2{margin:0 0 10px 0;font-size:18px}
    .muted{color:var(--muted);font-size:13px}
    label{display:block;margin:10px 0 6px 0;color:var(--muted);font-size:13px}
    input,select,textarea{width:100%;padding:12px;border-radius:12px;border:1px solid var(--field-border);background:var(--field-bg);color:var(--text);font-weight:600;}
    textarea{overflow:hidden;resize:vertical;min-height:40px;}
    ::placeholder{color:var(--muted);opacity:0.9;}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    button{padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.06);color:var(--text);cursor:pointer}
    button:hover{background:rgba(255,255,255,.10)}
    .notice{margin-top:12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(34,197,94,.35);
      background:rgba(34,197,94,.10)}
    .tabs{
      display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;
      position:sticky;top:10px;z-index:5;align-items:center;
      padding:12px;
      background:var(--bg);
      border-radius:14px;
      box-shadow:0 10px 28px rgba(0,0,0,0.06);
    }
    .tab{padding:10px 14px;border-radius:999px;border:1px solid var(--border);background:var(--card);cursor:pointer;color:var(--text);font-weight:700;min-height:44px;box-shadow:inset 0 1px 0 rgba(255,255,255,0.04);}
    .tab.active{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-color:transparent;box-shadow:0 6px 20px rgba(37,99,235,0.25);}
    .panel{display:none}
    .panel.active{display:block}
    .nav-items{display:grid;gap:8px}
    .nav-item{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:center}
    .small{padding:8px 10px}
    .layout-card{
      border:1px solid var(--border);border-radius:16px;overflow:hidden;
      background:rgba(255,255,255,.05);display:flex;flex-direction:column;
      box-shadow:0 10px 26px rgba(0,0,0,.25);
    }
    .layout-thumb{
      height:120px;
      background:linear-gradient(135deg,rgba(255,255,255,.08),rgba(255,255,255,.02));
      border-bottom:1px solid var(--border);
    }
    .layout-body{padding:12px;display:flex;flex-direction:column;gap:8px;flex:1;}
    .layout-title{font-weight:800;font-size:15px;}
    .btn.fill{justify-content:center;width:100%;background:rgba(37,99,235,.22);border-color:rgba(37,99,235,.4);}
    .modal-backdrop{
      position:fixed;inset:0;background:rgba(0,0,0,0.55);display:none;align-items:center;justify-content:center;z-index:1000;
      padding:14px;
    }
    .modal{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:18px;
      box-shadow:var(--shadow);
      max-width:760px;
      width:100%;
      overflow:auto;
      padding:18px;
    }
    .modal header{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;}
    .modal h3{margin:0;font-size:20px;}
    .modal .close-btn{border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:18px;}
    .modal .layout-grid{grid-template-columns:repeat(auto-fit,minmax(220px,1fr));}
    .layout-card.active{outline:2px solid var(--primary); box-shadow:0 0 0 3px rgba(59,130,246,0.35);}
    .layout-card .checkmark{display:none; position:absolute; top:8px; right:8px; background:var(--primary); color:#fff; border-radius:999px; width:22px; height:22px; font-size:14px; align-items:center; justify-content:center;}
    .layout-card.active .checkmark{display:flex;}
    .badge { display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; font-weight:700; font-size:13px; }
    .badge.ok { background:rgba(34,197,94,0.18); color:#0f5132; border:1px solid rgba(34,197,94,0.45); }
    .badge.warn { background:rgba(239,68,68,0.14); color:#7f1d1d; border:1px solid rgba(239,68,68,0.4); }
    .path { font-family:monospace; background:rgba(255,255,255,0.06); padding:8px 10px; border-radius:10px; word-break:break-all; }
    .section{margin-top:12px;padding:14px;border:1px solid var(--border);border-radius:14px;background:rgba(255,255,255,0.03);}
    .section h3{margin:0 0 6px;}
    .danger{border-color:rgba(239,68,68,0.35);background:rgba(239,68,68,0.08);}
    .btn.danger{background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;border-color:rgba(255,255,255,0.08);}
    .status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;}
    .status-badge.published{background:rgba(74,222,128,.16);color:#16a34a;}
    .status-badge.draft{background:rgba(148,163,184,.22);color:#475569;}
    .status-dot{width:8px;height:8px;border-radius:50%;background:currentColor;display:inline-block;}
    table.page-table{width:100%;margin-top:12px;border-collapse:collapse}
    table.page-table thead tr{color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:0.4px;}
    table.page-table tbody tr{border-top:1px solid var(--border);}
    table.page-table tbody tr:hover{background:rgba(37,99,235,0.06);}
    table.page-table td, table.page-table th{padding:10px 8px;vertical-align:middle;}
    .title-main{font-weight:800;font-size:15px;}
    .title-path{font-family:"SFMono-Regular","Menlo",monospace;font-size:12px;color:var(--muted);}
    .btn.icon{padding:8px 10px;gap:6px;}
    .btn.text{background:transparent;border-color:transparent;padding:8px 10px;}
    .btn.danger-outline{color:#fca5a5;border-color:rgba(248,113,113,.3);background:rgba(248,113,113,.05);}
    .kebab{position:relative;}
    .kebab-btn{padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.06);color:var(--muted);cursor:pointer;}
    .kebab-menu{position:absolute;right:0;top:110%;background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);min-width:160px;display:none;z-index:30;}
    .kebab-menu button{width:100%;text-align:left;border:none;background:transparent;padding:10px 12px;color:var(--text);border-radius:0;}
    .kebab-menu button:hover{background:rgba(255,255,255,.08);}
    .kebab-menu .danger{color:#fca5a5;}
    .collection-table{width:100%;border-collapse:collapse;margin-top:10px;}
    .collection-table th,.collection-table td{padding:10px 8px;text-align:left;vertical-align:middle;}
    .collection-table thead tr{color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:0.4px;}
    .collection-table tbody tr{border-top:1px solid rgba(255,255,255,.06);}
    .collection-table tbody tr:hover{background:rgba(255,255,255,.04);}
    .collection-name{font-weight:800;font-size:15px;}
  .collection-slug{font-family:"SFMono-Regular","Menlo",monospace;font-size:12px;color:var(--muted);}
  .citations-list{margin-top:12px;}
  .citation-table{width:100%;border-collapse:collapse;}
  .citation-table th,.citation-table td{padding:10px 8px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle;}
  .citation-table th{color:var(--muted);font-size:12px;letter-spacing:0.3px;text-transform:uppercase;}
  .citation-row:hover{background:rgba(255,255,255,0.04);}
  .citation-label{font-weight:800;font-size:15px;}
  .citation-style-pill{border-radius:999px;padding:6px 10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);font-weight:700;font-size:12px;}
  .badge-chip{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid var(--border);}
  .badge-chip.staged{background:rgba(37,99,235,0.12);color:#bfdbfe;border-color:rgba(59,130,246,0.4);}
  .analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:12px;}
  .analytic-card{border:1px solid var(--border);border-radius:14px;padding:12px;background:rgba(255,255,255,0.03);display:flex;flex-direction:column;gap:6px;}
  .analytic-card .label{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:0.4px;}
  .analytic-card .value{font-size:22px;font-weight:800;letter-spacing:-0.02em;}
  .analytic-card .delta{font-size:12px;color:var(--muted);}
  .analytics-controls{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:12px 0;}
  .analytics-breakdown{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-top:10px;}
  .list-table{width:100%;border-collapse:collapse;}
  .list-table th,.list-table td{padding:8px 6px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle;}
  .list-table th{color:var(--muted);font-size:12px;letter-spacing:0.4px;text-transform:uppercase;}
  .chart-line{display:flex;gap:4px;align-items:flex-end;height:52px;}
  .chart-line span{flex:1;border-radius:6px;background:linear-gradient(180deg, rgba(37,99,235,.65), rgba(37,99,235,.28));min-height:2px;}
  .trend-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.03);font-weight:700;font-size:12px;}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,0.04);font-weight:700;font-size:12px;}
  .cite-viewer{position:fixed;top:0;right:0;width:520px;max-width:90vw;height:100vh;background:var(--panel);border-left:1px solid var(--border);box-shadow:-12px 0 24px rgba(0,0,0,0.25);transition:transform 0.25s ease;z-index:1500;display:flex;flex-direction:column;transform:translateX(100%);}
  .cite-viewer.active{transform:translateX(0);}
  .cite-viewer header{padding:16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;}
  .cite-viewer .actions-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-bottom:1px solid var(--border);padding:10px 14px;}
  .cite-viewer main{padding:14px;overflow:auto;flex:1;display:grid;gap:10px;max-width:100%;margin:0;width:100%;}
  .cite-viewer .section{margin:0;}
  .cite-viewer footer{padding:12px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;}
  .cite-viewer main.viewer-body{display:flex;flex-direction:column;align-items:stretch;}
  .cite-viewer .viewer-body{padding:14px;overflow:auto;flex:1;display:flex;flex-direction:column;gap:12px;background:var(--panel);align-items:stretch;}
  .cite-viewer .viewer-body > *{width:100%;max-width:none;}
  .cite-viewer .viewer-body .citation-field{width:100%;max-width:none;}
  .cite-viewer .viewer-body .callout{width:100%;max-width:none;}
  .cite-viewer .edit-body{gap:12px;}
  .cite-viewer.edit-mode{background:var(--panel);}
  .cite-readonly-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,0.04);font-weight:700;font-size:12px;}
  .citation-edit-field input,
  .citation-edit-field textarea{
    background:var(--card);
    border:1px solid var(--border);
    color:inherit;
    font:inherit;
    padding:10px;
    border-radius:10px;
    line-height:1.6;
    box-shadow:0 1px 0 rgba(0,0,0,0.04);
    height:auto;
    min-height:44px;
  }
  .citation-edit-field input:focus,
  .citation-edit-field textarea:focus{border-color:var(--primary);}
  .citation-edit-field textarea{
    min-height:0;
    resize:none;
    overflow:hidden;
  }
  .citation-edit-field strong{display:block;margin-bottom:6px;}
  .citation-subtabs{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;}
  .citation-subtab{padding:8px 12px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);cursor:pointer;font-weight:700;min-height:36px;}
  .citation-subtab.active{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-color:transparent;}
  .pill-muted{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,0.04);font-weight:700;font-size:12px;}
  .grid-2{display:grid;grid-template-columns:2fr 1fr;gap:12px;}
  @media(max-width:960px){.grid-2{grid-template-columns:1fr;}}
  .citation-panel{display:none;}
  .citation-panel.active{display:block;}
  .rev-filters{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 12px;border:1px solid var(--border);border-radius:12px;padding:12px;background:rgba(255,255,255,0.02);}
  .rev-filters .field{display:flex;flex-direction:column;gap:6px;min-width:180px;}
  .rev-filters .field label{margin:0;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.4px;}
  .rev-advanced{display:none;flex-wrap:wrap;gap:10px;width:100%;margin-top:6px;}
  .rev-advanced.visible{display:flex;}
  .rev-no-results{margin-top:8px;color:var(--muted);}
  .revision-row{cursor:pointer;}
  .revision-row:hover{background:rgba(255,255,255,0.04);}
  .cite-viewer .callout{padding:10px;border:1px solid var(--border);border-radius:10px;background:rgba(255,255,255,0.03);width:100%;}
  </style>
</head>
  <body>
    <?php include __DIR__ . '/partials/header.php'; ?>
  <main>
    <div class="wrap">
      <div class="top">
        <div>
          <h1 style="margin:0;font-size:26px"><?= Security::e($site['name']) ?></h1>
          <div class="crumbs">Site Admin Home</div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">
          <a class="btn" href="<?= $base ?>/admin/">Back</a>
          <a class="btn" target="_blank" href="<?= $base ?>/s/<?= Security::e($site['slug']) ?>/home">Open site</a>
        </div>
      </div>

      <?php if ($notice): ?>
        <div class="notice"><?= Security::e($notice) ?></div>
      <?php endif; ?>

      <div class="tabs" role="tablist" aria-label="Site settings tabs" style="justify-content:space-between;align-items:center;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button class="tab active" data-tab="pages" type="button">Pages</button>
          <button class="tab" data-tab="header-footer" type="button">Header & Footer</button>
          <button class="tab" data-tab="collections" type="button">Collections</button>
          <button class="tab" data-tab="appearance" type="button">Appearance</button>
          <button class="tab" data-tab="analytics" type="button">Analytics</button>
          <button class="tab" data-tab="settings" type="button">Settings</button>
          <?php if ($siteSlug === 'cite-them-right'): ?>
            <button class="tab" data-tab="citations" type="button">Citation DB</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- PAGES -->
      <div class="panel active" id="panel-pages">
      <div class="card" style="margin-top:14px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
          <div>
            <h2 style="margin:0">Pages</h2>
            <div class="muted">Search and filter pages. Templates are starting points only.</div>
          </div>
          <button class="btn primary" type="button" id="addPageBtnTop">Add new page</button>
        </div>
        <div class="row" style="margin-top:12px">
          <div>
            <label class="muted">Search</label>
            <input id="pageSearch" placeholder="Search by title or path…">
          </div>
          <div>
            <label class="muted">Status</label>
            <select id="pageStatusFilter">
              <option value="">All</option>
              <option value="draft">Draft</option>
              <option value="published">Published</option>
            </select>
            <button class="btn text" type="button" id="clearFilters" style="display:none">Clear filters</button>
          </div>
        </div>
        <?php if (!$pages): ?>
          <div style="margin-top:14px">
            <p>No pages yet.</p>
          </div>
        <?php else: ?>
          <table class="page-table">
            <thead>
              <tr style="text-align:left;color:var(--muted);font-size:14px">
                <th>Title</th>
                <th>Path</th>
                <th>Status</th>
                <th>Collection</th>
                <th>Updated</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="pageTable">
              <?php foreach ($pages as $p): ?>
                <?php
                  $colName = '—';
                  if (!empty($p['collection_id'])) {
                    foreach ($collections as $c) {
                      if ((int)$c['id'] === (int)$p['collection_id']) { $colName = Security::e($c['name']); break; }
                    }
                  }
                  $colNameLower = strtolower(trim(strip_tags($colName === '—' ? '' : $colName)));
                ?>
                <tr
                  data-title="<?= Security::e(strtolower($p['title'])) ?>"
                  data-path="<?= Security::e(strtolower($p['slug'])) ?>"
                  data-status="<?= Security::e(strtolower($p['status'])) ?>"
                  data-collection-id="<?= isset($p['collection_id']) ? (int)$p['collection_id'] : 0 ?>"
                  data-collection-name="<?= Security::e($colNameLower) ?>"
                >
                  <td>
                    <div class="title-main">
                      <a href="<?= $base ?>/s/<?= Security::e($site['slug']) ?>/<?= Security::e($p['slug']) ?>" target="_blank" style="color:inherit;text-decoration:none;">
                        <?= Security::e($p['title']) ?>
                      </a>
                    </div>
                    <div class="title-path">/<?= Security::e($p['slug']) ?></div>
                  </td>
                  <td class="muted title-path">/<?= Security::e($p['slug']) ?></td>
                  <td>
                    <?php $st = strtolower($p['status']); ?>
                    <span class="status-badge <?= $st === 'published' ? 'published' : 'draft' ?>">
                      <span class="status-dot" aria-hidden="true"></span>
                      <?= $st === 'published' ? 'Published' : 'Draft' ?>
                    </span>
                  </td>
                  <td class="muted">
                    <?= $colName ?>
                  </td>
                  <td class="muted updated-cell" data-updated="<?= Security::e($p['updated_at'] ?? '') ?>"><?= Security::e($p['updated_at'] ?? '') ?></td>
                  <td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <a class="btn small" style="background:linear-gradient(135deg,var(--primary),var(--primary));color:#fff;border-color:rgba(255,255,255,.12)" href="<?= $base ?>/admin/page_builder.php?id=<?= (int)$p['id'] ?>">Edit</a>
                    <div class="kebab">
                      <button class="kebab-btn" type="button" aria-haspopup="true" aria-expanded="false">⋯</button>
                      <div class="kebab-menu" role="menu">
                        <button type="button" data-duplicate-page data-page-id="<?= (int)$p['id'] ?>" aria-label="Duplicate page">Duplicate</button>
                        <div style="padding:8px;border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
                          <div class="muted" style="font-size:12px;margin-bottom:6px;">Change collection</div>
                          <form method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:grid;gap:6px">
                            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                            <input type="hidden" name="change_collection" value="1">
                            <input type="hidden" name="page_id" value="<?= (int)$p['id'] ?>">
                            <select name="collection_id" style="width:100%;padding:8px;border-radius:10px;border:1px solid var(--border);background:var(--card);color:var(--text);">
                              <option value="0">No collection</option>
                              <?php foreach ($collections as $col): ?>
                                <option value="<?= (int)$col['id'] ?>" <?= ((int)($p['collection_id'] ?? 0) === (int)$col['id']) ? 'selected' : '' ?>><?= Security::e($col['name']) ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button class="btn small" type="submit" aria-label="Save collection">Save</button>
                          </form>
                        </div>
                        <button type="button" class="danger" data-delete-page data-page-id="<?= (int)$p['id'] ?>" data-page-title="<?= Security::e($p['title']) ?>" aria-label="Delete page">Delete</button>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div id="pageEmpty" style="margin-top:10px;display:none" class="muted">No pages match your search.</div>
        <?php endif; ?>
        <div class="actions" style="margin-top:14px">
          <button class="btn primary" type="button" id="addPageBtn">Add new page</button>
        </div>
      </div>
    </div>

      <!-- HEADER & FOOTER -->
      <div class="panel" id="panel-header-footer">
        <div class="card" style="margin-top:14px">
          <h2>Header & Footer</h2>
          <div class="muted">Edit in your IDE. Files below apply site-wide. Slug: <?= Security::e($siteSlug) ?></div>
          <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-top:14px">
            <div class="card">
              <h3>Header</h3>
              <div class="badge <?= $partialStatus['header']==='exists' ? 'ok' : 'warn' ?>"><?= $partialStatus['header']==='exists' ? 'Created' : 'Missing' ?></div>
              <div class="path" id="headerPath"><?= Security::e(str_replace(PartialsManager::projectRoot().'/', '', $partialPaths['header'])) ?></div>
              <p class="muted">Edit this file in VS Code; changes apply site-wide. Includes search form.</p>
              <div class="actions">
                <form method="post" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                  <button class="btn" type="submit" name="ensure_partial" value="header">Ensure header</button>
                </form>
                <button class="btn" data-copy="#headerPath" type="button">Copy path</button>
              </div>
            </div>
            <div class="card">
              <h3>Footer</h3>
              <div class="badge <?= $partialStatus['footer']==='exists' ? 'ok' : 'warn' ?>"><?= $partialStatus['footer']==='exists' ? 'Created' : 'Missing' ?></div>
              <div class="path" id="footerPath"><?= Security::e(str_replace(PartialsManager::projectRoot().'/', '', $partialPaths['footer'])) ?></div>
              <p class="muted">Edit this file in VS Code; changes apply site-wide.</p>
              <div class="actions">
                <form method="post" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                  <button class="btn" type="submit" name="ensure_partial" value="footer">Ensure footer</button>
                </form>
                <button class="btn" data-copy="#footerPath" type="button">Copy path</button>
              </div>
            </div>
            <div class="card">
              <h3>Site assets (optional)</h3>
              <div class="badge <?= ($partialStatus['css']==='exists' || $partialStatus['js']==='exists') ? 'ok' : 'warn' ?>"><?= ($partialStatus['css']==='exists' || $partialStatus['js']==='exists') ? 'Created' : 'Missing' ?></div>
              <div class="path" id="assetsPath"><?= Security::e(str_replace(PartialsManager::projectRoot().'/', '', $partialPaths['css'])) ?> & <?= Security::e(str_replace(PartialsManager::projectRoot().'/', '', $partialPaths['js'])) ?></div>
              <p class="muted">Custom CSS/JS for this site. No raw HTML allowed.</p>
              <div class="actions">
                <form method="post" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                  <button class="btn" type="submit" name="ensure_partial" value="assets">Ensure assets</button>
                </form>
                <button class="btn" data-copy="#assetsPath" type="button">Copy paths</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- COLLECTIONS -->
      <div class="panel" id="panel-collections">
        <div class="card" style="margin-top:14px">
          <div style="display:grid;gap:14px">
            <div class="section" style="margin-top:0">
              <h3>Create a collection</h3>
              <div class="muted">Collections group related pages for listings and navigation.</div>
              <form method="post" style="margin-top:12px;display:grid;gap:10px;">
                <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                <input type="hidden" name="add_collection" value="1">
                <div class="row">
                  <div>
                    <label>Collection name</label>
                    <input name="collection_name" placeholder="e.g. Articles" required>
                  </div>
                  <div>
                    <label>Slug</label>
                    <input name="collection_slug" placeholder="articles" required>
                  </div>
                </div>
                <div class="actions">
                  <button type="submit" class="btn primary">Create collection</button>
                </div>
              </form>
            </div>

            <div class="section" style="background:rgba(255,255,255,0.02);">
              <h3>Existing collections</h3>
              <div class="muted">Use the slug to display pages from this collection in listings or templates.</div>
              <?php if ($collections): ?>
                <table class="collection-table" aria-label="Collections table">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Slug</th>
                      <th>Pages</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($collections as $col): $cid=(int)$col['id']; $count = $collectionUsage[$cid] ?? 0; ?>
                      <tr data-collection-id="<?= $cid ?>" data-collection-name="<?= Security::e($col['name']) ?>">
                        <td>
                          <div class="collection-name"><?= Security::e($col['name']) ?></div>
                        </td>
                        <td><span class="collection-slug"><?= Security::e($col['slug']) ?></span></td>
                        <td>
                          <button class="btn text" type="button" data-view-collection="<?= $cid ?>" style="padding:6px 8px">
                            <?= $count ?> page<?= $count===1 ? '' : 's' ?>
                          </button>
                        </td>
                        <td>
                          <div class="kebab">
                            <button class="kebab-btn" type="button" aria-haspopup="true" aria-expanded="false">⋯</button>
                            <div class="kebab-menu" role="menu">
                              <button type="button" data-view-collection="<?= $cid ?>">View pages</button>
                              <button type="button" class="danger" data-delete-collection="<?= $cid ?>" data-collection-name="<?= Security::e($col['name']) ?>">Delete</button>
                            </div>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <div class="muted" style="display:flex;align-items:center;gap:10px;margin-top:10px">
                  <span style="font-size:20px;">📁</span>
                  <div>No collections yet. Create one to group related pages like Articles or Tutorials.</div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- APPEARANCE -->
      <div class="panel" id="panel-appearance">
        <div class="card" style="margin-top:14px">
          <h2>Appearance</h2>
          <div class="muted">Presets only; no custom code. Applies to all pages using defaults.</div>

          <form method="post" style="margin-top:10px">
            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <input type="hidden" name="save_theme" value="1">
            <div id="appearanceStatus" class="muted" style="margin:6px 0 10px 0;">Changes auto-save on selection.</div>

            <div class="section">
              <h3>Color &amp; Visual Identity</h3>
              <div class="row">
                <div>
                  <label>Page background</label>
                  <select name="c_pageBg">
                    <?php foreach ($colorOpts['pageBg'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['pageBg']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Surface / card background</label>
                  <select name="c_surface">
                    <?php foreach ($colorOpts['surface'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['surface']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Primary color</label>
                  <select name="c_primary">
                    <?php foreach ($colorOpts['primary'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['primary']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Secondary / accent</label>
                  <select name="c_secondary">
                    <?php foreach ($colorOpts['secondary'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['secondary']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Muted / subtle text</label>
                  <select name="c_muted">
                    <?php foreach ($colorOpts['muted'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['muted']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Body text</label>
                  <select name="c_text">
                    <?php foreach ($colorOpts['text'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['text']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Border color</label>
                  <select name="c_border">
                    <?php foreach ($colorOpts['border'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['border']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Divider style</label>
                  <select name="c_divider">
                    <?php foreach ($colorOpts['divider'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['divider']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Focus ring</label>
                  <select name="c_focus">
                    <?php foreach ($colorOpts['focus'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['focus']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Hover state</label>
                  <select name="c_hover">
                    <?php foreach ($colorOpts['hover'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['colors']['hover']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="section">
              <h3>Typography</h3>
              <div class="row">
                <div>
                  <label>Font family</label>
                  <select name="t_fontFamily">
                    <?php foreach ($typoOpts['fontFamily'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= $theme['typography']['fontFamily']===$val ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Base font size</label>
                  <select name="t_baseSize">
                    <?php foreach ($typoOpts['baseSize'] as $val => $label): ?>
                      <option value="<?= (int)$val ?>" <?= ((int)$theme['typography']['baseSize']===(int)$val) ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Heading scale</label>
                  <select name="t_headingScale">
                    <?php foreach ($typoOpts['headingScale'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= ((float)$theme['typography']['headingScale']==(float)$val) ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Font weight</label>
                  <select name="t_fontWeight">
                    <?php foreach ($typoOpts['fontWeight'] as $val => $label): ?>
                      <option value="<?= (int)$val ?>" <?= ((int)$theme['typography']['fontWeight']===(int)$val) ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Line height</label>
                  <select name="t_lineHeight">
                    <?php foreach ($typoOpts['lineHeight'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= ((float)$theme['typography']['lineHeight']==(float)$val) ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Letter spacing</label>
                  <select name="t_letterSpacing">
                    <?php foreach ($typoOpts['letterSpacing'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= ($theme['typography']['letterSpacing']===$val) ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Text rendering</label>
                  <select name="t_rendering">
                    <?php foreach ($typoOpts['rendering'] as $val => $label): ?>
                      <option value="<?= Security::e($val) ?>" <?= ($theme['typography']['rendering']===$val) ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="section">
              <h3>Layout &amp; Spacing</h3>
              <div class="row">
                <div>
                  <label>Page padding / content inset</label>
                  <select name="layout_padding">
                    <?php foreach ($layoutOpts['padding'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= (($theme['layout']['padding'] ?? 'medium') === $k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Max content width</label>
                  <select name="layout_maxwidth">
                    <?php foreach ($layoutOpts['maxWidth'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= (($theme['layout']['maxWidth'] ?? 'standard') === $k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Section spacing</label>
                  <select name="layout_section">
                    <?php foreach ($layoutOpts['sectionSpacing'] as $k=>$label): ?>
                      <option value="<?= (int)$k ?>" <?= ((int)($theme['layout']['sectionSpacing'] ?? 20)==(int)$k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Grid / column gaps</label>
                  <select name="layout_gridgap">
                    <?php foreach ($layoutOpts['gridGap'] as $k=>$label): ?>
                      <option value="<?= (int)$k ?>" <?= ((int)($theme['layout']['gridGap'] ?? 16)==(int)$k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Alignment</label>
                  <select name="layout_align">
                    <?php foreach ($layoutOpts['alignment'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= (($theme['layout']['alignment'] ?? 'left') === $k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Responsive breakpoint</label>
                  <select name="layout_breakpoint">
                    <?php foreach ($layoutOpts['breakpoint'] as $k=>$label): ?>
                      <option value="<?= (int)$k ?>" <?= ((int)($theme['layout']['breakpoint'] ?? 1200)===(int)$k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="section">
              <h3>Shape &amp; Surface</h3>
              <div class="row">
                <div>
                  <label>Default border radius</label>
                  <select name="shape_radius">
                    <?php foreach ($shapeOpts['radius'] as $k=>$label): ?>
                      <option value="<?= (int)$k ?>" <?= ((int)($theme['shape']['radius'] ?? $theme['radius'])===(int)$k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Card elevation / shadow depth</label>
                  <select name="shape_shadow">
                    <?php foreach ($shapeOpts['shadow'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['shape']['shadow'] ?? $themeDefaults['shape']['shadow'])===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Button style</label>
                  <select name="shape_button">
                    <?php foreach ($shapeOpts['buttonStyle'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['shape']['buttonStyle'] ?? 'pill')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Input style</label>
                  <select name="shape_input">
                    <?php foreach ($shapeOpts['inputStyle'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['shape']['inputStyle'] ?? 'rounded')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="section">
              <h3>Media Presentation</h3>
              <div class="row">
                <div>
                  <label>Image aspect ratio</label>
                  <select name="media_ratio">
                    <?php foreach ($mediaOpts['imageRatio'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['media']['imageRatio'] ?? '16:9')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Image border radius</label>
                  <select name="media_radius">
                    <?php foreach ($mediaOpts['imageRadius'] as $k=>$label): ?>
                      <option value="<?= (int)$k ?>" <?= ((int)($theme['media']['imageRadius'] ?? 12)===(int)$k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Video embed style</label>
                  <select name="media_video">
                    <?php foreach ($mediaOpts['videoStyle'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['media']['videoStyle'] ?? 'shadow')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Media max width</label>
                  <select name="media_maxwidth">
                    <?php foreach ($mediaOpts['mediaMaxWidth'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['media']['mediaMaxWidth'] ?? '1200px')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="section">
              <h3>UI Chrome</h3>
              <div class="row">
                <div>
                  <label>Header height / density</label>
                  <select name="chrome_header">
                    <?php foreach ($chromeOpts['headerDensity'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['chrome']['headerDensity'] ?? 'roomy')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Footer spacing</label>
                  <select name="chrome_footer">
                    <?php foreach ($chromeOpts['footerSpacing'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['chrome']['footerSpacing'] ?? 'normal')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row">
                <div>
                  <label>Navigation style</label>
                  <select name="chrome_nav">
                    <?php foreach ($chromeOpts['navStyle'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['chrome']['navStyle'] ?? 'horizontal')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Logo size rules</label>
                  <select name="chrome_logo">
                    <?php foreach ($chromeOpts['logoSize'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['chrome']['logoSize'] ?? 'medium')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Icon sizing / stroke</label>
                  <select name="chrome_icon">
                    <?php foreach ($chromeOpts['iconStroke'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['chrome']['iconStroke'] ?? 'regular')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="section">
              <h3>Motion (Visual only)</h3>
              <div class="row">
                <div>
                  <label>Animation duration</label>
                  <select name="motion_duration">
                    <?php foreach ($motionOpts['duration'] as $k=>$label): ?>
                      <option value="<?= (int)$k ?>" <?= ((int)($theme['motion']['duration'] ?? 220)===(int)$k ? 'selected' : '') ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Transition easing</label>
                  <select name="motion_easing">
                    <?php foreach ($motionOpts['easing'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['motion']['easing'] ?? 'ease-in-out')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label>Reduced motion preference</label>
                  <select name="motion_reduced">
                    <?php foreach ($motionOpts['reduced'] as $k=>$label): ?>
                      <option value="<?= Security::e($k) ?>" <?= ($theme['motion']['reduced'] ?? 'auto')===$k ? 'selected' : '' ?>><?= Security::e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="actions">
              <button type="button" disabled style="opacity:0.7;">Auto-saves on change</button>
            </div>
          </form>
        </div>
      </div>

      <!-- ANALYTICS -->
      <div class="panel" id="panel-analytics">
        <div class="card" style="margin-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
            <div>
              <h2 style="margin:0">Analytics</h2>
              <div class="muted">Per-site traffic, visitors, sessions, and content health.</div>
            </div>
            <div class="trend-badge" id="analyticsTrendBadge" aria-live="polite">Loading…</div>
          </div>

          <form method="post" style="margin-top:12px;border:1px solid var(--border);padding:12px;border-radius:12px;display:grid;gap:10px;">
            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <input type="hidden" name="save_analytics_settings" value="1">
            <div class="row">
              <label style="display:flex;align-items:center;gap:8px;font-weight:700;">
                <input type="checkbox" name="analytics_enabled" value="1" <?= !empty($site['analytics_enabled']) ? 'checked' : '' ?>>
                Enable analytics for this site
              </label>
              <label style="display:flex;align-items:center;gap:8px;font-weight:700;">
                <input type="checkbox" name="analytics_privacy_mode" value="1" <?= !empty($site['analytics_privacy_mode']) ? 'checked' : '' ?>>
                Privacy mode (referrer domain only, coarse UA)
              </label>
            </div>
            <div class="row">
              <div>
                <label>Retention (days)</label>
                <input type="number" min="30" max="720" name="analytics_retention_days" value="<?= (int)($site['analytics_retention_days'] ?? 180) ?>">
                <div class="muted">Raw events pruned after this window; rollups kept longer.</div>
              </div>
              <div>
                <label class="muted">Respect for DNT</label>
                <div class="pill">Tracking skips browsers with Do Not Track enabled.</div>
              </div>
              <div style="display:flex;align-items:flex-end;gap:10px;">
                <button class="btn primary" type="submit">Save settings</button>
              </div>
            </div>
          </form>

          <div class="analytics-controls">
            <div>
              <label class="muted">Quick range</label>
              <div class="actions" style="margin-top:6px">
                <button class="btn small" type="button" data-analytics-range="7d">7 days</button>
                <button class="btn small" type="button" data-analytics-range="30d">30 days</button>
                <button class="btn small" type="button" data-analytics-range="90d">90 days</button>
              </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
              <div>
                <label>Start</label>
                <input type="date" id="analyticsStart">
              </div>
              <div>
                <label>End</label>
                <input type="date" id="analyticsEnd">
              </div>
              <button class="btn" type="button" id="applyAnalyticsRange">Apply</button>
              <button class="btn" type="button" id="exportAnalyticsCsv">Export CSV</button>
            </div>
          </div>

          <div id="analyticsStatus" class="muted" style="margin-top:6px;">Loading…</div>
          <div class="analytics-grid" id="analyticsSummaryGrid" aria-live="polite">
            <div class="analytic-card">
              <div class="label">Page views</div>
              <div class="value" id="metricViews">—</div>
              <div class="delta" id="metricViewsDelta"></div>
            </div>
            <div class="analytic-card">
              <div class="label">Unique visitors</div>
              <div class="value" id="metricUnique">—</div>
              <div class="delta" id="metricUniqueDelta"></div>
            </div>
            <div class="analytic-card">
              <div class="label">Sessions</div>
              <div class="value" id="metricSessions">—</div>
              <div class="delta" id="metricSessionsDelta"></div>
            </div>
            <div class="analytic-card">
              <div class="label">Bounce rate</div>
              <div class="value" id="metricBounce">—</div>
              <div class="delta" id="metricBounceDelta"></div>
            </div>
            <div class="analytic-card">
              <div class="label">Pages / session</div>
              <div class="value" id="metricPagesPerSession">—</div>
              <div class="delta">Engagement</div>
            </div>
            <div class="analytic-card">
              <div class="label">Avg session duration</div>
              <div class="value" id="metricAvgDuration">—</div>
              <div class="delta">Time on site</div>
            </div>
          </div>

          <div class="section" style="margin-top:12px;">
            <h3>Trends</h3>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
              <div>
                <div class="muted">Page views</div>
                <div class="chart-line" id="chartViews"></div>
              </div>
              <div>
                <div class="muted">Unique visitors</div>
                <div class="chart-line" id="chartUniques"></div>
              </div>
            </div>
          </div>

          <div class="section" style="margin-top:12px;">
            <h3>Breakdowns</h3>
            <div class="analytics-breakdown">
              <div>
                <h4 style="margin:0 0 6px 0">Top pages</h4>
                <table class="list-table" id="topPagesTable">
                  <thead><tr><th>Path</th><th>Views</th><th>Uniques</th></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
              <div>
                <h4 style="margin:0 0 6px 0">Top referrers</h4>
                <table class="list-table" id="topReferrersTable">
                  <thead><tr><th>Domain</th><th>Views</th></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
              <div>
                <h4 style="margin:0 0 6px 0">UTM campaigns</h4>
                <table class="list-table" id="topCampaignsTable">
                  <thead><tr><th>Source / Medium / Campaign</th><th>Views</th></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
              <div>
                <h4 style="margin:0 0 6px 0">Device split</h4>
                <table class="list-table" id="deviceSplitTable">
                  <thead><tr><th>Device</th><th>Views</th></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
              <div>
                <h4 style="margin:0 0 6px 0">Browser</h4>
                <table class="list-table" id="browserSplitTable">
                  <thead><tr><th>Browser</th><th>Views</th></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
              <div>
                <h4 style="margin:0 0 6px 0">OS</h4>
                <table class="list-table" id="osSplitTable">
                  <thead><tr><th>OS</th><th>Views</th></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
              <div>
                <h4 style="margin:0 0 6px 0">New vs returning</h4>
                <div id="newReturning" class="pill">Loading…</div>
              </div>
            </div>
          </div>

          <div class="section" style="margin-top:12px;">
            <h3>Content health</h3>
            <div class="analytics-breakdown">
              <div>
                <h4 style="margin:0 0 6px 0">404 / missing pages</h4>
                <table class="list-table" id="fourOhFourTable">
                  <thead><tr><th>Path</th><th>Hits</th></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
              <div>
                <h4 style="margin:0 0 6px 0">Slow pages (client load)</h4>
                <table class="list-table" id="slowPagesTable">
                  <thead><tr><th>Path</th><th>Avg ms</th><th>Samples</th></tr></thead>
                  <tbody></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- SETTINGS -->
      <div class="panel" id="panel-settings">
        <div class="card" style="margin-top:14px">
          <h2>Settings</h2>
          <div class="muted">Follow a GitHub-style settings layout with clear sections.</div>
          <div class="section">
            <h3>Users</h3>
            <p class="muted">Placeholder for managing collaborators and roles.</p>
          </div>
          <div class="section">
            <h3>Maintenance</h3>
            <p class="muted">Placeholder for maintenance mode, backups, and scheduling.</p>
          </div>
          <div class="section">
            <h3>Analytics</h3>
            <p class="muted">Placeholder for analytics IDs, tracking toggles, and dashboards.</p>
          </div>
          <div class="section">
            <h3>Audit logs</h3>
            <p class="muted">Placeholder for recent changes, publish history, and security events.</p>
          </div>
          <div class="section">
            <h3>User summary</h3>
            <p class="muted">Placeholder for user counts, last activity, and roles breakdown.</p>
          </div>
          <div class="section danger">
            <h3>Lifecycle actions (Danger Zone)</h3>
            <p class="muted">Duplicate will clone this site and all pages. Delete will remove everything.</p>
            <div class="actions">
              <form method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="margin:0">
                <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                <button class="btn primary" type="submit" name="duplicate_site" value="1">Duplicate website</button>
              </form>
              <button class="btn danger" type="button" id="deleteSiteBtn">Delete website</button>
            </div>
          </div>
        </div>
      </div>

      <?php if ($siteSlug === 'cite-them-right'): ?>
      <!-- CITATION DATABASE (read-only) -->
      <div class="panel" id="panel-citations">
        <div class="card" style="margin-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
            <div>
              <h2 style="margin:0">Citation database</h2>
              <div class="muted">Manage citation records that power Citation order, Example, and You try blocks.</div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button class="btn primary" type="button" id="openCitationModal">+ Add citation</button>
            </div>
          </div>

          <div class="citation-subtabs" role="tablist" aria-label="Citation DB subtabs">
            <button class="citation-subtab active" data-subtab="entries" type="button">Entries</button>
            <button class="citation-subtab" data-subtab="revisions" type="button">Revisions</button>
            <button class="citation-subtab" data-subtab="releases" type="button">Releases</button>
          </div>
          <div class="citation-searchbar" style="margin:12px 0 16px; display:flex; gap:12px; flex-wrap:wrap;">
            <div style="flex:1; min-width:240px;">
              <label for="revSearch" class="muted" style="display:block;margin-bottom:6px;">Search</label>
              <input id="revSearch" type="search" placeholder="Search by citation title or key" style="width:100%;">
            </div>
            <div style="min-width:180px;">
              <label for="globalStyleFilter" class="muted" style="display:block;margin-bottom:6px;">Referencing style</label>
              <select id="globalStyleFilter" style="width:100%;">
                <option value="">All styles</option>
                <?php foreach ($citationStyles as $style): ?>
                  <option value="<?= Security::e($style) ?>"><?= Security::e($style) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="display:flex;align-items:flex-end">
              <button type="button" class="btn" id="advFiltersToggle">Advanced filters</button>
            </div>
          </div>

          <div class="row citation-panel active" style="margin-top:10px" data-subtab-panel="entries">
            <div style="height:1px"></div>
          </div>

          <!-- Advanced filters container (content switches per tab) -->
          <div id="advFiltersPanel" class="rev-filters" style="display:none; margin:0 0 12px 0;">
            <div id="advFiltersEntries" style="display:none; gap:12px; flex-wrap:wrap;">
              <div class="field" style="min-width:180px;">
                <label for="citationStatusFilter">Status</label>
                <select id="citationStatusFilter">
                  <option value="">All statuses</option>
                  <option value="staged">Staged in current release</option>
                  <option value="edited">Edited (other release)</option>
                  <option value="clean">Clean</option>
                </select>
              </div>
            </div>
            <div id="advFiltersRevs" style="display:none; gap:12px; flex-wrap:wrap;">
              <div class="field" style="min-width:160px;">
                <label for="revIdFilter">Citation ID</label>
                <input id="revIdFilter" type="number" placeholder="e.g. 12" min="1" step="1">
              </div>
              <div class="field">
                <label for="revReleaseFilter">Release tag</label>
                <select id="revReleaseFilter">
                  <option value="">All releases</option>
                  <option value="__unreleased">Unreleased / Not staged</option>
                  <?php foreach ($revTags as $tag): ?>
                    <option value="<?= Security::e($tag) ?>"><?= Security::e($tag) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="revActionFilter">Action</label>
                <select id="revActionFilter">
                  <option value="">All</option>
                  <option value="create">Create</option>
                  <option value="update">Update</option>
                  <option value="delete">Delete</option>
                  <option value="rollback">Rollback</option>
                </select>
              </div>
              <div class="field">
                <label for="revDateRange">Date</label>
                <select id="revDateRange">
                  <option value="">Any time</option>
                  <option value="24h">Last 24 hours</option>
                  <option value="7d">Last 7 days</option>
                  <option value="30d">Last 30 days</option>
                  <option value="custom">Custom range</option>
                </select>
              </div>
              <div class="field" id="revCustomDates" style="display:none;flex-direction:row;gap:8px;align-items:flex-end;">
                <div style="flex:1">
                  <label for="revDateStart">From</label>
                  <input type="date" id="revDateStart">
                </div>
                <div style="flex:1">
                  <label for="revDateEnd">To</label>
                  <input type="date" id="revDateEnd">
                </div>
              </div>
              <?php if ($revUsers): ?>
                <div class="field">
                  <label for="revUserFilter">User</label>
                  <select id="revUserFilter">
                    <option value="">All users</option>
                    <?php foreach ($revUsers as $u): ?>
                      <option value="<?= Security::e($u) ?>"><?= Security::e($u) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="section citation-panel" style="margin-top:12px" data-subtab-panel="releases">
            <?php
              $currentTagRevs = CitationRevision::listByRelease($siteSlug, $currentReleaseTag);
              // Group staged citations by referencing style (unique citation per key)
              $byStyle = [];
              foreach ($currentTagRevs as $r) {
                $after = json_decode($r['after_json'] ?? 'null', true);
                $before = json_decode($r['before_json'] ?? 'null', true);
                $style = $after['referencing_style'] ?? $before['referencing_style'] ?? 'Unknown';
                $label = $after['label'] ?? $before['label'] ?? $r['citation_key'] ?? 'Untitled';
                $key = $r['citation_key'] ?? '';
                $uniq = $style . '|' . $key;
                $byStyle[$style][$uniq] = $label;
              }
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
              <div>
                <h3 style="margin:0">Staged citations by style</h3>
                <div class="muted">Only citations staged for export are shown.</div>
              </div>
              <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                <input type="hidden" name="export_release" value="1">
                <input type="hidden" name="release_tag" value="<?= Security::e($currentReleaseTag) ?>">
                <button class="btn primary" type="submit">Export bundle</button>
              </form>
            </div>
            <?php if ($byStyle): ?>
              <div style="display:grid;gap:12px">
                <?php foreach ($byStyle as $style => $items): ?>
                  <div class="citation-field">
                    <strong><?= Security::e($style) ?></strong>
                    <ul style="margin:6px 0 0 16px; padding:0; list-style:disc;">
                      <?php foreach ($items as $label): ?>
                        <li><?= Security::e($label) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="muted">No staged citations for export.</div>
            <?php endif; ?>
          </div>

          <?php if ($citationExamples): ?>
            <div class="citations-list citation-panel active" id="citationList" data-subtab-panel="entries">
              <table class="citation-table">
                <thead>
                  <tr>
                    <th>Reference type</th>
                    <th>Style</th>
                    <th>Key</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($citationExamples as $ex): 
                    $key = $ex['example_key'] ?? '';
                    $staged = isset($stagedKeys[$key]);
                    $hasRevision = isset($latestByKey[$key]);
                    $statusLabel = 'Clean';
                    $statusTone = 'muted';
                    if ($staged) { $statusLabel = 'Staged in current release'; $statusTone = 'badge-chip staged'; }
                    elseif ($hasRevision) { $statusLabel = 'Edited (other release)'; $statusTone = 'badge-chip'; }
                    $statusValue = $staged ? 'staged' : ($hasRevision ? 'edited' : 'clean');
                  ?>
                    <tr class="citation-row" data-style="<?= Security::e($ex['referencing_style'] ?? '') ?>" data-status="<?= Security::e($statusValue) ?>">
                      <td>
                        <div class="citation-label"><?= Security::e($ex['label'] ?? '') ?></div>
                      </td>
                      <td><span class="citation-style-pill"><?= Security::e($ex['referencing_style'] ?? '') ?></span></td>
                      <td class="muted collection-slug"><?= Security::e($key) ?></td>
                      <td>
                        <span class="<?= $statusTone ?>"><?= Security::e($statusLabel) ?></span>
                      </td>
                      <td style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button
                          class="btn small"
                          type="button"
                          data-view-citation
                          data-style="<?= Security::e($ex['referencing_style'] ?? '') ?>"
                          data-key="<?= Security::e($ex['example_key'] ?? '') ?>"
                          data-label="<?= Security::e($ex['label'] ?? '') ?>"
                          data-order="<?= Security::e($ex['citation_order'] ?? '') ?>"
                          data-heading="<?= Security::e($ex['example_heading'] ?? '') ?>"
                          data-body="<?= Security::e($ex['example_body'] ?? '') ?>"
                          data-youtry="<?= Security::e($ex['you_try'] ?? '') ?>"
                          data-notes="<?= Security::e($ex['notes'] ?? '') ?>"
                          data-id="<?= (int)($ex['id'] ?? 0) ?>"
                        >View</button>
                        <form method="post" style="margin:0">
                          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                          <input type="hidden" name="delete_citation" value="1">
                          <input type="hidden" name="citation_id" value="<?= (int)($ex['id'] ?? 0) ?>">
                          <button class="btn danger" type="submit" onclick="return confirm('Delete this citation?')">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="section citation-panel" style="margin-top:14px" data-subtab-panel="revisions">
              <h3>Revisions (latest 100)</h3>
              <?php
                $revStyles = [];
                $revUsers = [];
                $revTags = [];
                foreach ($citationRevisions as $r) {
                  $after = json_decode($r['after_json'] ?? 'null', true);
                  $before = json_decode($r['before_json'] ?? 'null', true);
                  $style = $after['referencing_style'] ?? $before['referencing_style'] ?? '';
                  if ($style) $revStyles[$style] = true;
                  $tag = $r['release_tag'] ?? '';
                  if ($tag !== '') $revTags[$tag] = true;
                  $userEmail = $r['user_email'] ?? '';
                  if ($userEmail) $revUsers[$userEmail] = true;
                }
                $revStyles = array_keys($revStyles);
                sort($revStyles);
                $revTags = array_keys($revTags);
                usort($revTags, 'version_compare');
                $revTags = array_reverse($revTags);
                $revUsers = array_keys($revUsers);
                sort($revUsers);
              ?>
              <?php if ($citationRevisions): ?>
                <div class="rev-filters" id="revFilters"></div>
                <div style="overflow:auto">
                  <table class="collection-table" aria-label="Citation revisions" id="revisionTable">
                    <thead>
                      <tr>
                        <th>Citation ID</th>
                        <th>Action</th>
                        <th>Citation</th>
                        <th>Referencing style</th>
                        <th>Release tag</th>
                        <th>User</th>
                        <th>When</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($citationRevisions as $rev):
                        $after = json_decode($rev['after_json'] ?? 'null', true);
                        $before = json_decode($rev['before_json'] ?? 'null', true);
                        $label = $after['label'] ?? $before['label'] ?? $rev['citation_key'];
                        $style = $after['referencing_style'] ?? $before['referencing_style'] ?? '';
                        $key = $rev['citation_key'] ?? '';
                        $releaseTag = $rev['release_tag'] ?? '';
                        $userEmail = $rev['user_email'] ?? '—';
                        $created = $rev['created_at'] ?? '';
                      ?>
                        <tr
                          class="revision-row"
                          data-revision-row
                          data-id="<?= (int)$rev['id'] ?>"
                          data-key="<?= Security::e($key) ?>"
                          data-label="<?= Security::e($label) ?>"
                          data-citation-id="<?= (int)($rev['citation_id'] ?? 0) ?>"
                          data-style="<?= Security::e($style) ?>"
                          data-release="<?= Security::e($releaseTag) ?>"
                          data-action="<?= Security::e(strtolower($rev['action'] ?? '')) ?>"
                          data-user="<?= Security::e($userEmail) ?>"
                          data-date="<?= Security::e($created) ?>"
                          data-after="<?= Security::e($rev['after_json'] ?? '') ?>"
                          data-before="<?= Security::e($rev['before_json'] ?? '') ?>"
                        >
                          <td class="muted">#<?= (int)($rev['citation_id'] ?? 0) ?></td>
                          <td><?= Security::e($rev['action']) ?></td>
                          <td>
                            <div class="collection-name"><?= Security::e($label) ?></div>
                          </td>
                          <td class="muted"><?= Security::e($style) ?></td>
                          <td class="muted"><?= Security::e($releaseTag ?: '—') ?></td>
                          <td class="muted"><?= Security::e($userEmail) ?></td>
                          <td class="muted"><?= Security::e($created) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="rev-no-results" id="revNoResults" style="display:none;">No revisions match your filters.</div>
              <?php else: ?>
                <div class="muted">No revisions yet.</div>
              <?php endif; ?>
            </div>
            <div class="section citation-panel" style="margin-top:14px" data-subtab-panel="releases">
              <h3>Release details (<?= Security::e($currentReleaseTag) ?>)</h3>
              <?php
                $currentTagRevs = CitationRevision::listByRelease($siteSlug, $currentReleaseTag);
              ?>
              <?php if ($currentTagRevs): ?>
                <div class="citation-field">
                  <strong>Staged revisions</strong>
                  <div style="display:grid;gap:6px;margin-top:6px">
                    <?php foreach ($currentTagRevs as $r): ?>
                      <div class="citation-field" style="padding:6px 8px;background:rgba(255,255,255,0.02);">
                        <div class="collection-name">#<?= (int)$r['id'] ?> — <?= Security::e($r['action']) ?> — <?= Security::e($r['citation_key']) ?></div>
                        <div class="muted" style="font-size:12px;display:flex;gap:10px;flex-wrap:wrap;">
                          <span><?= Security::e($r['user_email'] ?? '—') ?></span>
                          <span><?= Security::e($r['created_at'] ?? '') ?></span>
                          <span>Release: <?= Security::e($r['release_tag'] ?? '') ?></span>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="citation-field">
                  <strong>Net effect</strong>
                  <?php if ($netEffects): ?>
                    <div style="display:grid;gap:6px;margin-top:6px">
                      <?php foreach ($netEffects as $key => $state): ?>
                        <div class="citation-field" style="padding:6px 8px;">
                          <div class="collection-name"><?= Security::e($key) ?></div>
                          <?php if ($state): ?>
                            <div class="muted" style="white-space:pre-line"><?= Security::e($state['label'] ?? '') ?></div>
                          <?php else: ?>
                            <div class="muted">Deleted</div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="muted" style="margin-top:4px">No net changes.</div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="muted">No staged revisions for this release.</div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="muted" style="margin-top:12px;display:flex;align-items:center;gap:10px">
              <span style="font-size:20px">📚</span>
              <div>No citation entries found. Populate via SQL seed file.</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </main>

  <form id="modalCreateForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="create_modal_page" value="1">
    <input type="hidden" name="modal_title" id="modal_title_field">
    <input type="hidden" name="modal_slug" id="modal_slug_field">
    <input type="hidden" name="modal_layout" id="modal_layout_field">
    <input type="hidden" name="modal_collection" id="modal_collection_field">
  </form>
  <form id="deletePageForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="delete_page" value="1">
    <input type="hidden" name="page_id" id="delete_page_id">
  </form>
  <form id="duplicatePageForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="duplicate_page" id="duplicate_page_id" value="0">
  </form>
  <form id="deleteCollectionForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="delete_collection" value="1">
    <input type="hidden" name="collection_id" id="delete_collection_id">
  </form>
  <form id="deleteSiteForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="delete_site" value="1">
  </form>

  <div class="modal-backdrop" id="citationModalBackdrop" style="display:none">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="citationModalTitle">
      <header style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
        <div>
          <h3 id="citationModalTitle" style="margin:0">Add citation</h3>
          <div class="muted" style="font-size:13px">Reference type, formatting, and “You try” guidance in one place.</div>
        </div>
        <button type="button" class="close-btn" id="closeCitationModal" aria-label="Close">×</button>
      </header>
      <form method="post" id="citationModalForm" style="display:grid;gap:12px">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
        <input type="hidden" name="add_citation" id="citationActionAdd" value="1">
        <input type="hidden" name="update_citation" id="citationActionUpdate" value="">
        <input type="hidden" name="citation_id" id="citationIdField" value="">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
          <label class="citation-field" style="display:block">
            <strong>Referencing style</strong>
            <select name="citation_style" id="citationStyleField" style="margin-top:6px;width:100%">
              <?php foreach ($citationStyles as $style): ?>
                <option value="<?= Security::e($style) ?>"><?= Security::e($style) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="citation-field" style="display:block">
            <strong>Key (unique)</strong>
            <input name="citation_key" id="citationKeyField" placeholder="book_one_author" required style="margin-top:6px;width:100%">
            <div class="muted" style="font-size:12px;margin-top:4px">Stable identifier used in templates.</div>
          </label>
          <label class="citation-field" style="display:block">
            <strong>Label</strong>
            <input name="citation_label" id="citationLabelField" placeholder="Book with one author" required style="margin-top:6px;width:100%">
          </label>
        </div>

        <div class="citation-field" style="display:grid;gap:8px">
          <strong>Citation order</strong>
          <textarea name="citation_order" id="citationOrderField" rows="3" placeholder="• Author/editor&#10;• Year..." required style="width:100%"></textarea>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px">
          <label class="citation-field" style="display:block">
            <strong>Example heading</strong>
            <input name="citation_heading" id="citationHeadingField" placeholder="Example: book with one author" required style="margin-top:6px;width:100%">
            <div class="muted" style="font-size:12px;margin-top:4px">Short descriptor shown above the example.</div>
          </label>
          <label class="citation-field" style="display:block">
            <strong>Example body</strong>
            <textarea name="citation_body" id="citationBodyField" rows="4" placeholder="In-text citations&#10;... Reference list" required style="margin-top:6px;width:100%"></textarea>
          </label>
          <label class="citation-field" style="display:block">
            <strong>You try</strong>
            <textarea name="citation_youtry" id="citationYouTryField" rows="4" placeholder="Surname, Initial. (Year)..." required style="margin-top:6px;width:100%"></textarea>
          </label>
        </div>

        <div class="citation-field" style="display:block">
          <strong>Editorial notes (optional)</strong>
          <textarea name="citation_notes" id="citationNotesField" rows="3" placeholder="House style notes, reminders…" style="margin-top:6px;width:100%"></textarea>
        </div>

        <div class="actions" style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap">
          <button class="btn" type="button" id="cancelCitationModal">Cancel</button>
          <button class="btn primary" type="submit" id="citationSubmitBtn">Add citation</button>
        </div>
      </form>
    </div>
  </div>

  <aside class="cite-viewer" id="citationViewer" aria-label="Citation details">
    <header>
      <div>
        <div class="citation-label" id="viewLabel">Citation</div>
        <div class="muted" id="viewSubtitle" style="font-size:12px;">Read-only view</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="cite-readonly-badge" id="viewBadge">Read-only</span>
        <button class="close-btn" type="button" id="closeCitationViewer" aria-label="Close">×</button>
      </div>
    </header>
    <div class="actions-bar" id="viewActions">
      <button class="btn primary" type="button" id="viewerEdit">Edit citation</button>
      <button class="btn" type="button" id="viewerRevisions">View revisions</button>
    </div>
    <main id="viewBody" class="viewer-body">
      <div class="citation-field">
        <strong>Style</strong>
        <div id="viewStyle" class="muted">—</div>
      </div>
      <div class="citation-field">
        <strong>ID</strong>
        <div id="viewId" class="muted">—</div>
      </div>
      <div class="citation-field">
        <strong>Key</strong>
        <div id="viewKey" class="collection-slug">—</div>
      </div>
      <div class="citation-field">
        <strong>Citation order</strong>
        <div id="viewOrder" class="muted" style="white-space:pre-line">—</div>
      </div>
      <div class="citation-field">
        <strong>Example</strong>
        <div id="viewExampleHeading" class="collection-name">—</div>
        <div id="viewExampleBody" class="muted" style="white-space:pre-line">—</div>
      </div>
      <div class="citation-field">
        <strong>You try</strong>
        <div id="viewYouTry" class="muted" style="white-space:pre-line">—</div>
      </div>
      <div class="citation-field">
        <strong>Editorial notes</strong>
        <div id="viewNotes" class="muted" style="white-space:pre-line">—</div>
      </div>
    </main>

    <form id="editBody" class="viewer-body edit-body" style="display:none;" method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="update_citation" value="1">
      <input type="hidden" name="citation_id" id="editIdField">
      <input type="hidden" name="citation_style" id="editStyleField">
      <input type="hidden" name="citation_key" id="editKeyField">
      <div class="citation-field citation-edit-field">
        <strong>Label</strong>
        <input name="citation_label" id="editLabelField" required>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>Citation order</strong>
        <textarea name="citation_order" id="editOrderField" rows="4" required></textarea>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>Example heading</strong>
        <input name="citation_heading" id="editHeadingField" required>
        <strong style="margin-top:12px;display:block;">Example body</strong>
        <textarea name="citation_body" id="editBodyField" rows="4" required></textarea>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>You try</strong>
        <textarea name="citation_youtry" id="editYouTryField" rows="4" required></textarea>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>Editorial notes</strong>
        <textarea name="citation_notes" id="editNotesField" rows="3"></textarea>
      </div>
    </form>
    <footer id="editFooter" style="display:none;">
      <button class="btn" type="button" id="editCancel">Cancel</button>
      <button class="btn primary" type="submit" form="editBody">Save changes</button>
    </footer>
  </aside>

  <aside class="cite-viewer" id="revisionViewer" aria-label="Revision details">
    <header>
      <div>
        <div class="citation-label" id="revViewLabel">Revision</div>
        <div class="muted" id="revViewSubtitle" style="font-size:12px;">Audit trail</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="cite-readonly-badge" id="revViewBadge">Read-only</span>
        <button class="close-btn" type="button" id="closeRevisionViewer" aria-label="Close">×</button>
      </div>
    </header>
    <div class="actions-bar" id="revActions">
      <div class="pill-muted" id="revActionPill">—</div>
      <div class="pill-muted" id="revReleasePill">Release: —</div>
    </div>
    <main class="viewer-body" id="revViewBody">
      <div class="citation-field">
        <strong>Citation</strong>
        <div class="collection-name" id="revCitationLabel">—</div>
        <div class="muted collection-slug" id="revCitationKey">—</div>
        <div class="muted" id="revCitationStyle">—</div>
      </div>
      <div class="citation-field">
        <strong>Metadata</strong>
        <div class="muted" id="revMetaUser">User: —</div>
        <div class="muted" id="revMetaTime">When: —</div>
      </div>
      <div class="citation-field">
        <strong>Resulting state</strong>
        <div class="callout" id="revAfterBlock">
          <div><strong>Label</strong><div id="revAfterLabel" class="muted">—</div></div>
          <div style="margin-top:8px"><strong>Citation order</strong><div id="revAfterOrder" class="muted" style="white-space:pre-line">—</div></div>
          <div style="margin-top:8px"><strong>Example heading</strong><div id="revAfterHeading" class="muted">—</div></div>
          <div style="margin-top:8px"><strong>Example body</strong><div id="revAfterBody" class="muted" style="white-space:pre-line">—</div></div>
          <div style="margin-top:8px"><strong>You try</strong><div id="revAfterYouTry" class="muted" style="white-space:pre-line">—</div></div>
          <div style="margin-top:8px"><strong>Editorial notes</strong><div id="revAfterNotes" class="muted" style="white-space:pre-line">—</div></div>
        </div>
      </div>
      <div class="citation-field">
        <strong>Prior state</strong>
        <div class="callout" id="revBeforeBlock">
          <div><strong>Label</strong><div id="revBeforeLabel" class="muted">—</div></div>
          <div style="margin-top:8px"><strong>Citation order</strong><div id="revBeforeOrder" class="muted" style="white-space:pre-line">—</div></div>
          <div style="margin-top:8px"><strong>Example heading</strong><div id="revBeforeHeading" class="muted">—</div></div>
          <div style="margin-top:8px"><strong>Example body</strong><div id="revBeforeBody" class="muted" style="white-space:pre-line">—</div></div>
          <div style="margin-top:8px"><strong>You try</strong><div id="revBeforeYouTry" class="muted" style="white-space:pre-line">—</div></div>
          <div style="margin-top:8px"><strong>Editorial notes</strong><div id="revBeforeNotes" class="muted" style="white-space:pre-line">—</div></div>
        </div>
      </div>
    </main>
    <footer>
      <form method="post" style="display:flex;gap:8px;align-items:center;margin:0" id="revRestoreForm">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
        <input type="hidden" name="rollback_citation" value="1">
        <input type="hidden" name="revision_id" id="revRestoreId" value="">
        <button class="btn" type="button" id="revCloseBtn">Close</button>
        <button class="btn primary" type="submit" onclick="return confirm('Restore this revision and stage it?')">Restore</button>
      </form>
    </footer>
  </aside>

  <script>
    (function(){
      const themeBtn = document.getElementById('themeToggleBtn');
      function setTheme(mode){
        if(mode==='light'){
          document.documentElement.classList.add('theme-light');
          if(themeBtn) themeBtn.textContent='🌙 Switch to dark';
        } else {
          document.documentElement.classList.remove('theme-light');
          if(themeBtn) themeBtn.textContent='☀️ Switch to light';
        }
        try{localStorage.setItem('nexusTheme', mode);}catch(e){}
      }
      if(themeBtn){
        const stored = (()=>{try{return localStorage.getItem('nexusTheme');}catch(e){return null;}})();
        if(stored==='light') setTheme('light'); else setTheme('dark');
        themeBtn.addEventListener('click',()=>{
          const isLight=document.documentElement.classList.contains('theme-light');
          setTheme(isLight?'dark':'light');
        });
      }
    })();
    const basePath = <?= json_encode($base) ?>;
    
    // tabs with hash support
    const tabs = Array.from(document.querySelectorAll('.tab'));
    const panels = Array.from(document.querySelectorAll('.panel'));
    function activate(tabName) {
      tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
      panels.forEach(p => p.classList.toggle('active', p.id === 'panel-' + tabName));
      history.replaceState(null, '', '#' + tabName);
    }
    tabs.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.tab)));
    let initialHash = location.hash.replace('#','');
    if (initialHash === 'theme') initialHash = 'appearance';
    if (initialHash && document.getElementById('panel-' + initialHash)) activate(initialHash);

    // page search/filter
    const searchInput = document.getElementById('pageSearch');
    const statusFilter = document.getElementById('pageStatusFilter');
    const rows = Array.from(document.querySelectorAll('#pageTable tr'));
    const empty = document.getElementById('pageEmpty');
    const clearFilters = document.getElementById('clearFilters');
    let collectionFilter = '';
    const collectionFilterBadge = document.getElementById('collectionFilterBadge');
    function applyPageFilters() {
      const q = (searchInput?.value || '').toLowerCase();
      const st = (statusFilter?.value || '').toLowerCase();
      let visible = 0;
      rows.forEach(r => {
        const matchesSearch = (!q || r.dataset.title.includes(q) || r.dataset.path.includes(q) || (r.dataset.collectionName || '').includes(q));
        const matchesStatus = (!st || r.dataset.status === st);
        const matchesCollection = (!collectionFilter || r.dataset.collectionId === collectionFilter);
        const match = matchesSearch && matchesStatus && matchesCollection;
        r.style.display = match ? '' : 'none';
        if (match) visible++;
      });
      if (empty) empty.style.display = visible ? 'none' : '';
      if (clearFilters) clearFilters.style.display = (q || st) ? 'inline-flex' : 'none';
      if (collectionFilterBadge) collectionFilterBadge.style.display = collectionFilter ? 'inline-flex' : 'none';
      if (collectionFilterBadge && collectionFilter) {
        const name = rows.find(r => r.dataset.collectionId === collectionFilter)?.dataset.collectionName || 'Collection';
        collectionFilterBadge.querySelector('.badge-label').textContent = name;
      }
    }
    searchInput?.addEventListener('input', applyPageFilters);
    statusFilter?.addEventListener('change', applyPageFilters);
    clearFilters?.addEventListener('click', () => {
      if (searchInput) searchInput.value = '';
      if (statusFilter) statusFilter.value = '';
      collectionFilter = '';
      applyPageFilters();
    });
    applyPageFilters();

    // Analytics dashboard
    const analyticsSiteId = <?= (int)$site['id'] ?>;
    const analyticsStatus = document.getElementById('analyticsStatus');
    const analyticsBadge = document.getElementById('analyticsTrendBadge');
    const analyticsStart = document.getElementById('analyticsStart');
    const analyticsEnd = document.getElementById('analyticsEnd');
    const analyticsRangeBtns = Array.from(document.querySelectorAll('[data-analytics-range]'));
    const applyAnalyticsBtn = document.getElementById('applyAnalyticsRange');
    const exportAnalyticsBtn = document.getElementById('exportAnalyticsCsv');
    let analyticsRange = '7d';

    const fmtNumber = (n) => new Intl.NumberFormat('en-US').format(n || 0);
    const fmtDuration = (seconds) => {
      const s = Math.max(0, parseInt(seconds || 0, 10));
      const m = Math.floor(s / 60);
      const rem = s % 60;
      return (m ? m + 'm ' : '') + rem + 's';
    };
    const setStatus = (msg) => { if (analyticsStatus) analyticsStatus.textContent = msg; };

    const fillTable = (tableId, rows, cols) => {
      const table = document.getElementById(tableId);
      if (!table) return;
      const body = table.querySelector('tbody');
      if (!body) return;
      body.innerHTML = '';
      if (!rows || !rows.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = cols.length;
        td.className = 'muted';
        td.textContent = 'No data for this range.';
        tr.appendChild(td);
        body.appendChild(tr);
        return;
      }
      rows.forEach(r => {
        const tr = document.createElement('tr');
        cols.forEach(c => {
          const td = document.createElement('td');
          td.textContent = r[c] !== undefined ? r[c] : '—';
          tr.appendChild(td);
        });
        body.appendChild(tr);
      });
    };

    const renderChart = (elId, points) => {
      const el = document.getElementById(elId);
      if (!el) return;
      el.innerHTML = '';
      if (!points || !points.length) {
        el.innerHTML = '<div class="muted">No data</div>';
        return;
      }
      const max = Math.max(...points.map(p => p.value || 0)) || 1;
      points.forEach(p => {
        const bar = document.createElement('span');
        bar.style.height = Math.max(2, Math.round((p.value / max) * 52)) + 'px';
        bar.title = `${p.day}: ${p.value}`;
        el.appendChild(bar);
      });
    };

    const renderAnalytics = (data) => {
      if (!data) return;
      const summary = data.summary || {};
      const comparison = (data.period && data.period.comparison) ? data.period.comparison : {views:0,sessions:0};
      const viewsDelta = summary.views - (comparison.views || 0);
      const sessionsDelta = summary.sessions - (comparison.sessions || 0);
      const bounceRate = summary.sessions ? Math.round((summary.bounces / summary.sessions) * 10000) / 100 : 0;

      const setVal = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
      setVal('metricViews', fmtNumber(summary.views));
      setVal('metricViewsDelta', (viewsDelta >= 0 ? '▲ ' : '▼ ') + fmtNumber(Math.abs(viewsDelta)) + ' vs prior');
      setVal('metricUnique', fmtNumber(summary.unique));
      setVal('metricSessions', fmtNumber(summary.sessions));
      setVal('metricSessionsDelta', (sessionsDelta >= 0 ? '▲ ' : '▼ ') + fmtNumber(Math.abs(sessionsDelta)) + ' vs prior');
      setVal('metricBounce', summary.sessions ? bounceRate.toFixed(1) + '%' : '—');
      setVal('metricBounceDelta', summary.sessions ? (summary.bounces || 0) + ' bounces' : '');
      setVal('metricPagesPerSession', summary.pages_per_session ? summary.pages_per_session.toFixed(2) : '0.00');
      setVal('metricAvgDuration', fmtDuration(summary.avg_session_seconds));

      if (analyticsBadge) {
        const pct = comparison.views > 0 ? Math.round((viewsDelta / comparison.views) * 1000) / 10 : (summary.views > 0 ? 100 : 0);
        analyticsBadge.textContent = `${fmtNumber(summary.views)} views • ${pct >= 0 ? '▲' : '▼'} ${Math.abs(pct)}% vs prior`;
      }

      renderChart('chartViews', data.trend?.views || []);
      renderChart('chartUniques', data.trend?.unique || []);

      fillTable('topPagesTable', (data.breakdowns?.pages || []).map(r => ({path:r.path || '/', views: r.views ?? 0, uniq: r.uniq ?? r.unique ?? 0})), ['path','views','uniq']);
      fillTable('topReferrersTable', (data.breakdowns?.referrers || []).map(r => ({domain: r.referrer || r.referrer_domain || '(direct)', views: r.views ?? 0})), ['domain','views']);
      fillTable('topCampaignsTable', (data.breakdowns?.campaigns || []).map(r => ({campaign: r.campaign || '(unspecified)', views: r.views ?? 0})), ['campaign','views']);
      fillTable('deviceSplitTable', (data.breakdowns?.devices || []).map(r => ({device: r.label || r.device || 'Unknown', views: r.views ?? 0})), ['device','views']);
      fillTable('browserSplitTable', (data.breakdowns?.browsers || []).map(r => ({browser: r.label || r.browser || 'Unknown', views: r.views ?? 0})), ['browser','views']);
      fillTable('osSplitTable', (data.breakdowns?.oses || []).map(r => ({os: r.label || r.os || 'Unknown', views: r.views ?? 0})), ['os','views']);
      fillTable('fourOhFourTable', (data.breakdowns?.four_oh_four || []).map(r => ({path: r.path || '', hits: r.hits ?? 0})), ['path','hits']);
      fillTable('slowPagesTable', (data.breakdowns?.slow_pages || []).map(r => ({path: r.path || '', load_ms: r.load_ms ?? 0, samples: r.samples ?? 0})), ['path','load_ms','samples']);

      const nr = data.breakdowns?.new_vs_returning || {};
      const nrEl = document.getElementById('newReturning');
      if (nrEl) nrEl.textContent = `New ${fmtNumber(nr.new || 0)} / Returning ${fmtNumber(nr.returning || 0)}`;
    };

    const loadAnalytics = async () => {
      if (!analyticsStatus) return;
      setStatus('Loading…');
      try {
        const url = new URL((basePath || '') + '/api/analytics/dashboard', window.location.origin);
        url.searchParams.set('site_id', analyticsSiteId);
        if (analyticsRange !== 'custom') url.searchParams.set('range', analyticsRange);
        if (analyticsStart?.value) url.searchParams.set('start', analyticsStart.value);
        if (analyticsEnd?.value) url.searchParams.set('end', analyticsEnd.value);
        const res = await fetch(url.toString(), {credentials:'same-origin'});
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Unable to load analytics');
        renderAnalytics(json.data);
        setStatus('Updated ' + new Date().toLocaleTimeString());
      } catch (err) {
        setStatus(err.message || 'Unable to load analytics');
      }
    };

    const todayIso = new Date().toISOString().slice(0,10);
    const weekAgoIso = new Date(Date.now() - 6*864e5).toISOString().slice(0,10);
    if (analyticsStart && !analyticsStart.value) analyticsStart.value = weekAgoIso;
    if (analyticsEnd && !analyticsEnd.value) analyticsEnd.value = todayIso;
    if (analyticsRangeBtns[0]) analyticsRangeBtns[0].classList.add('primary');

    analyticsRangeBtns.forEach(btn => btn.addEventListener('click', () => {
      analyticsRangeBtns.forEach(b => b.classList.remove('primary'));
      btn.classList.add('primary');
      analyticsRange = btn.dataset.analyticsRange || '7d';
      loadAnalytics();
    }));

    applyAnalyticsBtn?.addEventListener('click', () => {
      analyticsRange = 'custom';
      analyticsRangeBtns.forEach(b => b.classList.remove('primary'));
      loadAnalytics();
    });

    exportAnalyticsBtn?.addEventListener('click', () => {
      const start = analyticsStart?.value || '';
      const end = analyticsEnd?.value || '';
      const url = new URL((basePath || '') + '/api/analytics/export', window.location.origin);
      url.searchParams.set('site_id', analyticsSiteId);
      if (start) url.searchParams.set('start', start);
      if (end) url.searchParams.set('end', end);
      url.searchParams.set('report', 'pages');
      window.location = url.toString();
    });

    if (document.getElementById('panel-analytics')) {
      loadAnalytics();
    }

    // Citation subtabs
    const subtabButtons = Array.from(document.querySelectorAll('.citation-subtab'));
    const subtabPanels = Array.from(document.querySelectorAll('[data-subtab-panel]'));
    const advPanel = document.getElementById('advFiltersPanel');
    const advEntries = document.getElementById('advFiltersEntries');
    const advRevs = document.getElementById('advFiltersRevs');
    const advToggleBtn = document.getElementById('advFiltersToggle');
    const showSubtab = (name) => {
      subtabButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.subtab === name));
      subtabPanels.forEach(p => {
        const isMatch = p.dataset.subtabPanel === name;
        p.classList.toggle('active', isMatch);
      });
      if (advPanel && advEntries && advRevs) {
        const isEntries = name === 'entries';
        const isRevs = name === 'revisions';
        advPanel.style.display = (isEntries || isRevs) ? 'flex' : 'none';
        advEntries.style.display = isEntries ? 'flex' : 'none';
        advRevs.style.display = isRevs ? 'flex' : 'none';
      }
      try { localStorage.setItem('citationSubtab', name); } catch(e){}
    };
    subtabButtons.forEach(btn => btn.addEventListener('click', () => showSubtab(btn.dataset.subtab)));
    const storedSubtab = (()=>{try{return localStorage.getItem('citationSubtab');}catch(e){return null;}})();
    if (storedSubtab && subtabButtons.find(b => b.dataset.subtab === storedSubtab)) showSubtab(storedSubtab);
    else showSubtab('entries');

    // Citation modal
    const citationBackdrop = document.getElementById('citationModalBackdrop');
    const openCitationModal = document.getElementById('openCitationModal');
    const closeCitationModal = document.getElementById('closeCitationModal');
    const cancelCitationModal = document.getElementById('cancelCitationModal');
    const citationTitle = document.getElementById('citationModalTitle');
    const citationActionAdd = document.getElementById('citationActionAdd');
    const citationActionUpdate = document.getElementById('citationActionUpdate');
    const citationSubmitBtn = document.getElementById('citationSubmitBtn');
    const citationIdField = document.getElementById('citationIdField');
    const citationStyleField = document.getElementById('citationStyleField');
    const citationKeyField = document.getElementById('citationKeyField');
    const citationLabelField = document.getElementById('citationLabelField');
    const citationOrderField = document.getElementById('citationOrderField');
    const citationHeadingField = document.getElementById('citationHeadingField');
    const citationBodyField = document.getElementById('citationBodyField');
    const citationYouTryField = document.getElementById('citationYouTryField');
    const citationNotesField = document.getElementById('citationNotesField');
    const resetCitationForm = () => {
      if (citationTitle) citationTitle.textContent = 'Add citation';
      if (citationActionAdd) citationActionAdd.value = '1';
      if (citationActionUpdate) citationActionUpdate.value = '';
      if (citationSubmitBtn) citationSubmitBtn.textContent = 'Add citation';
      if (citationIdField) citationIdField.value = '';
      if (citationStyleField) citationStyleField.value = citationStyleField.options?.[0]?.value || '';
      if (citationKeyField) citationKeyField.value = '';
      if (citationLabelField) citationLabelField.value = '';
      if (citationOrderField) citationOrderField.value = '';
      if (citationHeadingField) citationHeadingField.value = '';
      if (citationBodyField) citationBodyField.value = '';
      if (citationYouTryField) citationYouTryField.value = '';
      if (citationNotesField) citationNotesField.value = '';
    };
    const showCitationModal = () => { if (citationBackdrop) citationBackdrop.style.display = 'flex'; };
    const hideCitationModal = () => { if (citationBackdrop) citationBackdrop.style.display = 'none'; };
    openCitationModal?.addEventListener('click', () => { resetCitationForm(); showCitationModal(); });
    closeCitationModal?.addEventListener('click', hideCitationModal);
    cancelCitationModal?.addEventListener('click', hideCitationModal);
    citationBackdrop?.addEventListener('click', (e) => { if (e.target === citationBackdrop) hideCitationModal(); });
    document.querySelectorAll('[data-edit-citation]').forEach(btn => {
      btn.addEventListener('click', () => {
        resetCitationForm();
        if (citationTitle) citationTitle.textContent = 'Edit citation';
        if (citationActionAdd) citationActionAdd.value = '';
        if (citationActionUpdate) citationActionUpdate.value = '1';
        if (citationSubmitBtn) citationSubmitBtn.textContent = 'Save changes';
        const get = (attr) => btn.getAttribute(attr) || '';
        if (citationIdField) citationIdField.value = get('data-id');
        if (citationStyleField) citationStyleField.value = get('data-style');
        if (citationKeyField) citationKeyField.value = get('data-key');
        if (citationLabelField) citationLabelField.value = get('data-label');
        if (citationOrderField) citationOrderField.value = get('data-order');
        if (citationHeadingField) citationHeadingField.value = get('data-heading');
        if (citationBodyField) citationBodyField.value = get('data-body');
        if (citationYouTryField) citationYouTryField.value = get('data-youtry');
        if (citationNotesField) citationNotesField.value = get('data-notes');
        showCitationModal();
      });
    });

    // Citation view/edit drawer
    (function(){
      const viewer = document.getElementById('citationViewer');
      if (!viewer) return;
      const viewerClose = document.getElementById('closeCitationViewer');
      const viewerEdit = document.getElementById('viewerEdit');
      const viewerRevisions = document.getElementById('viewerRevisions');
      const viewBody = document.getElementById('viewBody');
      const editBody = document.getElementById('editBody');
      const editFooter = document.getElementById('editFooter');
      const editIdField = document.getElementById('editIdField');
      const editStyleField = document.getElementById('editStyleField');
      const editLabelField = document.getElementById('editLabelField');
      const editOrderField = document.getElementById('editOrderField');
      const editHeadingField = document.getElementById('editHeadingField');
      const editBodyField = document.getElementById('editBodyField');
      const editYouTryField = document.getElementById('editYouTryField');
      const editNotesField = document.getElementById('editNotesField');
      const autoGrow = (el) => {
        if (!el) return;
        el.style.height = 'auto';
        const h = el.scrollHeight > 0 ? el.scrollHeight : el.getBoundingClientRect().height;
        el.style.height = (h + 2) + 'px';
      };
      const autoGrowAll = () => {
        [editLabelField, editOrderField, editHeadingField, editBodyField, editYouTryField, editNotesField].forEach(el => {
          if (el && (el.tagName === 'TEXTAREA' || el.dataset.autogrow === '1')) autoGrow(el);
        });
      };

      let viewerMode = 'view';
      let viewerEditId = null;
      let editDirty = false;
      const setMode = (mode) => {
        viewerMode = mode;
        viewer.classList.toggle('edit-mode', mode === 'edit');
        if (mode === 'view') {
          if (viewBody) viewBody.style.display = 'grid';
          if (editBody) editBody.style.display = 'none';
          if (editFooter) editFooter.style.display = 'none';
          editDirty = false;
        } else {
          if (viewBody) viewBody.style.display = 'none';
          if (editBody) editBody.style.display = 'grid';
          if (editFooter) editFooter.style.display = 'flex';
          viewer.scrollTop = 0;
          requestAnimationFrame(autoGrowAll);
        }
      };

      const setView = (data) => {
        const formatMarked = (str) => {
          if (!str) return '—';
          const escaped = String(str)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#39;');
          const withItalics = escaped.replace(/\*(.+?)\*/g,'<em>$1</em>');
          return withItalics.replace(/\r?\n/g,'<br>');
        };
        const fill = (id, val) => {
          const el = document.getElementById(id);
          if (!el) return;
          el.textContent = val || '—';
        };
        const fillHtml = (id, val) => {
          const el = document.getElementById(id);
          if (!el) return;
          el.innerHTML = formatMarked(val);
        };
        const title = data.label && data.style ? `${data.label} — ${data.style}` : (data.label || 'Citation');
        fillHtml('viewLabel', title);
        fill('viewSubtitle', 'Read-only view');
        fill('viewStyle', data.style);
        fill('viewId', data.id ? `#${data.id}` : '—');
        fill('viewKey', data.key);
        fillHtml('viewOrder', data.order);
        fillHtml('viewExampleHeading', data.heading);
        fillHtml('viewExampleBody', data.body);
        fillHtml('viewYouTry', data.youtry);
        fillHtml('viewNotes', data.notes);
        viewerEditId = data.id || null;
        if (editIdField) editIdField.value = data.id || '';
        if (editStyleField) editStyleField.value = data.style || '';
        const keyVal = data.key || '';
        const keyInputModal = document.getElementById('citationKeyField');
        const keyInputEdit = document.getElementById('editKeyField');
        if (keyInputEdit) keyInputEdit.value = keyVal;
        if (keyInputModal && !keyInputModal.value) keyInputModal.value = keyVal;
        if (editLabelField) { editLabelField.value = data.label || ''; editLabelField.dataset.autogrow = '1'; }
        if (editOrderField) { editOrderField.value = data.order || ''; }
        if (editHeadingField) { editHeadingField.value = data.heading || ''; editHeadingField.dataset.autogrow = '1'; }
        if (editBodyField) { editBodyField.value = data.body || ''; }
        if (editYouTryField) { editYouTryField.value = data.youtry || ''; }
        if (editNotesField) { editNotesField.value = data.notes || ''; }
        setMode('view');
        viewer.classList.add('active');
      };

      document.querySelectorAll('[data-view-citation]').forEach(btn => {
        btn.addEventListener('click', () => {
          const get = (attr) => btn.getAttribute(attr) || '';
          setView({
            id: btn.getAttribute('data-id'),
            label: get('data-label'),
            style: get('data-style'),
            key: get('data-key'),
            order: get('data-order'),
            heading: get('data-heading'),
            body: get('data-body'),
            youtry: get('data-youtry'),
            notes: get('data-notes'),
          });
        });
      });

      viewerClose?.addEventListener('click', () => {
        if (viewerMode === 'edit' && editDirty && !confirm('Discard unsaved changes?')) return;
        viewer.classList.remove('active');
        setMode('view');
      });

      document.addEventListener('click', (e) => {
        const withinViewer = viewer.contains(e.target);
        const isViewBtn = (e.target.closest?.('[data-view-citation]') ?? null) !== null;
        if (!withinViewer && !isViewBtn) {
          if (viewerMode === 'edit' && editDirty && !confirm('Discard unsaved changes?')) return;
          viewer.classList.remove('active');
          setMode('view');
        }
      });

      viewerEdit?.addEventListener('click', () => {
        if (!viewerEditId) return;
        setMode('edit');
        editDirty = false;
      });

      const markDirty = () => { if (viewerMode === 'edit') editDirty = true; };
      [editLabelField, editOrderField, editHeadingField, editBodyField, editYouTryField, editNotesField].forEach(el => {
        el?.addEventListener('input', (e) => {
          markDirty();
          autoGrow(e.target);
        });
      });

      const confirmExitEdit = () => {
        if (!editDirty) return true;
        return confirm('Discard unsaved changes?');
      };

      document.getElementById('editCancel')?.addEventListener('click', () => {
        if (!confirmExitEdit()) return;
        setMode('view');
      });

      viewerRevisions?.addEventListener('click', (e) => {
        e.preventDefault();
        showSubtab('revisions');
        viewer.classList.remove('active');
        setMode('view');
        document.getElementById('panel-citations')?.scrollIntoView({behavior:'smooth'});
      });
    })();

      // Revision filters + viewer
      (function(){
        const rows = Array.from(document.querySelectorAll('[data-revision-row]'));
      const search = document.getElementById('revSearch');
      const styleFilter = document.getElementById('globalStyleFilter');
      const idFilter = document.getElementById('revIdFilter');
      const releaseFilter = document.getElementById('revReleaseFilter');
      const actionFilter = document.getElementById('revActionFilter');
      const dateRange = document.getElementById('revDateRange');
      const dateStart = document.getElementById('revDateStart');
      const dateEnd = document.getElementById('revDateEnd');
      const userFilter = document.getElementById('revUserFilter');
      const noResults = document.getElementById('revNoResults');
      const customDatesWrap = document.getElementById('revCustomDates');

      const parseDate = (str) => {
        if (!str) return null;
        const s = str.replace(' ', 'T');
        const d = new Date(s);
        return isNaN(d.getTime()) ? null : d;
      };

      const withinCustomRange = (d) => {
        if (!d) return true;
        if (dateRange?.value === 'custom') {
          const start = dateStart?.value ? new Date(dateStart.value) : null;
          const end = dateEnd?.value ? new Date(dateEnd.value) : null;
          if (start && d < start) return false;
          if (end) {
            const endDay = new Date(end);
            endDay.setHours(23,59,59,999);
            if (d > endDay) return false;
          }
        } else if (dateRange?.value) {
          const now = new Date();
          let cutoff = null;
          if (dateRange.value === '24h') cutoff = new Date(now.getTime() - 24*60*60*1000);
          if (dateRange.value === '7d') cutoff = new Date(now.getTime() - 7*24*60*60*1000);
          if (dateRange.value === '30d') cutoff = new Date(now.getTime() - 30*24*60*60*1000);
          if (cutoff && d < cutoff) return false;
        }
        return true;
      };

      const applyFilters = () => {
        const q = (search?.value || '').toLowerCase();
        const cid = (idFilter?.value || '').trim();
        const style = styleFilter?.value || '';
        const rel = releaseFilter?.value || '';
        const act = actionFilter?.value || '';
        const user = userFilter?.value || '';
        const custom = dateRange?.value === 'custom';
        if (customDatesWrap) customDatesWrap.style.display = custom ? 'flex' : 'none';

        let visible = 0;
        rows.forEach(r => {
          const label = (r.dataset.label || '').toLowerCase();
          const key = (r.dataset.key || '').toLowerCase();
          const styleVal = (r.dataset.style || '');
          const cidVal = (r.dataset.citationId || '');
          const relVal = r.dataset.release || '';
          const actVal = (r.dataset.action || '').toLowerCase();
          const userVal = r.dataset.user || '';
          const dateVal = parseDate(r.dataset.date || '');

          const matchesSearch = !q || label.includes(q) || key.includes(q) || cidVal === q;
          const matchesStyle = !style || style === styleVal;
          const matchesId = !cid || cidVal === cid;
          const matchesRelease = !rel || (rel === '__unreleased' ? relVal === '' : rel === relVal);
          const matchesAction = !act || act === actVal;
          const matchesUser = !user || user === userVal;
          const matchesDate = withinCustomRange(dateVal);

          const match = matchesSearch && matchesStyle && matchesId && matchesRelease && matchesAction && matchesUser && matchesDate;
          r.style.display = match ? '' : 'none';
          if (match) visible++;
        });
        if (noResults) noResults.style.display = visible ? 'none' : '';
      };

      [search, idFilter, styleFilter, releaseFilter, actionFilter, dateRange, dateStart, dateEnd, userFilter].forEach(el => {
        el?.addEventListener(el?.type === 'search' ? 'input' : 'change', applyFilters);
      });
      applyFilters();

      // Entries filtering via the shared search/style/status
      const entries = Array.from(document.querySelectorAll('.citation-row'));
      const globalStyle = document.getElementById('globalStyleFilter');
      const statusSelect = document.getElementById('citationStatusFilter');
      const entryFilter = () => {
        const q = (search?.value || '').toLowerCase();
        const styleSel = globalStyle?.value || '';
        const statusSel = statusSelect?.value || '';
        entries.forEach(row => {
          const label = (row.querySelector('.citation-label')?.textContent || '').toLowerCase();
          const key = (row.querySelector('.collection-slug')?.textContent || '').toLowerCase();
          const style = row.dataset.style || '';
          const status = row.dataset.status || '';
          const matchesText = !q || label.includes(q) || key.includes(q);
          const matchesStyle = !styleSel || styleSel === style;
          const matchesStatus = !statusSel || statusSel === status;
          row.style.display = (matchesText && matchesStyle && matchesStatus) ? '' : 'none';
        });
      };
      if (entries.length) {
        search?.addEventListener('input', entryFilter);
        globalStyle?.addEventListener('change', entryFilter);
        statusSelect?.addEventListener('change', entryFilter);
        entryFilter();
      }

      // Advanced filters toggle (tab-specific)
      advToggleBtn?.addEventListener('click', () => {
        if (!advPanel || !advEntries || !advRevs) return;
        const isEntries = advEntries.style.display !== 'none';
        const isRevs = advRevs.style.display !== 'none';
        advPanel.style.display = (advPanel.style.display === 'none' || !advPanel.style.display) ? 'flex' : 'none';
      });

      // Revision viewer
      const viewer = document.getElementById('revisionViewer');
      const closeBtn = document.getElementById('closeRevisionViewer');
      const revCloseBtn = document.getElementById('revCloseBtn');
      const restoreId = document.getElementById('revRestoreId');
      const badge = document.getElementById('revViewBadge');
      const actionPill = document.getElementById('revActionPill');
      const releasePill = document.getElementById('revReleasePill');

      const fillText = (id, val, fallback='—') => {
        const el = document.getElementById(id);
        if (el) el.textContent = val || fallback;
      };
      const formatMarked = (str, fallback='—') => {
        if (!str) return fallback;
        const escaped = String(str)
          .replace(/&/g,'&amp;')
          .replace(/</g,'&lt;')
          .replace(/>/g,'&gt;')
          .replace(/"/g,'&quot;')
          .replace(/'/g,'&#39;');
        const withItalics = escaped.replace(/\*(.+?)\*/g,'<em>$1</em>');
        return withItalics.replace(/\r?\n/g,'<br>');
      };
      const fillHtml = (id, val, fallback='—') => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = formatMarked(val, fallback);
      };
      const fillBlock = (prefix, snap, emptyLabel) => {
        const exists = !!snap;
        document.getElementById(prefix + 'Block')?.classList.toggle('muted', !exists);
        fillHtml(prefix + 'Label', snap?.label || (exists ? '' : emptyLabel));
        fillHtml(prefix + 'Order', snap?.citation_order || '');
        fillHtml(prefix + 'Heading', snap?.example_heading || '');
        fillHtml(prefix + 'Body', snap?.example_body || '');
        fillHtml(prefix + 'YouTry', snap?.you_try || '');
        fillHtml(prefix + 'Notes', snap?.notes || '');
      };

      const openRevision = (row) => {
        const id = row.dataset.id;
        let after = null;
        let before = null;
        try { after = JSON.parse(row.dataset.after || 'null'); } catch(e){}
        try { before = JSON.parse(row.dataset.before || 'null'); } catch(e){}
        const preferred = after ?? before ?? {};
        fillText('revViewLabel', preferred.label || 'Revision #' + id);
        fillText('revCitationLabel', preferred.label || '—');
        fillText('revCitationKey', row.dataset.key || '—');
        fillText('revCitationStyle', (preferred.referencing_style || row.dataset.style || '—'));
        fillText('revViewSubtitle', (row.dataset.action || '').toUpperCase() + ' • #' + id);
        fillText('revMetaUser', 'User: ' + (row.dataset.user || '—'));
        fillText('revMetaTime', 'When: ' + (row.dataset.date || '—'));
        badge.textContent = 'Read-only';
        actionPill.textContent = (row.dataset.action || '—').toUpperCase();
        releasePill.textContent = 'Release: ' + (row.dataset.release || 'Unreleased');
        fillBlock('revAfter', after, 'Deleted');
        fillBlock('revBefore', before, 'Not available');
        if (restoreId) restoreId.value = id || '';
        viewer.classList.add('active');
      };

      const closeRevision = () => viewer.classList.remove('active');
      closeBtn?.addEventListener('click', closeRevision);
      revCloseBtn?.addEventListener('click', closeRevision);
      document.addEventListener('click', (e) => {
        if (!viewer.classList.contains('active')) return;
        const isRow = e.target.closest?.('[data-revision-row]');
        const insideViewer = viewer.contains(e.target);
        if (!insideViewer && !isRow) closeRevision();
      });

      rows.forEach(r => {
        r.addEventListener('click', (e) => {
          if (e.target.closest('button') || e.target.closest('form')) return;
          openRevision(r);
        });
      });
    })();

    // Plain-text italics (using *text* markup) for citation fields
    (function(){
      try {
        var targets = [
          'citationLabelField','citationOrderField','citationHeadingField','citationBodyField','citationYouTryField','citationNotesField',
          'editLabelField','editOrderField','editHeadingField','editBodyField','editYouTryField','editNotesField'
        ];
        var idSet = new Set(targets);
        function toggleItalic(el){
          if (!el || typeof el.value !== 'string') return;
          var start = el.selectionStart || 0;
          var end = el.selectionEnd || 0;
          var val = el.value || '';
          var sel = val.slice(start,end);
          var replacement = sel;
          var newStart = start;
          var newEnd = end;
          if (sel && sel.startsWith('*') && sel.endsWith('*') && sel.length>1){
            replacement = sel.slice(1,-1);
            newEnd = start + replacement.length;
          } else if (sel){
            replacement = '*' + sel + '*';
            newEnd = start + replacement.length;
          } else {
            replacement = '**';
            newStart = start + 1;
            newEnd = newStart;
          }
          el.value = val.slice(0,start) + replacement + val.slice(end);
          el.focus();
          el.setSelectionRange(newStart,newEnd);
          el.dispatchEvent(new Event('input',{bubbles:true}));
        }
        document.querySelectorAll('[data-italic-btn]').forEach(function(btn){
          btn.addEventListener('click', function(e){
            e.preventDefault();
            var active = document.activeElement;
            if (!active || (active.tagName !== 'INPUT' && active.tagName !== 'TEXTAREA')) return;
            if (!idSet.has(active.id)) return;
            toggleItalic(active);
          });
        });
      } catch(err){ console.error('Italic handler error', err); }
    })();

    // Appearance autosave
      const appearanceForm = document.querySelector('#panel-appearance form');
      const appearanceStatus = document.getElementById('appearanceStatus');
    const setAppearanceStatus = (text, tone='muted') => {
      if (!appearanceStatus) return;
      appearanceStatus.textContent = text;
      appearanceStatus.style.color = tone === 'ok' ? '#22c55e' : (tone === 'danger' ? '#f87171' : 'var(--muted)');
    };
    const autosaveAppearance = (() => {
      let t = null;
      return () => {
        if (!appearanceForm) return;
        if (t) clearTimeout(t);
        t = setTimeout(() => {
          const fd = new FormData(appearanceForm);
          fd.set('save_theme', '1');
          setAppearanceStatus('Saving…');
          fetch(location.pathname + location.search, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          }).then(res => {
            if (!res.ok) throw new Error('save failed');
            setAppearanceStatus('Saved', 'ok');
          }).catch(() => {
            setAppearanceStatus('Save failed. Retry?', 'danger');
          });
        }, 200);
      };
    })();
    if (appearanceForm) {
      appearanceForm.addEventListener('change', autosaveAppearance);
      appearanceForm.addEventListener('input', autosaveAppearance);
    }

    // quick new page
    function slugify(val){return (val||'').toLowerCase().replace(/[^a-z0-9-]+/g,'-').replace(/-+/g,'-').replace(/^-+|-+$/g,'');}
    // nav add/remove
    const navItems = document.getElementById('navItems');
    document.getElementById('addNav')?.addEventListener('click', () => {
      const row = document.createElement('div');
      row.className = 'nav-item';
      row.innerHTML = `
        <input name="nav_label[]" placeholder="Label">
        <input name="nav_href[]"  placeholder="/path">
        <button class="small" type="button" data-remove>Remove</button>
      `;
      navItems.appendChild(row);
    });
    navItems?.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-remove]');
      if (!btn) return;
      const row = btn.closest('.nav-item');
      row?.remove();
    });

    // footer links add/remove
    const footerLinks = document.getElementById('footerLinks');
    document.getElementById('addFooterLink')?.addEventListener('click', () => {
      const row = document.createElement('div');
      row.className = 'nav-item';
      row.innerHTML = `
        <input name="footer_label[]" placeholder="Label">
        <input name="footer_href[]"  placeholder="/path">
        <button class="small" type="button" data-remove>Remove</button>
      `;
      footerLinks?.appendChild(row);
    });
    footerLinks?.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-remove]');
      if (!btn) return;
      btn.closest('.nav-item')?.remove();
    });

    // Add new page modal
    const modalBackdrop = document.createElement('div');
    modalBackdrop.className = 'modal-backdrop';
    modalBackdrop.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <header>
          <h3 id="modalTitle">Add new page</h3>
          <button class="close-btn" type="button" aria-label="Close">×</button>
        </header>
        <div class="row" style="margin-bottom:10px">
          <div>
            <label>Page title</label>
            <input id="modalTitleInput" placeholder="e.g. Landing page">
          </div>
          <div>
            <label>Slug</label>
            <input id="modalSlugInput" placeholder="landing-page">
          </div>
        </div>
        <div class="row" style="margin-bottom:10px">
          <div>
            <label>Collection</label>
            <select id="modalCollectionSelect">
              <option value="">(No collection)</option>
              <?php foreach ($collections as $col): ?>
                <option value="<?= (int)$col['id'] ?>"><?= Security::e($col['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="muted" style="margin-bottom:6px">Choose a layout</div>
        <div class="grid layout-grid" id="modalLayoutGrid">
          <button class="layout-card blank" type="button" data-layout="blank">
            <div class="checkmark">✓</div>
            <div class="layout-thumb"></div>
            <div class="layout-body">
              <div class="layout-title">Blank page</div>
              <div class="muted">Start from scratch with an empty row.</div>
              <div class="chip">Build manually</div>
            </div>
          </button>
          <?php
            $modalLayouts = [
              ['id' => 'landing', 'name' => 'Landing', 'desc' => 'Hero + features starter.'],
              ['id' => 'resource-library', 'name' => 'Resource', 'desc' => 'Resource grid with intro.'],
              ['id' => 'about-profile', 'name' => 'About', 'desc' => 'Profile/overview with highlights.'],
            ];
          ?>
          <?php foreach ($modalLayouts as $layout): ?>
            <button class="layout-card" type="button" data-layout="<?= Security::e($layout['id']) ?>">
              <div class="checkmark">✓</div>
              <div class="layout-thumb"></div>
              <div class="layout-body">
                <div class="layout-title"><?= Security::e($layout['name']) ?></div>
                <div class="muted" style="min-height:44px"><?= Security::e($layout['desc']) ?></div>
                <div class="chip">Use layout</div>
              </div>
            </button>
          <?php endforeach; ?>
        </div>
        <div class="actions" style="margin-top:16px">
          <button class="btn primary" type="button" id="modalCreateBtn">Create & open builder</button>
          <button class="btn text" type="button" id="modalCancelBtn">Cancel</button>
        </div>
      </div>
    `;
    document.body.appendChild(modalBackdrop);

    const openModalBtns = [document.getElementById('addPageBtn'), document.getElementById('addPageBtnEmpty')].filter(Boolean);
    document.getElementById('addPageBtnTop')?.addEventListener('click', () => {
      modalBackdrop.style.display = 'flex';
      document.getElementById('modalTitleInput')?.focus();
    });
    const closeModal = () => { modalBackdrop.style.display = 'none'; };
    openModalBtns.forEach(btn => btn.addEventListener('click', () => { modalBackdrop.style.display = 'flex'; document.getElementById('modalTitleInput').focus(); }));
    modalBackdrop.querySelector('.close-btn')?.addEventListener('click', closeModal);
    modalBackdrop.querySelector('#modalCancelBtn')?.addEventListener('click', closeModal);
    modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeModal(); });

    function slugify(val){return (val||'').toLowerCase().replace(/[^a-z0-9-]+/g,'-').replace(/-+/g,'-').replace(/^-+|-+$/g,'');}
    document.getElementById('modalTitleInput')?.addEventListener('blur', e => {
      const slug = document.getElementById('modalSlugInput');
      if (slug && !slug.value.trim()) slug.value = slugify(e.target.value);
    });

    let selectedLayout = 'blank';
    modalBackdrop.querySelectorAll('.layout-card').forEach(card => {
      card.addEventListener('click', () => {
        modalBackdrop.querySelectorAll('.layout-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        selectedLayout = card.dataset.layout || 'blank';
      });
    });
    // default selection on blank
    const firstCard = modalBackdrop.querySelector('.layout-card');
    if (firstCard) firstCard.classList.add('active');

    modalBackdrop.querySelector('#modalCreateBtn')?.addEventListener('click', () => {
      const title = (document.getElementById('modalTitleInput')?.value || '').trim();
      const slug = slugify(document.getElementById('modalSlugInput')?.value || '');
      const collectionSelect = document.getElementById('modalCollectionSelect');
      const collectionId = collectionSelect ? parseInt(collectionSelect.value, 10) : 0;
      if (!title || !slug) { alert('Enter a title and slug'); return; }
      document.getElementById('modal_title_field').value = title;
      document.getElementById('modal_slug_field').value = slug;
      document.getElementById('modal_layout_field').value = selectedLayout;
      document.getElementById('modal_collection_field').value = collectionId || '';
      document.getElementById('modalCreateForm').submit();
    });

    // Delete page modal
    const deleteBackdrop = document.createElement('div');
    deleteBackdrop.className = 'modal-backdrop';
    deleteBackdrop.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteTitle">
        <header>
          <h3 id="deleteTitle">Delete page</h3>
          <button class="close-btn" type="button" aria-label="Close">×</button>
        </header>
        <p>Are you sure you want to delete this page?</p>
        <p><strong id="deletePageName"></strong></p>
        <div class="actions" style="margin-top:12px">
          <button class="btn primary" type="button" id="confirmDeleteBtn">Yes, delete</button>
          <button class="btn text" type="button" id="cancelDeleteBtn">No</button>
        </div>
      </div>
    `;
    document.body.appendChild(deleteBackdrop);

    let deleteTargetId = null;
    const deletePageName = deleteBackdrop.querySelector('#deletePageName');
    const closeDelete = () => { deleteBackdrop.style.display = 'none'; deleteTargetId = null; };

    document.querySelectorAll('[data-delete-page]').forEach(btn => {
      btn.addEventListener('click', () => {
        deleteTargetId = btn.dataset.pageId || null;
        if (deletePageName) deletePageName.textContent = btn.dataset.pageTitle || '';
        deleteBackdrop.style.display = 'flex';
      });
    });

    // Duplicate page buttons
    document.querySelectorAll('[data-duplicate-page]').forEach(btn => {
      btn.addEventListener('click', () => {
        const pid = btn.dataset.pageId;
        if (!pid) return;
        document.getElementById('duplicate_page_id').value = pid;
        document.getElementById('duplicatePageForm').submit();
      });
    });

    // View pages for a collection
    document.querySelectorAll('[data-view-collection]').forEach(btn => {
      btn.addEventListener('click', () => {
        const cid = btn.dataset.viewCollection;
        if (!cid) return;
        collectionFilter = cid;
        activate('pages');
        applyPageFilters();
        document.getElementById('panel-pages')?.scrollIntoView({behavior:'smooth',block:'start'});
      });
    });

    // Delete collection confirmation
    document.querySelectorAll('[data-delete-collection]').forEach(btn => {
      btn.addEventListener('click', () => {
        const cid = btn.dataset.deleteCollection;
        const name = btn.dataset.collectionName || 'this collection';
        const msg = `Delete "${name}"?\nPages will not be deleted, but will become unassigned.`;
        if (!confirm(msg)) return;
        document.getElementById('delete_collection_id').value = cid;
        document.getElementById('deleteCollectionForm').submit();
      });
    });

    deleteBackdrop.querySelector('#cancelDeleteBtn')?.addEventListener('click', closeDelete);
    deleteBackdrop.querySelector('.close-btn')?.addEventListener('click', closeDelete);
    deleteBackdrop.addEventListener('click', (e) => { if (e.target === deleteBackdrop) closeDelete(); });

    deleteBackdrop.querySelector('#confirmDeleteBtn')?.addEventListener('click', () => {
      if (!deleteTargetId) return;
      document.getElementById('delete_page_id').value = deleteTargetId;
      document.getElementById('deletePageForm').submit();
    });

    // Kebab menus
    const closeAllKebabs = () => document.querySelectorAll('.kebab-menu').forEach(m => m.style.display = 'none');
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.kebab-btn');
      if (btn) {
        const menu = btn.parentElement.querySelector('.kebab-menu');
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        closeAllKebabs();
        if (!expanded) {
          menu.style.display = 'block';
          btn.setAttribute('aria-expanded','true');
        } else {
          btn.setAttribute('aria-expanded','false');
        }
        return;
      }
      if (!e.target.closest('.kebab')) closeAllKebabs();
    });

    // Relative timestamps
    function relTime(ts) {
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return ts;
      const diff = Date.now() - d.getTime();
      const mins = Math.floor(diff/60000);
      if (mins < 1) return 'just now';
      if (mins < 60) return mins + ' min' + (mins>1?'s':'') + ' ago';
      const hrs = Math.floor(mins/60);
      if (hrs < 24) return hrs + ' hour' + (hrs>1?'s':'') + ' ago';
      const days = Math.floor(hrs/24);
      if (days < 30) return days + ' day' + (days>1?'s':'') + ' ago';
      const months = Math.floor(days/30);
      if (months < 12) return months + ' mo' + (months>1?'s':'') + ' ago';
      const years = Math.floor(months/12);
      return years + ' yr' + (years>1?'s':'') + ' ago';
    }
    document.querySelectorAll('.updated-cell').forEach(cell => {
      const ts = cell.dataset.updated;
      if (!ts) return;
      cell.title = ts;
      cell.textContent = relTime(ts);
    });

    // Delete site modal
    const deleteSiteBackdrop = document.createElement('div');
    deleteSiteBackdrop.className = 'modal-backdrop';
    deleteSiteBackdrop.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteSiteTitle">
        <header>
          <h3 id="deleteSiteTitle">Delete website</h3>
          <button class="close-btn" type="button" aria-label="Close">×</button>
        </header>
        <p>Are you sure you want to delete <?= Security::e($site['name']) ?>?</p>
        <div class="actions" style="margin-top:12px">
          <button class="btn primary" type="button" id="confirmDeleteSiteBtn">Yes, I do</button>
          <button class="btn text" type="button" id="cancelDeleteSiteBtn">No</button>
        </div>
      </div>
    `;
    document.body.appendChild(deleteSiteBackdrop);
    document.getElementById('deleteSiteBtn')?.addEventListener('click', () => { deleteSiteBackdrop.style.display = 'flex'; });
    deleteSiteBackdrop.querySelector('#cancelDeleteSiteBtn')?.addEventListener('click', () => { deleteSiteBackdrop.style.display = 'none'; });
    deleteSiteBackdrop.querySelector('.close-btn')?.addEventListener('click', () => { deleteSiteBackdrop.style.display = 'none'; });
    deleteSiteBackdrop.addEventListener('click', (e) => { if (e.target === deleteSiteBackdrop) deleteSiteBackdrop.style.display = 'none'; });
    deleteSiteBackdrop.querySelector('#confirmDeleteSiteBtn')?.addEventListener('click', () => {
      document.getElementById('deleteSiteForm').submit();
    });

    // Copy path buttons
    document.querySelectorAll('[data-copy]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const targetSel = btn.getAttribute('data-copy');
        const el = document.querySelector(targetSel);
        if (!el) return;
        const text = el.textContent.trim();
        try {
          await navigator.clipboard.writeText(text);
          btn.textContent = 'Copied';
          setTimeout(() => { btn.textContent = 'Copy path'; }, 1200);
        } catch (e) { btn.textContent = 'Copy failed'; }
      });
    });
  </script>
</body>
</html>
