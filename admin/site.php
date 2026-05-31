<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Models\DeletedPage;
use NexusCMS\Models\SiteForm;
use NexusCMS\Models\FormResponse;
use NexusCMS\Models\User;
use NexusCMS\Core\Security;
use NexusCMS\Core\DB;
use NexusCMS\Support\PartialsManager;
use NexusCMS\Support\PagePath;
use NexusCMS\Models\CitationExample;
use NexusCMS\Models\CitationRevision;
use NexusCMS\Models\CitationRelease;
use NexusCMS\Models\CitationStyleDocument;

// -----------------------------
// Site loading
// -----------------------------
$siteId = (int)($_GET['id'] ?? 0);
$site = Site::find($siteId);
if (!$site) { http_response_code(404); echo "Site not found"; exit; }
$me = null;
if (isset($_SESSION['user_id'])) {
  $me = User::findById((int)$_SESSION['user_id']) ?: null;
}
$myRole = strtolower((string)($me['role'] ?? ($_SESSION['user_role'] ?? '')));
$isSuperAdmin = $myRole === 'super_admin';
$isCtrSite = PartialsManager::safeSlug((string)($site['slug'] ?? '')) === 'cite-them-right';
$styleOptions = ['Harvard','APA 7th','Chicago 18th','Chicago 17th','IEEE','MHRA 4th','MHRA 3rd','MLA 9th','OSCOLA','Vancouver'];
$topicOptions = ['Books','Journals','Digital & Internet','Media & Art','Research','Legal','Governmental','Communications'];

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

function nx_site_json_payload(array $payload): string {
  return (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function nx_update_site_theme_json(int $siteId, array $payload): void {
  $stmt = nx_db()->prepare("UPDATE sites SET theme_json = :json WHERE id = :id LIMIT 1");
  $stmt->execute([':json' => nx_site_json_payload($payload), ':id' => $siteId]);
}

function nx_update_site_header_json(int $siteId, array $payload): void {
  $stmt = nx_db()->prepare("UPDATE sites SET header_json = :json WHERE id = :id LIMIT 1");
  $stmt->execute([':json' => nx_site_json_payload($payload), ':id' => $siteId]);
}

function nx_update_site_footer_json(int $siteId, array $payload): void {
  $stmt = nx_db()->prepare("UPDATE sites SET footer_json = :json WHERE id = :id LIMIT 1");
  $stmt->execute([':json' => nx_site_json_payload($payload), ':id' => $siteId]);
}

function nx_safe_rollback($pdo): void {
  if ($pdo instanceof \PDO && $pdo->inTransaction()) {
    try { $pdo->rollBack(); } catch (\Throwable $e) {}
  }
}

function nx_page_editable_or_notice(?array $page, bool $isSuperAdmin, ?string &$notice, string $action = 'edit'): bool {
  if (!$page) return false;
  if (Page::canEdit($page, $isSuperAdmin ? 'super_admin' : '')) return true;
  $notice = 'This page is locked. Only super admins can ' . $action . ' it.';
  return false;
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

function nx_page_path_tree(array $pages): array {
  $tree = [];
  foreach ($pages as $page) {
    $parts = PagePath::split((string)($page['slug'] ?? ''));
    if (count($parts) < 2) continue;
    $cursor = &$tree;
    foreach (array_slice($parts, 0, -1) as $part) {
      if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
        $cursor[$part] = [];
      }
      $cursor = &$cursor[$part];
    }
    unset($cursor);
  }

  $sortTree = static function(array &$node) use (&$sortTree): void {
    ksort($node);
    foreach ($node as &$child) {
      if (is_array($child)) $sortTree($child);
    }
  };
  $sortTree($tree);
  return $tree;
}

function nx_site_form_questions_from_post(array $post): array {
  $labels = array_values((array)($post['question_label'] ?? []));
  $types = array_values((array)($post['question_type'] ?? []));
  $ids = array_values((array)($post['question_id'] ?? []));
  $questions = [];
  $count = max(count($labels), count($types), count($ids));
  for ($i = 0; $i < $count; $i++) {
    $label = trim((string)($labels[$i] ?? ''));
    $type = strtolower(trim((string)($types[$i] ?? 'text')));
    $id = trim((string)($ids[$i] ?? ''));
    if ($label === '') continue;
    if (!in_array($type, ['text', 'rating'], true)) $type = 'text';
    $questions[] = [
      'id' => $id,
      'label' => $label,
      'type' => $type,
    ];
  }
  return $questions;
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
    'fontFamily' => $isCtrSite ? 'Georgia,"Times New Roman",serif' : 'system-ui,-apple-system,Segoe UI,Roboto,Arial',
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
    'shadow' => 'none',
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
    'Georgia,"Times New Roman",serif' => 'Georgia / Times',
    '"Nunito",system-ui,-apple-system,Segoe UI,Roboto,Arial' => 'Nunito',
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
  // Controls default corner rounding across the site
  'radius' => [
    16 => 'Curved',
    0  => 'Square',
  ],
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
$citationsOnly = ($siteSlug === 'cite-them-right') && (($_GET['view'] ?? '') === 'citations');
$partialPaths = PartialsManager::paths($siteSlug);
$partialStatus = [
  'header' => file_exists($partialPaths['header']) ? 'exists' : 'missing',
  'footer' => file_exists($partialPaths['footer']) ? 'exists' : 'missing',
  'css'    => file_exists($partialPaths['css']) ? 'exists' : 'missing',
  'js'     => file_exists($partialPaths['js']) ? 'exists' : 'missing',
];
$citationStyles = [
  'Harvard',
  'APA 7th',
  'Chicago 18th',
  'Chicago 17th',
  'IEEE',
  'MHRA 4th',
  'MHRA 3rd',
  'MLA 9th',
  'OSCOLA',
  'Vancouver'
];
$citationCategories = [
  'Books',
  'Journals',
  'Digital & Internet',
  'Media & Art',
  'Research',
  'Legal',
  'Governmental',
  'Communications',
];
$citationStyleDocTypes = [
  'Style guide',
  'Source type information',
  'Referencing rules',
  'Editorial guidance',
  'Examples policy',
];

// Utility: truncate long strings for display
function nx_truncate(string $str, int $limit = 30): string {
  return (strlen($str) > $limit) ? substr($str, 0, $limit) . '…' : $str;
}

function nx_doc_text_preview(string $str, int $limit = 160): string {
  $text = trim(preg_replace('/\s+/', ' ', strip_tags($str)));
  if ($text === '') return 'No document body yet.';
  return strlen($text) > $limit ? substr($text, 0, $limit - 1) . '…' : $text;
}

function nx_citation_table_preview(string $str): string {
  $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $str);
  $text = preg_replace('/<\/\s*(p|div|li|h[1-6])\s*>/i', "\n", (string)$text);
  $text = trim(strip_tags((string)$text));
  return nl2br(Security::e($text !== '' ? $text : '—'));
}

// Citation key helpers
function nx_citation_style_code(string $style): string {
  $norm = strtolower(trim($style));
  $map = [
    'harvard' => 'Harv',
    'apa' => 'APA7',
    'apa 7' => 'APA7',
    'apa 7th' => 'APA7',
    'apa7th' => 'APA7',
    'chicago 18' => 'Ch18',
    'chicago 18th' => 'Ch18',
    'chicago 17' => 'Ch17',
    'chicago 17th' => 'Ch17',
    'ieee' => 'IEEE',
    'mhra' => 'MHRA4',
    'mhra3' => 'MHRA3',
    'mhra 3' => 'MHRA3',
    'mhra4' => 'MHRA4',
    'mhra 4' => 'MHRA4',
    'mhra 3rd' => 'MHRA3',
    'mhra 4th' => 'MHRA4',
    'mla' => 'MLA9',
    'mla 9th' => 'MLA9',
    'mla9' => 'MLA9',
    'oscola' => 'OSCO',
    'osco' => 'OSCO',
    'vancouver' => 'Vanc'
  ];
  if (isset($map[$norm])) return $map[$norm];
  foreach ($map as $needle => $code) {
    if (strpos($norm, $needle) !== false) return $code;
  }
  $fallback = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $style), 0, 4));
  return $fallback !== '' ? $fallback : 'CITE';
}

function nx_citation_label_slug(string $label): string {
  $clean = preg_replace('/[^a-z0-9]+/i', ' ', $label);
  $clean = trim($clean);
  if ($clean === '') return 'Entry';
  $parts = preg_split('/\s+/', $clean) ?: [];
  $parts = array_map(function($w){ return ucfirst(strtolower($w)); }, $parts);
  return implode('_', $parts);
}

function nx_generate_citation_key(string $siteSlug, string $style, string $label): string {
  $prefix = nx_citation_style_code($style);
  $baseLabel = nx_citation_label_slug($label);
  $base = $prefix . ':' . $baseLabel;
  $key = $base;
  $suffix = 2;
  while (CitationExample::find($siteSlug, $key)) {
    $key = $base . '_' . $suffix;
    $suffix++;
  }
  return $key;
}

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

      try {
        $forms = SiteForm::listBySite($siteId);
        foreach ($forms as $formRow) {
          SiteForm::create(
            $newSiteId,
            (string)($formRow['name'] ?? ''),
            (string)($formRow['description'] ?? ''),
            (array)($formRow['questions'] ?? [])
          );
        }
      } catch (\Throwable $e) {}

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
        $pdo->prepare("DELETE FROM deleted_pages WHERE site_id=?")->execute([$siteId]);
      } catch (\Throwable $e) {}
      try {
        $pdo->prepare("DELETE FROM form_responses WHERE site_id=?")->execute([$siteId]);
      } catch (\Throwable $e) {}
      try {
        $pdo->prepare("DELETE FROM site_forms WHERE site_id=?")->execute([$siteId]);
      } catch (\Throwable $e) {}
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
        if (!nx_page_editable_or_notice($page, $isSuperAdmin, $notice, 'delete')) {
          // fall through with notice
        } else {
        DeletedPage::softDelete($page, [
          'user_id' => (int)($_SESSION['user_id'] ?? 0),
          'email' => (string)($me['email'] ?? ''),
          'name' => (string)($me['display_name'] ?? ($_SESSION['user_name'] ?? '')),
          'role' => $myRole,
        ]);
        header('Location: site.php?id=' . $siteId . '&saved=deleted');
        exit;
        }
      } else {
        $notice = 'Page not found for this site.';
      }
    } catch (\Throwable $e) {
      $notice = 'Delete failed. Please try again.';
    }
  }

  if (isset($_POST['restore_deleted_page']) && $isSuperAdmin) {
    $deletedPageId = (int)($_POST['deleted_page_id'] ?? 0);
    try {
      $deletedPage = DeletedPage::find($deletedPageId);
      if ($deletedPage && (int)($deletedPage['site_id'] ?? 0) === $siteId) {
        $restoredPage = DeletedPage::restore($deletedPageId);
        PartialsManager::ensurePageDirectory((string)($site['slug'] ?? ''), (string)($restoredPage['slug'] ?? ''));
        header('Location: site.php?id=' . $siteId . '&saved=restored#deleted-pages');
        exit;
      } else {
        $notice = 'Deleted page not found for this site.';
      }
    } catch (\Throwable $e) {
      $notice = 'Restore failed. Please try again.';
    }
  }

  if (isset($_POST['rename_page'])) {
    $pageId = (int)($_POST['page_id'] ?? 0);
    $newTitle = trim((string)($_POST['page_title'] ?? ''));
    $newPath = trim((string)($_POST['page_slug'] ?? ''));
    try {
      $page = Page::find($pageId);
      if ($page && (int)$page['site_id'] === $siteId) {
        if (!nx_page_editable_or_notice($page, $isSuperAdmin, $notice, 'edit')) {
          // fall through with notice
        } else {
        $normalizedSlug = PagePath::normalizePath($newPath);
        if ($newTitle === '' || $normalizedSlug === '') {
          $notice = 'Page name and file path are required.';
        } else {
          $existing = Page::findBySlugAnyStatus($siteId, $normalizedSlug);
          if ($existing && (int)($existing['id'] ?? 0) !== $pageId) {
            $notice = 'File path already exists. Choose another.';
          } else {
            Page::updateTitleAndSlug($pageId, $newTitle, $normalizedSlug);
            PartialsManager::movePageDirectory((string)($site['slug'] ?? ''), (string)($page['slug'] ?? ''), $normalizedSlug);
            header('Location: site.php?id=' . $siteId . '&saved=page');
            exit;
          }
        }
        }
      } else {
        $notice = 'Page not found for this site.';
      }
    } catch (\Throwable $e) {
      $notice = 'Rename failed. Please try again.';
    }
  }

  // Duplicate page
  if (isset($_POST['duplicate_page'])) {
    $pageId = (int)($_POST['duplicate_page'] ?? 0);
    try {
      $page = Page::find($pageId);
      if ($page && (int)$page['site_id'] === $siteId) {
        if (!nx_page_editable_or_notice($page, $isSuperAdmin, $notice, 'duplicate')) {
          // fall through with notice
        } else {
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
          null
        );
        header('Location: site.php?id=' . $siteId . '&saved=page');
        exit;
        }
      } else {
        $notice = 'Page not found for this site.';
      }
    } catch (\Throwable $e) {
      $notice = 'Duplicate failed. Please try again.';
    }
  }

  if (isset($_POST['toggle_page_lock']) && $isSuperAdmin) {
    $pageId = (int)($_POST['page_id'] ?? 0);
    $shouldLock = !empty($_POST['lock_page']);
    try {
      $page = Page::find($pageId);
      if ($page && (int)$page['site_id'] === $siteId) {
        Page::setLocked($pageId, $shouldLock);
        header('Location: site.php?id=' . $siteId . '&saved=page');
        exit;
      }
      $notice = 'Page not found for this site.';
    } catch (\Throwable $e) {
      $notice = 'Lock update failed. Please try again.';
    }
  }

  if (isset($_POST['save_site_form'])) {
    $formId = (int)($_POST['form_id'] ?? 0);
    $name = trim((string)($_POST['form_name'] ?? ''));
    $description = trim((string)($_POST['form_description'] ?? ''));
    $questions = nx_site_form_questions_from_post($_POST);
    $editingSiteForm = [
      'id' => $formId,
      'site_id' => $siteId,
      'name' => $name,
      'description' => $description,
      'questions' => $questions,
    ];

    try {
      if ($name === '') {
        $notice = 'Form name is required.';
      } elseif (!$questions) {
        $notice = 'Add at least one question before saving the form.';
      } else {
        if ($formId > 0) {
          $existingForm = SiteForm::find($formId);
          if (!$existingForm || (int)($existingForm['site_id'] ?? 0) !== $siteId) {
            $notice = 'Form not found for this site.';
          } else {
            SiteForm::update($formId, $siteId, $name, $description, $questions);
            header('Location: site.php?id=' . $siteId . '&saved=form#forms');
            exit;
          }
        } else {
          SiteForm::create($siteId, $name, $description, $questions);
          header('Location: site.php?id=' . $siteId . '&saved=form#forms');
          exit;
        }
      }
    } catch (\Throwable $e) {
      $notice = 'Form save failed. Please try again.';
    }
  }

  if (isset($_POST['delete_site_form'])) {
    $formId = (int)($_POST['form_id'] ?? 0);
    try {
      $existingForm = SiteForm::find($formId);
      if (!$existingForm || (int)($existingForm['site_id'] ?? 0) !== $siteId) {
        $notice = 'Form not found for this site.';
      } else {
        SiteForm::delete($formId, $siteId);
        try {
          nx_db()->prepare("DELETE FROM form_responses WHERE form_id=? AND site_id=?")->execute([$formId, $siteId]);
        } catch (\Throwable $e) {}
        header('Location: site.php?id=' . $siteId . '&saved=form#forms');
        exit;
      }
    } catch (\Throwable $e) {
      $notice = 'Form delete failed. Please try again.';
    }
  }

  // Modal create page
  if (isset($_POST['create_modal_page'])) {
    $title = trim((string)($_POST['modal_title'] ?? ''));
    $slug  = trim((string)($_POST['modal_slug'] ?? ''));
    $layout = trim((string)($_POST['modal_layout'] ?? 'blank'));
    $style = trim((string)($_POST['modal_path_style'] ?? ''));
    $topic = trim((string)($_POST['modal_path_topic'] ?? ''));
    $pathParts = [];
    $stylePart = PagePath::normalizeSegment($style);
    $topicPart = PagePath::normalizeSegment($topic);
    if ($stylePart !== '') $pathParts[] = $stylePart;
    if ($topicPart !== '') $pathParts[] = $topicPart;
    $leafSlug = PagePath::normalizeSegment($slug);
    if ($leafSlug !== '') $pathParts[] = $leafSlug;
    $normalizedSlug = PagePath::join($pathParts);

    $needsSourceTypePath = ($layout === 'source-type');
    if ($title === '' || $leafSlug === '' || ($needsSourceTypePath && ($stylePart === '' || $topicPart === ''))) {
      $notice = $needsSourceTypePath
        ? 'Title, style, topic, and source type are required.'
        : 'Title and source type are required.';
    } elseif (Page::findBySlugAnyStatus($siteId, $normalizedSlug)) {
      $notice = 'Page path already exists. Choose another.';
    } else {
      $templates = require __DIR__ . '/../app/templates/page_templates.php';
      $doc = ['version'=>1,'rows'=>[ ['cols'=>[['span'=>12,'blocks'=>[]]]] ]];
      if ($layout !== 'blank' && isset($templates[$layout])) {
        $doc = $templates[$layout];
      }
      $pageId = Page::create($siteId, $title, $normalizedSlug, $doc, $layout ?: 'blank', null, null);
      PartialsManager::ensurePageDirectory((string)($site['slug'] ?? ''), $normalizedSlug);
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

    nx_update_site_theme_json($siteId, $newTheme);
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

    nx_update_site_header_json($siteId, $newHeader);
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

    nx_update_site_footer_json($siteId, $newFooter);
    header('Location: site.php?id=' . $siteId . '&saved=footer');
    exit;
  }

  // Citation database CRUD + revisions (Cite Them Right only)
  if ($siteSlug === 'cite-them-right') {
    $currentReleaseTag = $_SESSION['citation_release_tag_'.$siteSlug] ?? $currentReleaseTag;
    $currentUserId = $_SESSION['user_id'] ?? null;
    $currentUserEmail = $currentUser['email'] ?? null;

    $diffFn = function(array $before, array $after): array {
      $fields = ['example_key','label','referencing_style','category','sub_category','citation_order','example_heading','example_body','you_try','notes'];
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
      $citationKey = (string)($after['example_key'] ?? $before['example_key'] ?? '');
      CitationRevision::record([
        'site_slug' => $siteSlug,
        'citation_id' => $after['id'] ?? $before['id'] ?? null,
        'citation_key' => $citationKey,
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
          'category' => $snapshot['category'] ?? 'Books',
          'sub_category' => $snapshot['sub_category'] ?? null,
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
          'category' => $snapshot['category'] ?? 'Books',
          'sub_category' => $snapshot['sub_category'] ?? null,
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
    $clearQueuedByKey = function(string $key) use ($siteSlug): void {
      if ($key === '') return;
      $pdo = nx_db();
      $stmt = $pdo->prepare("DELETE FROM citation_revisions WHERE site_slug=? AND citation_key=? AND (release_tag IS NULL OR release_tag='')");
      $stmt->execute([$siteSlug, $key]);
    };
    $hasQueuedByKey = function(string $key) use ($siteSlug): bool {
      if ($key === '') return false;
      $pdo = nx_db();
      $stmt = $pdo->prepare("SELECT id FROM citation_revisions WHERE site_slug=? AND citation_key=? AND (release_tag IS NULL OR release_tag='') LIMIT 1");
      $stmt->execute([$siteSlug, $key]);
      return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    };
    $nextQueuedKey = function(string $style, string $label) use ($siteSlug, $hasQueuedByKey): string {
      $baseKey = nx_generate_citation_key($siteSlug, $style, $label);
      if (!$hasQueuedByKey($baseKey)) return $baseKey;
      $candidate = $baseKey;
      $n = 2;
      while ($hasQueuedByKey($candidate) || CitationExample::find($siteSlug, $candidate)) {
        $candidate = $baseKey . '_' . $n;
        $n++;
      }
      return $candidate;
    };
    $applyQueuedRevision = function(array $rev) use ($siteSlug, $applySnapshot): void {
      $action = strtolower((string)($rev['action'] ?? ''));
      $key = (string)($rev['citation_key'] ?? '');
      $after = json_decode((string)($rev['after_json'] ?? 'null'), true);
      if ($action === 'delete' || !$after) {
        $existing = CitationExample::find($siteSlug, $key);
        if ($existing && isset($existing['id'])) CitationExample::delete((int)$existing['id'], $siteSlug);
        return;
      }
      $applySnapshot($after);
    };

    if (($_POST['save_style_document'] ?? '') === '1') {
      try {
        $id = (int)($_POST['style_document_id'] ?? 0);
        $style = trim((string)($_POST['style_document_style'] ?? ''));
        if (!in_array($style, $citationStyles, true)) $style = $citationStyles[0];
        $docType = trim((string)($_POST['style_document_type'] ?? ''));
        if (!in_array($docType, $citationStyleDocTypes, true)) $docType = $citationStyleDocTypes[0];
        $category = trim((string)($_POST['style_document_category'] ?? ''));
        if ($category === '') $category = null;
        elseif (!in_array($category, $citationCategories, true)) $category = null;
        $subCategory = trim((string)($_POST['style_document_sub_category'] ?? ''));
        if ($subCategory === '') $subCategory = null;
        $title = trim((string)($_POST['style_document_title'] ?? ''));
        $body = trim((string)($_POST['style_document_body'] ?? ''));
        if ($title === '') throw new Exception('Document title is required.');
        if ($body === '') throw new Exception('Document body is required.');
        $docPayload = [
          'site_slug' => $siteSlug,
          'referencing_style' => $style,
          'doc_type' => $docType,
          'category' => $category,
          'sub_category' => $subCategory,
          'title' => $title,
          'body' => $body,
          'updated_by_email' => $currentUserEmail,
        ];
        if ($id > 0) {
          CitationStyleDocument::update($id, $siteSlug, $docPayload);
          $notice = 'Style document updated.';
        } else {
          CitationStyleDocument::create($docPayload);
          $notice = 'Style document created.';
        }
      } catch (\Throwable $e) {
        $notice = 'Error saving style document: ' . $e->getMessage();
      }
    }

    if (isset($_POST['delete_style_document'])) {
      try {
        $id = (int)($_POST['style_document_id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid style document.');
        CitationStyleDocument::delete($id, $siteSlug);
        $notice = 'Style document deleted.';
      } catch (\Throwable $e) {
        $notice = 'Error deleting style document: ' . $e->getMessage();
      }
    }

    if (($_POST['add_citation'] ?? '') === '1') {
      try {
        $pdo = nx_db();
        $pdo->beginTransaction();
        $style = trim((string)($_POST['citation_style'] ?? ''));
        if (!in_array($style, $citationStyles, true)) $style = $citationStyles[0];
        $category = trim((string)($_POST['citation_category'] ?? ''));
        if (!in_array($category, $citationCategories, true)) $category = $citationCategories[0];
        $subCategory = trim((string)($_POST['citation_sub_category'] ?? ''));
        if ($subCategory === '') $subCategory = null;
        $label = trim((string)($_POST['citation_label'] ?? ''));
        if ($label === '') throw new Exception('Label is required');
        $data = [
          'site_slug' => $siteSlug,
          'referencing_style' => $style,
          'category' => $category,
          'sub_category' => $subCategory,
          'example_key' => '',
          'label' => $label,
          'citation_order' => trim((string)($_POST['citation_order'] ?? '')),
          'example_heading' => trim((string)($_POST['citation_heading'] ?? '')),
          'example_body' => trim((string)($_POST['citation_body'] ?? '')),
          'you_try' => trim((string)($_POST['citation_youtry'] ?? '')),
          'notes' => trim((string)($_POST['citation_notes'] ?? ''))
        ];
        $data['example_key'] = $citationsOnly
          ? $nextQueuedKey($style, $label)
          : nx_generate_citation_key($siteSlug, $style, $label);
        if ($citationsOnly) {
          $clearQueuedByKey($data['example_key']);
          $after = array_merge($data, ['id' => null]);
          $recordRevision('create', null, $after, null);
          $notice = 'Citation queued. Live citation remains unchanged until export.';
        } else {
          $newId = CitationExample::create($data);
          $after = array_merge($data, ['id'=>$newId]);
          $recordRevision('create', null, $after, $currentReleaseTag);
          $notice = 'Citation saved.';
        }
        if ($pdo->inTransaction()) $pdo->commit();
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Error saving citation: ' . $e->getMessage();
      }
    }
    if (($_POST['update_citation'] ?? '') === '1') {
      try {
        $pdo = nx_db();
        $pdo->beginTransaction();
        $id = (int)($_POST['citation_id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid citation ID');
        $style = trim((string)($_POST['citation_style'] ?? ''));
        if (!in_array($style, $citationStyles, true)) $style = $citationStyles[0];
        $category = trim((string)($_POST['citation_category'] ?? ''));
        if (!in_array($category, $citationCategories, true)) $category = $citationCategories[0];
        $subCategory = trim((string)($_POST['citation_sub_category'] ?? ''));
        if ($subCategory === '') $subCategory = null;
        $before = CitationExample::findById($id);
        $existingKey = $before['example_key'] ?? '';
        $data = [
          'referencing_style' => $style,
          'category' => $category,
          'sub_category' => $subCategory,
          'example_key' => $existingKey !== '' ? $existingKey : nx_generate_citation_key($siteSlug, $style, (string)($_POST['citation_label'] ?? '')),
          'label' => trim((string)($_POST['citation_label'] ?? '')),
          'citation_order' => trim((string)($_POST['citation_order'] ?? '')),
          'example_heading' => trim((string)($_POST['citation_heading'] ?? '')),
          'example_body' => trim((string)($_POST['citation_body'] ?? '')),
          'you_try' => trim((string)($_POST['citation_youtry'] ?? '')),
          'notes' => trim((string)($_POST['citation_notes'] ?? ''))
        ];
        $after = array_merge($data, ['id'=>$id, 'site_slug'=>$siteSlug]);
        if ($citationsOnly) {
          $clearQueuedByKey((string)($data['example_key'] ?? ''));
          $recordRevision('update', $before ?? [], $after, null);
          $notice = 'Citation update queued. Live citation remains unchanged until export.';
        } else {
          CitationExample::update($id, $data);
          $recordRevision('update', $before ?? [], $after, $currentReleaseTag);
          $notice = 'Citation updated.';
        }
        if ($pdo->inTransaction()) $pdo->commit();
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
          if ($citationsOnly) {
            $clearQueuedByKey((string)($before['example_key'] ?? ''));
            $recordRevision('delete', $before ?? [], null, null);
            $notice = 'Citation delete queued. Live citation remains unchanged until export.';
          } else {
            CitationExample::delete($id, $siteSlug);
            $recordRevision('delete', $before ?? [], null, $currentReleaseTag);
            $notice = 'Citation deleted.';
          }
          if ($pdo->inTransaction()) $pdo->commit();
        }
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Error deleting citation: ' . $e->getMessage();
      }
    }

    if ($citationsOnly && isset($_POST['export_single_citation'])) {
      try {
        $pdo = nx_db();
        $pdo->beginTransaction();
        $revId = (int)($_POST['queued_revision_id'] ?? 0);
        if ($revId <= 0) throw new Exception('Invalid queued citation');
        $stmt = $pdo->prepare("SELECT * FROM citation_revisions WHERE id=? AND site_slug=? AND (release_tag IS NULL OR release_tag='') LIMIT 1");
        $stmt->execute([$revId, $siteSlug]);
        $rev = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rev) throw new Exception('Queued citation not found');
        $key = (string)($rev['citation_key'] ?? '');
        if ($key === '') throw new Exception('Queued citation key missing');
        $allForKeyStmt = $pdo->prepare("SELECT * FROM citation_revisions WHERE site_slug=? AND citation_key=? AND (release_tag IS NULL OR release_tag='') ORDER BY id ASC");
        $allForKeyStmt->execute([$siteSlug, $key]);
        $allForKey = $allForKeyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$allForKey) throw new Exception('No queued revisions found for citation');
        $latest = end($allForKey);
        if ($latest) $applyQueuedRevision($latest);
        $releaseTag = $currentReleaseTag ?: ('export-' . date('Ymd-His'));
        $pdo->prepare("UPDATE citation_revisions SET release_tag=? WHERE site_slug=? AND citation_key=? AND (release_tag IS NULL OR release_tag='')")->execute([$releaseTag, $siteSlug, $key]);
        if ($pdo->inTransaction()) $pdo->commit();
        $notice = 'Citation exported from bundle.';
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Export failed: ' . $e->getMessage();
      }
    }

    if ($citationsOnly && isset($_POST['export_all_citations'])) {
      try {
        $pdo = nx_db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM citation_revisions WHERE site_slug=? AND (release_tag IS NULL OR release_tag='') ORDER BY id ASC");
        $stmt->execute([$siteSlug]);
        $queued = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$queued) throw new Exception('No queued citations to export');
        $byKey = [];
        foreach ($queued as $rev) {
          $k = (string)($rev['citation_key'] ?? '');
          if ($k === '') continue;
          $byKey[$k][] = $rev;
        }
        foreach ($byKey as $k => $revs) {
          $latest = end($revs);
          if ($latest) $applyQueuedRevision($latest);
        }
        $releaseTag = $currentReleaseTag ?: ('export-' . date('Ymd-His'));
        $pdo->prepare("UPDATE citation_revisions SET release_tag=? WHERE site_slug=? AND (release_tag IS NULL OR release_tag='')")->execute([$releaseTag, $siteSlug]);
        if ($pdo->inTransaction()) $pdo->commit();
        $notice = 'Exported all queued citations (' . count($queued) . ').';
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Export failed: ' . $e->getMessage();
      }
    }

    if ($citationsOnly && isset($_POST['discard_all_citations'])) {
      try {
        $pdo = nx_db();
        $stmt = $pdo->prepare("DELETE FROM citation_revisions WHERE site_slug=? AND (release_tag IS NULL OR release_tag='')");
        $stmt->execute([$siteSlug]);
        $notice = 'Discarded all queued changes.';
      } catch (\Throwable $e) {
        $notice = 'Discard failed: ' . $e->getMessage();
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
        if ($citationsOnly) {
          // Queue restore: do not touch live citation until export.
          $key = (string)($rev['citation_key'] ?? ($target['example_key'] ?? $before['example_key'] ?? ''));
          if ($key !== '') $clearQueuedByKey($key);
          $recordRevision('rollback', $before ?? [], $target ?: null, null);
          $notice = 'Restore queued. Export bundle to apply this version live.';
        } else {
          if ($target) {
            $after = $applySnapshot($target);
            $recordRevision('rollback', $before ?? [], $after ?? [], $currentReleaseTag);
          } else {
            if ($before && isset($before['id'])) {
              CitationExample::delete((int)$before['id'], $siteSlug);
            }
            $recordRevision('rollback', $before ?? [], null, $currentReleaseTag);
          }
          $notice = 'Rolled back.';
        }
        $pdo->commit();
      } catch (\Throwable $e) {
        nx_safe_rollback($pdo ?? null);
        $notice = 'Error during rollback: ' . $e->getMessage();
      }
    }

    if (!$citationsOnly && isset($_POST['export_release'])) {
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
  switch ((string)$_GET['saved']) {
    case 'theme':
      $notice = 'Theme saved.';
      break;
    case 'header':
      $notice = 'Header saved.';
      break;
    case 'deleted':
      $notice = 'Page moved to Deleted pages. It will be permanently removed after 30 days.';
      break;
    case 'restored':
      $notice = 'Deleted page restored to the active pages list.';
      break;
    case 'form':
      $notice = 'Form saved.';
      break;
    default:
      $notice = 'Saved.';
      break;
  }
}

DeletedPage::purgeExpired();
$pages = Page::listBySite($siteId);
$siteForms = SiteForm::listBySite($siteId);
$siteFormResponses = [];
foreach ($siteForms as $siteFormRow) {
  $formRowId = (int)($siteFormRow['id'] ?? 0);
  if ($formRowId > 0) {
    $siteFormResponses[$formRowId] = FormResponse::listByForm($siteId, $formRowId);
  }
}
$deletedPages = $isSuperAdmin ? DeletedPage::listBySite($siteId) : [];
$editingSiteFormId = (int)($_GET['edit_form'] ?? 0);
$editingSiteForm = $editingSiteForm ?? null;
if (!$editingSiteForm && $editingSiteFormId > 0) {
  $candidateForm = SiteForm::find($editingSiteFormId);
  if ($candidateForm && (int)($candidateForm['site_id'] ?? 0) === $siteId) {
    $editingSiteForm = $candidateForm;
  }
}
if (!$editingSiteForm) {
  $editingSiteForm = [
    'id' => 0,
    'name' => '',
    'description' => '',
    'questions' => [
      ['id' => '', 'label' => '', 'type' => 'text'],
    ],
  ];
}

// Ensure a default Home page exists (blank draft)
$homeExisting = Page::findBySlugAnyStatus($siteId, 'home');
if (!$homeExisting) {
  $homeDoc = ['version'=>1,'rows'=>[ ['cols'=>[['span'=>12,'blocks'=>[]]]] ]];
  $homeId = Page::create($siteId, 'Home', 'home', $homeDoc, 'blank', null);
  PartialsManager::ensurePageDirectory((string)($site['slug'] ?? ''), 'home');
  Site::setHomepage($siteId, $homeId);
  $pages = Page::listBySite($siteId); // refresh
}

$hasHomeSignedInVariant = false;
foreach ($pages as $pRow) {
  if (strtolower((string)($pRow['slug'] ?? '')) === 'home-signed-in') {
    $hasHomeSignedInVariant = true;
    break;
  }
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
$citationStyleDocuments = [];
if ($siteSlug === 'cite-them-right') {
  $citationRevisions = CitationRevision::recent($siteSlug, 5000);
  $citationReleases = CitationRelease::listAll($siteSlug);
  $citationStyleDocuments = CitationStyleDocument::listForSiteSlug($siteSlug);
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
$queuedBundleItems = [];
$citationExamplesView = $citationExamples;
$revisionViewerSeed = [];
$liveCitationSeed = [];
$styleDocumentSeed = [];
if ($siteSlug === 'cite-them-right') {
  foreach ($citationExamples as $exRow) {
    $row = [
      'id' => (int)($exRow['id'] ?? 0),
      'example_key' => (string)($exRow['example_key'] ?? ''),
      'label' => (string)($exRow['label'] ?? ''),
      'referencing_style' => (string)($exRow['referencing_style'] ?? ''),
      'category' => (string)($exRow['category'] ?? ''),
      'sub_category' => (string)($exRow['sub_category'] ?? ''),
      'citation_order' => (string)($exRow['citation_order'] ?? ''),
      'example_heading' => (string)($exRow['example_heading'] ?? ''),
      'example_body' => (string)($exRow['example_body'] ?? ''),
      'you_try' => (string)($exRow['you_try'] ?? ''),
      'notes' => (string)($exRow['notes'] ?? ''),
    ];
    if ($row['example_key'] !== '') $liveCitationSeed['key:' . $row['example_key']] = $row;
    if ($row['id'] > 0) $liveCitationSeed['id:' . $row['id']] = $row;
  }
  if ($citationsOnly) {
    $queuedRows = array_values(array_filter($citationRevisions, function($rev){
      $tag = trim((string)($rev['release_tag'] ?? ''));
      return $tag === '';
    }));
    foreach ($queuedRows as $rev) {
      $key = (string)($rev['citation_key'] ?? '');
      if ($key === '') continue;
      $latestByKey[$key] = $rev; // keep latest queued per key
    }
    $stagedCount = count($latestByKey);
    foreach ($latestByKey as $key => $rev) {
      $stagedKeys[$key] = true;
      $queuedBundleItems[] = $rev;
    }
    // Build a staged "latest" view so edit/view reflects queued, unexported changes.
    $byKeyIndex = [];
    foreach ($citationExamplesView as $idx => $exRow) {
      $k = (string)($exRow['example_key'] ?? '');
      if ($k !== '') $byKeyIndex[$k] = $idx;
    }
    foreach ($latestByKey as $key => $rev) {
      $action = (string)($rev['action'] ?? '');
      $after = json_decode($rev['after_json'] ?? 'null', true);
      $before = json_decode($rev['before_json'] ?? 'null', true);
      if ($action === 'update' && is_array($after) && isset($byKeyIndex[$key])) {
        $idx = $byKeyIndex[$key];
        $baseId = $citationExamplesView[$idx]['id'] ?? null;
        $citationExamplesView[$idx] = array_merge($citationExamplesView[$idx], $after);
        if (!isset($citationExamplesView[$idx]['id']) || (int)$citationExamplesView[$idx]['id'] <= 0) {
          $citationExamplesView[$idx]['id'] = $baseId;
        }
      } elseif ($action === 'create' && is_array($after) && !isset($byKeyIndex[$key])) {
        $citationExamplesView[] = [
          'id' => (int)($after['id'] ?? 0),
          'site_slug' => $siteSlug,
          'referencing_style' => (string)($after['referencing_style'] ?? ''),
          'category' => (string)($after['category'] ?? ''),
          'sub_category' => $after['sub_category'] ?? null,
          'example_key' => (string)($after['example_key'] ?? $key),
          'label' => (string)($after['label'] ?? ''),
          'citation_order' => (string)($after['citation_order'] ?? ''),
          'example_heading' => (string)($after['example_heading'] ?? ''),
          'example_body' => (string)($after['example_body'] ?? ''),
          'you_try' => (string)($after['you_try'] ?? ''),
          'notes' => (string)($after['notes'] ?? ''),
        ];
      } elseif ($action === 'delete' && is_array($before) && isset($byKeyIndex[$key])) {
        // Keep row visible until export, but preserve latest known staged fields if present.
        $idx = $byKeyIndex[$key];
        $citationExamplesView[$idx] = array_merge($citationExamplesView[$idx], $before);
      }
    }
  } else {
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
  $userDisplayById = [];
  try {
    $uRows = DB::pdo()->query("SELECT id, display_name, email FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($uRows as $u) {
      $uid = (int)($u['id'] ?? 0);
      if ($uid <= 0) continue;
      $disp = trim((string)($u['display_name'] ?? ''));
      $mail = trim((string)($u['email'] ?? ''));
      $userDisplayById[$uid] = $disp !== '' ? $disp : $mail;
    }
  } catch (\Throwable $e) {
    $userDisplayById = [];
  }
  foreach ($citationRevisions as $rev) {
    $after = json_decode((string)($rev['after_json'] ?? 'null'), true) ?: [];
    $before = json_decode((string)($rev['before_json'] ?? 'null'), true) ?: [];
    $revUserId = (int)($rev['user_id'] ?? 0);
    $revUserEmail = trim((string)($rev['user_email'] ?? ''));
    $revUserDisplay = trim((string)($userDisplayById[$revUserId] ?? ''));
    if ($revUserDisplay === '') $revUserDisplay = $revUserEmail;
    $revisionViewerSeed[] = [
      'id' => (int)($rev['id'] ?? 0),
      'key' => (string)($rev['citation_key'] ?? ''),
      'citationId' => (string)($rev['citation_id'] ?? ''),
      'label' => (string)($after['label'] ?? $before['label'] ?? $rev['citation_key'] ?? 'Citation revision'),
      'style' => (string)($after['referencing_style'] ?? $before['referencing_style'] ?? ''),
      'action' => strtolower((string)($rev['action'] ?? '')),
      'user' => $revUserDisplay,
      'date' => (string)($rev['created_at'] ?? ''),
      'release' => (string)($rev['release_tag'] ?? ''),
      'before' => $before,
      'after' => $after,
    ];
  }
  foreach ($citationStyleDocuments as $doc) {
    $styleDocumentSeed[] = [
      'id' => (int)($doc['id'] ?? 0),
      'style' => (string)($doc['referencing_style'] ?? ''),
      'type' => (string)($doc['doc_type'] ?? ''),
      'category' => (string)($doc['category'] ?? ''),
      'subCategory' => (string)($doc['sub_category'] ?? ''),
      'title' => (string)($doc['title'] ?? ''),
      'body' => (string)($doc['body'] ?? ''),
      'updatedBy' => (string)($doc['updated_by_email'] ?? ''),
      'updatedAt' => (string)($doc['updated_at'] ?? ''),
    ];
  }
}
$citationViewCategories = [];
$citationViewSubCategories = [];
foreach ($citationExamplesView as $exRow) {
  $cat = trim((string)($exRow['category'] ?? ''));
  $sub = trim((string)($exRow['sub_category'] ?? ''));
  if ($cat !== '') $citationViewCategories[$cat] = true;
  if ($sub !== '') $citationViewSubCategories[$sub] = true;
}
$citationViewCategories = array_keys($citationViewCategories);
$citationViewSubCategories = array_keys($citationViewSubCategories);
sort($citationViewCategories, SORT_NATURAL | SORT_FLAG_CASE);
sort($citationViewSubCategories, SORT_NATURAL | SORT_FLAG_CASE);

$base = base_path();
$themeIsLight = ui_theme_is_light();
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
  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function() {
      document.documentElement.classList.toggle('theme-light', <?= $themeIsLight ? 'true' : 'false' ?>);
    })();
  </script>
  <style>
    :root {
      --bg: #0f172a;
      --panel: #111827;
      --card: #111827;
      --border: #334155;
      --muted: #94a3b8;
      --text: #e5e7eb;
      --primary: #3b82f6;
      --primary-strong: #1d4ed8;
      --radius: 4px;
      --shadow: none;
      --focus: 0 0 0 2px rgba(59,130,246,0.28);
      --field-bg: #0b1220;
      --field-border: #334155;
    }
    .theme-light {
      --bg: #f3f4f6;
      --panel: #ffffff;
      --card: #ffffff;
      --border: #d1d5db;
      --muted: #4b5563;
      --text: #0f172a;
      --primary: #2563eb;
      --primary-strong: #1d4ed8;
      --shadow: none;
      --focus: 0 0 0 2px rgba(37,99,235,0.22);
      --field-bg: #f9fafb;
      --field-border: #d1d5db;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      background: var(--bg);
      color:var(--text);
      font-family:Arial, Helvetica, sans-serif;
      line-height:1.4;
      transition:background .2s ease,color .2s ease;
    }
    a{color:inherit;text-decoration:none}
    a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline:none; box-shadow:var(--focus); border-color:var(--primary); }
    main { max-width:100%; margin:0; padding:14px; }
    .wrap{max-width:none;margin:0;padding:0;display:grid;gap:12px}
    .top{
      display:flex;justify-content:space-between;align-items:flex-end;gap:12px;
      padding:16px 18px;border:1px solid var(--border);border-radius:4px;
      background:var(--card);
    }
    .crumbs{color:var(--muted);font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
    .card{border:1px solid var(--border);border-radius:4px;background:var(--card);padding:16px;box-shadow:none}
    .card h2{margin:0 0 10px 0;font-size:18px}
    .muted{color:var(--muted);font-size:13px}
    label{display:block;margin:10px 0 6px 0;color:var(--muted);font-size:13px}
    input,select,textarea{width:100%;padding:10px 12px;border-radius:4px;border:1px solid var(--field-border);background:var(--field-bg);color:var(--text);font-weight:400;}
    textarea{overflow:hidden;resize:vertical;min-height:40px;}
    ::placeholder{color:var(--muted);opacity:0.9;}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    button{padding:9px 12px;border-radius:4px;border:1px solid var(--border);background:var(--field-bg);color:var(--text);cursor:pointer;font-weight:600}
    button:hover{background:color-mix(in srgb, var(--primary) 10%, var(--field-bg))}
    .notice{margin-top:0;padding:10px 12px;border-radius:4px;border:1px solid rgba(34,197,94,.35);
      background:rgba(34,197,94,.10)}
    .tabs{
      display:flex;gap:8px;flex-wrap:wrap;
      position:sticky;top:10px;z-index:5;align-items:center;
      padding:10px 12px;
      background:var(--card);
      border:1px solid var(--border);
      border-radius:4px;
      box-shadow:none;
    }
    .tab{padding:8px 12px;border-radius:4px;border:1px solid var(--border);background:var(--field-bg);cursor:pointer;color:var(--text);font-weight:700;min-height:36px;box-shadow:none;font-size:13px;letter-spacing:.02em;}
    .tab.active{background:var(--primary);color:#fff;border-color:color-mix(in srgb, var(--primary) 68%, var(--border));box-shadow:none;}
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
      position:relative;
      overflow:hidden;
      padding:12px;
      display:flex;
      align-items:flex-end;
    }
    .thumb-title{position:relative;z-index:2;font-weight:900;font-size:36px;line-height:1;color:rgba(255,255,255,.92);text-shadow:0 2px 12px rgba(0,0,0,.35);}
    .thumb-blueprint{position:absolute;inset:12px;border:1px solid rgba(148,163,184,.2);background:rgba(15,23,42,.16);}
    .thumb-blueprint::before,.thumb-blueprint::after{content:"";position:absolute;inset:auto;}
    .layout-thumb--blank .thumb-blueprint{border-style:dashed;background:linear-gradient(135deg,rgba(255,255,255,.02),rgba(255,255,255,.01));}
    .layout-thumb--blank .thumb-blueprint::before{left:16px;right:16px;top:50%;height:1px;background:rgba(148,163,184,.28);}
    .layout-thumb--blank .thumb-blueprint::after{top:16px;bottom:16px;left:50%;width:1px;background:rgba(148,163,184,.28);}
    .layout-thumb--title-page .thumb-blueprint{background:
      linear-gradient(rgba(148,163,184,.25),rgba(148,163,184,.25)) 10px 10px/calc(100% - 20px) 18px no-repeat,
      linear-gradient(rgba(148,163,184,.18),rgba(148,163,184,.18)) 10px 36px/31% 20px no-repeat,
      linear-gradient(rgba(148,163,184,.18),rgba(148,163,184,.18)) calc(34% + 4px) 36px/31% 20px no-repeat,
      linear-gradient(rgba(148,163,184,.18),rgba(148,163,184,.18)) calc(68% - 2px) 36px/22% 20px no-repeat,
      linear-gradient(rgba(148,163,184,.2),rgba(148,163,184,.2)) 10px 64px/calc(100% - 20px) 1px no-repeat,
      linear-gradient(rgba(148,163,184,.16),rgba(148,163,184,.16)) 10px 74px/48% 26px no-repeat,
      linear-gradient(rgba(148,163,184,.12),rgba(148,163,184,.12)) calc(48% + 18px) 74px/calc(52% - 28px) 26px no-repeat;
    }
    .layout-thumb--referencing-browse .thumb-blueprint{background:
      linear-gradient(rgba(148,163,184,.22),rgba(148,163,184,.22)) 10px 10px/calc(100% - 20px) 16px no-repeat,
      linear-gradient(rgba(148,163,184,.18),rgba(148,163,184,.18)) 10px 34px/calc(100% - 20px) 10px no-repeat,
      linear-gradient(rgba(148,163,184,.18),rgba(148,163,184,.18)) 10px 52px/60% 48px no-repeat,
      linear-gradient(rgba(148,163,184,.12),rgba(148,163,184,.12)) calc(60% + 18px) 52px/calc(40% - 28px) 48px no-repeat;
    }
    .layout-thumb--referencing-browse .thumb-blueprint::before{left:20px;top:60px;width:44%;height:1px;background:rgba(226,232,240,.36);box-shadow:0 10px 0 rgba(226,232,240,.36),0 20px 0 rgba(226,232,240,.36);}
    .layout-thumb--referencing-browse .thumb-blueprint::after{right:14px;top:60px;width:calc(40% - 18px);height:1px;background:rgba(226,232,240,.28);box-shadow:0 12px 0 rgba(226,232,240,.28),0 24px 0 rgba(226,232,240,.28);}
    .layout-thumb--source-type .thumb-blueprint{background:
      linear-gradient(rgba(148,163,184,.22),rgba(148,163,184,.22)) 10px 10px/64% 14px no-repeat,
      linear-gradient(rgba(148,163,184,.18),rgba(148,163,184,.18)) 10px 32px/64% 68px no-repeat,
      linear-gradient(rgba(148,163,184,.12),rgba(148,163,184,.12)) calc(64% + 18px) 10px/calc(36% - 28px) 90px no-repeat;
    }
    .layout-thumb--source-type .thumb-blueprint::before{right:18px;top:18px;width:calc(36% - 36px);height:1px;background:rgba(226,232,240,.32);box-shadow:0 18px 0 rgba(226,232,240,.32),0 36px 0 rgba(226,232,240,.32),0 54px 0 rgba(226,232,240,.32);}
    .layout-body{padding:12px;display:flex;flex-direction:column;gap:8px;flex:1;}
    .layout-title{font-weight:800;font-size:15px;}
    .btn.fill{justify-content:center;width:100%;background:rgba(37,99,235,.22);border-color:rgba(37,99,235,.4);}
    .modal-backdrop{
      position:fixed;inset:0;background:rgba(0,0,0,0.55);display:none;align-items:center;justify-content:center;z-index:1000;
      padding:14px;
      overflow-y:auto;
      overscroll-behavior:contain;
    }
    .modal{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:18px;
      box-shadow:var(--shadow);
      max-width:1020px;
      width:100%;
      height:85vh;
      max-height:calc(100dvh - 28px);
      min-height:0;
      display:flex;
      flex-direction:column;
      padding:18px;
      overscroll-behavior:contain;
    }
    .modal header{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;}
    .modal h3{margin:0;font-size:20px;}
    .modal .close-btn{border:none;background:transparent;color:var(--muted);cursor:pointer;font-size:18px;}
    .modal .layout-grid{grid-template-columns:repeat(auto-fit,minmax(220px,1fr));}
    .page-path-builder{padding:12px;border:1px solid var(--border);border-radius:14px;background:rgba(255,255,255,.03);}
    .page-path-row{margin-bottom:10px;}
    .page-path-row > div{display:grid;gap:6px;}
    .page-path-fixed{grid-template-columns:1fr 1fr;}
    .path-prefix{font-family:"SFMono-Regular","Menlo",monospace;font-size:12px;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.04);margin-bottom:10px;}
    .path-preview-wrap{display:grid;gap:6px;}
    .path-preview{font-family:"SFMono-Regular","Menlo",monospace;font-size:12px;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,.04);word-break:break-all;}
    .modal.modal-danger{
      max-width:560px;
      width:min(560px, 100%);
      height:auto;
      min-height:0;
      padding:0;
      overflow:hidden;
      border-radius:24px;
    }
    .danger-modal-head{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:18px;
      padding:24px 24px 18px;
      margin:0;
      border-bottom:1px solid rgba(255,255,255,0.06);
      background:
        radial-gradient(circle at top left, rgba(239,68,68,0.18), transparent 42%),
        linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0));
    }
    .danger-modal-titlewrap{display:flex;align-items:flex-start;gap:14px;min-width:0;}
    .danger-modal-icon{
      width:52px;height:52px;border-radius:16px;display:grid;place-items:center;flex:0 0 auto;
      background:linear-gradient(180deg, rgba(239,68,68,0.26), rgba(127,29,29,0.3));
      border:1px solid rgba(248,113,113,0.35);
      color:#fecaca;
      box-shadow:inset 0 1px 0 rgba(255,255,255,0.06);
      font-size:24px;
      line-height:1;
    }
    .danger-modal-titlegroup{display:grid;gap:6px;}
    .danger-modal-titlegroup h3{margin:0;font-size:28px;line-height:1.05;letter-spacing:-0.03em;}
    .danger-modal-eyebrow{
      display:inline-flex;align-items:center;gap:8px;
      font-size:12px;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;
      color:#fca5a5;
    }
    .danger-modal-eyebrow::before{
      content:"";
      width:8px;height:8px;border-radius:999px;background:currentColor;display:inline-block;
      box-shadow:0 0 0 6px rgba(248,113,113,0.12);
    }
    .danger-modal-body{
      display:grid;
      gap:18px;
      padding:22px 24px 24px;
    }
    .danger-modal-copy{
      color:var(--muted);
      font-size:15px;
      line-height:1.6;
    }
    .danger-page-card{
      display:grid;
      gap:10px;
      padding:16px 18px;
      border-radius:18px;
      border:1px solid rgba(248,113,113,0.18);
      background:linear-gradient(180deg, rgba(127,29,29,0.14), rgba(255,255,255,0.02));
    }
    .danger-page-label{
      font-size:11px;
      font-weight:800;
      letter-spacing:0.14em;
      text-transform:uppercase;
      color:#fca5a5;
    }
    .danger-page-name{
      font-size:24px;
      line-height:1.15;
      font-weight:800;
      color:var(--text);
      word-break:break-word;
    }
    .danger-modal-note{
      padding:12px 14px;
      border-radius:14px;
      background:rgba(255,255,255,0.04);
      border:1px solid rgba(255,255,255,0.08);
      color:var(--muted);
      font-size:13px;
      line-height:1.5;
    }
    .danger-modal-actions{
      display:flex;
      justify-content:flex-end;
      gap:10px;
      flex-wrap:wrap;
      margin-top:4px;
    }
    .btn.danger-solid{
      background:linear-gradient(180deg,#ef4444,#b91c1c);
      color:#fff;
      border-color:rgba(255,255,255,0.08);
    }
    .btn.subtle{
      background:rgba(255,255,255,0.04);
      border-color:rgba(255,255,255,0.10);
      color:var(--text);
    }
    html.theme-light .danger-modal-head{
      border-bottom-color:rgba(15,23,42,0.08);
      background:
        radial-gradient(circle at top left, rgba(239,68,68,0.12), transparent 42%),
        linear-gradient(180deg, rgba(248,250,252,1), rgba(248,250,252,0.96));
    }
    html.theme-light .danger-modal-icon{
      color:#b91c1c;
      border-color:rgba(239,68,68,0.22);
      background:linear-gradient(180deg, rgba(254,226,226,1), rgba(254,242,242,1));
      box-shadow:none;
    }
    html.theme-light .danger-page-card{
      border-color:rgba(239,68,68,0.16);
      background:linear-gradient(180deg, rgba(254,242,242,1), rgba(255,255,255,1));
    }
    html.theme-light .danger-modal-note{
      background:#fff;
      border-color:rgba(15,23,42,0.08);
    }
    html.theme-light .btn.subtle{
      background:#fff;
      border-color:rgba(15,23,42,0.10);
    }
    /* Ensure citation form fields have clear contrast */
    #citationModalBackdrop input,
    #citationModalBackdrop textarea,
    #citationModalBackdrop select{
      background:rgba(255,255,255,0.08);
      color:var(--text);
      border:1px solid rgba(255,255,255,0.22);
    }
    #citationYouTryField,
    #citationOrderField,
    #citationBodyField,
    #editYouTryField,
    #editOrderField,
    #editBodyField{
      background:rgba(255,255,255,0.08);
      border:1px solid rgba(255,255,255,0.22);
    }
    #citationModalBackdrop input::placeholder,
    #citationModalBackdrop textarea::placeholder{
      color:rgba(226,232,240,0.72);
    }
    #citationModalBackdrop .example-panel{
      background:rgba(255,255,255,0.03);
      border:1px solid rgba(255,255,255,0.14);
    }
    .modal-body{flex:1;overflow:auto;padding-right:4px;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;}
    .modal-footer{position:sticky;bottom:0;background:var(--card);padding-top:12px;margin-top:12px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;}
    .modal-sections{display:grid;gap:24px;}
    .modal-section{padding:0;}
    .section-head{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
    .section-title{font-size:14px;font-weight:700;letter-spacing:0.4px;text-transform:uppercase;}
    .section-sub{color:var(--muted);font-size:13px;}
    .two-col{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}
    .section-surface{background:rgba(17,24,39,0.04);border:1px solid var(--border);border-radius:12px;padding:12px;}
    .example-panel{background:rgba(17,24,39,0.03);border:1px solid rgba(17,24,39,0.08);border-radius:14px;padding:14px;}
    .example-panel input{background:transparent;}
    .example-panel .rich-editor{background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.22);}
    .helper{color:var(--muted);font-size:12px;margin-top:6px;}
    .mini-toolbar{display:flex;gap:6px;margin:6px 0;}
    .mini-toolbar button{border:1px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.1);color:var(--text);padding:5px 10px;border-radius:8px;cursor:pointer;font-size:12px;display:inline-flex;align-items:center;gap:4px;}
    .mini-toolbar button:hover{background:rgba(37,99,235,0.08);}
    .mini-toolbar .toolbar-label{font-weight:600;}
    .rich-editor{
      min-height:120px;
      padding:10px;
      border:1px solid var(--border);
      border-radius:10px;
      background:rgba(255,255,255,0.08);
      color:var(--text);
      outline:none;
      white-space:pre-wrap;
    }
    #citationHeadingField,
    #editHeadingField{
      font-family:"Nunito",system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      font-size:17px;
      line-height:1.2;
      font-weight:800;
    }
    .rich-editor[data-bind="citationOrderField"],
    .rich-editor[data-bind="citationBodyField"],
    .rich-editor[data-bind="citationYouTryField"],
    .rich-editor[data-bind="editOrderField"],
    .rich-editor[data-bind="editBodyField"],
    .rich-editor[data-bind="editYouTryField"]{
      font-family:"Nunito",system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      font-size:16px;
      line-height:1.45;
      font-weight:400;
      color:#1f2937;
    }
    .rich-editor:focus{box-shadow:0 0 0 2px rgba(37,99,235,0.25);border-color:rgba(37,99,235,0.45);}
    html.theme-light #citationModalBackdrop input,
    html.theme-light #citationModalBackdrop textarea,
    html.theme-light #citationModalBackdrop select{
      background:#fff;
      border:1px solid rgba(17,24,39,0.18);
    }
    html.theme-light #citationModalBackdrop input::placeholder,
    html.theme-light #citationModalBackdrop textarea::placeholder{
      color:rgba(71,85,105,0.72);
    }
    html.theme-light .mini-toolbar button{
      background:rgba(255,255,255,0.9);
      border:1px solid var(--border);
    }
    html.theme-light .rich-editor{
      background:#fff;
      border:1px solid var(--border);
    }
    html.theme-light #citationHeadingField,
    html.theme-light #editHeadingField{
      color:#111827;
    }
    html.theme-light .example-panel .rich-editor{
      background:#fff;
      border:1px solid var(--border);
    }
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
    .status-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700;}
    .status-badge.published{background:rgba(74,222,128,.16);color:#16a34a;}
    .status-badge.draft{background:rgba(148,163,184,.22);color:#475569;}
    .status-dot{width:8px;height:8px;border-radius:50%;background:currentColor;display:inline-block;}
    table.page-table{width:100%;margin-top:12px;border-collapse:collapse}
    table.page-table thead tr{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:0.4px;background:var(--field-bg);}
    table.page-table tbody tr{border-top:1px solid var(--border);}
    table.page-table tbody tr:hover{background:color-mix(in srgb, var(--primary) 8%, transparent);}
    table.page-table td, table.page-table th{padding:10px 8px;vertical-align:middle;}
    .title-main{font-weight:800;font-size:15px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    .page-lock-icon{display:inline-flex;align-items:center;justify-content:center;color:#b45309;flex:0 0 auto;}
    .page-lock-icon svg{width:14px;height:14px;display:block;}
    .btn.disabled{opacity:.55;pointer-events:none;cursor:not-allowed;}
    .locked-note{font-size:12px;color:var(--muted);}
    .title-path{font-family:"SFMono-Regular","Menlo",monospace;font-size:12px;color:var(--muted);}
    .page-kind-badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;line-height:1.2;border:1px solid transparent;}
    .page-kind-badge.home-logged-out{background:rgba(59,130,246,.12);color:#1d4ed8;border-color:rgba(59,130,246,.35);}
    .page-kind-badge.home-logged-in{background:rgba(16,185,129,.12);color:#047857;border-color:rgba(16,185,129,.35);}
    .btn.icon{padding:8px 10px;gap:6px;}
    .btn.text{background:transparent;border-color:transparent;padding:8px 10px;}
    .btn.danger-outline{color:#fca5a5;border-color:rgba(248,113,113,.3);background:rgba(248,113,113,.05);}
    .kebab{position:relative;}
    .kebab-btn{padding:8px 10px;border-radius:4px;border:1px solid var(--border);background:var(--field-bg);color:var(--muted);cursor:pointer;}
    .kebab-menu{position:absolute;right:0;top:110%;background:var(--card);border:1px solid var(--border);border-radius:4px;box-shadow:none;min-width:160px;display:none;z-index:30;}
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
  .citation-ops-table{table-layout:fixed;}
  .citation-ops-table th:nth-child(1),.citation-ops-table td:nth-child(1){width:18%;}
  .citation-ops-table th:nth-child(2),.citation-ops-table td:nth-child(2){width:11%;}
  .citation-ops-table th:nth-child(3),.citation-ops-table td:nth-child(3){width:12%;}
  .citation-ops-table th:nth-child(4),.citation-ops-table td:nth-child(4){width:14%;}
  .citation-ops-table th:nth-child(5),.citation-ops-table td:nth-child(5){width:18%;}
  .citation-ops-table th:nth-child(6),.citation-ops-table td:nth-child(6){width:9%;}
  .citation-ops-table th:nth-child(7),.citation-ops-table td:nth-child(7){width:18%;}
  .citation-ops-table .citation-label{max-width:100%;overflow-wrap:anywhere;}
  .citation-ops-table td{overflow:hidden;}
  .citation-key-wrap{
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    max-width:100%;
    white-space:normal;
    overflow:hidden;
    overflow-wrap:anywhere;
    word-break:break-word;
    line-height:1.25;
  }
  .citation-table-wrap{overflow:auto;width:100%;}
  .citation-data-table{
    min-width:980px;
    font-family:"Inter","SF Pro Text","Segoe UI",Roboto,Arial,sans-serif;
    font-feature-settings:"tnum" 1,"ss01" 1;
    letter-spacing:0;
  }
  .citation-data-table th,.citation-data-table td{vertical-align:top;}
  .citation-data-table th{
    font-size:11px;
    font-weight:800;
  }
  .citation-data-table td{
    font-size:13px;
  }
  .citation-data-text{max-width:360px;max-height:98px;overflow:hidden;line-height:1.45;color:var(--text);}
  .citation-data-text p{margin:0 0 6px;}
  .citation-data-text p:last-child{margin-bottom:0;}
  .citation-data-text ul,.citation-data-text ol{margin:0 0 6px 18px;padding:0;}
  .citation-data-meta{min-width:130px;}
  .citation-row{cursor:pointer;}
  .citation-row:hover{background:rgba(255,255,255,0.04);}
  .citation-label{font-weight:800;font-size:15px;}
  .citation-style-pill{border-radius:999px;padding:6px 10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);font-weight:700;font-size:12px;}
  .badge-chip{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid var(--border);}
  .badge-chip.staged{background:rgba(37,99,235,0.12);color:#bfdbfe;border-color:rgba(59,130,246,0.4);}
  .citation-toolbar{
    margin:14px 0 18px;
    padding:14px;
    border:1px solid color-mix(in srgb, var(--border) 72%, transparent);
    border-radius:18px;
    background:linear-gradient(180deg, color-mix(in srgb, var(--card) 94%, #f8f9fb 6%), color-mix(in srgb, var(--card) 98%, #f8f9fb 2%));
    box-shadow:0 16px 40px rgba(2,6,23,0.12);
    display:grid;
    gap:12px;
  }
  html.theme-light .citation-toolbar{
    background:#f8f9fb;
    border-color:#e5e7eb;
    box-shadow:0 14px 34px rgba(15,23,42,0.06);
  }
  .citation-toolbar-top{display:flex;gap:12px;align-items:center;justify-content:space-between;}
  .citation-search-shell{
    position:relative;
    flex:1 1 460px;
    min-width:260px;
    display:flex;
    align-items:center;
  }
  .citation-search-shell svg{
    position:absolute;
    left:14px;
    width:18px;
    height:18px;
    color:var(--muted);
    pointer-events:none;
  }
  .citation-search-shell input{
    min-height:46px;
    padding:12px 14px 12px 42px;
    border-radius:14px;
    border:1px solid color-mix(in srgb, var(--border) 72%, transparent);
    background:var(--card);
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.04);
    font-size:14px;
    font-weight:600;
  }
  html.theme-light .citation-search-shell input{
    background:#fff;
    border-color:#e5e7eb;
    box-shadow:0 1px 2px rgba(15,23,42,0.04);
  }
  .citation-toolbar-main{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;}
  .citation-filter-group{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0;}
  .citation-group-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-right:2px;}
  .citation-filter-chip{
    display:inline-flex;
    align-items:center;
    gap:7px;
    min-height:36px;
    padding:0 10px;
    border:1px solid color-mix(in srgb, var(--border) 70%, transparent);
    border-radius:999px;
    background:color-mix(in srgb, var(--card) 92%, #ffffff 8%);
    color:var(--text);
    transition:background .18s ease,border-color .18s ease,box-shadow .18s ease,transform .18s ease;
  }
  .citation-filter-chip:hover{border-color:color-mix(in srgb, var(--primary) 42%, var(--border));box-shadow:0 6px 18px rgba(2,6,23,0.08);transform:translateY(-1px);}
  .citation-filter-chip.active{border-color:color-mix(in srgb, var(--primary) 50%, var(--border));background:color-mix(in srgb, var(--primary) 12%, var(--card));}
  .citation-filter-chip span{font-size:12px;font-weight:800;color:var(--muted);}
  .citation-filter-chip select{
    width:auto;
    min-width:82px;
    height:28px;
    padding:0 20px 0 0;
    border:0;
    border-radius:0;
    background:transparent;
    color:var(--text);
    font-size:13px;
    font-weight:800;
    outline:0;
  }
  .citation-toolbar-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-left:auto;}
  .citation-column-menu-wrap{position:relative;display:inline-flex;}
  .citation-ghost-btn{
    min-height:36px;
    border-radius:999px;
    border:1px solid color-mix(in srgb, var(--border) 70%, transparent);
    background:transparent;
    color:var(--text);
    font-size:13px;
    font-weight:800;
    padding:0 12px;
    display:inline-flex;
    align-items:center;
    gap:7px;
    transition:background .18s ease,border-color .18s ease,color .18s ease;
  }
  .citation-ghost-btn:hover{background:color-mix(in srgb, var(--primary) 8%, transparent);border-color:color-mix(in srgb, var(--primary) 34%, var(--border));}
  .citation-column-menu{
    position:absolute;
    right:0;
    top:calc(100% + 8px);
    width:260px;
    padding:10px;
    border:1px solid color-mix(in srgb, var(--border) 70%, transparent);
    border-radius:16px;
    background:var(--card);
    box-shadow:0 18px 42px rgba(2,6,23,0.22);
    z-index:80;
    display:none;
  }
  .citation-column-menu.open{display:grid;gap:6px;animation:citationToolbarIn .16s ease;}
  html.theme-light .citation-column-menu{background:#fff;border-color:#e5e7eb;box-shadow:0 18px 42px rgba(15,23,42,0.12);}
  .citation-column-menu-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);padding:4px 6px 6px;}
  .citation-column-option{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    min-height:34px;
    padding:6px 8px;
    border-radius:10px;
    color:var(--text);
    font-size:13px;
    font-weight:750;
    cursor:pointer;
  }
  .citation-column-option:hover{background:color-mix(in srgb, var(--primary) 8%, transparent);}
  .citation-column-option input{width:16px;height:16px;margin:0;accent-color:var(--primary);}
  .citation-filter-count{
    display:none;
    min-width:18px;
    height:18px;
    padding:0 6px;
    border-radius:999px;
    align-items:center;
    justify-content:center;
    background:var(--primary);
    color:#fff;
    font-size:11px;
    line-height:18px;
  }
  .citation-filter-count.active{display:inline-flex;}
  .citation-advanced-filters{
    display:none;
    gap:12px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    padding-top:12px;
    border-top:1px solid color-mix(in srgb, var(--border) 58%, transparent);
  }
  .citation-advanced-filters.open{display:flex;animation:citationToolbarIn .18s ease;}
  .citation-presets{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .citation-preset-label,.citation-recent-filters{font-size:12px;color:var(--muted);font-weight:700;}
  .citation-preset-btn{
    min-height:30px;
    padding:0 10px;
    border-radius:999px;
    border:1px solid transparent;
    background:color-mix(in srgb, var(--primary) 8%, transparent);
    color:var(--text);
    font-size:12px;
    font-weight:800;
  }
  .citation-preset-btn:hover{border-color:color-mix(in srgb, var(--primary) 30%, var(--border));background:color-mix(in srgb, var(--primary) 12%, transparent);}
  .citation-toolbar-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;color:var(--muted);font-size:12px;}
  .citation-ai-hint{display:inline-flex;align-items:center;gap:8px;font-weight:700;}
  .citation-ai-dot{width:7px;height:7px;border-radius:999px;background:var(--primary);box-shadow:0 0 0 4px color-mix(in srgb, var(--primary) 12%, transparent);}
  .citation-view-toggle{display:inline-flex;gap:3px;padding:3px;border:1px solid color-mix(in srgb, var(--border) 70%, transparent);border-radius:14px;background:color-mix(in srgb, var(--card) 86%, #fff 14%);}
  .citation-view-toggle button{border:0;border-radius:11px;background:transparent;color:var(--muted);font:inherit;font-weight:800;padding:8px 12px;cursor:pointer;transition:background .18s ease,color .18s ease,box-shadow .18s ease;}
  .citation-view-toggle button:hover{color:var(--text);background:color-mix(in srgb, var(--primary) 8%, transparent);}
  .citation-view-toggle button.active{background:var(--text);color:var(--bg);box-shadow:0 6px 16px rgba(2,6,23,0.16);}
  html.theme-light .citation-view-toggle{background:#fff;border-color:#e5e7eb;}
  html.theme-light .citation-view-toggle button.active{background:#111827;color:#fff;}
  @keyframes citationToolbarIn{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);}}
  @media(max-width:860px){
    .citation-toolbar-top{align-items:stretch;flex-direction:column;}
    .citation-search-shell{flex-basis:auto;min-width:0;}
    .citation-toolbar-actions{width:100%;margin-left:0;}
    .citation-ghost-btn{flex:1;}
  }
  .citation-no-results{display:none;margin-top:12px;color:var(--muted);}
  .citation-row-actions{display:flex;align-items:center;gap:6px;flex-wrap:nowrap;justify-content:flex-end;white-space:nowrap;}
  .citation-row-actions form{flex:0 0 auto;}
  .citation-doc-menu{position:relative;display:inline-flex;}
  .citation-doc-menu-btn{width:32px;height:32px;border:1px solid var(--border);border-radius:8px;background:transparent;color:var(--text);font-size:18px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}
  .citation-doc-menu-btn:hover{background:color-mix(in srgb, var(--primary) 8%, transparent);border-color:color-mix(in srgb, var(--primary) 32%, var(--border));}
  .citation-doc-menu-list{position:absolute;right:0;top:calc(100% + 6px);min-width:220px;padding:8px;border:1px solid var(--border);border-radius:12px;background:var(--card);box-shadow:0 18px 42px rgba(2,6,23,0.22);z-index:90;display:none;}
  .citation-doc-menu.open .citation-doc-menu-list{display:grid;gap:6px;}
  .citation-doc-menu-list button{width:100%;text-align:left;border:0;border-radius:8px;background:transparent;color:var(--text);font:inherit;font-size:13px;font-weight:750;padding:8px 9px;cursor:pointer;}
  .citation-doc-menu-list button:hover{background:color-mix(in srgb, var(--primary) 10%, transparent);}
  .style-library-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:16px;}
  .style-doc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:14px;}
  .style-doc-card{border:1px solid var(--border);border-radius:14px;background:color-mix(in srgb, var(--card) 96%, #fff 4%);padding:13px;display:grid;gap:9px;min-width:0;}
  .style-doc-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}
  .style-doc-title{font-size:15px;font-weight:850;color:var(--text);line-height:1.25;}
  .style-doc-meta{display:flex;gap:6px;flex-wrap:wrap;}
  .style-doc-pill{display:inline-flex;align-items:center;min-height:24px;padding:0 8px;border-radius:999px;border:1px solid var(--border);background:rgba(255,255,255,0.04);font-size:11px;font-weight:800;color:var(--muted);}
  .style-doc-preview{font-size:13px;color:var(--muted);line-height:1.45;}
  .style-doc-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .doc-viewer-body{white-space:pre-wrap;line-height:1.55;}
  .doc-viewer-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;}
  .doc-modal-grid{display:grid;gap:10px;}
  .doc-modal-grid .row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
  .doc-modal-grid textarea{min-height:220px;resize:vertical;}
  #styleDocumentModal{padding:0;overflow:hidden;}
  #styleDocumentForm{display:flex;flex-direction:column;min-height:0;height:100%;margin:0;}
  #styleDocumentModal .modal-head{padding:18px 18px 12px;border-bottom:1px solid var(--border);}
  #styleDocumentModal .modal-body{flex:1;min-height:0;overflow-y:auto;padding:0 18px 16px;}
  #styleDocumentModal .modal-footer{position:static;margin:0;padding:12px 18px;border-top:1px solid var(--border);background:var(--card);}
  @media(max-width:720px){.doc-modal-grid .row{grid-template-columns:1fr;}}
  html.theme-light .citation-doc-menu-list,
  html.theme-light .style-doc-card{background:#fff;border-color:#e2e8f0;box-shadow:0 1px 2px rgba(15,23,42,0.04);}
  .analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:12px;}
  .analytic-card{border:1px solid var(--border);border-radius:4px;padding:12px;background:var(--field-bg);display:flex;flex-direction:column;gap:6px;}
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
  .trend-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:4px;border:1px solid var(--border);background:var(--field-bg);font-weight:700;font-size:12px;}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:var(--field-bg);font-weight:700;font-size:12px;}
  .cite-viewer{position:fixed;top:0;right:0;bottom:auto;left:auto;width:520px;max-width:100vw;height:100vh;height:100dvh;max-height:100vh;max-height:100dvh;background:linear-gradient(180deg, rgba(17,24,39,0.98), rgba(15,23,42,1));border-left:1px solid rgba(148,163,184,0.18);box-shadow:-18px 0 40px rgba(2,6,23,0.38);transition:transform 0.25s ease, visibility 0s linear 0.25s;z-index:2600;display:flex;flex-direction:column;transform:translateX(100%);overflow:hidden;visibility:hidden;pointer-events:none;contain:layout paint;}
  .cite-viewer.active{transform:translateX(0);visibility:visible;pointer-events:auto;transition-delay:0s;}
  .cite-viewer header{position:sticky;top:0;z-index:2;padding:18px 18px 14px;border-bottom:1px solid rgba(148,163,184,0.14);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0));}
  .cite-viewer .actions-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-bottom:1px solid rgba(148,163,184,0.12);padding:12px 18px;background:rgba(15,23,42,0.72);}
  .cite-viewer main{padding:14px;overflow-y:auto;overflow-x:hidden;flex:0 1 auto;display:grid;gap:10px;max-width:100%;margin:0;width:100%;}
  .cite-viewer .section{margin:0;}
  .cite-viewer footer{padding:12px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;}
  .cite-viewer main.viewer-body{display:flex;flex-direction:column;align-items:stretch;}
  .cite-viewer .viewer-body{padding:12px 14px 16px;overflow-y:auto;overflow-x:hidden;overscroll-behavior-y:contain;-webkit-overflow-scrolling:touch;flex:1;min-height:0;display:flex;flex-direction:column;gap:8px;background:transparent;align-items:stretch;}
  .cite-viewer .viewer-body{scrollbar-width:none;-ms-overflow-style:none;}
  .cite-viewer .viewer-body::-webkit-scrollbar{display:none;width:0;height:0;}
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body){gap:8px;}
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body){
    justify-content:flex-start;
    align-content:flex-start;
  }
  .cite-viewer,
  .cite-viewer header,
  .cite-viewer main,
  .cite-viewer footer,
  .cite-viewer .section,
  .cite-viewer .citation-field,
  .cite-viewer .citation-field strong,
  .cite-viewer .collection-name,
  .cite-viewer .meta-value{
    color:var(--text);
  }
  .cite-viewer .muted,
  .cite-viewer #viewOrder,
  .cite-viewer #viewExampleBody,
  .cite-viewer #viewYouTry,
  .cite-viewer #viewNotes,
  .cite-viewer #citationRevisionsHint{
    color:#cbd5e1;
  }
  .cite-viewer .viewer-body p,
  .cite-viewer .viewer-body div,
  .cite-viewer .viewer-body span,
  .cite-viewer .viewer-body li,
  .cite-viewer .viewer-body ul,
  .cite-viewer .viewer-body ol,
  .cite-viewer .viewer-body a,
  .cite-viewer .viewer-body em,
  .cite-viewer .viewer-body i,
  .cite-viewer .viewer-body b,
  .cite-viewer .viewer-body strong{
    color:inherit;
  }
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) p,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) ul,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) ol{
    margin:0;
  }
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) h1,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) h2,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) h3,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) h4,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) h5,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) h6{
    margin:0;
  }
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) ul,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) ol{
    padding-left:18px;
  }
  .cite-viewer .viewer-body > *{width:100%;max-width:none;}
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) > *{
    flex:0 0 auto;
    min-height:0;
  }
  .cite-viewer .viewer-body .citation-field{width:100%;max-width:none;}
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) .citation-field{
    display:grid;
    gap:4px;
    padding:10px 12px;
    margin:0;
    background:rgba(255,255,255,0.03);
    border:1px solid rgba(148,163,184,0.12);
    border-radius:14px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.03);
  }
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) .citation-field > :first-child{
    margin-top:0;
  }
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) .citation-field > :last-child{
    margin-bottom:0;
  }
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) > .view-meta-grid,
  .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) > .citation-field{
    align-self:stretch;
  }
  .cite-viewer .viewer-body .callout{width:100%;max-width:none;}
  .cite-viewer .viewer-body,
  .cite-viewer #viewOrder,
  .cite-viewer #viewExampleBody,
  .cite-viewer #viewYouTry,
  .cite-viewer #viewNotes{
    font-weight:400;
  }
  .cite-viewer .citation-field > strong,
  .cite-viewer .view-meta-item strong,
  .cite-viewer .collection-name{
    font-weight:700;
  }
  .cite-viewer .citation-label{
    font-size:18px;
    line-height:1.15;
    letter-spacing:-0.02em;
    margin:0 0 2px 0;
  }
  .cite-viewer #viewSubtitle{
    font-size:12px;
    line-height:1.25;
  }
  .cite-viewer .citation-field > strong{
    font-size:11px;
    line-height:1.15;
    letter-spacing:.02em;
    text-transform:uppercase;
    color:var(--text);
    opacity:.95;
  }
  .cite-viewer .collection-name{
    font-size:13px;
    line-height:1.2;
    letter-spacing:-0.01em;
  }
  .cite-viewer #viewExampleHeading{
    font-size:13px;
    text-transform:none;
  }
  .cite-viewer #viewOrder strong,
  .cite-viewer #viewExampleBody strong,
  .cite-viewer #viewYouTry strong,
  .cite-viewer #viewNotes strong,
  .cite-viewer #viewOrder b,
  .cite-viewer #viewExampleBody b,
  .cite-viewer #viewYouTry b,
  .cite-viewer #viewNotes b{
    font-weight:700;
  }
  .cite-viewer #viewOrder,
  .cite-viewer #viewExampleBody,
  .cite-viewer #viewYouTry,
  .cite-viewer #viewNotes,
  .cite-viewer .viewer-body li{
    color:#dbe4f0;
  }
  .cite-viewer .edit-body{
    gap:6px;
    padding:8px 10px 0;
    align-content:start;
    grid-auto-rows:max-content;
    font-family:"Inter","SF Pro Text","Segoe UI",Roboto,Arial,sans-serif;
    letter-spacing:0;
  }
  .cite-viewer .revisions-body{gap:10px;align-content:start;}
  .cite-viewer.edit-mode,
  .cite-viewer.revisions-mode{background:var(--panel);}
  #revTimelineSelect{
    width:100%;
    min-height:38px;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--panel);
    color:var(--text);
    padding:8px 10px;
  }
  .rev-diff-rows{display:grid;gap:8px;}
  .rev-diff-row{
    border:1px solid var(--border);
    border-radius:10px;
    overflow:hidden;
    background:rgba(255,255,255,0.02);
  }
  .rev-diff-row-head{
    font-size:12px;
    font-weight:700;
    color:var(--muted);
    padding:7px 9px;
    border-bottom:1px solid var(--border);
    text-transform:uppercase;
    letter-spacing:.03em;
  }
  .rev-diff-cols{display:grid;grid-template-columns:1fr 1fr;gap:0;}
  .rev-diff-col{padding:8px 9px;border-right:1px solid var(--border);min-height:52px;}
  .rev-diff-col:last-child{border-right:none;}
  .rev-diff-col-label{font-size:11px;color:var(--muted);margin-bottom:4px;}
  .rev-diff-col-body{font-size:12px;line-height:1.4;white-space:pre-wrap;word-break:break-word;}
  .rev-before-removed{opacity:0.78;text-decoration:line-through;}
  .rev-after-added{background:rgba(59,130,246,0.14);border-radius:4px;padding:0 2px;}
  .rev-inline-same{opacity:0.8;}
  .rev-empty-state{
    border:1px dashed var(--border);
    border-radius:10px;
    padding:12px;
    color:var(--muted);
    font-size:12px;
  }
  .citation-rev-item{
    border:1px solid var(--border);
    border-radius:10px;
    background:rgba(255,255,255,0.02);
    overflow:visible;
    position:relative;
  }
  .citation-rev-item summary{
    list-style:none;
    cursor:pointer;
    padding:10px 12px;
    display:grid;
    gap:4px;
  }
  .citation-rev-item summary::-webkit-details-marker{display:none;}
  .citation-rev-item[open] summary{border-bottom:1px solid var(--border);}
  .citation-rev-item[open]{z-index:40;}
  .citation-rev-head{display:flex;align-items:center;justify-content:space-between;gap:8px;}
  .citation-rev-head-right{display:flex;align-items:center;gap:8px;}
  .citation-rev-title{font-size:13px;font-weight:700;}
  .citation-rev-meta{font-size:12px;color:var(--muted);}
  .citation-rev-summary{font-size:12px;color:var(--muted);}
  .citation-rev-body{padding:10px 12px;display:grid;gap:8px;}
  .citation-rev-actions{display:flex;justify-content:flex-end;align-items:center;position:relative;margin-bottom:2px;}
  .citation-rev-kebab{position:relative;display:inline-flex;}
  .citation-rev-kebab-btn{
    border:1px solid var(--border);
    background:transparent;
    color:var(--text);
    border-radius:8px;
    width:32px;
    height:30px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:18px;
    line-height:1;
    padding:0;
    text-align:center;
  }
  .citation-rev-kebab-menu{
    position:absolute;
    right:0;
    top:34px;
    min-width:170px;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--panel);
    padding:8px;
    box-shadow:0 8px 20px rgba(0,0,0,0.22);
    z-index:60;
    display:none;
  }
  .citation-rev-kebab.open .citation-rev-kebab-menu{display:block;}
  .citation-rev-menu-btn{
    width:100%;
    text-align:left;
    border:1px solid var(--border);
    background:transparent;
    color:var(--text);
    border-radius:8px;
    padding:7px 9px;
    cursor:pointer;
    font-weight:700;
  }
  .citation-rev-note{font-size:11px;color:var(--muted);margin-top:2px;}
  #citationRevisionsList{display:grid;gap:8px;align-content:start;}
  .citation-rev-change{
    border:1px solid var(--border);
    border-radius:8px;
    padding:8px;
    background:rgba(255,255,255,0.02);
  }
  .citation-rev-label{font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.03em;margin-bottom:4px;}
  .citation-rev-before,.citation-rev-after{font-size:12px;line-height:1.35;white-space:pre-wrap;word-break:break-word;}
  .citation-rev-before{opacity:.8;}
  .citation-rev-after strong{font-weight:800;}
  html.theme-light #revTimelineSelect{background:#fff;}
  html.theme-light .rev-after-added{background:rgba(37,99,235,0.12);}
  .cite-readonly-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border-radius:999px;border:1px solid rgba(148,163,184,0.16);background:rgba(255,255,255,0.04);font-weight:700;font-size:12px;backdrop-filter:blur(8px);}
  .citation-edit-field input,
  .citation-edit-field select,
  .citation-edit-field textarea{
    background:var(--card);
    border:1px solid var(--border);
    color:inherit;
    font-family:"Inter","SF Pro Text","Segoe UI",Roboto,Arial,sans-serif;
    font-size:13px;
    font-weight:500;
    padding:8px 10px;
    border-radius:9px;
    line-height:1.45;
    box-shadow:0 1px 0 rgba(0,0,0,0.04);
    height:auto;
    min-height:38px;
  }
  .citation-edit-field input:focus,
  .citation-edit-field select:focus,
  .citation-edit-field textarea:focus{border-color:var(--primary);}
  .cite-viewer .edit-body .citation-field{
    margin:0;
    padding:6px 8px;
    border:1px solid rgba(148,163,184,0.14);
    border-radius:10px;
    background:rgba(255,255,255,0.025);
  }
  .cite-viewer.edit-mode .edit-body{
    flex:1;
    max-height:none;
  }
  .citation-edit-field textarea{
    min-height:38px;
    resize:none;
    overflow:hidden;
  }
  .citation-edit-field strong{
    display:block;
    margin:0 0 5px;
    font-family:"Inter","SF Pro Text","Segoe UI",Roboto,Arial,sans-serif;
    font-size:11px;
    line-height:1.1;
    letter-spacing:.04em;
    text-transform:uppercase;
  }
  .cite-viewer .edit-body .rich-editor{
    min-height:72px;
    margin:0;
    padding:8px 10px;
    border-radius:9px;
    font-family:"Inter","SF Pro Text","Segoe UI",Roboto,Arial,sans-serif;
    font-size:13px;
    line-height:1.45;
  }
  .cite-viewer .edit-body .mini-toolbar{
    margin:4px 0;
  }
  .cite-viewer .edit-body .mini-toolbar button{
    min-height:26px;
    padding:3px 8px;
    border-radius:7px;
    font-size:11px;
  }
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
  .cite-viewer .callout{padding:4px 6px;border:1px solid var(--border);border-radius:10px;background:rgba(255,255,255,0.03);width:100%;}
  .view-meta-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:12px 14px;border:1px solid rgba(148,163,184,0.14);border-radius:16px;background:linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));align-self:stretch;box-shadow:inset 0 1px 0 rgba(255,255,255,0.03);}
  .view-meta-item{min-width:0;}
  .view-meta-item strong{display:block;font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:var(--muted);}
  .view-meta-item .meta-value{margin-top:3px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.05;font-size:13px;color:var(--text);}
  html.theme-light .cite-viewer{
    background:linear-gradient(180deg,#ffffff,#f8fafc);
    border-left:1px solid #dbe3ee;
    box-shadow:-18px 0 42px rgba(15,23,42,0.14);
    color:#0f172a;
  }
  html.theme-light .cite-viewer.edit-mode,
  html.theme-light .cite-viewer.revisions-mode{
    background:#ffffff;
  }
  html.theme-light .cite-viewer header{
    background:linear-gradient(180deg,#ffffff,#f8fafc);
    border-bottom:1px solid #e2e8f0;
  }
  html.theme-light .cite-viewer .actions-bar{
    background:#f8fafc;
    border-bottom:1px solid #e2e8f0;
  }
  html.theme-light .cite-viewer .viewer-body{
    background:transparent;
  }
  html.theme-light .cite-viewer,
  html.theme-light .cite-viewer header,
  html.theme-light .cite-viewer main,
  html.theme-light .cite-viewer footer,
  html.theme-light .cite-viewer .section,
  html.theme-light .cite-viewer .citation-field,
  html.theme-light .cite-viewer .citation-field strong,
  html.theme-light .cite-viewer .collection-name,
  html.theme-light .cite-viewer .meta-value,
  html.theme-light .cite-viewer .viewer-body,
  html.theme-light .cite-viewer .viewer-body p,
  html.theme-light .cite-viewer .viewer-body div,
  html.theme-light .cite-viewer .viewer-body span,
  html.theme-light .cite-viewer .viewer-body li,
  html.theme-light .cite-viewer .viewer-body ul,
  html.theme-light .cite-viewer .viewer-body ol,
  html.theme-light .cite-viewer .viewer-body a,
  html.theme-light .cite-viewer .viewer-body em,
  html.theme-light .cite-viewer .viewer-body i,
  html.theme-light .cite-viewer .viewer-body b,
  html.theme-light .cite-viewer .viewer-body strong{
    color:#0f172a;
  }
  html.theme-light .cite-viewer .muted,
  html.theme-light .cite-viewer #viewSubtitle,
  html.theme-light .cite-viewer #citationRevisionsHint{
    color:#64748b;
  }
  html.theme-light .cite-viewer #viewOrder,
  html.theme-light .cite-viewer #viewExampleBody,
  html.theme-light .cite-viewer #viewYouTry,
  html.theme-light .cite-viewer #viewNotes,
  html.theme-light .cite-viewer .viewer-body li{
    color:#1e293b;
  }
  html.theme-light .cite-viewer .viewer-body:not(.edit-body):not(.revisions-body) .citation-field,
  html.theme-light .cite-viewer .callout{
    background:#ffffff;
    border-color:#e2e8f0;
    box-shadow:0 1px 2px rgba(15,23,42,0.04);
  }
  html.theme-light .cite-viewer .edit-body .citation-field{
    background:#ffffff;
    border-color:#e2e8f0;
    box-shadow:0 1px 2px rgba(15,23,42,0.04);
  }
  html.theme-light .citation-edit-field input,
  html.theme-light .citation-edit-field select,
  html.theme-light .citation-edit-field textarea,
  html.theme-light .cite-viewer .edit-body .rich-editor{
    background:#ffffff;
    border-color:#dbe3ee;
    color:#0f172a;
  }
  html.theme-light .view-meta-grid{
    background:linear-gradient(180deg,#ffffff,#f8fafc);
    border-color:#e2e8f0;
    box-shadow:0 1px 2px rgba(15,23,42,0.04);
  }
  html.theme-light .cite-readonly-badge{
    background:#f1f5f9;
    border-color:#dbe3ee;
    color:#334155;
  }
  html.theme-light .cite-viewer .close-btn{
    background:#ffffff;
    border:1px solid #dbe3ee;
    color:#0f172a;
    box-shadow:0 1px 2px rgba(15,23,42,0.08);
  }
  html.theme-light .cite-viewer .btn:not(.primary):not(.danger){
    background:#ffffff;
    border-color:#dbe3ee;
    color:#0f172a;
  }
  html.theme-light .cite-viewer .btn:not(.primary):not(.danger):hover{
    background:#f8fafc;
    border-color:#bfccda;
  }
  html.theme-light #revTimelineSelect{
    background:#ffffff;
    color:#0f172a;
    border-color:#dbe3ee;
  }
  @media(max-width:720px){.view-meta-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
  </style>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/admin-shared.css?v=20260322">
</head>
  <body>
    <?php if (!$citationsOnly): ?>
      <?php include __DIR__ . '/partials/header.php'; ?>
    <?php endif; ?>
  <main>
    <div class="wrap">
      <?php if (!$citationsOnly): ?>
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
          <button class="tab" data-tab="appearance" type="button">Appearance</button>
          <button class="tab" data-tab="analytics" type="button">Analytics</button>
          <button class="tab" data-tab="settings" type="button">Settings</button>
          <button class="tab" data-tab="forms" type="button">Forms</button>
          <?php if ($isSuperAdmin): ?>
            <button class="tab" data-tab="deleted-pages" type="button">Deleted pages</button>
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
                <th>Status</th>
                <th>Updated</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="pageTable">
              <?php foreach ($pages as $p): ?>
                <?php
                  $slug = strtolower((string)($p['slug'] ?? ''));
                  $pageLocked = (int)($p['is_locked'] ?? 0) === 1;
                  $pageCanEdit = Page::canEdit($p, $myRole);
                  if ($slug === 'home-signed-in') { continue; }
                ?>
                <tr
                  data-title="<?= Security::e(strtolower($p['title'])) ?>"
                  data-path="<?= Security::e(strtolower($p['slug'])) ?>"
                  data-status="<?= Security::e(strtolower($p['status'])) ?>"
                >
                  <td>
                    <div class="title-main">
                      <?php if ($pageLocked): ?>
                        <span class="page-lock-icon" title="Locked page" aria-label="Locked page">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 1 1 8 0v3"/></svg>
                        </span>
                      <?php endif; ?>
                      <a href="<?= Security::e(PagePath::publicUrl($base, (string)($site['slug'] ?? ''), (string)($p['slug'] ?? ''))) ?>" target="_blank" style="color:inherit;text-decoration:none;">
                        <?= Security::e($p['title']) ?>
                      </a>
                    </div>
                    <div class="title-path">/<?= Security::e($p['slug']) ?></div>
                  </td>
                  <td>
                    <?php $st = strtolower($p['status']); ?>
                    <span class="status-badge <?= $st === 'published' ? 'published' : 'draft' ?>">
                      <span class="status-dot" aria-hidden="true"></span>
                      <?= $st === 'published' ? 'Published' : 'Draft' ?>
                    </span>
                  </td>
                  <td class="muted updated-cell" data-updated="<?= Security::e($p['updated_at'] ?? '') ?>"><?= Security::e($p['updated_at'] ?? '') ?></td>
                  <td style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <?php if ($pageCanEdit): ?>
                      <a class="btn small" style="background:linear-gradient(135deg,var(--primary),var(--primary));color:#fff;border-color:rgba(255,255,255,.12)" href="<?= $base ?>/admin/page_builder.php?id=<?= (int)$p['id'] ?>">Edit</a>
                    <?php else: ?>
                      <span class="btn small disabled" aria-disabled="true">Locked</span>
                    <?php endif; ?>
                    <?php if ($pageCanEdit || $isSuperAdmin): ?>
                      <div class="kebab">
                        <button class="kebab-btn" type="button" aria-haspopup="true" aria-expanded="false">⋯</button>
                        <div class="kebab-menu" role="menu">
                          <?php if ($pageCanEdit): ?>
                            <button type="button" data-duplicate-page data-page-id="<?= (int)$p['id'] ?>" aria-label="Duplicate page">Duplicate</button>
                            <button
                              type="button"
                              data-rename-page
                              data-page-id="<?= (int)$p['id'] ?>"
                              data-page-title="<?= Security::e($p['title']) ?>"
                              data-page-slug="<?= Security::e($p['slug']) ?>"
                              aria-label="Rename page"
                            >Rename page</button>
                            <button type="button" class="danger" data-delete-page data-page-id="<?= (int)$p['id'] ?>" data-page-title="<?= Security::e($p['title']) ?>" aria-label="Delete page">Delete</button>
                          <?php endif; ?>
                          <?php if ($isSuperAdmin): ?>
                            <button
                              type="button"
                              data-lock-page
                              data-page-id="<?= (int)$p['id'] ?>"
                              data-lock-state="<?= $pageLocked ? '0' : '1' ?>"
                              aria-label="<?= $pageLocked ? 'Unlock page' : 'Lock page' ?>"
                            ><?= $pageLocked ? 'Unlock page' : 'Lock page' ?></button>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php elseif ($pageLocked): ?>
                      <span class="locked-note">Only super admins can edit</span>
                    <?php endif; ?>
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

      <div class="panel" id="panel-forms">
        <div class="card" style="margin-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div>
              <h2 style="margin:0">Forms</h2>
              <div class="muted">Create reusable forms for this site and attach them to pages through the builder. Click a form to view or edit it.</div>
            </div>
            <button class="btn primary" type="button" id="openCreateFormModal">Create new form</button>
          </div>
          <div class="row" style="margin-top:12px">
            <div>
              <label class="muted">Search</label>
              <input id="formSearch" placeholder="Search by form name…">
            </div>
          </div>
          <?php if (!$siteForms): ?>
            <div style="margin-top:14px">
              <p>No forms yet.</p>
            </div>
          <?php else: ?>
            <table class="page-table">
              <thead>
                <tr style="text-align:left;color:var(--muted);font-size:14px">
                  <th>Name</th>
                  <th>Questions</th>
                  <th>Updated</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="formTable">
                <?php foreach ($siteForms as $formRow): ?>
                  <?php $questionCount = count((array)($formRow['questions'] ?? [])); ?>
                  <?php $responseCount = count((array)($siteFormResponses[(int)($formRow['id'] ?? 0)] ?? [])); ?>
                  <tr
                    data-name="<?= Security::e(strtolower((string)($formRow['name'] ?? ''))) ?>"
                    data-form-row
                    data-form-id="<?= (int)($formRow['id'] ?? 0) ?>"
                    style="cursor:pointer"
                  >
                    <td>
                      <div class="title-main"><?= Security::e((string)($formRow['name'] ?? 'Untitled form')) ?></div>
                      <?php if (trim((string)($formRow['description'] ?? '')) !== ''): ?>
                        <div class="title-path"><?= Security::e((string)$formRow['description']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="muted"><?= (int)$questionCount ?> question<?= $questionCount === 1 ? '' : 's' ?></td>
                    <td class="muted updated-cell" data-updated="<?= Security::e((string)($formRow['updated_at'] ?? '')) ?>"><?= Security::e((string)($formRow['updated_at'] ?? '')) ?></td>
                    <td>
                      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <button class="btn small" type="button" data-open-form-feedback data-form-id="<?= (int)($formRow['id'] ?? 0) ?>">Feedback<?= $responseCount > 0 ? ' (' . $responseCount . ')' : '' ?></button>
                        <button class="btn small" type="button" data-open-form-modal data-form-id="<?= (int)($formRow['id'] ?? 0) ?>">View</button>
                        <form method="post" action="site.php?id=<?= (int)$site['id'] ?>#forms" style="margin:0" onsubmit="return confirm('Delete this form?');">
                          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                          <input type="hidden" name="delete_site_form" value="1">
                          <input type="hidden" name="form_id" value="<?= (int)$formRow['id'] ?>">
                          <button class="btn small" type="submit" style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12);color:#fecaca">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div id="formEmpty" style="margin-top:10px;display:none" class="muted">No forms match your search.</div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isSuperAdmin): ?>
      <div class="panel" id="panel-deleted-pages">
        <div class="card" style="margin-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div>
              <h2 style="margin:0">Deleted pages</h2>
              <div class="muted">Soft-deleted pages for this site. Records are retained for 30 days, then permanently purged.</div>
            </div>
          </div>
          <?php if (!$deletedPages): ?>
            <div style="margin-top:14px">
              <p>No deleted pages recorded for this site.</p>
            </div>
          <?php else: ?>
            <table class="page-table" style="margin-top:14px">
              <thead>
                <tr style="text-align:left;color:var(--muted);font-size:14px">
                  <th>Title</th>
                  <th>Path</th>
                  <th>Deleted by</th>
                  <th>Deleted</th>
                  <th>Purges</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($deletedPages as $dp): ?>
                  <?php
                    $deletedBy = trim((string)($dp['deleted_by_name'] ?? ''));
                    if ($deletedBy === '') $deletedBy = trim((string)($dp['deleted_by_email'] ?? ''));
                    if ($deletedBy === '') $deletedBy = 'Unknown user';
                    $deletedRole = trim((string)($dp['deleted_by_role'] ?? ''));
                  ?>
                  <tr>
                    <td>
                      <div class="title-main"><?= Security::e($dp['title'] ?? '') ?></div>
                      <div class="title-path">Original ID: <?= (int)($dp['original_page_id'] ?? 0) ?></div>
                    </td>
                    <td class="muted title-path">/<?= Security::e($dp['slug'] ?? '') ?></td>
                    <td>
                      <div><?= Security::e($deletedBy) ?></div>
                      <div class="muted"><?= Security::e($deletedRole !== '' ? ucwords(str_replace('_', ' ', $deletedRole)) : 'Role unavailable') ?></div>
                    </td>
                    <td class="muted"><?= Security::e($dp['deleted_at'] ?? '') ?></td>
                    <td class="muted"><?= Security::e($dp['purge_after'] ?? '') ?></td>
                    <td>
                      <form method="post" action="site.php?id=<?= (int)$site['id'] ?>#deleted-pages" style="margin:0">
                        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                        <input type="hidden" name="restore_deleted_page" value="1">
                        <input type="hidden" name="deleted_page_id" value="<?= (int)($dp['id'] ?? 0) ?>">
                        <button class="btn small" type="submit">Restore</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

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
      <?php endif; ?>

      <?php if ($siteSlug === 'cite-them-right' && $citationsOnly): ?>
      <!-- CITATION DATABASE (read-only) -->
      <div class="panel active" id="panel-citations">
        <div class="card" style="margin-top:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
            <div>
              <h2 style="margin:0">Citation database</h2>
              <div class="muted">Edit citations safely. Changes are queued and only applied when exported.</div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button class="btn" type="button" id="openExportBundleModal">Export bundle (<?= (int)$stagedCount ?>)</button>
              <button class="btn primary" type="button" id="openCitationModal">+ Add citation</button>
            </div>
          </div>

          <div class="citation-toolbar" aria-label="Citation database controls">
            <div class="citation-toolbar-top">
              <div class="citation-search-shell">
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                  <path d="M9 15.5a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13Zm4.6-1.9 3.4 3.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                <input id="revSearch" type="search" placeholder="Search citations, authors, ISBNs, or keywords" aria-label="Search citations" style="width:100%;">
              </div>
              <div class="citation-view-toggle" role="group" aria-label="Citation view">
                <button type="button" class="active" data-citation-view-button="summary">Operations</button>
                <button type="button" data-citation-view-button="data">Editorial</button>
                <button type="button" data-citation-view-button="library">Style Library</button>
              </div>
            </div>
            <div class="citation-toolbar-main">
              <div class="citation-filter-group" aria-label="Quick filters">
                <span class="citation-group-label">Quick filters</span>
                <label class="citation-filter-chip" for="globalStyleFilter">
                  <span>Style</span>
                  <select id="globalStyleFilter">
                    <option value="">All styles</option>
                    <?php foreach ($citationStyles as $style): ?>
                      <option value="<?= Security::e($style) ?>"><?= Security::e($style) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="citation-filter-chip" for="citationCategoryFilter">
                  <span>Category</span>
                  <select id="citationCategoryFilter">
                    <option value="">All categories</option>
                    <?php foreach ($citationViewCategories as $cat): ?>
                      <option value="<?= Security::e($cat) ?>"><?= Security::e($cat) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="citation-filter-chip" for="citationStatusFilter">
                  <span>Status</span>
                  <select id="citationStatusFilter">
                    <option value="">Any status</option>
                    <option value="clean">Clean</option>
                    <option value="staged">Queued</option>
                    <option value="edited">Edited</option>
                  </select>
                </label>
              </div>
              <div class="citation-toolbar-actions">
                <div class="citation-column-menu-wrap">
                  <button class="citation-ghost-btn" type="button" id="citationColumnsBtn" aria-expanded="false" aria-controls="citationColumnMenu">Columns</button>
                  <div class="citation-column-menu" id="citationColumnMenu" role="menu" aria-label="Visible data table columns">
                    <div class="citation-column-menu-title">Data table columns</div>
                    <label class="citation-column-option"><span>Example header</span><input type="checkbox" data-column-toggle="example_header" checked></label>
                    <label class="citation-column-option"><span>Example body</span><input type="checkbox" data-column-toggle="example_body" checked></label>
                    <label class="citation-column-option"><span>You try</span><input type="checkbox" data-column-toggle="you_try" checked></label>
                    <label class="citation-column-option"><span>Category</span><input type="checkbox" data-column-toggle="category" checked></label>
                    <label class="citation-column-option"><span>Sub-category</span><input type="checkbox" data-column-toggle="sub_category" checked></label>
                    <label class="citation-column-option"><span>Reference type</span><input type="checkbox" data-column-toggle="label"></label>
                    <label class="citation-column-option"><span>Style</span><input type="checkbox" data-column-toggle="style"></label>
                    <label class="citation-column-option"><span>Key</span><input type="checkbox" data-column-toggle="key"></label>
                    <label class="citation-column-option"><span>Status</span><input type="checkbox" data-column-toggle="status"></label>
                  </div>
                </div>
                <button class="citation-ghost-btn" type="button" id="citationMoreFilters" aria-expanded="false" aria-controls="citationAdvancedFilters">More filters <span class="citation-filter-count" id="citationFilterCount">0</span></button>
                <button class="citation-ghost-btn" type="button" id="citationClearFilters">Clear</button>
              </div>
            </div>
            <div class="citation-advanced-filters" id="citationAdvancedFilters">
              <div class="citation-filter-group" aria-label="Advanced filters">
                <span class="citation-group-label">Advanced</span>
                <label class="citation-filter-chip" for="citationSubCategoryFilter">
                  <span>Sub-category</span>
                  <select id="citationSubCategoryFilter">
                    <option value="">All sub-categories</option>
                    <?php foreach ($citationViewSubCategories as $subCat): ?>
                      <option value="<?= Security::e($subCat) ?>"><?= Security::e($subCat) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="citation-filter-chip" for="citationSortSelect">
                  <span>Sort</span>
                  <select id="citationSortSelect">
                    <option value="label-asc">Reference type A-Z</option>
                    <option value="label-desc">Reference type Z-A</option>
                    <option value="style-asc">Style A-Z</option>
                    <option value="category-asc">Category A-Z</option>
                    <option value="sub_category-asc">Sub-category A-Z</option>
                  </select>
                </label>
              </div>
              <div class="citation-presets" aria-label="Saved filter presets">
                <span class="citation-preset-label">Saved presets</span>
                <button class="citation-preset-btn" type="button" data-citation-preset data-style="Harvard">Harvard</button>
                <button class="citation-preset-btn" type="button" data-citation-preset data-category="Books">Books</button>
                <button class="citation-preset-btn" type="button" data-citation-preset data-status="staged">Queued changes</button>
              </div>
            </div>
            <div class="citation-toolbar-footer">
              <span class="citation-ai-hint"><span class="citation-ai-dot"></span> Try natural language search, e.g. journal article with DOI.</span>
              <span class="citation-recent-filters" id="citationRecentFilters">Recent filters: none</span>
            </div>
          </div>

          <?php if ($citationExamples): ?>
            <div class="citations-list" id="citationList" data-citation-view="summary">
              <table class="citation-table citation-ops-table">
                <thead>
                  <tr>
                    <th>Reference type</th>
                    <th>Style</th>
                    <th>Category</th>
                    <th>Sub-category</th>
                    <th>Key</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($citationExamplesView as $ex): 
                    $key = $ex['example_key'] ?? '';
                    $keyDisplay = nx_truncate($key, 30);
                    $staged = isset($stagedKeys[$key]);
                    $queuedRevId = $staged ? (int)($latestByKey[$key]['id'] ?? 0) : 0;
                    $hasRevision = isset($latestByKey[$key]);
                    $statusLabel = 'Clean';
                    $statusTone = 'muted';
                    if ($staged) { $statusLabel = 'Queued'; $statusTone = 'badge-chip staged'; }
                    elseif ($hasRevision) { $statusLabel = 'Edited (other release)'; $statusTone = 'badge-chip'; }
                    $statusValue = $staged ? 'staged' : ($hasRevision ? 'edited' : 'clean');
                  ?>
                    <tr
                      class="citation-row"
                      data-style="<?= Security::e($ex['referencing_style'] ?? '') ?>"
                      data-status="<?= Security::e($statusValue) ?>"
                      data-category="<?= Security::e($ex['category'] ?? '') ?>"
                      data-sub-category="<?= Security::e($ex['sub_category'] ?? '') ?>"
                      data-key="<?= Security::e($ex['example_key'] ?? '') ?>"
                      data-label="<?= Security::e($ex['label'] ?? '') ?>"
                      data-order="<?= Security::e($ex['citation_order'] ?? '') ?>"
                      data-heading="<?= Security::e($ex['example_heading'] ?? '') ?>"
                      data-body="<?= Security::e($ex['example_body'] ?? '') ?>"
                      data-youtry="<?= Security::e($ex['you_try'] ?? '') ?>"
                      data-notes="<?= Security::e($ex['notes'] ?? '') ?>"
                      data-id="<?= (int)($ex['id'] ?? 0) ?>"
                      data-queued-revision-id="<?= $queuedRevId ?>"
                    >
                      <td>
                        <div class="citation-label"><?= Security::e($ex['label'] ?? '') ?></div>
                      </td>
                      <td><span class="citation-style-pill"><?= Security::e($ex['referencing_style'] ?? '') ?></span></td>
                      <td class="muted"><?= Security::e($ex['category'] ?? '') ?></td>
                      <td class="muted"><?= Security::e($ex['sub_category'] ?? '—') ?></td>
                      <td class="muted collection-slug" title="<?= Security::e($key) ?>"><span class="citation-key-wrap"><?= Security::e($key) ?></span></td>
                      <td>
                        <span class="<?= $statusTone ?>"><?= Security::e($statusLabel) ?></span>
                      </td>
                      <td>
                        <div class="citation-row-actions">
                          <div class="citation-doc-menu" data-doc-menu>
                            <button class="citation-doc-menu-btn" type="button" aria-label="Document actions" aria-expanded="false">⋮</button>
                            <div class="citation-doc-menu-list">
                              <button type="button" data-open-style-docs data-doc-scope="style">Style guide</button>
                              <button type="button" data-open-style-docs data-doc-scope="source">Source type information</button>
                              <button type="button" data-open-style-docs data-doc-scope="rules">Referencing rules</button>
                              <button type="button" data-open-style-docs data-doc-scope="all">All related documents</button>
                            </div>
                          </div>
                        <?php if ($staged && $queuedRevId > 0): ?>
                          <button class="btn text" type="button" data-view-bundle data-revision-id="<?= $queuedRevId ?>">View in bundle</button>
                        <?php endif; ?>
                        <?php if ((int)($ex['id'] ?? 0) > 0): ?>
                          <form method="post" style="margin:0">
                            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                            <input type="hidden" name="delete_citation" value="1">
                            <input type="hidden" name="citation_id" value="<?= (int)($ex['id'] ?? 0) ?>">
                            <button class="btn danger" type="submit" onclick="return confirm('Queue delete for this citation?')">Queue delete</button>
                          </form>
                        <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="citations-list" id="citationDataList" data-citation-view="data" style="display:none;">
              <div class="citation-table-wrap">
                <table class="citation-table citation-data-table" aria-label="Citation data table">
                  <thead>
                    <tr>
                      <th data-column="example_header">Example header</th>
                      <th data-column="example_body">Example body</th>
                      <th data-column="you_try">You try</th>
                      <th data-column="category">Category</th>
                      <th data-column="sub_category">Sub-category</th>
                      <th data-column="label" hidden>Reference type</th>
                      <th data-column="style" hidden>Style</th>
                      <th data-column="key" hidden>Key</th>
                      <th data-column="status" hidden>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($citationExamplesView as $ex):
                      $key = $ex['example_key'] ?? '';
                      $keyDisplay = nx_truncate($key, 30);
                      $staged = isset($stagedKeys[$key]);
                      $hasRevision = isset($latestByKey[$key]);
                      $statusLabel = 'Clean';
                      $statusTone = 'muted';
                      if ($staged) { $statusLabel = 'Queued'; $statusTone = 'badge-chip staged'; }
                      elseif ($hasRevision) { $statusLabel = 'Edited (other release)'; $statusTone = 'badge-chip'; }
                      $statusValue = $staged ? 'staged' : ($hasRevision ? 'edited' : 'clean');
                    ?>
                      <tr
                        class="citation-row"
                        data-style="<?= Security::e($ex['referencing_style'] ?? '') ?>"
                        data-status="<?= Security::e($statusValue) ?>"
                        data-category="<?= Security::e($ex['category'] ?? '') ?>"
                        data-sub-category="<?= Security::e($ex['sub_category'] ?? '') ?>"
                        data-key="<?= Security::e($ex['example_key'] ?? '') ?>"
                        data-label="<?= Security::e($ex['label'] ?? '') ?>"
                        data-order="<?= Security::e($ex['citation_order'] ?? '') ?>"
                        data-heading="<?= Security::e($ex['example_heading'] ?? '') ?>"
                        data-body="<?= Security::e($ex['example_body'] ?? '') ?>"
                        data-youtry="<?= Security::e($ex['you_try'] ?? '') ?>"
                        data-notes="<?= Security::e($ex['notes'] ?? '') ?>"
                        data-id="<?= (int)($ex['id'] ?? 0) ?>"
                      >
                        <td data-column="example_header"><div class="citation-data-text"><?= nx_citation_table_preview((string)($ex['example_heading'] ?? '')) ?></div></td>
                        <td data-column="example_body"><div class="citation-data-text"><?= nx_citation_table_preview((string)($ex['example_body'] ?? '')) ?></div></td>
                        <td data-column="you_try"><div class="citation-data-text"><?= nx_citation_table_preview((string)($ex['you_try'] ?? '')) ?></div></td>
                        <td data-column="category" class="muted citation-data-meta"><?= Security::e($ex['category'] ?? '') ?></td>
                        <td data-column="sub_category" class="muted citation-data-meta"><?= Security::e($ex['sub_category'] ?? '—') ?></td>
                        <td data-column="label" hidden><div class="citation-label"><?= Security::e($ex['label'] ?? '') ?></div></td>
                        <td data-column="style" hidden><span class="citation-style-pill"><?= Security::e($ex['referencing_style'] ?? '') ?></span></td>
                        <td data-column="key" hidden class="muted collection-slug" title="<?= Security::e($key) ?>"><?= Security::e($keyDisplay) ?></td>
                        <td data-column="status" hidden><span class="<?= $statusTone ?>"><?= Security::e($statusLabel) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="citations-list" id="styleLibraryList" data-citation-view="library" style="display:none;">
              <div class="style-library-head">
                <div>
                  <h3 style="margin:0">Style Library</h3>
                  <div class="muted">Guides, source type information, referencing rules, and editorial guidance for each referencing style.</div>
                </div>
                <button class="btn primary" type="button" id="openStyleDocumentModal">+ Add document</button>
              </div>
              <?php if ($citationStyleDocuments): ?>
                <div class="style-doc-grid">
                  <?php foreach ($citationStyleDocuments as $doc): ?>
                    <article
                      class="style-doc-card"
                      data-style-doc-card
                      data-id="<?= (int)($doc['id'] ?? 0) ?>"
                      data-style="<?= Security::e($doc['referencing_style'] ?? '') ?>"
                      data-type="<?= Security::e($doc['doc_type'] ?? '') ?>"
                      data-category="<?= Security::e($doc['category'] ?? '') ?>"
                      data-sub-category="<?= Security::e($doc['sub_category'] ?? '') ?>"
                      data-title="<?= Security::e($doc['title'] ?? '') ?>"
                      data-body="<?= Security::e($doc['body'] ?? '') ?>"
                      data-updated-by="<?= Security::e($doc['updated_by_email'] ?? '') ?>"
                      data-updated-at="<?= Security::e($doc['updated_at'] ?? '') ?>"
                    >
                      <div class="style-doc-card-head">
                        <div>
                          <div class="style-doc-title"><?= Security::e($doc['title'] ?? '') ?></div>
                          <div class="style-doc-meta" style="margin-top:7px;">
                            <span class="style-doc-pill"><?= Security::e($doc['referencing_style'] ?? '') ?></span>
                            <span class="style-doc-pill"><?= Security::e($doc['doc_type'] ?? '') ?></span>
                            <?php if (!empty($doc['category'])): ?><span class="style-doc-pill"><?= Security::e($doc['category']) ?></span><?php endif; ?>
                          </div>
                        </div>
                      </div>
                      <div class="style-doc-preview"><?= Security::e(nx_doc_text_preview((string)($doc['body'] ?? ''))) ?></div>
                      <div class="style-doc-actions">
                        <button class="btn small" type="button" data-open-doc-card>Open</button>
                        <button class="btn small" type="button" data-edit-doc-card>Edit</button>
                        <button
                          class="btn danger small"
                          type="button"
                          data-delete-style-doc
                          data-style-document-id="<?= (int)($doc['id'] ?? 0) ?>"
                          data-style-document-title="<?= Security::e($doc['title'] ?? 'Style document') ?>"
                        >Delete</button>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="empty" style="margin-top:14px;">No style documents yet. Add a guide, source type note, or referencing rule.</div>
              <?php endif; ?>
            </div>
            <div class="citation-no-results" id="citationNoResults">No citations match the selected filters.</div>
            <?php if (!$citationsOnly): ?>
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
                        $keyDisplay = nx_truncate($key, 30);
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
            <?php endif; ?>
          <?php else: ?>
            <div class="muted" style="margin-top:12px;display:flex;align-items:center;gap:10px">
              <span style="font-size:20px">📚</span>
              <div>No citation entries found. Populate via SQL seed file.</div>
            </div>
          <?php endif; ?>

          <div class="modal-backdrop" id="exportBundleBackdrop" style="display:none">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="exportBundleTitle" style="max-width:860px;width:100%;">
              <header style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
                <div>
                  <h3 id="exportBundleTitle" style="margin:0">Export bundle (<?= (int)$stagedCount ?>)</h3>
                  <div class="muted" style="font-size:13px">Queued citations only. Live records update on export.</div>
                </div>
                <button type="button" class="close-btn" id="closeExportBundleModal" aria-label="Close">×</button>
              </header>
              <?php if ($queuedBundleItems): ?>
                <div style="display:grid;gap:10px;max-height:60vh;overflow:auto;padding-right:2px;">
                  <?php foreach ($queuedBundleItems as $qrev):
                    $qid = (int)($qrev['id'] ?? 0);
                    $qaction = strtolower((string)($qrev['action'] ?? 'update'));
                    $qbefore = json_decode((string)($qrev['before_json'] ?? 'null'), true) ?: [];
                    $qafter = json_decode((string)($qrev['after_json'] ?? 'null'), true) ?: [];
                    $qlabel = $qafter['label'] ?? $qbefore['label'] ?? ($qrev['citation_key'] ?? 'Untitled');
                    $qkey = (string)($qrev['citation_key'] ?? '');
                    $fieldLabels = [
                      'label' => 'Reference type',
                      'referencing_style' => 'Style',
                      'category' => 'Category',
                      'sub_category' => 'Sub-category',
                      'citation_order' => 'Citation order',
                      'example_heading' => 'Example heading',
                      'example_body' => 'Example body',
                      'you_try' => 'You try',
                      'notes' => 'Notes',
                    ];
                    $changedFields = [];
                    foreach ($fieldLabels as $fk => $flabel) {
                      $bv = trim((string)($qbefore[$fk] ?? ''));
                      $av = trim((string)($qafter[$fk] ?? ''));
                      if ($qaction === 'create') {
                        if ($av !== '') $changedFields[] = $flabel;
                      } elseif ($qaction === 'delete') {
                        if ($bv !== '') $changedFields[] = $flabel;
                      } elseif ($bv !== $av) {
                        $changedFields[] = $flabel;
                      }
                    }
                    if ($qaction === 'create') {
                      $summaryText = 'New citation queued';
                    } elseif ($qaction === 'delete') {
                      $summaryText = 'Citation queued for deletion';
                    } elseif (!$changedFields) {
                      $summaryText = 'No field changes detected';
                    } elseif (count($changedFields) <= 2) {
                      $summaryText = implode(' and ', $changedFields) . ' updated';
                    } else {
                      $summaryText = count($changedFields) . ' fields changed';
                    }
                  ?>
                    <div class="citation-field" id="bundle-item-<?= $qid ?>" data-bundle-item="<?= $qid ?>" style="padding:10px 12px;">
                      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <div style="min-width:0;">
                          <div class="collection-name" style="margin:0;"><?= Security::e($qlabel) ?></div>
                          <div class="muted collection-slug"><?= Security::e($qkey) ?></div>
                          <div class="muted" style="font-size:12px;"><?= Security::e($summaryText) ?></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                          <button class="btn text" type="button" data-bundle-toggle="<?= $qid ?>" aria-expanded="false">View changes</button>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                            <input type="hidden" name="export_single_citation" value="1">
                            <input type="hidden" name="queued_revision_id" value="<?= $qid ?>">
                            <button class="btn" type="submit">Export individually</button>
                          </form>
                        </div>
                      </div>
                      <div id="bundle-diff-<?= $qid ?>" style="display:none;margin-top:10px;border-top:1px solid var(--border);padding-top:10px;">
                        <?php
                          $printedAny = false;
                          foreach ($fieldLabels as $fk => $flabel):
                            $bv = trim((string)($qbefore[$fk] ?? ''));
                            $av = trim((string)($qafter[$fk] ?? ''));
                            $changed = ($qaction === 'create')
                              ? ($av !== '')
                              : (($qaction === 'delete') ? ($bv !== '') : ($bv !== $av));
                            if (!$changed) continue;
                            $printedAny = true;
                        ?>
                          <div style="margin-bottom:10px;">
                            <div class="muted" style="font-size:12px;margin-bottom:4px;"><?= Security::e($flabel) ?></div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                              <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.24);border-radius:8px;padding:8px;">
                                <div class="muted" style="font-size:11px;margin-bottom:3px;">Before</div>
                                <div style="white-space:pre-wrap;font-size:12px;line-height:1.4;"><?= Security::e($bv !== '' ? $bv : '—') ?></div>
                              </div>
                              <div style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.24);border-radius:8px;padding:8px;">
                                <div class="muted" style="font-size:11px;margin-bottom:3px;">After</div>
                                <div style="white-space:pre-wrap;font-size:12px;line-height:1.4;"><?= Security::e(($qaction === 'delete') ? '[Removed]' : ($av !== '' ? $av : '—')) ?></div>
                              </div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                        <?php if (!$printedAny): ?>
                          <div class="muted" style="font-size:12px;">No detailed field changes available.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="actions" style="margin-top:12px;justify-content:flex-end;">
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                    <input type="hidden" name="discard_all_citations" value="1">
                    <button class="btn text" type="submit" onclick="return confirm('Discard all queued citation changes?')">Discard all</button>
                  </form>
                  <button class="btn" type="button" id="closeExportBundleBtn">Close</button>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                    <input type="hidden" name="export_all_citations" value="1">
                    <button class="btn primary" type="submit">Export all</button>
                  </form>
                </div>
              <?php else: ?>
                <div class="muted">No queued citations.</div>
                <div class="actions" style="margin-top:12px;justify-content:flex-end;">
                  <button class="btn" type="button" id="closeExportBundleBtn">Close</button>
                </div>
              <?php endif; ?>
            </div>
          </div>
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
    <input type="hidden" name="modal_path_style" id="modal_path_style_field">
    <input type="hidden" name="modal_path_topic" id="modal_path_topic_field">
  </form>
  <form id="deletePageForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="delete_page" value="1">
    <input type="hidden" name="page_id" id="delete_page_id">
  </form>
  <form id="renamePageForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="rename_page" value="1">
    <input type="hidden" name="page_id" id="rename_page_id">
    <input type="hidden" name="page_title" id="rename_page_title">
    <input type="hidden" name="page_slug" id="rename_page_slug">
  </form>
  <form id="duplicatePageForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="duplicate_page" id="duplicate_page_id" value="0">
  </form>
  <form id="togglePageLockForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="toggle_page_lock" value="1">
    <input type="hidden" name="page_id" id="toggle_page_lock_id" value="0">
    <input type="hidden" name="lock_page" id="toggle_page_lock_state" value="0">
  </form>
  <form id="deleteSiteForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="delete_site" value="1">
  </form>
  <form id="deleteFormModalActionForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>#forms" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="delete_site_form" value="1">
    <input type="hidden" name="form_id" id="deleteFormModalActionId" value="0">
  </form>
  <form id="deleteStyleDocumentForm" method="post" action="site.php?id=<?= (int)$site['id'] ?>&amp;view=citations" style="display:none">
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
    <input type="hidden" name="delete_style_document" value="1">
    <input type="hidden" name="style_document_id" id="deleteStyleDocumentId" value="0">
  </form>

  <div class="modal-backdrop" id="formModalBackdrop" style="display:none">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="formModalTitle" style="max-width:860px;width:100%">
      <header>
        <div>
          <h3 id="formModalTitle" style="margin:0">Create form</h3>
          <div class="muted" style="font-size:13px">Add text-answer or 1-10 rating questions. The live block always shows a submit button.</div>
        </div>
        <button class="close-btn" type="button" id="closeFormModal" aria-label="Close">×</button>
      </header>
      <div id="formPreviewToolbar" style="display:none;padding:0 24px 0;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
        <button class="btn" type="button" id="editFormPreviewBtn">Edit</button>
        <button class="btn" type="button" id="deleteFormPreviewBtn" style="border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12);color:#fecaca">Delete</button>
      </div>
      <div class="modal-body">
        <div id="formPreviewPanel" style="display:none"></div>
        <form method="post" action="site.php?id=<?= (int)$site['id'] ?>#forms" id="formModalForm">
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <input type="hidden" name="save_site_form" value="1">
          <input type="hidden" name="form_id" id="form_modal_id" value="0">
          <div class="row">
            <div>
              <label class="muted">Form name</label>
              <input id="form_modal_name" name="form_name" value="" placeholder="Course feedback">
            </div>
            <div>
              <label class="muted">Description</label>
              <input id="form_modal_description" name="form_description" value="" placeholder="Optional short description">
            </div>
          </div>

          <div style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div class="muted" style="font-weight:700">Questions</div>
            <button class="btn" type="button" id="addFormQuestionBtn">Add question</button>
          </div>
          <div id="formQuestionsList" style="display:grid;gap:12px;margin-top:12px"></div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn text" type="button" id="cancelFormModalBtn">Cancel</button>
        <button class="btn primary" type="submit" form="formModalForm" id="saveFormModalBtn">Create form</button>
      </div>
    </div>
  </div>

  <script nonce="<?= Security::e(csp_nonce()) ?>" id="siteFormsData" type="application/json"><?= json_encode(array_map(static function (array $row): array {
    return [
      'id' => (int)($row['id'] ?? 0),
      'name' => (string)($row['name'] ?? ''),
      'description' => (string)($row['description'] ?? ''),
      'questions' => array_values(array_map(static function (array $question): array {
        return [
          'id' => (string)($question['id'] ?? ''),
          'label' => (string)($question['label'] ?? ''),
          'type' => (string)($question['type'] ?? 'text'),
        ];
      }, (array)($row['questions'] ?? []))),
    ];
  }, $siteForms), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script nonce="<?= Security::e(csp_nonce()) ?>" id="siteFormResponsesData" type="application/json"><?= json_encode(array_map(static function (array $responses): array {
    return array_values(array_map(static function (array $response): array {
      return [
        'id' => (int)($response['id'] ?? 0),
        'site_id' => (int)($response['site_id'] ?? 0),
        'form_id' => (int)($response['form_id'] ?? 0),
        'user_id' => isset($response['user_id']) ? (int)$response['user_id'] : null,
        'user_name' => (string)($response['user_name'] ?? ''),
        'institution_name' => (string)($response['institution_name'] ?? ''),
        'page_id' => isset($response['page_id']) ? (int)$response['page_id'] : null,
        'page_slug' => (string)($response['page_slug'] ?? ''),
        'created_at' => (string)($response['created_at'] ?? ''),
        'responses' => array_values(array_map(static function (array $item): array {
          return [
            'id' => (string)($item['id'] ?? ''),
            'label' => (string)($item['label'] ?? ''),
            'type' => (string)($item['type'] ?? 'text'),
            'value' => $item['value'] ?? '',
          ];
        }, (array)($response['responses'] ?? []))),
      ];
    }, $responses));
  }, $siteFormResponses), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

  <div class="modal-backdrop" id="formFeedbackModalBackdrop" style="display:none">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="formFeedbackModalTitle" style="max-width:980px;width:100%">
      <header>
        <div>
          <h3 id="formFeedbackModalTitle" style="margin:0">Form feedback</h3>
          <div class="muted" id="formFeedbackModalMeta" style="font-size:13px">Submitted responses for this form.</div>
        </div>
        <button class="close-btn" type="button" id="closeFormFeedbackModal" aria-label="Close">×</button>
      </header>
      <div class="modal-body">
        <div id="formFeedbackModalBody"></div>
      </div>
      <div class="modal-footer">
        <button class="btn text" type="button" id="closeFormFeedbackModalBtn">Close</button>
      </div>
    </div>
  </div>

  <div class="modal-backdrop" id="citationModalBackdrop" style="display:none">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="citationModalTitle">
      <header style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
        <div>
          <h3 id="citationModalTitle" style="margin:0">Add citation</h3>
          <div class="muted" style="font-size:13px">Reference type, formatting, and “You try” guidance in one place.</div>
        </div>
        <button type="button" class="close-btn" id="closeCitationModal" aria-label="Close">×</button>
      </header>
      <form method="post" id="citationModalForm" style="display:flex;flex-direction:column;gap:0;flex:1;min-height:0;">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
        <input type="hidden" name="add_citation" id="citationActionAdd" value="1">
        <input type="hidden" name="update_citation" id="citationActionUpdate" value="0">
        <input type="hidden" name="citation_id" id="citationIdField" value="">
        <input type="hidden" name="citation_key" id="citationKeyField" value="">

        <div class="modal-body">
          <div class="modal-sections">
            <section class="modal-section">
              <div class="section-head">
                <div class="section-title">Metadata</div>
                <div class="section-sub">Reference identity (always visible)</div>
              </div>
              <div class="two-col">
                <label class="citation-field" style="display:block">
                  <strong>Referencing style</strong>
                  <select name="citation_style" id="citationStyleField" style="margin-top:6px;width:100%">
                    <?php foreach ($citationStyles as $style): ?>
                      <option value="<?= Security::e($style) ?>"><?= Security::e($style) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="citation-field" style="display:block">
                  <strong>Category</strong>
                  <select name="citation_category" id="citationCategoryField" style="margin-top:6px;width:100%">
                    <?php foreach ($citationCategories as $cat): ?>
                      <option value="<?= Security::e($cat) ?>"><?= Security::e($cat) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="citation-field" style="display:block">
                  <strong>Sub-category (optional)</strong>
                  <input name="citation_sub_category" id="citationSubCategoryField" placeholder="e.g. Print books" style="margin-top:6px;width:100%">
                </label>
                <label class="citation-field" style="display:block">
                  <strong>Label</strong>
                  <input name="citation_label" id="citationLabelField" placeholder="Book with one author" required style="margin-top:6px;width:100%">
                </label>
              </div>
            </section>

            <section class="modal-section">
              <div class="section-head">
                <div class="section-title">Citation order</div>
                <div class="section-sub">Define the canonical sequence with the rich editor. Stored as limited HTML.</div>
              </div>
              <div id="citationToolbarAnchor"></div>
              <textarea name="citation_order" id="citationOrderField" rows="5" placeholder="Author / editor&#10;Year of publication (round brackets)&#10;Title of work&#10;Publisher&#10;DOI or URL (Accessed: date)" style="width:100%;max-height:160px"></textarea>
              <div class="helper">Use the toolbar for bold, italics, and bullets. Saved output is sanitised HTML.</div>
            </section>

            <section class="modal-section example-panel">
              <div class="section-head">
                <div class="section-title">Example</div>
                <div class="section-sub">Documentation-style preview (reader-friendly)</div>
              </div>
              <label class="citation-field" style="display:block">
                <strong>Example heading</strong>
                <input name="citation_heading" id="citationHeadingField" placeholder="Example: book with one author" required style="margin-top:6px;width:100%">
              </label>
              <textarea name="citation_body" id="citationBodyField" rows="4" placeholder="In-text citations&#10;Reference list..." required style="margin-top:6px;width:100%;max-height:160px"></textarea>
              <div class="helper">Use paragraphs for sections and italics/bold from the toolbar. Saved output is sanitised HTML.</div>
            </section>

            <section class="modal-section">
              <div class="section-head">
                <div class="section-title">You try</div>
                <div class="section-sub">Template shown to users</div>
              </div>
              <textarea name="citation_youtry" id="citationYouTryField" rows="4" placeholder="Surname, Initial. (Year) Title of book. Publisher. Available at: DOI or URL (Accessed: date)." style="width:100%;max-height:160px"></textarea>
              <div class="helper">Use italics from the toolbar instead of `*asterisks*`.</div>
            </section>

            <section class="modal-section">
              <details>
                <summary class="section-title" style="cursor:pointer;">Editorial notes (internal)</summary>
                <div class="section-sub" style="margin-top:6px;">Optional. Not shown to end users.</div>
                <textarea name="citation_notes" id="citationNotesField" rows="3" placeholder="House style notes, reminders…" style="margin-top:10px;width:100%;max-height:140px"></textarea>
              </details>
            </section>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn" type="button" id="cancelCitationModal">Cancel</button>
          <button class="btn primary" type="submit" id="citationSubmitBtn">Add citation</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-backdrop" id="styleDocumentModalBackdrop" style="display:none;">
    <div class="modal" id="styleDocumentModal" role="dialog" aria-modal="true" aria-labelledby="styleDocumentModalTitle">
      <form method="post" id="styleDocumentForm">
        <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
        <input type="hidden" name="save_style_document" value="1">
        <input type="hidden" name="style_document_id" id="styleDocumentIdField" value="0">
        <div class="modal-head">
          <div>
            <h2 id="styleDocumentModalTitle">Add style document</h2>
            <div class="muted">Store style guides, source type notes, and referencing rules for fast editorial lookup.</div>
          </div>
          <button class="close-btn" type="button" id="closeStyleDocumentModal" aria-label="Close">×</button>
        </div>
        <div class="modal-body">
          <div class="doc-modal-grid">
            <div class="row">
              <div>
                <label class="muted" for="styleDocumentStyleField">Referencing style</label>
                <select name="style_document_style" id="styleDocumentStyleField" required>
                  <?php foreach ($citationStyles as $style): ?>
                    <option value="<?= Security::e($style) ?>"><?= Security::e($style) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="muted" for="styleDocumentTypeField">Document type</label>
                <select name="style_document_type" id="styleDocumentTypeField" required>
                  <?php foreach ($citationStyleDocTypes as $type): ?>
                    <option value="<?= Security::e($type) ?>"><?= Security::e($type) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="row">
              <div>
                <label class="muted" for="styleDocumentCategoryField">Category (optional)</label>
                <select name="style_document_category" id="styleDocumentCategoryField">
                  <option value="">Applies to all categories</option>
                  <?php foreach ($citationCategories as $cat): ?>
                    <option value="<?= Security::e($cat) ?>"><?= Security::e($cat) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="muted" for="styleDocumentSubCategoryField">Sub-category (optional)</label>
                <input name="style_document_sub_category" id="styleDocumentSubCategoryField" placeholder="e.g. Audiobooks">
              </div>
            </div>
            <div>
              <label class="muted" for="styleDocumentTitleField">Title</label>
              <input name="style_document_title" id="styleDocumentTitleField" required placeholder="Harvard source type rules">
            </div>
            <div>
              <label class="muted" for="styleDocumentBodyField">Document body</label>
              <textarea name="style_document_body" id="styleDocumentBodyField" required placeholder="Paste or write the guidance here."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn" type="button" id="cancelStyleDocumentModal">Cancel</button>
          <button class="btn primary" type="submit" id="styleDocumentSubmitBtn">Save document</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-backdrop" id="deleteStyleDocumentModalBackdrop" style="display:none;">
    <div class="modal modal-danger" role="dialog" aria-modal="true" aria-labelledby="deleteStyleDocumentTitle">
      <header class="danger-modal-head">
        <div class="danger-modal-titlewrap">
          <div class="danger-modal-icon" aria-hidden="true">!</div>
          <div class="danger-modal-titlegroup">
            <div class="danger-modal-eyebrow">Destructive action</div>
            <h3 id="deleteStyleDocumentTitle">Delete style document</h3>
          </div>
        </div>
        <button class="close-btn" type="button" id="closeDeleteStyleDocumentModal" aria-label="Close">×</button>
      </header>
      <div class="danger-modal-body">
        <div class="danger-modal-copy">Are you sure you want to delete this style guide document? This cannot be undone.</div>
        <div class="danger-page-card">
          <div class="danger-page-label">Selected document</div>
          <div class="danger-page-name" id="deleteStyleDocumentName"></div>
        </div>
        <div class="danger-modal-actions">
          <button class="btn subtle" type="button" id="cancelDeleteStyleDocumentBtn">Keep document</button>
          <button class="btn danger-solid" type="button" id="confirmDeleteStyleDocumentBtn">Delete document</button>
        </div>
      </div>
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
      <button class="btn" type="button" id="editCancel" style="display:none;">Cancel</button>
      <button class="btn primary" type="submit" form="editBody" id="editSaveBtn" style="display:none;">Save changes</button>
    </div>
    <main id="viewBody" class="viewer-body">
      <div class="view-meta-grid">
        <div class="view-meta-item">
          <strong>Style</strong>
          <div id="viewStyle" class="meta-value">—</div>
        </div>
        <div class="view-meta-item">
          <strong>Category</strong>
          <div id="viewCategory" class="meta-value">—</div>
        </div>
        <div class="view-meta-item">
          <strong>Sub-category</strong>
          <div id="viewSubCategory" class="meta-value">—</div>
        </div>
        <div class="view-meta-item">
          <strong>ID</strong>
          <div id="viewId" class="meta-value">—</div>
        </div>
      </div>
      <div class="citation-field">
        <strong>Citation order</strong>
        <div id="viewOrder" class="muted" style="white-space:pre-line">—</div>
      </div>
      <div class="citation-field">
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
    <main id="revisionsBody" class="viewer-body revisions-body" style="display:none;">
      <div class="citation-field">
        <strong>Revision history</strong>
        <div class="muted" id="citationRevisionsHint">Select any revision to view what changed.</div>
      </div>
      <div id="citationRevisionsList"></div>
    </main>

    <form id="editBody" class="viewer-body edit-body" style="display:none;" method="post">
      <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="update_citation" value="1">
      <input type="hidden" name="citation_id" id="editIdField">
      <input type="hidden" name="citation_style" id="editStyleField">
      <input type="hidden" name="citation_key" id="editKeyField">
      <div class="citation-field citation-edit-field">
        <strong>Category</strong>
        <select name="citation_category" id="editCategoryField">
          <?php foreach ($citationCategories as $cat): ?>
            <option value="<?= Security::e($cat) ?>"><?= Security::e($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>Sub-category (optional)</strong>
        <input name="citation_sub_category" id="editSubCategoryField">
      </div>
      <div class="citation-field citation-edit-field">
        <strong>Label</strong>
        <input name="citation_label" id="editLabelField" required>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>Citation order</strong>
        <textarea name="citation_order" id="editOrderField" rows="4"></textarea>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>Example heading</strong>
        <input name="citation_heading" id="editHeadingField" required>
        <strong style="margin-top:8px;display:block;">Example body</strong>
        <textarea name="citation_body" id="editBodyField" rows="4" required></textarea>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>You try</strong>
        <textarea name="citation_youtry" id="editYouTryField" rows="4"></textarea>
      </div>
      <div class="citation-field citation-edit-field">
        <strong>Editorial notes</strong>
        <textarea name="citation_notes" id="editNotesField" rows="3"></textarea>
      </div>
    </form>
    <footer id="revisionsFooter" style="display:none;">
      <button class="btn" type="button" id="revisionsBackBtn">Back to citation</button>
    </footer>
  </aside>

  <aside class="cite-viewer" id="revisionViewer" aria-label="Revision details">
    <header>
      <div>
        <div class="citation-label" id="revViewLabel">Revision timeline</div>
        <div class="muted" id="revViewSubtitle" style="font-size:12px;">Select a revision to inspect what changed.</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="cite-readonly-badge" id="revViewBadge">History</span>
        <button class="close-btn" type="button" id="closeRevisionViewer" aria-label="Close">×</button>
      </div>
    </header>
    <div class="actions-bar" id="revActions">
      <div class="pill-muted" id="revActionPill">Revision timeline</div>
      <div class="pill-muted" id="revReleasePill">Compare with previous</div>
      <button class="btn text small" type="button" id="revCompareToggle">Compare with current</button>
    </div>
    <main class="viewer-body" id="revViewBody">
      <div class="citation-field">
        <strong>Revision list</strong>
        <div class="muted" id="revTimelineHint">Select a revision to inspect its changes.</div>
        <div style="margin-top:8px;display:grid;gap:8px;">
          <select id="revTimelineSelect" aria-label="Select revision"></select>
          <div class="muted" id="revSelectionMeta">—</div>
        </div>
      </div>
      <div class="citation-field">
        <strong id="revDiffTitle">Before vs After</strong>
        <div class="muted" id="revDiffHint">Changed fields only.</div>
        <div id="revDiffRows" class="rev-diff-rows" style="margin-top:8px;"></div>
      </div>
      <div class="citation-field">
        <strong>Citation</strong>
        <div class="collection-name" id="revCitationLabel">—</div>
        <div class="muted collection-slug" id="revCitationKey">—</div>
        <div class="muted" id="revCitationStyle">—</div>
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

  <aside class="cite-viewer" id="styleDocumentViewer" aria-label="Style document">
    <header>
      <div>
        <div class="citation-label" id="styleDocViewTitle">Style document</div>
        <div class="muted" id="styleDocViewSubtitle" style="font-size:12px;">Style Library</div>
        <div class="doc-viewer-meta" id="styleDocViewMeta"></div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <button class="btn small" type="button" id="styleDocEditFromViewer">Edit</button>
        <button class="close-btn" type="button" id="closeStyleDocumentViewer" aria-label="Close">×</button>
      </div>
    </header>
    <main class="viewer-body" id="styleDocViewBody">
      <div class="citation-field">
        <strong>Document</strong>
        <div class="doc-viewer-body" id="styleDocViewContent">No document selected.</div>
      </div>
    </main>
  </aside>

  <script nonce="<?= Security::e(csp_nonce()) ?>" id="revisionViewerSeed" type="application/json"><?= (string)json_encode($revisionViewerSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script nonce="<?= Security::e(csp_nonce()) ?>" id="liveCitationSeed" type="application/json"><?= (string)json_encode($liveCitationSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script nonce="<?= Security::e(csp_nonce()) ?>" id="styleDocumentSeed" type="application/json"><?= (string)json_encode($styleDocumentSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

  <script nonce="<?= Security::e(csp_nonce()) ?>">
    (function(){
    })();
    const basePath = <?= json_encode($base) ?>;

    // Style Library documents
    (function(){
      const seedEl = document.getElementById('styleDocumentSeed');
      let docs = [];
      try { docs = JSON.parse(seedEl?.textContent || '[]'); } catch(e) { docs = []; }
      const modalBackdrop = document.getElementById('styleDocumentModalBackdrop');
      const modalTitle = document.getElementById('styleDocumentModalTitle');
      const idField = document.getElementById('styleDocumentIdField');
      const styleField = document.getElementById('styleDocumentStyleField');
      const typeField = document.getElementById('styleDocumentTypeField');
      const categoryField = document.getElementById('styleDocumentCategoryField');
      const subCategoryField = document.getElementById('styleDocumentSubCategoryField');
      const titleField = document.getElementById('styleDocumentTitleField');
      const bodyField = document.getElementById('styleDocumentBodyField');
      const viewer = document.getElementById('styleDocumentViewer');
      const viewerClose = document.getElementById('closeStyleDocumentViewer');
      const viewerTitle = document.getElementById('styleDocViewTitle');
      const viewerSub = document.getElementById('styleDocViewSubtitle');
      const viewerMeta = document.getElementById('styleDocViewMeta');
      const viewerContent = document.getElementById('styleDocViewContent');
      const editFromViewer = document.getElementById('styleDocEditFromViewer');
      const deleteModalBackdrop = document.getElementById('deleteStyleDocumentModalBackdrop');
      const deleteModalName = document.getElementById('deleteStyleDocumentName');
      const deleteModalId = document.getElementById('deleteStyleDocumentId');
      const deleteModalForm = document.getElementById('deleteStyleDocumentForm');
      let activeDoc = null;
      let activeDocDefaults = {};
      let pendingDeleteStyleDocumentId = '';

      const escapeHtml = (str) => String(str ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
      const docTypeForScope = (scope) => {
        if (scope === 'source') return 'Source type information';
        if (scope === 'rules') return 'Referencing rules';
        if (scope === 'style') return 'Style guide';
        return '';
      };
      const pill = (text) => text ? `<span class="style-doc-pill">${escapeHtml(text)}</span>` : '';
      const docMatchesCitation = (doc, row, scope) => {
        if (!doc || !row) return false;
        const style = row.dataset.style || '';
        const category = row.dataset.category || '';
        const subCategory = row.dataset.subCategory || '';
        const wantedType = docTypeForScope(scope);
        if (style && doc.style !== style) return false;
        if (wantedType && doc.type !== wantedType) return false;
        if (doc.category && doc.category !== category) return false;
        if (doc.subCategory && doc.subCategory !== subCategory) return false;
        return true;
      };

      const fillModal = (doc = null, defaults = {}) => {
        if (idField) idField.value = String(doc?.id || 0);
        if (styleField) styleField.value = doc?.style || defaults.style || styleField.options?.[0]?.value || '';
        if (typeField) typeField.value = doc?.type || defaults.type || typeField.options?.[0]?.value || '';
        if (categoryField) categoryField.value = doc?.category || defaults.category || '';
        if (subCategoryField) subCategoryField.value = doc?.subCategory || defaults.subCategory || '';
        if (titleField) titleField.value = doc?.title || defaults.title || '';
        if (bodyField) bodyField.value = doc?.body || '';
        if (modalTitle) modalTitle.textContent = doc?.id ? 'Edit style document' : 'Add style document';
      };
      const openModal = (doc = null, defaults = {}) => {
        fillModal(doc, defaults);
        if (modalBackdrop) modalBackdrop.style.display = 'flex';
        window.setTimeout(() => titleField?.focus(), 0);
      };
      const closeModal = () => {
        if (modalBackdrop) modalBackdrop.style.display = 'none';
      };
      document.getElementById('openStyleDocumentModal')?.addEventListener('click', () => openModal());
      document.getElementById('closeStyleDocumentModal')?.addEventListener('click', closeModal);
      document.getElementById('cancelStyleDocumentModal')?.addEventListener('click', closeModal);
      modalBackdrop?.addEventListener('click', (event) => {
        if (event.target === modalBackdrop) closeModal();
      });
      const openDeleteModal = (id, title) => {
        pendingDeleteStyleDocumentId = String(id || '');
        if (!pendingDeleteStyleDocumentId || !deleteModalBackdrop) return;
        if (deleteModalName) deleteModalName.textContent = title || 'Style document';
        deleteModalBackdrop.style.display = 'flex';
      };
      const closeDeleteModal = () => {
        pendingDeleteStyleDocumentId = '';
        if (deleteModalBackdrop) deleteModalBackdrop.style.display = 'none';
      };
      document.getElementById('closeDeleteStyleDocumentModal')?.addEventListener('click', closeDeleteModal);
      document.getElementById('cancelDeleteStyleDocumentBtn')?.addEventListener('click', closeDeleteModal);
      deleteModalBackdrop?.addEventListener('click', (event) => {
        if (event.target === deleteModalBackdrop) closeDeleteModal();
      });
      document.getElementById('confirmDeleteStyleDocumentBtn')?.addEventListener('click', () => {
        if (!pendingDeleteStyleDocumentId || !deleteModalId || !deleteModalForm) return;
        deleteModalId.value = pendingDeleteStyleDocumentId;
        try { localStorage.setItem('citationDatabaseView', 'library'); } catch (e) {}
        deleteModalForm.submit();
      });

      const openViewer = (doc, fallback = {}) => {
        activeDoc = doc || null;
        activeDocDefaults = fallback || {};
        if (viewerTitle) viewerTitle.textContent = doc?.title || fallback.title || 'No style document found';
        if (viewerSub) viewerSub.textContent = doc ? `${doc.style} · ${doc.type}` : 'Style Library';
        if (viewerMeta) {
          viewerMeta.innerHTML = doc
            ? [pill(doc.style), pill(doc.type), pill(doc.category), pill(doc.subCategory), pill(doc.updatedAt ? `Updated ${doc.updatedAt}` : '')].join('')
            : [pill(fallback.style), pill(fallback.category), pill(fallback.subCategory)].join('');
        }
        if (viewerContent) {
          viewerContent.textContent = doc?.body || 'No matching document exists yet. Use “Edit” to create one for this style or source type.';
        }
        if (editFromViewer) editFromViewer.textContent = doc ? 'Edit' : 'Create';
        viewer?.classList.add('active');
      };
      const closeViewer = () => viewer?.classList.remove('active');
      viewerClose?.addEventListener('click', closeViewer);
      editFromViewer?.addEventListener('click', () => {
        openModal(activeDoc, activeDoc ? {} : activeDocDefaults);
      });

      document.querySelectorAll('[data-style-doc-card]').forEach((card) => {
        const cardDoc = () => docs.find(d => String(d.id) === String(card.dataset.id)) || {
          id: card.dataset.id || '',
          style: card.dataset.style || '',
          type: card.dataset.type || '',
          category: card.dataset.category || '',
          subCategory: card.dataset.subCategory || '',
          title: card.dataset.title || '',
          body: card.dataset.body || '',
          updatedBy: card.dataset.updatedBy || '',
          updatedAt: card.dataset.updatedAt || '',
        };
        card.querySelector('[data-open-doc-card]')?.addEventListener('click', () => openViewer(cardDoc()));
        card.querySelector('[data-edit-doc-card]')?.addEventListener('click', () => openModal(cardDoc()));
        card.querySelector('[data-delete-style-doc]')?.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          openDeleteModal(card.dataset.id || '', card.dataset.title || 'Style document');
        });
      });

      document.querySelectorAll('[data-doc-menu]').forEach((menu) => {
        const btn = menu.querySelector('.citation-doc-menu-btn');
        btn?.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          document.querySelectorAll('[data-doc-menu].open').forEach((other) => {
            if (other !== menu) {
              other.classList.remove('open');
              other.querySelector('.citation-doc-menu-btn')?.setAttribute('aria-expanded', 'false');
            }
          });
          const open = !menu.classList.contains('open');
          menu.classList.toggle('open', open);
          btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        menu.querySelectorAll('[data-open-style-docs]').forEach((item) => {
          item.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const row = menu.closest('.citation-row');
            const scope = item.getAttribute('data-doc-scope') || 'all';
            const matches = docs.filter(doc => docMatchesCitation(doc, row, scope));
            const selected = matches[0] || null;
            const wantedType = docTypeForScope(scope);
            openViewer(selected, {
              title: wantedType || 'Related style documents',
              style: row?.dataset.style || '',
              type: wantedType || 'Style guide',
              category: row?.dataset.category || '',
              subCategory: row?.dataset.subCategory || '',
            });
            menu.classList.remove('open');
            btn?.setAttribute('aria-expanded', 'false');
          });
        });
      });

      document.addEventListener('click', (event) => {
        if (event.target.closest?.('[data-doc-menu]')) return;
        document.querySelectorAll('[data-doc-menu].open').forEach((menu) => {
          menu.classList.remove('open');
          menu.querySelector('.citation-doc-menu-btn')?.setAttribute('aria-expanded', 'false');
        });
      });
    })();
    
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
    function applyPageFilters() {
      const q = (searchInput?.value || '').toLowerCase();
      const st = (statusFilter?.value || '').toLowerCase();
      let visible = 0;
      rows.forEach(r => {
        const matchesSearch = (!q || r.dataset.title.includes(q) || r.dataset.path.includes(q));
        const matchesStatus = (!st || r.dataset.status === st);
        const match = matchesSearch && matchesStatus;
        r.style.display = match ? '' : 'none';
        if (match) visible++;
      });
      if (empty) empty.style.display = visible ? 'none' : '';
      if (clearFilters) clearFilters.style.display = (q || st) ? 'inline-flex' : 'none';
    }
    searchInput?.addEventListener('input', applyPageFilters);
    statusFilter?.addEventListener('change', applyPageFilters);
    clearFilters?.addEventListener('click', () => {
      if (searchInput) searchInput.value = '';
      if (statusFilter) statusFilter.value = '';
      applyPageFilters();
    });
    applyPageFilters();

    const formSearchInput = document.getElementById('formSearch');
    const formRows = Array.from(document.querySelectorAll('#formTable tr'));
    const formEmpty = document.getElementById('formEmpty');
    const formModalBackdrop = document.getElementById('formModalBackdrop');
    const formModalTitle = document.getElementById('formModalTitle');
    const formModalForm = document.getElementById('formModalForm');
    const formModalId = document.getElementById('form_modal_id');
    const formModalName = document.getElementById('form_modal_name');
    const formModalDescription = document.getElementById('form_modal_description');
    const formPreviewToolbar = document.getElementById('formPreviewToolbar');
    const formPreviewPanel = document.getElementById('formPreviewPanel');
    const editFormPreviewBtn = document.getElementById('editFormPreviewBtn');
    const deleteFormPreviewBtn = document.getElementById('deleteFormPreviewBtn');
    const deleteFormModalActionForm = document.getElementById('deleteFormModalActionForm');
    const deleteFormModalActionId = document.getElementById('deleteFormModalActionId');
    const cancelFormModalBtn = document.getElementById('cancelFormModalBtn');
    const saveFormModalBtn = document.getElementById('saveFormModalBtn');
    const siteFormsData = (() => {
      const el = document.getElementById('siteFormsData');
      if (!el) return [];
      try { return JSON.parse(el.textContent || '[]'); } catch (err) { return []; }
    })();
    const siteFormResponsesData = (() => {
      const el = document.getElementById('siteFormResponsesData');
      if (!el) return {};
      try { return JSON.parse(el.textContent || '{}'); } catch (err) { return {}; }
    })();
    const formFeedbackModalBackdrop = document.getElementById('formFeedbackModalBackdrop');
    const formFeedbackModalTitle = document.getElementById('formFeedbackModalTitle');
    const formFeedbackModalMeta = document.getElementById('formFeedbackModalMeta');
    const formFeedbackModalBody = document.getElementById('formFeedbackModalBody');
    const pendingEditingForm = <?= json_encode([
      'id' => (int)($editingSiteForm['id'] ?? 0),
      'name' => (string)($editingSiteForm['name'] ?? ''),
      'description' => (string)($editingSiteForm['description'] ?? ''),
      'questions' => array_values(array_map(static function (array $question): array {
        return [
          'id' => (string)($question['id'] ?? ''),
          'label' => (string)($question['label'] ?? ''),
          'type' => (string)($question['type'] ?? 'text'),
        ];
      }, (array)($editingSiteForm['questions'] ?? []))),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const shouldOpenFormModalOnLoad = <?= (!empty($_POST['save_site_form']) || $editingSiteFormId > 0) ? 'true' : 'false' ?>;
    let activeFormModalData = null;
    const closeFormFeedbackModal = () => {
      if (formFeedbackModalBackdrop) formFeedbackModalBackdrop.style.display = 'none';
      document.body.style.overflow = '';
    };
    function applyFormFilters() {
      const q = (formSearchInput?.value || '').toLowerCase();
      let visible = 0;
      formRows.forEach((row) => {
        const match = !q || (row.dataset.name || '').includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
      });
      if (formEmpty) formEmpty.style.display = visible ? 'none' : '';
    }
    formSearchInput?.addEventListener('input', applyFormFilters);
    applyFormFilters();

    const formQuestionsList = document.getElementById('formQuestionsList');
    const addFormQuestionBtn = document.getElementById('addFormQuestionBtn');
    const simpleEscapeHtml = (str) => String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
    let draggedQuestionRow = null;
    const renumberFormQuestions = () => {
      formQuestionsList?.querySelectorAll('[data-question-row]').forEach((row, index) => {
        const numberEl = row.querySelector('[data-question-number]');
        if (numberEl) numberEl.textContent = `Question ${index + 1}`;
      });
    };
    const findDropTargetRow = (event) => {
      const rows = [...(formQuestionsList?.querySelectorAll('[data-question-row]') || [])];
      const candidates = rows.filter((row) => row !== draggedQuestionRow);
      return candidates.find((row) => {
        const rect = row.getBoundingClientRect();
        return event.clientY >= rect.top && event.clientY <= rect.bottom;
      }) || null;
    };
    const bindQuestionDragAndDrop = () => {
      formQuestionsList?.querySelectorAll('[data-question-row]').forEach((row) => {
        row.draggable = false;
        const handle = row.querySelector('[data-drag-handle]');
        if (!handle) return;
        handle.draggable = true;
        handle.ondragstart = (event) => {
          draggedQuestionRow = row;
          row.style.opacity = '0.55';
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', 'question-row');
        };
        handle.ondragend = () => {
          if (draggedQuestionRow) draggedQuestionRow.style.opacity = '';
          draggedQuestionRow = null;
          renumberFormQuestions();
        };
      });
      formQuestionsList.ondragover = (event) => {
        if (!draggedQuestionRow) return;
        event.preventDefault();
        const targetRow = findDropTargetRow(event);
        if (!targetRow || !formQuestionsList) return;
        const rect = targetRow.getBoundingClientRect();
        const insertBefore = event.clientY < rect.top + (rect.height / 2);
        formQuestionsList.insertBefore(draggedQuestionRow, insertBefore ? targetRow : targetRow.nextSibling);
      };
      formQuestionsList.ondrop = (event) => {
        if (!draggedQuestionRow) return;
        event.preventDefault();
        renumberFormQuestions();
      };
    };
    const renderQuestionRow = (question = {}) => {
      const row = document.createElement('div');
      row.className = 'card form-question-row';
      row.setAttribute('data-question-row', '');
      row.style.padding = '14px';
      row.innerHTML = `
        <input type="hidden" name="question_id[]" value="${simpleEscapeHtml(question.id || '')}">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">
          <div class="muted" data-question-number style="font-weight:700;">Question</div>
          <div class="muted" data-drag-handle aria-hidden="true" title="Drag to reorder" style="font-size:18px;letter-spacing:1px;cursor:grab;user-select:none;">&#8942;&#8942;</div>
        </div>
        <div class="row">
          <div>
            <label class="muted">Question</label>
            <input name="question_label[]" value="${simpleEscapeHtml(question.label || '')}" placeholder="What did you think of this page?">
          </div>
          <div>
            <label class="muted">Answer type</label>
            <select name="question_type[]">
              <option value="text" ${(question.type || 'text') === 'text' ? 'selected' : ''}>Text answer</option>
              <option value="rating" ${(question.type || '') === 'rating' ? 'selected' : ''}>Rating answer</option>
            </select>
          </div>
        </div>
        <div style="margin-top:10px;display:flex;justify-content:flex-end">
          <button class="btn text" type="button" data-remove-question>Remove</button>
        </div>
      `;
      return row;
    };
    const bindQuestionRemoval = () => {
      formQuestionsList?.querySelectorAll('[data-remove-question]').forEach((btn) => {
        btn.onclick = () => {
          const rows = formQuestionsList.querySelectorAll('[data-question-row]');
          if (rows.length <= 1) return;
          btn.closest('[data-question-row]')?.remove();
          renumberFormQuestions();
        };
      });
    };
    const syncQuestionEditorUi = () => {
      bindQuestionRemoval();
      bindQuestionDragAndDrop();
      renumberFormQuestions();
    };
    const renderFormPreview = (formData = {}) => {
      const title = (formData?.name || '').trim() || 'Form';
      const description = (formData?.description || '').trim();
      const questions = Array.isArray(formData?.questions) ? formData.questions : [];
      const questionsHtml = questions.length
        ? questions.map((question, index) => {
          const label = (question?.label || '').trim() || `Question ${index + 1}`;
          const type = (question?.type || 'text').toLowerCase();
          const inputHtml = type === 'rating'
            ? `
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                ${Array.from({ length: 10 }, (_, scoreIndex) => `
                  <span style="display:inline-flex;align-items:center;justify-content:center;min-width:42px;height:42px;padding:0 12px;border-radius:999px;border:1px solid rgba(17,24,39,.16);background:#fff;color:#111827;font-weight:700;">${scoreIndex + 1}</span>
                `).join('')}
              </div>
              <div style="margin-top:8px;font-size:13px;color:#6b7280;">1 is low, 10 is high.</div>
            `
            : '<textarea rows="4" placeholder="Type your answer" disabled style="width:100%;margin-top:10px;padding:12px 14px;border:1px solid rgba(17,24,39,.16);border-radius:12px;background:#fff;color:#111827;font:inherit;resize:vertical;"></textarea>';
          return `
            <div style="padding:16px 0;border-top:1px solid rgba(17,24,39,.1);">
              <label style="display:block;font-weight:700;color:#111827;">${index + 1}. ${simpleEscapeHtml(label)}</label>
              ${inputHtml}
            </div>
          `;
        }).join('')
        : '<div style="margin-top:12px;color:#6b7280;">This form has no questions yet.</div>';
      return `
        <form style="padding:20px;border:1px solid rgba(17,24,39,.12);border-radius:18px;background:rgba(255,255,255,.94);box-shadow:0 16px 40px rgba(15,23,42,.08);">
          <div style="font-size:1.2em;font-weight:800;color:#111827;">${simpleEscapeHtml(title)}</div>
          ${description ? `<div style="margin-top:8px;color:#4b5563;">${simpleEscapeHtml(description)}</div>` : ''}
          <div style="margin-top:16px;">${questionsHtml}</div>
          <button type="button" style="margin-top:16px;display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border:0;border-radius:999px;background:var(--nexus-primary,#2563eb);color:#fff;font:inherit;font-weight:800;cursor:pointer;">Submit</button>
        </form>
      `;
    };
    const formatFeedbackValue = (item = {}) => (
      item?.type === 'rating'
        ? `${Number(item?.value || 0)}/10`
        : String(item?.value || '')
    );
    const renderFormFeedbackSubmissions = (responses = []) => {
      if (!responses.length) {
        return '<div class="muted">No feedback has been submitted for this form yet.</div>';
      }
      return responses.map((response, index) => {
        const submittedAt = simpleEscapeHtml(response?.created_at || '');
        const pageSlug = simpleEscapeHtml(response?.page_slug || '');
        const userName = simpleEscapeHtml((response?.user_name || '').trim() || 'Anonymous');
        const institutionName = simpleEscapeHtml((response?.institution_name || '').trim() || '—');
        const items = Array.isArray(response?.responses) ? response.responses : [];
        const responsesHtml = items.length
          ? items.map((item, itemIndex) => {
            const label = (item?.label || '').trim() || `Question ${itemIndex + 1}`;
            return `
              <div style="padding:12px 0;border-top:1px solid rgba(17,24,39,.08);">
                <div style="font-weight:700;color:#111827;">${simpleEscapeHtml(label)}</div>
                <div style="margin-top:6px;color:#374151;white-space:pre-wrap;word-break:break-word;">${simpleEscapeHtml(formatFeedbackValue(item))}</div>
              </div>
            `;
          }).join('')
          : '<div class="muted" style="margin-top:12px;">No answers stored for this submission.</div>';
        return `
          <section class="card" style="padding:16px;margin-top:${index === 0 ? '0' : '12px'};">
            <div style="display:grid;gap:10px;">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div>
                  <div style="font-weight:800;color:#111827;">Submission #${index + 1}</div>
                  <div class="muted" style="margin-top:4px;font-size:13px;">${submittedAt || 'Unknown date'}${pageSlug ? ` • Page: ${pageSlug}` : ''}</div>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
                <div style="padding:12px;border-radius:12px;background:rgba(17,24,39,.04);">
                  <div class="muted" style="font-size:12px;">User</div>
                  <div style="margin-top:4px;font-weight:700;color:#111827;">${userName}</div>
                </div>
                <div style="padding:12px;border-radius:12px;background:rgba(17,24,39,.04);">
                  <div class="muted" style="font-size:12px;">Institution</div>
                  <div style="margin-top:4px;font-weight:700;color:#111827;">${institutionName}</div>
                </div>
              </div>
            </div>
            <div style="margin-top:10px;">${responsesHtml}</div>
          </section>
        `;
      }).join('');
    };
    const renderFormFeedbackByQuestion = (formData = {}, responses = []) => {
      const questions = Array.isArray(formData?.questions) ? formData.questions : [];
      if (!responses.length) {
        return '<div class="muted">No feedback has been submitted for this form yet.</div>';
      }
      const grouped = questions.map((question, index) => {
        const qid = String(question?.id || '');
        const label = (question?.label || '').trim() || `Question ${index + 1}`;
        const answers = responses.flatMap((response) => {
          const items = Array.isArray(response?.responses) ? response.responses : [];
          return items
            .filter((item) => String(item?.id || '') === qid)
            .map((item) => ({
              created_at: response?.created_at || '',
              page_slug: response?.page_slug || '',
              value: formatFeedbackValue(item),
            }));
        });
        return { label, answers };
      });
      return grouped.map((group, index) => {
        const answersHtml = group.answers.length
          ? group.answers.map((answer) => `
            <div style="padding:12px 0;border-top:1px solid rgba(17,24,39,.08);">
              <div style="color:#374151;white-space:pre-wrap;word-break:break-word;">${simpleEscapeHtml(answer.value)}</div>
              <div class="muted" style="margin-top:6px;font-size:12px;">${simpleEscapeHtml(answer.created_at || 'Unknown date')}${answer.page_slug ? ` • Page: ${simpleEscapeHtml(answer.page_slug)}` : ''}</div>
            </div>
          `).join('')
          : '<div class="muted" style="margin-top:12px;">No answers recorded for this question yet.</div>';
        return `
          <section class="card" style="padding:16px;margin-top:${index === 0 ? '0' : '12px'};">
            <div style="font-weight:800;color:#111827;">${index + 1}. ${simpleEscapeHtml(group.label)}</div>
            <div style="margin-top:4px" class="muted">${group.answers.length} answer${group.answers.length === 1 ? '' : 's'}</div>
            <div style="margin-top:10px;">${answersHtml}</div>
          </section>
        `;
      }).join('');
    };
    const openFormFeedbackModal = (formData = null) => {
      const formId = String(formData?.id || '');
      const responses = Array.isArray(siteFormResponsesData[formId]) ? siteFormResponsesData[formId] : [];
      if (formFeedbackModalTitle) formFeedbackModalTitle.textContent = `${formData?.name || 'Form'} feedback`;
      if (formFeedbackModalMeta) {
        formFeedbackModalMeta.textContent = responses.length
          ? `${responses.length} submission${responses.length === 1 ? '' : 's'} received.`
          : 'No submissions yet.';
      }
      if (formFeedbackModalBody) {
        formFeedbackModalBody.innerHTML = `
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
            <button class="btn small" type="button" data-feedback-tab="submissions" style="background:#111827;color:#fff;border-color:#111827;">Submissions</button>
            <button class="btn small" type="button" data-feedback-tab="questions">By question</button>
          </div>
          <div data-feedback-panel="submissions">${renderFormFeedbackSubmissions(responses)}</div>
          <div data-feedback-panel="questions" style="display:none">${renderFormFeedbackByQuestion(formData || {}, responses)}</div>
        `;
        const tabButtons = Array.from(formFeedbackModalBody.querySelectorAll('[data-feedback-tab]'));
        const tabPanels = Array.from(formFeedbackModalBody.querySelectorAll('[data-feedback-panel]'));
        tabButtons.forEach((btn) => {
          btn.addEventListener('click', () => {
            const activeTab = btn.getAttribute('data-feedback-tab') || 'submissions';
            tabButtons.forEach((candidate) => {
              const isActive = candidate.getAttribute('data-feedback-tab') === activeTab;
              candidate.style.background = isActive ? '#111827' : '';
              candidate.style.color = isActive ? '#fff' : '';
              candidate.style.borderColor = isActive ? '#111827' : '';
            });
            tabPanels.forEach((panel) => {
              panel.style.display = panel.getAttribute('data-feedback-panel') === activeTab ? '' : 'none';
            });
          });
        });
      }
      if (formFeedbackModalBackdrop) formFeedbackModalBackdrop.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    };
    const setFormModalMode = (mode, formData = null) => {
      activeFormModalData = formData ? { ...formData } : null;
      const isPreview = mode === 'preview';
      const isEdit = mode === 'edit';
      if (formModalTitle) formModalTitle.textContent = isPreview ? 'Form preview' : (isEdit ? 'Edit form' : 'Create form');
      if (saveFormModalBtn) {
        saveFormModalBtn.textContent = isEdit ? 'Save form' : 'Create form';
        saveFormModalBtn.style.display = isPreview ? 'none' : '';
      }
      if (cancelFormModalBtn) cancelFormModalBtn.textContent = isPreview ? 'Close' : 'Cancel';
      if (formPreviewToolbar) formPreviewToolbar.style.display = isPreview ? 'flex' : 'none';
      if (formPreviewPanel) {
        formPreviewPanel.style.display = isPreview ? 'block' : 'none';
        formPreviewPanel.innerHTML = isPreview ? renderFormPreview(formData) : '';
      }
      if (formModalForm) formModalForm.style.display = isPreview ? 'none' : '';
      if (deleteFormModalActionId) deleteFormModalActionId.value = String(formData?.id || 0);
      if (isPreview) return;
      if (formModalId) formModalId.value = String(formData?.id || 0);
      if (formModalName) formModalName.value = formData?.name || '';
      if (formModalDescription) formModalDescription.value = formData?.description || '';
      if (formQuestionsList) {
        formQuestionsList.innerHTML = '';
        const questions = Array.isArray(formData?.questions) && formData.questions.length
          ? formData.questions
          : [{ id: '', label: '', type: 'text' }];
        questions.forEach((question) => formQuestionsList.appendChild(renderQuestionRow(question)));
      }
      syncQuestionEditorUi();
    };
    const openFormModal = (mode, formData = null) => {
      setFormModalMode(mode, formData);
      if (formModalBackdrop) formModalBackdrop.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      if (mode !== 'preview') formModalName?.focus();
    };
    const closeFormModal = () => {
      if (formModalBackdrop) formModalBackdrop.style.display = 'none';
      document.body.style.overflow = '';
    };
    document.getElementById('openCreateFormModal')?.addEventListener('click', () => {
      openFormModal('create');
      activate('forms');
    });
    addFormQuestionBtn?.addEventListener('click', () => {
      if (!formQuestionsList) return;
      formQuestionsList.appendChild(renderQuestionRow({ type: 'text' }));
      syncQuestionEditorUi();
    });
    syncQuestionEditorUi();
    document.querySelectorAll('[data-form-row]').forEach((row) => {
      row.addEventListener('click', (event) => {
        if (event.target.closest('form') || event.target.closest('button')) return;
        const formId = String(row.dataset.formId || '');
        const formData = siteFormsData.find((item) => String(item.id) === formId);
        if (formData) {
          openFormModal('preview', formData);
          activate('forms');
        }
      });
    });
    document.querySelectorAll('[data-open-form-modal]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.stopPropagation();
        const formId = String(btn.dataset.formId || '');
        const formData = siteFormsData.find((item) => String(item.id) === formId);
        if (formData) {
          openFormModal('preview', formData);
          activate('forms');
        }
      });
    });
    document.querySelectorAll('[data-open-form-feedback]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.stopPropagation();
        const formId = String(btn.dataset.formId || '');
        const formData = siteFormsData.find((item) => String(item.id) === formId);
        if (formData) {
          openFormFeedbackModal(formData);
          activate('forms');
        }
      });
    });
    editFormPreviewBtn?.addEventListener('click', () => {
      if (activeFormModalData) setFormModalMode('edit', activeFormModalData);
    });
    deleteFormPreviewBtn?.addEventListener('click', () => {
      const formId = Number(activeFormModalData?.id || 0);
      if (!formId || !deleteFormModalActionId || !deleteFormModalActionForm) return;
      if (!window.confirm('Delete this form?')) return;
      deleteFormModalActionId.value = String(formId);
      deleteFormModalActionForm.submit();
    });
    document.getElementById('closeFormModal')?.addEventListener('click', closeFormModal);
    cancelFormModalBtn?.addEventListener('click', closeFormModal);
    document.getElementById('closeFormFeedbackModal')?.addEventListener('click', closeFormFeedbackModal);
    document.getElementById('closeFormFeedbackModalBtn')?.addEventListener('click', closeFormFeedbackModal);
    formModalBackdrop?.addEventListener('click', (event) => {
      if (event.target === formModalBackdrop) closeFormModal();
    });
    formFeedbackModalBackdrop?.addEventListener('click', (event) => {
      if (event.target === formFeedbackModalBackdrop) closeFormFeedbackModal();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && formModalBackdrop?.style.display === 'flex') closeFormModal();
      if (event.key === 'Escape' && formFeedbackModalBackdrop?.style.display === 'flex') closeFormFeedbackModal();
    });
    if (shouldOpenFormModalOnLoad) {
      openFormModal((pendingEditingForm?.id || 0) > 0 ? 'edit' : 'create', pendingEditingForm);
      activate('forms');
    }

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
    const citationModalBody = citationBackdrop?.querySelector('.modal-body') || null;
    const openCitationModal = document.getElementById('openCitationModal');
    const closeCitationModal = document.getElementById('closeCitationModal');
    const cancelCitationModal = document.getElementById('cancelCitationModal');
    const citationTitle = document.getElementById('citationModalTitle');
    const citationActionAdd = document.getElementById('citationActionAdd');
    const citationActionUpdate = document.getElementById('citationActionUpdate');
    const citationSubmitBtn = document.getElementById('citationSubmitBtn');
    const citationIdField = document.getElementById('citationIdField');
    const citationStyleField = document.getElementById('citationStyleField');
    const citationCategoryField = document.getElementById('citationCategoryField');
    const citationSubCategoryField = document.getElementById('citationSubCategoryField');
    const citationLabelField = document.getElementById('citationLabelField');
    const citationOrderField = document.getElementById('citationOrderField');
    const citationHeadingField = document.getElementById('citationHeadingField');
    const citationBodyField = document.getElementById('citationBodyField');
    const citationYouTryField = document.getElementById('citationYouTryField');
    const citationNotesField = document.getElementById('citationNotesField');
    const citationKeyField = document.getElementById('citationKeyField');
    const citationModalForm = document.getElementById('citationModalForm');
    const viewStyle = document.getElementById('viewStyle');
    const viewCategory = document.getElementById('viewCategory');
    const viewSubCategory = document.getElementById('viewSubCategory');
    const viewId = document.getElementById('viewId');
    const viewOrder = document.getElementById('viewOrder');
    const viewExampleHeading = document.getElementById('viewExampleHeading');
    const viewExampleBody = document.getElementById('viewExampleBody');
    const viewYouTry = document.getElementById('viewYouTry');
    const viewNotes = document.getElementById('viewNotes');
    const editOrderField = document.getElementById('editOrderField');
    const editBodyField = document.getElementById('editBodyField');
    const editYouTryField = document.getElementById('editYouTryField');
    const editNotesField = document.getElementById('editNotesField');
    const editCategoryField = document.getElementById('editCategoryField');
    const editSubCategoryField = document.getElementById('editSubCategoryField');

    const richSourceToHtml = (str) => {
      if (!str) return '';
      const raw = String(str);
      if (/<[a-z][\s\S]*>/i.test(raw)) {
        return raw;
      }
      const escaped = raw
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
      const withBold = escaped.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
      // Italics: single asterisks that are not part of bold markers
      const withItalics = withBold.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g,'<em>$1</em>');
      const lines = withItalics.split(/\r?\n/);
      let html = '';
      let inList = false;
      lines.forEach((line, idx) => {
        const m = line.match(/^\s*[\\-\\*•]\s+(.+)/);
        if (m) {
          if (!inList) { html += '<ul>'; inList = true; }
          html += '<li>' + m[1] + '</li>';
        } else {
          if (inList) { html += '</ul>'; inList = false; }
          html += line;
          if (idx !== lines.length -1) html += '<br>';
        }
      });
      if (inList) html += '</ul>';
      return html;
    };

    const htmlToStorageHtml = (html, collapseToSingleBreak = false) => {
      const escape = (str) => String(str ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
      const parseInlineState = (node, state) => {
        const next = { bold: !!state.bold, italic: !!state.italic };
        if (!node || node.nodeType !== Node.ELEMENT_NODE) return next;
        const tag = node.tagName.toLowerCase();
        if (tag === 'strong' || tag === 'b') next.bold = true;
        if (tag === 'em' || tag === 'i') next.italic = true;
        const style = String(node.getAttribute('style') || '').toLowerCase();
        const weightMatch = style.match(/font-weight\s*:\s*([^;]+)/);
        if (weightMatch) {
          const weightRaw = weightMatch[1].trim();
          const weightNum = parseInt(weightRaw, 10);
          if (!Number.isNaN(weightNum)) {
            next.bold = weightNum >= 600;
          } else if (weightRaw === 'normal') {
            next.bold = false;
          } else if (weightRaw === 'bold' || weightRaw === 'bolder') {
            next.bold = true;
          }
        }
        const styleMatch = style.match(/font-style\s*:\s*([^;]+)/);
        if (styleMatch) {
          const fontStyle = styleMatch[1].trim();
          if (fontStyle === 'normal') next.italic = false;
          if (fontStyle === 'italic' || fontStyle === 'oblique') next.italic = true;
        }
        return next;
      };
      const wrapInline = (text, state) => {
        let out = escape(text);
        if (out === '') return '';
        if (state.italic) out = `<em>${out}</em>`;
        if (state.bold) out = `<strong>${out}</strong>`;
        return out;
      };
      const walk = (node, state = { bold: false, italic: false }) => {
        if (node.nodeType === Node.TEXT_NODE) return wrapInline(node.textContent || '', state);
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        const tag = node.tagName.toLowerCase();
        const nextState = parseInlineState(node, state);
        const inner = Array.from(node.childNodes).map((child) => walk(child, nextState)).join('');
        if (tag === 'br') return '<br>';
        if (tag === 'ul') return inner ? `<ul>${inner}</ul>` : '';
        if (tag === 'ol') return inner ? `<ol>${inner}</ol>` : '';
        if (tag === 'li') return inner.trim() ? `<li>${inner}</li>` : '';
        if (tag === 'p' || tag === 'div' || tag === 'section' || tag === 'article') {
          const compact = inner.replace(/(?:<br>\s*){2,}/g, '<br>').trim();
          return compact ? `<p>${compact}</p>` : '';
        }
        return inner;
      };

      const container = document.createElement('div');
      container.innerHTML = html || '';
      let out = Array.from(container.childNodes).map((child) => walk(child)).join('');
      out = out.replace(/<p><\/p>/g, '');
      out = out.replace(/(?:<br>\s*){3,}/g, collapseToSingleBreak ? '<br>' : '<br><br>');
      return out.trim();
    };

    const htmlToMd = (html, collapseToSingleBreak = false) => {
      const walk = (node) => {
        if (node.nodeType === Node.TEXT_NODE) return node.textContent;
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        const tag = node.tagName.toLowerCase();
        if (tag === 'br') return '\n';
        if (tag === 'strong' || tag === 'b') return '**' + Array.from(node.childNodes).map(walk).join('') + '**';
        if (tag === 'em' || tag === 'i') return '*' + Array.from(node.childNodes).map(walk).join('') + '*';
      if (tag === 'ul' || tag === 'ol') {
          // Preserve visual bullets in saved text (use • instead of dash).
          return Array.from(node.children).map(li => '• ' + walk(li)).join('\n');
        }
      if (tag === 'li') return Array.from(node.childNodes).map(walk).join('');
      if (tag === 'p' || tag === 'div' || tag === 'section' || tag === 'article') {
        const inner = Array.from(node.childNodes).map(walk).join('');
        return inner + '\n';
      }
        return Array.from(node.childNodes).map(walk).join('');
      };
      const container = document.createElement('div');
      container.innerHTML = html;
      // Preserve user-entered structure: keep original newlines and spacing.
      return walk(container)
        .replace(/\r/g, '')
        .replace(/[ \t]+\n/g, '\n')
        .replace(collapseToSingleBreak ? /\n{2,}/g : /\n{3,}/g, collapseToSingleBreak ? '\n' : '\n\n')
        .trim();
    };

    const richTargets = [
      citationOrderField,
      citationBodyField,
      citationYouTryField,
      citationNotesField,
      editOrderField,
      editBodyField,
      editYouTryField,
      editNotesField
    ].filter(Boolean);
    const htmlStorageFieldIds = new Set([
      'citationOrderField',
      'citationBodyField',
      'citationYouTryField',
      'editOrderField',
      'editBodyField',
      'editYouTryField'
    ]);

    let activeEditor = null;

    const createToolbar = (editor) => {
      const bar = document.createElement('div');
      bar.className = 'mini-toolbar';
      const mkBtn = (label, title, action) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.title = title;
        b.addEventListener('click', (e) => {
          e.preventDefault();
          activeEditor = editor;
          editor.focus();
          document.execCommand(action);
        });
        return b;
      };
      bar.appendChild(mkBtn('B', 'Bold', 'bold'));
      bar.appendChild(mkBtn('I', 'Italics', 'italic'));
      const bullets = mkBtn('•', 'Bullets', 'insertUnorderedList');
      bullets.innerHTML = '<span aria-hidden="true">•</span><span class="toolbar-label">Bullets</span>';
      bar.appendChild(bullets);
      return bar;
    };

    const createEditor = (textarea, withToolbar=false) => {
      const wrapper = document.createElement('div');
      wrapper.style.display = 'flex';
      wrapper.style.flexDirection = 'column';
      wrapper.style.gap = '4px';
      const useSoftBreaks = [
        'citationOrderField',
        'citationBodyField',
        'citationYouTryField',
        'editOrderField',
        'editBodyField',
        'editYouTryField'
      ].includes(textarea.id);

      const editor = document.createElement('div');
      editor.className = 'rich-editor';
      editor.contentEditable = 'true';
      editor.dataset.bind = textarea.id;
      editor.dataset.storage = htmlStorageFieldIds.has(textarea.id) ? 'html' : 'text';
      editor.innerHTML = editor.dataset.storage === 'html' ? richSourceToHtml(textarea.value) : richSourceToHtml(textarea.value);
      textarea.style.display = 'none';
      textarea.insertAdjacentElement('afterend', wrapper);
      wrapper.appendChild(editor);
      if (withToolbar) wrapper.insertBefore(createToolbar(editor), editor);

      const syncToTextarea = () => {
        textarea.value = editor.dataset.storage === 'html'
          ? htmlToStorageHtml(editor.innerHTML, useSoftBreaks)
          : htmlToMd(editor.innerHTML, useSoftBreaks);
      };
      editor.addEventListener('input', syncToTextarea);
      editor.addEventListener('focus', () => { activeEditor = editor; });
      editor.addEventListener('blur', syncToTextarea);
      editor.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && !e.altKey) {
          const key = String(e.key || '').toLowerCase();
          if (key === 'b' || key === 'i') {
            e.preventDefault();
            editor.focus();
            document.execCommand(key === 'b' ? 'bold' : 'italic');
            syncToTextarea();
            return;
          }
        }
        if (!useSoftBreaks || e.key !== 'Enter') return;
        e.preventDefault();
        if (document.queryCommandSupported && document.queryCommandSupported('insertLineBreak')) {
          document.execCommand('insertLineBreak');
        } else if (document.queryCommandSupported && document.queryCommandSupported('insertHTML')) {
          document.execCommand('insertHTML', false, '<br>');
        }
        syncToTextarea();
      });
      return editor;
    };

    const editors = [];
    richTargets.forEach((ta) => {
      const withToolbar =
        ta === citationOrderField ||
        ta === citationBodyField ||
        ta === citationYouTryField ||
        ta === editOrderField ||
        ta === editBodyField ||
        ta === editYouTryField;
      editors.push(createEditor(ta, withToolbar));
    });

    const syncAllEditors = () => editors.forEach(ed => {
      const taId = ed.dataset.bind;
      const ta = document.getElementById(taId);
      if (!ta) return;
      ta.value = ed.dataset.storage === 'html'
        ? htmlToStorageHtml(ed.innerHTML)
        : htmlToMd(ed.innerHTML);
    });
    const syncEditorFromTextarea = (taId) => {
      const ta = document.getElementById(taId);
      if (!ta) return;
      const ed = editors.find(e => e.dataset.bind === taId);
      if (!ed) return;
      ed.innerHTML = richSourceToHtml(ta.value || '');
    };

    citationModalForm?.addEventListener('submit', () => {
      syncAllEditors();
      if (citationActionAdd?.value === '1' && citationKeyField) {
        const generated = buildCitationKey();
        citationKeyField.value = generated;
      }
    });
    const editForm = document.getElementById('editBody');
    editForm?.addEventListener('submit', () => syncAllEditors());

    const styleCodeMap = {
      harvard: 'Harv',
      apa: 'APA7',
      'apa 7': 'APA7',
      'apa 7th': 'APA7',
      'chicago author-date': 'Ch17',
      'chicago notes & bibliography': 'Ch17',
      'chicago notes and bibliography': 'Ch17',
      'chicago 17': 'Ch17',
      'chicago 18': 'Ch18',
      ieee: 'IEEE',
      mhra: 'MHRA4',
      mhra3: 'MHRA3',
      'mhra 3': 'MHRA3',
      mhra4: 'MHRA4',
      'mhra 4': 'MHRA4',
      mla: 'MLA9',
      mla9: 'MLA9',
      oscola: 'OSCO',
      osco: 'OSCO',
      vancouver: 'Vanc'
    };
    const buildCitationKey = () => {
      const style = (citationStyleField?.value || '').trim();
      const label = (citationLabelField?.value || '').trim();
      if (!style || !label) return '';
      const normStyle = style.toLowerCase();
      let prefix = styleCodeMap[normStyle];
      if (!prefix) {
        prefix = Object.keys(styleCodeMap).find(k => normStyle.includes(k));
        prefix = prefix ? styleCodeMap[prefix] : (style.replace(/[^a-z0-9]/gi,'').toUpperCase().slice(0,4) || 'CITE');
      }
      const cleaned = label.replace(/[^a-z0-9]+/gi, ' ').trim();
      const words = cleaned ? cleaned.split(/\s+/) : ['Entry'];
      const slug = words.map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join('_');
      return `${prefix}:${slug}`;
    };
    const resetCitationForm = () => {
      if (citationTitle) citationTitle.textContent = 'Add citation';
      if (citationActionAdd) citationActionAdd.value = '1';
      if (citationActionUpdate) citationActionUpdate.value = '0';
      if (citationSubmitBtn) citationSubmitBtn.textContent = 'Add citation';
      if (citationIdField) citationIdField.value = '';
      if (citationStyleField) citationStyleField.value = citationStyleField.options?.[0]?.value || '';
      if (citationCategoryField) citationCategoryField.value = citationCategoryField.options?.[0]?.value || '';
      if (citationSubCategoryField) citationSubCategoryField.value = '';
      if (citationLabelField) citationLabelField.value = '';
      if (citationOrderField) citationOrderField.value = '';
      if (citationHeadingField) citationHeadingField.value = '';
      if (citationBodyField) citationBodyField.value = '';
      if (citationYouTryField) citationYouTryField.value = '';
      if (citationNotesField) citationNotesField.value = '';
      if (citationKeyField) citationKeyField.value = '';
    };
    const showCitationModal = () => {
      if (!citationBackdrop) return;
      citationBackdrop.style.display = 'flex';
    };
    const hideCitationModal = () => {
      if (!citationBackdrop) return;
      citationBackdrop.style.display = 'none';
    };
    openCitationModal?.addEventListener('click', () => { resetCitationForm(); showCitationModal(); });
    closeCitationModal?.addEventListener('click', hideCitationModal);
    cancelCitationModal?.addEventListener('click', hideCitationModal);
    citationBackdrop?.addEventListener('click', (e) => { if (e.target === citationBackdrop) hideCitationModal(); });
    // Export bundle modal
    const exportBundleBackdrop = document.getElementById('exportBundleBackdrop');
    const openExportBundleModal = document.getElementById('openExportBundleModal');
    const closeExportBundleModal = document.getElementById('closeExportBundleModal');
    const closeExportBundleBtn = document.getElementById('closeExportBundleBtn');
    const showExportBundle = () => {
      if (!exportBundleBackdrop) return;
      exportBundleBackdrop.style.display = 'flex';
    };
    const hideExportBundle = () => {
      if (!exportBundleBackdrop) return;
      exportBundleBackdrop.style.display = 'none';
    };
    openExportBundleModal?.addEventListener('click', showExportBundle);
    closeExportBundleModal?.addEventListener('click', hideExportBundle);
    closeExportBundleBtn?.addEventListener('click', hideExportBundle);
    exportBundleBackdrop?.addEventListener('click', (e) => { if (e.target === exportBundleBackdrop) hideExportBundle(); });
    document.querySelectorAll('[data-view-bundle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const revId = btn.getAttribute('data-revision-id') || '';
        showExportBundle();
        if (!revId) return;
        const item = document.getElementById('bundle-item-' + revId);
        if (item) {
          item.scrollIntoView({ behavior: 'smooth', block: 'center' });
          const diff = document.getElementById('bundle-diff-' + revId);
          const toggle = document.querySelector('[data-bundle-toggle="' + revId + '"]');
          if (diff) diff.style.display = 'block';
          if (toggle) {
            toggle.setAttribute('aria-expanded', 'true');
            toggle.textContent = 'Hide changes';
          }
          item.style.outline = '2px solid rgba(59,130,246,0.45)';
          setTimeout(() => { item.style.outline = ''; }, 1200);
        }
      });
    });
    document.querySelectorAll('[data-bundle-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const revisionId = btn.getAttribute('data-bundle-toggle') || '';
        if (!revisionId) return;
        const diff = document.getElementById('bundle-diff-' + revisionId);
        if (!diff) return;
        const isOpen = diff.style.display !== 'none';
        diff.style.display = isOpen ? 'none' : 'block';
        btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        btn.textContent = isOpen ? 'View changes' : 'Hide changes';
      });
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { hideCitationModal(); hideExportBundle(); } });
    document.querySelectorAll('[data-edit-citation]').forEach(btn => {
      btn.addEventListener('click', () => {
        resetCitationForm();
        if (citationTitle) citationTitle.textContent = 'Edit citation';
        if (citationActionAdd) citationActionAdd.value = '0';
        if (citationActionUpdate) citationActionUpdate.value = '1';
        if (citationSubmitBtn) citationSubmitBtn.textContent = 'Save changes';
        const get = (attr) => btn.getAttribute(attr) || '';
        if (citationIdField) citationIdField.value = get('data-id');
        if (citationStyleField) citationStyleField.value = get('data-style');
        if (citationCategoryField) citationCategoryField.value = get('data-category') || (citationCategoryField.options?.[0]?.value || '');
        if (citationSubCategoryField) citationSubCategoryField.value = get('data-sub-category');
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

    citationModalForm?.addEventListener('submit', () => {
      if (citationActionAdd?.value === '1' && citationKeyField) {
        const generated = buildCitationKey();
        citationKeyField.value = generated;
      }
    });

    // Citation view/edit drawer
    (function(){
      const viewer = document.getElementById('citationViewer');
      if (!viewer) return;
      const viewerClose = document.getElementById('closeCitationViewer');
      const viewerEdit = document.getElementById('viewerEdit');
      const viewerRevisions = document.getElementById('viewerRevisions');
      const viewBody = document.getElementById('viewBody');
      const revisionsBody = document.getElementById('revisionsBody');
      const editBody = document.getElementById('editBody');
      const revisionsFooter = document.getElementById('revisionsFooter');
      const revisionsBackBtn = document.getElementById('revisionsBackBtn');
      const editCancelBtn = document.getElementById('editCancel');
      const editSaveBtn = document.getElementById('editSaveBtn');
      const citationRevisionsList = document.getElementById('citationRevisionsList');
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
      let currentCitation = null;
      const activeScrollBody = () => {
        if (viewerMode === 'edit') return editBody;
        if (viewerMode === 'revisions') return revisionsBody;
        return viewBody;
      };
      const keepDrawerScrollPinned = (drawer, getBody) => {
        if (!drawer || drawer.dataset.scrollPinned === '1') return;
        drawer.dataset.scrollPinned = '1';
        let lastTouchY = 0;
        const maxScroll = (el) => Math.max(0, el.scrollHeight - el.clientHeight);
        const atTop = (el) => el.scrollTop <= 0;
        const atBottom = (el) => el.scrollTop >= maxScroll(el) - 1;
        const scrollInside = (event, deltaY) => {
          if (!drawer.classList.contains('active')) return;
          const body = getBody();
          if (!body) return;
          const max = maxScroll(body);
          if (max <= 0) {
            event.preventDefault();
            return;
          }
          const next = Math.max(0, Math.min(max, body.scrollTop + deltaY));
          body.scrollTop = next;
          event.preventDefault();
        };

        drawer.addEventListener('wheel', (event) => {
          scrollInside(event, event.deltaY);
        }, { passive: false });

        drawer.addEventListener('touchstart', (event) => {
          if (event.touches.length !== 1) return;
          lastTouchY = event.touches[0].clientY;
        }, { passive: true });

        drawer.addEventListener('touchmove', (event) => {
          if (event.touches.length !== 1) return;
          const y = event.touches[0].clientY;
          const deltaY = lastTouchY - y;
          lastTouchY = y;
          if (Math.abs(deltaY) < 1) return;
          scrollInside(event, deltaY);
        }, { passive: false });
      };
      const revSeedEl = document.getElementById('revisionViewerSeed');
      const liveSeedEl = document.getElementById('liveCitationSeed');
      let revisionSeed = [];
      let liveCitationSeed = {};
      try { revisionSeed = JSON.parse(revSeedEl?.textContent || '[]'); } catch(e) { revisionSeed = []; }
      try { liveCitationSeed = JSON.parse(liveSeedEl?.textContent || '{}'); } catch(e) { liveCitationSeed = {}; }
      const setMode = (mode) => {
        viewerMode = mode;
        viewer.classList.toggle('edit-mode', mode === 'edit');
        viewer.classList.toggle('revisions-mode', mode === 'revisions');
        if (viewBody) viewBody.style.display = mode === 'view' ? 'grid' : 'none';
        if (editBody) editBody.style.display = mode === 'edit' ? 'grid' : 'none';
        if (revisionsBody) revisionsBody.style.display = mode === 'revisions' ? 'grid' : 'none';
        if (revisionsFooter) revisionsFooter.style.display = mode === 'revisions' ? 'flex' : 'none';
        if (viewerEdit) viewerEdit.style.display = mode === 'view' ? '' : 'none';
        if (viewerRevisions) viewerRevisions.style.display = mode === 'view' ? '' : 'none';
        if (editCancelBtn) editCancelBtn.style.display = mode === 'edit' ? '' : 'none';
        if (editSaveBtn) editSaveBtn.style.display = mode === 'edit' ? '' : 'none';
        if (mode === 'edit') {
          requestAnimationFrame(autoGrowAll);
        } else if (mode === 'view') {
          editDirty = false;
        } else if (mode === 'revisions') {
        }
      };

      const applyEditFields = (data) => {
        if (!data) return;
        if (editIdField) editIdField.value = data.id || '';
        if (editStyleField) editStyleField.value = data.style || '';
        if (editCategoryField) editCategoryField.value = data.category || (editCategoryField.options?.[0]?.value ?? '');
        if (editSubCategoryField) editSubCategoryField.value = data.subCategory || '';
        const keyInputEdit = document.getElementById('editKeyField');
        const keyVal = data.key || '';
        if (keyInputEdit) keyInputEdit.value = keyVal;
        if (editLabelField) { editLabelField.value = data.label || ''; editLabelField.dataset.autogrow = '1'; }
        if (editOrderField) { editOrderField.value = data.order || ''; }
        if (editHeadingField) { editHeadingField.value = data.heading || ''; editHeadingField.dataset.autogrow = '1'; }
        if (editBodyField) { editBodyField.value = data.body || ''; }
        if (editYouTryField) { editYouTryField.value = data.youtry || ''; }
        if (editNotesField) { editNotesField.value = data.notes || ''; }
        syncEditorFromTextarea('editOrderField');
        syncEditorFromTextarea('editBodyField');
        syncEditorFromTextarea('editYouTryField');
        syncEditorFromTextarea('editNotesField');
      };

      const escapeHtml = (str) => String(str ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
      const formatDateShort = (raw) => {
        if (!raw) return 'Unknown time';
        const d = new Date(String(raw).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return String(raw);
        return d.toLocaleString([], { year:'numeric', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
      };
      const revFieldLabels = [
        ['label', 'Reference type'],
        ['referencing_style', 'Style'],
        ['category', 'Category'],
        ['sub_category', 'Sub-category'],
        ['citation_order', 'Citation order'],
        ['example_heading', 'Example heading'],
        ['example_body', 'Example body'],
        ['you_try', 'You try'],
        ['notes', 'Notes'],
      ];
      const revisionSummary = (rev) => {
        const action = String(rev.action || '').toLowerCase();
        if (action === 'create') return 'Created citation';
        if (action === 'delete') return 'Deleted citation';
        const before = rev.before || {};
        const after = rev.after || {};
        const changed = revFieldLabels.filter(([f]) => String(before[f] ?? '') !== String(after[f] ?? ''));
        if (!changed.length) return 'No field changes';
        if (changed.length <= 2) return 'Updated ' + changed.map(([, l]) => l).join(' and ');
        return `Updated ${changed.length} fields`;
      };
      const renderCitationRevisions = (citation) => {
        if (!citationRevisionsList) return;
        const csrfToken = document.querySelector('input[name="_csrf"]')?.value || '';
        const key = String(citation?.key || '');
        const cid = String(citation?.id || '');
        const label = String(citation?.label || '').trim().toLowerCase();
        const style = String(citation?.style || '').trim().toLowerCase();
        let revs = revisionSeed.filter((r) => {
          const rk = String(r.key || '');
          const rid = String(r.citationId || '');
          return (key && rk === key) || (cid && rid === cid);
        });
        if (!revs.length && (label || style)) {
          revs = revisionSeed.filter((r) => {
            const rLabel = String(r.label || '').trim().toLowerCase();
            const rStyle = String(r.style || '').trim().toLowerCase();
            const labelMatch = label && rLabel === label;
            const styleMatch = style && rStyle === style;
            return labelMatch && (!style || styleMatch);
          });
        }
        revs.sort((a, b) => {
          const ta = Date.parse(String(a.date || '').replace(' ', 'T')) || 0;
          const tb = Date.parse(String(b.date || '').replace(' ', 'T')) || 0;
          return tb - ta;
        });
        const liveKey = key ? ('key:' + key) : '';
        const liveId = cid ? ('id:' + cid) : '';
        const liveCitation = (liveKey && liveCitationSeed[liveKey]) || (liveId && liveCitationSeed[liveId]) || null;
        const currentSnapshot = {
          id: liveCitation?.id ?? citation?.id ?? '',
          example_key: liveCitation?.example_key ?? citation?.key ?? '',
          label: liveCitation?.label ?? citation?.label ?? '',
          referencing_style: liveCitation?.referencing_style ?? citation?.style ?? '',
          category: liveCitation?.category ?? citation?.category ?? '',
          sub_category: liveCitation?.sub_category ?? citation?.subCategory ?? '',
          citation_order: liveCitation?.citation_order ?? citation?.order ?? '',
          example_heading: liveCitation?.example_heading ?? citation?.heading ?? '',
          example_body: liveCitation?.example_body ?? citation?.body ?? '',
          you_try: liveCitation?.you_try ?? citation?.youtry ?? '',
          notes: liveCitation?.notes ?? citation?.notes ?? '',
        };
        const compareBase = revs[0]?.after || revs[0]?.before || {};
        const liveDate = revs[0]?.date || new Date().toISOString();
        const combined = [{
          id: 'current',
          user: 'Current live version',
          date: liveDate,
          action: 'current',
          before: compareBase,
          after: currentSnapshot,
          _isCurrent: true,
        }, ...revs.map((r) => ({ ...r, _isCurrent: false }))];
        citationRevisionsList.innerHTML = combined.map((rev, idx) => {
          const before = rev.before || {};
          const after = rev.after || {};
          const changes = revFieldLabels.filter(([f]) => String(before[f] ?? '') !== String(after[f] ?? ''));
          const changesHtml = changes.length
            ? changes.map(([f, label]) => {
                const b = String(before[f] ?? '');
                const a = String(after[f] ?? '');
                return `<div class="citation-rev-change">
                  <div class="citation-rev-label">${escapeHtml(label)}</div>
                  <div class="citation-rev-before">Before: ${escapeHtml(b || '—')}</div>
                  <div class="citation-rev-after">After: <strong>${escapeHtml(a || '—')}</strong></div>
                </div>`;
              }).join('')
            : '';
          const kebabHtml = rev._isCurrent ? '' : `<div class="citation-rev-kebab" data-rev-kebab>
              <button class="citation-rev-kebab-btn" type="button" aria-label="Revision actions">⋮</button>
              <div class="citation-rev-kebab-menu">
                <form method="post" style="margin:0">
                  <input type="hidden" name="_csrf" value="${escapeHtml(csrfToken)}">
                  <input type="hidden" name="rollback_citation" value="1">
                  <input type="hidden" name="revision_id" value="${escapeHtml(String(rev.id || ''))}">
                  <button class="citation-rev-menu-btn" type="submit" onclick="return confirm('Queue restore to this revision? You will still need to export for it to go live.')">Restore version</button>
                </form>
                <div class="citation-rev-note">Queues this version. Export to apply live.</div>
              </div>
            </div>`;
          const headerTime = formatDateShort(rev.date || '');
          const headerUser = rev._isCurrent ? 'Live state' : (rev.user || 'Unknown editor');
          const summaryText = rev._isCurrent ? 'Current version' : revisionSummary(rev);
          if (rev._isCurrent) {
            return `<div class="citation-rev-item current-live">
              <div class="citation-rev-head" style="padding:10px 12px 6px 12px;">
                <div class="citation-rev-title">${escapeHtml(headerTime)}</div>
                <div class="citation-rev-head-right">
                  <div class="citation-rev-meta">${escapeHtml(headerUser)}</div>
                </div>
              </div>
              <div class="citation-rev-summary" style="padding:0 12px 10px 12px;"><strong>${escapeHtml(summaryText)}</strong></div>
              <div class="citation-rev-body">${changesHtml}</div>
            </div>`;
          }
          return `<details class="citation-rev-item" ${idx === 1 ? 'open' : ''}>
            <summary>
              <div class="citation-rev-head">
                <div class="citation-rev-title">${escapeHtml(headerTime)}</div>
                <div class="citation-rev-head-right">
                  <div class="citation-rev-meta">${escapeHtml(headerUser)}</div>
                  ${rev._isCurrent ? '' : kebabHtml}
                </div>
              </div>
              <div class="citation-rev-summary"><strong>${escapeHtml(summaryText)}</strong></div>
            </summary>
            <div class="citation-rev-body">${changesHtml}</div>
          </details>`;
        }).join('');
        citationRevisionsList.querySelectorAll('[data-rev-kebab]').forEach((menu) => {
          const btn = menu.querySelector('.citation-rev-kebab-btn');
          btn?.addEventListener('click', (ev) => {
            ev.preventDefault();
            ev.stopPropagation();
            citationRevisionsList.querySelectorAll('[data-rev-kebab]').forEach((other) => {
              if (other !== menu) other.classList.remove('open');
            });
            menu.classList.toggle('open');
          });
        });
      };
      const openCitationRevisions = (citation) => {
        if (!citation || (!citation.key && !citation.id)) return false;
        currentCitation = citation;
        renderCitationRevisions(citation);
        setMode('revisions');
        viewer.classList.add('active');
        return true;
      };
      const openCitationRevisionsByRevisionId = (revisionId) => {
        if (!revisionId) return false;
        const rev = revisionSeed.find((r) => String(r.id || '') === String(revisionId));
        if (!rev) return false;
        const snap = rev.after || rev.before || {};
        return openCitationRevisions({
          id: rev.citationId || snap.id || '',
          key: rev.key || snap.example_key || '',
          label: snap.label || rev.label || '',
          style: snap.referencing_style || rev.style || '',
          category: snap.category || '',
          subCategory: snap.sub_category || '',
          order: snap.citation_order || '',
          heading: snap.example_heading || '',
          body: snap.example_body || '',
          youtry: snap.you_try || '',
          notes: snap.notes || '',
        });
      };

      const setView = (data) => {
        currentCitation = data;
        const fill = (id, val) => {
          const el = document.getElementById(id);
          if (!el) return;
          el.textContent = val || '—';
        };
        const fillHtml = (id, val) => {
          const el = document.getElementById(id);
          if (!el) return;
          el.innerHTML = val ? richSourceToHtml(val) : '—';
        };
        const title = data.label && data.style ? `${data.label} — ${data.style}` : (data.label || 'Citation');
        fillHtml('viewLabel', title);
        fill('viewSubtitle', 'Read-only view');
        fill('viewStyle', data.style);
        fill('viewCategory', data.category);
        fill('viewSubCategory', data.subCategory || '—');
        fill('viewId', data.id ? `#${data.id}` : '—');
        fillHtml('viewOrder', data.order);
        fillHtml('viewExampleHeading', data.heading);
        fillHtml('viewExampleBody', data.body);
        fillHtml('viewYouTry', data.youtry);
        fillHtml('viewNotes', data.notes);
        viewerEditId = data.id || null;
        const keyVal = data.key || '';
        const keyInputModal = document.getElementById('citationKeyField');
        if (keyInputModal && !keyInputModal.value) keyInputModal.value = keyVal;
        applyEditFields(data);
        setMode('view');
        viewer.classList.add('active');
      };

      window.NexusCitationViewer = {
        openForData: (data, mode = 'edit') => {
          if (!data) return;
          setView(data);
          if (mode === 'edit') {
            applyEditFields(data);
            setMode('edit');
          }
        },
        openRevisionsForCitation: (citation) => openCitationRevisions(citation),
        openRevisionsForRevisionId: (revisionId) => openCitationRevisionsByRevisionId(revisionId),
        keepDrawerScrollPinned,
      };
      keepDrawerScrollPinned(viewer, activeScrollBody);

      document.querySelectorAll('.citation-row').forEach(row => {
        row.addEventListener('click', (e) => {
          if (e.target.closest('button, form, a, input, select, textarea, label')) return;
          const get = (attr) => row.getAttribute(attr) || '';
          setView({
            id: row.getAttribute('data-id'),
            label: get('data-label'),
            style: get('data-style'),
            category: get('data-category'),
            subCategory: get('data-sub-category'),
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
        if (!viewer.classList.contains('active')) return;
        if (e.target.closest('#exportBundleModal') || e.target.closest('#citationModal')) return;
        const withinViewer = viewer.contains(e.target);
        const isViewRow = (e.target.closest?.('.citation-row') ?? null) !== null;
        if (!withinViewer && !isViewRow) {
          if (viewerMode === 'edit' && editDirty && !confirm('Discard unsaved changes?')) return;
          viewer.classList.remove('active');
          setMode('view');
        }
      });

      viewerEdit?.addEventListener('click', () => {
        if (!viewerEditId) return;
        applyEditFields(currentCitation);
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
        if (viewerMode === 'edit' && editDirty && !confirm('Discard unsaved changes?')) return;
        if (!currentCitation) return;
        renderCitationRevisions(currentCitation);
        setMode('revisions');
      });
      revisionsBackBtn?.addEventListener('click', () => setMode('view'));
      document.addEventListener('click', (e) => {
        if (!citationRevisionsList) return;
        citationRevisionsList.querySelectorAll('[data-rev-kebab].open').forEach((menu) => {
          if (!menu.contains(e.target)) menu.classList.remove('open');
        });
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

      // Entries filtering via the shared search/style/status controls
      const entries = Array.from(document.querySelectorAll('.citation-row'));
      const globalStyle = document.getElementById('globalStyleFilter');
      const statusSelect = document.getElementById('citationStatusFilter');
      const categorySelect = document.getElementById('citationCategoryFilter');
      const subCategorySelect = document.getElementById('citationSubCategoryFilter');
      const sortSelect = document.getElementById('citationSortSelect');
      const viewButtons = Array.from(document.querySelectorAll('[data-citation-view-button]'));
      const viewPanels = Array.from(document.querySelectorAll('[data-citation-view]'));
      const citationViewStorageKey = 'citationDatabaseView';
      const citationNoResults = document.getElementById('citationNoResults');
      const moreFiltersBtn = document.getElementById('citationMoreFilters');
      const advancedFilters = document.getElementById('citationAdvancedFilters');
      const clearFiltersBtn = document.getElementById('citationClearFilters');
      const filterCount = document.getElementById('citationFilterCount');
      const recentFilters = document.getElementById('citationRecentFilters');
      const presetButtons = Array.from(document.querySelectorAll('[data-citation-preset]'));
      const columnsBtn = document.getElementById('citationColumnsBtn');
      const columnsMenu = document.getElementById('citationColumnMenu');
      const columnToggles = Array.from(document.querySelectorAll('[data-column-toggle]'));
      const styleDocCards = Array.from(document.querySelectorAll('[data-style-doc-card]'));
      const dynamicFilterSelect = statusSelect;
      const dynamicFilterLabel = dynamicFilterSelect?.closest?.('.citation-filter-chip')?.querySelector('span') || null;
      const subCategoryChip = subCategorySelect?.closest?.('.citation-filter-chip') || null;
      const columnMenuWrap = columnsBtn?.closest?.('.citation-column-menu-wrap') || null;
      const presetWrap = document.querySelector('.citation-presets');
      let citationView = 'summary';
      let dynamicFilterRole = 'status';
      const viewFilterState = {
        summary: { style: '', category: '', dynamic: '', subCategory: '' },
        data: { style: '', category: '', dynamic: '', subCategory: '' },
        library: { style: '', category: '', dynamic: '', subCategory: '' },
      };

      const activeViewRows = () => entries.filter(row => row.closest('[data-citation-view]')?.dataset.citationView === citationView);
      const setChipState = (select) => {
        const chip = select?.closest?.('.citation-filter-chip');
        if (chip) chip.classList.toggle('active', !!select.value);
      };
      const filterSelects = [globalStyle, statusSelect, categorySelect, subCategorySelect].filter(Boolean);
      const viewConfig = {
        summary: {
          search: 'Search operations by reference type, key, author, or keyword',
          dynamicRole: 'status',
          dynamicLabel: 'Status',
          dynamicAll: 'Any status',
          dynamicOptions: [
            ['clean', 'Clean'],
            ['staged', 'Queued'],
            ['edited', 'Edited'],
          ],
          sortOptions: [
            ['label-asc', 'Reference type A-Z'],
            ['label-desc', 'Reference type Z-A'],
            ['style-asc', 'Style A-Z'],
            ['category-asc', 'Category A-Z'],
            ['sub_category-asc', 'Sub-category A-Z'],
          ],
        },
        data: {
          search: 'Search editorial text, examples, You try guidance, or keywords',
          dynamicRole: 'subCategory',
          dynamicLabel: 'Sub-category',
          dynamicAll: 'All sub-categories',
          sortOptions: [
            ['label-asc', 'Reference type A-Z'],
            ['label-desc', 'Reference type Z-A'],
            ['style-asc', 'Style A-Z'],
            ['category-asc', 'Category A-Z'],
            ['sub_category-asc', 'Sub-category A-Z'],
          ],
        },
        library: {
          search: 'Search style guides, source notes, rules, or guidance',
          dynamicRole: 'docType',
          dynamicLabel: 'Document type',
          dynamicAll: 'All document types',
          sortOptions: [
            ['title-asc', 'Title A-Z'],
            ['title-desc', 'Title Z-A'],
            ['style-asc', 'Style A-Z'],
            ['type-asc', 'Document type A-Z'],
            ['category-asc', 'Category A-Z'],
          ],
        },
      };
      const optionLabel = (select, fallback) => select?.options?.[select.selectedIndex]?.text || fallback || '';
      const sourceForView = (view) => view === 'library' ? styleDocCards : entries;
      const dataValue = (el, key) => {
        if (!el) return '';
        if (key === 'type') return el.dataset.type || '';
        if (key === 'subCategory') return el.dataset.subCategory || '';
        return el.dataset[key] || '';
      };
      const uniqueValues = (items, key) => {
        const values = new Set();
        items.forEach(item => {
          const value = String(dataValue(item, key) || '').trim();
          if (value) values.add(value);
        });
        return Array.from(values).sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }));
      };
      const setOptions = (select, allLabel, values, selected = '') => {
        if (!select) return;
        select.innerHTML = '';
        if (allLabel !== null) select.appendChild(new Option(allLabel, ''));
        values.forEach(item => {
          if (Array.isArray(item)) select.appendChild(new Option(item[1], item[0]));
          else select.appendChild(new Option(item, item));
        });
        select.value = values.some(item => (Array.isArray(item) ? item[0] : item) === selected) ? selected : '';
      };
      const setPreset = (index, label, dataset = {}) => {
        const btn = presetButtons[index];
        if (!btn) return;
        btn.style.display = label ? '' : 'none';
        btn.textContent = label || '';
        ['style', 'category', 'status', 'dynamic', 'subCategory'].forEach(key => delete btn.dataset[key]);
        Object.entries(dataset).forEach(([key, value]) => {
          if (value !== undefined && value !== null && value !== '') btn.dataset[key] = String(value);
        });
      };
      const configurePresetsForView = (source) => {
        if (!presetButtons.length) return;
        if (citationView === 'summary') {
          setPreset(0, 'Harvard', { style: 'Harvard' });
          setPreset(1, 'Books', { category: 'Books' });
          setPreset(2, 'Queued changes', { dynamic: 'staged' });
          return;
        }
        if (citationView === 'data') {
          const subCategory = uniqueValues(source, 'subCategory')[0] || '';
          setPreset(0, 'Harvard', { style: 'Harvard' });
          setPreset(1, 'Books', { category: 'Books' });
          setPreset(2, subCategory || '', { dynamic: subCategory });
          return;
        }
        setPreset(0, 'Style guides', { dynamic: 'Style guide' });
        setPreset(1, 'Source info', { dynamic: 'Source type information' });
        setPreset(2, 'Referencing rules', { dynamic: 'Referencing rules' });
      };
      const saveFilterState = () => {
        const state = viewFilterState[citationView];
        if (!state) return;
        state.style = globalStyle?.value || '';
        state.category = categorySelect?.value || '';
        state.dynamic = dynamicFilterSelect?.value || '';
        state.subCategory = subCategorySelect?.value || '';
      };
      const configureFiltersForView = () => {
        const config = viewConfig[citationView] || viewConfig.summary;
        const state = viewFilterState[citationView] || viewFilterState.summary;
        const source = sourceForView(citationView);
        dynamicFilterRole = config.dynamicRole;
        if (search) search.placeholder = config.search;
        if (dynamicFilterLabel) dynamicFilterLabel.textContent = config.dynamicLabel;

        setOptions(globalStyle, 'All styles', uniqueValues(source, 'style'), state.style);
        setOptions(categorySelect, 'All categories', uniqueValues(source, 'category'), state.category);

        if (config.dynamicOptions) {
          setOptions(dynamicFilterSelect, config.dynamicAll, config.dynamicOptions, state.dynamic);
        } else if (config.dynamicRole === 'subCategory') {
          setOptions(dynamicFilterSelect, config.dynamicAll, uniqueValues(source, 'subCategory'), state.dynamic);
        } else if (config.dynamicRole === 'docType') {
          setOptions(dynamicFilterSelect, config.dynamicAll, uniqueValues(styleDocCards, 'type'), state.dynamic);
        }

        setOptions(subCategorySelect, 'All sub-categories', uniqueValues(source, 'subCategory'), state.subCategory);
        if (subCategoryChip) subCategoryChip.style.display = config.dynamicRole === 'subCategory' ? 'none' : '';
        if (columnMenuWrap) columnMenuWrap.style.display = citationView === 'data' ? '' : 'none';
        if (presetWrap) presetWrap.style.display = '';
        configurePresetsForView(source);
        if (sortSelect) {
          const selectedSort = sortSelect.value;
          setOptions(sortSelect, null, config.sortOptions, selectedSort || config.sortOptions[0]?.[0] || '');
          if (!sortSelect.value && config.sortOptions[0]) sortSelect.value = config.sortOptions[0][0];
        }
      };
      const activeFilterLabels = () => {
        const labels = [];
        if (globalStyle?.value) labels.push(globalStyle.value);
        if (categorySelect?.value) labels.push(categorySelect.value);
        if (dynamicFilterSelect?.value) labels.push(optionLabel(dynamicFilterSelect, dynamicFilterSelect.value));
        if (subCategorySelect?.value && dynamicFilterRole !== 'subCategory') labels.push(subCategorySelect.value);
        return labels;
      };
      const updateFilterMeta = () => {
        const labels = activeFilterLabels();
        if (filterCount) {
          filterCount.textContent = String(labels.length);
          filterCount.classList.toggle('active', labels.length > 0);
        }
        if (recentFilters) {
          recentFilters.textContent = labels.length ? 'Recent filters: ' + labels.slice(0, 3).join(', ') : 'Recent filters: none';
        }
        filterSelects.forEach(setChipState);
      };
      const setCitationView = (view) => {
        saveFilterState();
        citationView = view || 'summary';
        try { localStorage.setItem(citationViewStorageKey, citationView); } catch (e) {}
        viewButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.citationViewButton === citationView));
        viewPanels.forEach(panel => {
          panel.style.display = panel.dataset.citationView === citationView ? '' : 'none';
        });
        configureFiltersForView();
        sortEntries();
        entryFilter();
      };
      const compareText = (a, b, dir) => {
        const result = String(a || '').localeCompare(String(b || ''), undefined, { sensitivity: 'base', numeric: true });
        return dir === 'desc' ? -result : result;
      };
      const sortEntries = () => {
        const raw = sortSelect?.value || 'label-asc';
        const lastDash = raw.lastIndexOf('-');
        const key = lastDash > 0 ? raw.slice(0, lastDash) : raw;
        const dir = lastDash > 0 ? raw.slice(lastDash + 1) : 'asc';
        if (citationView === 'library') {
          const grid = document.querySelector('#styleLibraryList .style-doc-grid');
          if (!grid) return;
          const cards = Array.from(grid.querySelectorAll('[data-style-doc-card]'));
          cards.sort((a, b) => {
            if (key === 'style') return compareText(a.dataset.style, b.dataset.style, dir) || compareText(a.dataset.title, b.dataset.title, 'asc');
            if (key === 'type') return compareText(a.dataset.type, b.dataset.type, dir) || compareText(a.dataset.title, b.dataset.title, 'asc');
            if (key === 'category') return compareText(a.dataset.category, b.dataset.category, dir) || compareText(a.dataset.title, b.dataset.title, 'asc');
            return compareText(a.dataset.title, b.dataset.title, dir);
          });
          cards.forEach(card => grid.appendChild(card));
          return;
        }
        viewPanels.forEach(panel => {
          const tbody = panel.querySelector('tbody');
          if (!tbody) return;
          const rows = Array.from(tbody.querySelectorAll('.citation-row'));
          rows.sort((a, b) => {
            if (key === 'style') return compareText(a.dataset.style, b.dataset.style, dir) || compareText(a.dataset.label, b.dataset.label, 'asc');
            if (key === 'category') return compareText(a.dataset.category, b.dataset.category, dir) || compareText(a.dataset.label, b.dataset.label, 'asc');
            if (key === 'sub_category') return compareText(a.dataset.subCategory, b.dataset.subCategory, dir) || compareText(a.dataset.label, b.dataset.label, 'asc');
            return compareText(a.dataset.label, b.dataset.label, dir);
          });
          rows.forEach(row => tbody.appendChild(row));
        });
      };
      const setColumnMenuOpen = (open) => {
        columnsMenu?.classList.toggle('open', open);
        columnsBtn?.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      const applyColumnVisibility = () => {
        columnToggles.forEach(toggle => {
          const column = toggle.dataset.columnToggle || '';
          document.querySelectorAll(`#citationDataList [data-column="${column}"]`).forEach(cell => {
            cell.hidden = !toggle.checked;
          });
        });
      };
      const entryFilter = () => {
        if (citationView === 'library') {
          const q = (search?.value || '').toLowerCase();
          const styleSel = globalStyle?.value || '';
          const typeSel = dynamicFilterRole === 'docType' ? (dynamicFilterSelect?.value || '') : '';
          const categorySel = categorySelect?.value || '';
          const subCategorySel = subCategorySelect?.value || '';
          let visible = 0;
          styleDocCards.forEach(card => {
            const title = (card.dataset.title || '').toLowerCase();
            const body = (card.dataset.body || '').toLowerCase();
            const style = card.dataset.style || '';
            const type = card.dataset.type || '';
            const category = card.dataset.category || '';
            const subCategory = card.dataset.subCategory || '';
            const matchesText = !q || title.includes(q) || body.includes(q) || style.toLowerCase().includes(q) || type.toLowerCase().includes(q) || category.toLowerCase().includes(q);
            const matchesStyle = !styleSel || styleSel === style;
            const matchesType = !typeSel || typeSel === type;
            const matchesCategory = !categorySel || categorySel === category;
            const matchesSubCategory = !subCategorySel || subCategorySel === subCategory;
            const match = matchesText && matchesStyle && matchesType && matchesCategory && matchesSubCategory;
            card.style.display = match ? '' : 'none';
            if (match) visible++;
          });
          if (citationNoResults) {
            citationNoResults.textContent = 'No style guide documents match the selected filters.';
            citationNoResults.style.display = visible || !styleDocCards.length ? 'none' : '';
          }
          updateFilterMeta();
          return;
        }
        const q = (search?.value || '').toLowerCase();
        const styleSel = globalStyle?.value || '';
        const dynamicSel = dynamicFilterSelect?.value || '';
        const categorySel = categorySelect?.value || '';
        const subCategorySel = dynamicFilterRole === 'subCategory' ? dynamicSel : (subCategorySelect?.value || '');
        let activeVisible = 0;
        entries.forEach(row => {
          const label = (row.dataset.label || '').toLowerCase();
          const key = (row.dataset.key || '').toLowerCase();
          const heading = (row.dataset.heading || '').toLowerCase();
          const body = (row.dataset.body || '').toLowerCase();
          const youtry = (row.dataset.youtry || '').toLowerCase();
          const style = row.dataset.style || '';
          const status = row.dataset.status || '';
          const category = row.dataset.category || '';
          const subCategory = row.dataset.subCategory || '';
          const matchesText = !q || label.includes(q) || key.includes(q) || heading.includes(q) || body.includes(q) || youtry.includes(q);
          const matchesStyle = !styleSel || styleSel === style;
          const matchesStatus = dynamicFilterRole !== 'status' || !dynamicSel || dynamicSel === status;
          const matchesCategory = !categorySel || categorySel === category;
          const matchesSubCategory = !subCategorySel || subCategorySel === subCategory;
          const match = matchesText && matchesStyle && matchesStatus && matchesCategory && matchesSubCategory;
          row.style.display = match ? '' : 'none';
          if (match && activeViewRows().includes(row)) activeVisible++;
        });
        if (citationNoResults) {
          citationNoResults.textContent = citationView === 'data'
            ? 'No editorial rows match the selected filters.'
            : 'No citations match the selected filters.';
          citationNoResults.style.display = activeVisible ? 'none' : '';
        }
        updateFilterMeta();
      };
      if (entries.length) {
        search?.addEventListener('input', entryFilter);
        globalStyle?.addEventListener('change', entryFilter);
        statusSelect?.addEventListener('change', entryFilter);
        categorySelect?.addEventListener('change', entryFilter);
        subCategorySelect?.addEventListener('change', entryFilter);
        sortSelect?.addEventListener('change', () => {
          sortEntries();
          entryFilter();
        });
        viewButtons.forEach(btn => btn.addEventListener('click', () => setCitationView(btn.dataset.citationViewButton)));
        moreFiltersBtn?.addEventListener('click', () => {
          const open = !advancedFilters?.classList.contains('open');
          advancedFilters?.classList.toggle('open', open);
          moreFiltersBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        columnsBtn?.addEventListener('click', (event) => {
          event.stopPropagation();
          setColumnMenuOpen(!columnsMenu?.classList.contains('open'));
        });
        columnToggles.forEach(toggle => toggle.addEventListener('change', applyColumnVisibility));
        document.addEventListener('click', (event) => {
          if (!columnsMenu?.classList.contains('open')) return;
          if (event.target.closest('#citationColumnMenu') || event.target.closest('#citationColumnsBtn')) return;
          setColumnMenuOpen(false);
        });
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') setColumnMenuOpen(false);
        });
        clearFiltersBtn?.addEventListener('click', () => {
          if (search) search.value = '';
          filterSelects.forEach(select => { select.value = ''; });
          if (sortSelect) sortSelect.value = 'label-asc';
          sortEntries();
          entryFilter();
          search?.focus();
        });
        presetButtons.forEach(btn => {
          btn.addEventListener('click', () => {
            filterSelects.forEach(select => { select.value = ''; });
            if (btn.dataset.style && globalStyle) globalStyle.value = btn.dataset.style;
            if (btn.dataset.category && categorySelect) categorySelect.value = btn.dataset.category;
            if (btn.dataset.status && statusSelect) statusSelect.value = btn.dataset.status;
            if (btn.dataset.dynamic && dynamicFilterSelect) dynamicFilterSelect.value = btn.dataset.dynamic;
            if (btn.dataset.subCategory && subCategorySelect) subCategorySelect.value = btn.dataset.subCategory;
            entryFilter();
          });
        });
        sortEntries();
        applyColumnVisibility();
        const storedCitationView = (() => {
          try { return localStorage.getItem(citationViewStorageKey); } catch (e) { return null; }
        })();
        const initialCitationView = viewButtons.some(btn => btn.dataset.citationViewButton === storedCitationView) ? storedCitationView : 'summary';
        setCitationView(initialCitationView);
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
      const timelineSelect = document.getElementById('revTimelineSelect');
      const selectionMeta = document.getElementById('revSelectionMeta');
      const compareToggle = document.getElementById('revCompareToggle');
      const diffRowsWrap = document.getElementById('revDiffRows');
      const diffTitle = document.getElementById('revDiffTitle');
      const diffHint = document.getElementById('revDiffHint');
      const revLabel = document.getElementById('revViewLabel');
      const revSub = document.getElementById('revViewSubtitle');
      const citationLabel = document.getElementById('revCitationLabel');
      const citationKey = document.getElementById('revCitationKey');
      const citationStyle = document.getElementById('revCitationStyle');
      let compareMode = 'previous'; // previous | current
      let activeTimeline = [];
      let activeIndex = 0;

      const escapeHtml = (str) => String(str ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
      const truncateKey = (str) => (!str ? '—' : (str.length > 30 ? str.slice(0,30) + '…' : str));
      const parseJsonSafe = (str) => { try { return JSON.parse(str || 'null'); } catch(e) { return null; } };
      const prettyDate = (raw) => {
        if (!raw) return 'Unknown time';
        const d = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return raw;
        return d.toLocaleString([], { year:'numeric', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
      };
      const fieldMap = [
        ['label', 'Reference type'],
        ['referencing_style', 'Style'],
        ['category', 'Category'],
        ['sub_category', 'Sub-category'],
        ['citation_order', 'Citation order'],
        ['example_heading', 'Example heading'],
        ['example_body', 'Example body'],
        ['you_try', 'You try'],
        ['notes', 'Notes'],
      ];
      const summarizeChange = (before, after, action) => {
        if (action === 'create') return 'Created citation entry';
        if (action === 'delete') return 'Deleted citation entry';
        const changed = [];
        fieldMap.forEach(([field, label]) => {
          if ((before?.[field] ?? '') !== (after?.[field] ?? '')) changed.push(label);
        });
        if (!changed.length) return 'No visible field changes';
        if (changed.length <= 2) return 'Updated ' + changed.join(' and ');
        return `Updated ${changed.length} fields`;
      };
      const inlineDiffHtml = (beforeVal, afterVal, side) => {
        const a = String(beforeVal ?? '');
        const b = String(afterVal ?? '');
        if (a === b) return `<span class="rev-inline-same">${escapeHtml(a || '—')}</span>`;
        let start = 0;
        const min = Math.min(a.length, b.length);
        while (start < min && a[start] === b[start]) start++;
        let endA = a.length - 1;
        let endB = b.length - 1;
        while (endA >= start && endB >= start && a[endA] === b[endB]) { endA--; endB--; }
        const src = side === 'before' ? a : b;
        const midStart = start;
        const midEnd = side === 'before' ? endA : endB;
        const prefix = escapeHtml(src.slice(0, midStart));
        const middle = escapeHtml(src.slice(midStart, midEnd + 1));
        const suffix = escapeHtml(src.slice(midEnd + 1));
        if (!middle) return `<span class="rev-inline-same">${escapeHtml(src || '—')}</span>`;
        const markClass = side === 'before' ? 'rev-before-removed' : 'rev-after-added';
        return `${prefix}<span class="${markClass}">${middle}</span>${suffix}`;
      };
      const renderDiffRows = (selected, baseline) => {
        if (!diffRowsWrap) return;
        const selectedAfter = selected?.after || {};
        const selectedBefore = selected?.before || {};
        const compareFrom = baseline || selectedBefore || {};
        const compareTo = selectedAfter || {};
        const action = selected?.action || '';
        const rowsHtml = [];
        fieldMap.forEach(([field, label]) => {
          const beforeValRaw = compareFrom?.[field] ?? '';
          const afterValRaw = compareTo?.[field] ?? '';
          const beforeVal = String(beforeValRaw ?? '');
          const afterVal = String(afterValRaw ?? '');
          if (beforeVal === afterVal) return;
          rowsHtml.push(
            `<div class="rev-diff-row">
              <div class="rev-diff-row-head">${escapeHtml(label)}</div>
              <div class="rev-diff-cols">
                <div class="rev-diff-col">
                  <div class="rev-diff-col-label">Before</div>
                  <div class="rev-diff-col-body">${inlineDiffHtml(beforeVal, afterVal, 'before') || '—'}</div>
                </div>
                <div class="rev-diff-col">
                  <div class="rev-diff-col-label">After</div>
                  <div class="rev-diff-col-body">${action === 'delete' ? '<span class="rev-before-removed">[Removed]</span>' : inlineDiffHtml(beforeVal, afterVal, 'after')}</div>
                </div>
              </div>
            </div>`
          );
        });
        diffRowsWrap.innerHTML = rowsHtml.length
          ? rowsHtml.join('')
          : '<div class="rev-empty-state">No field-level differences for this comparison.</div>';
      };
      const renderRevisionSelect = () => {
        if (!timelineSelect) return;
        timelineSelect.innerHTML = activeTimeline.map((rev, idx) => {
          const summary = summarizeChange(rev.before || {}, rev.after || {}, rev.action);
          const time = prettyDate(rev.date || '');
          const current = idx === 0 ? ' (Current)' : '';
          const label = `${time} — ${summary}${current}`;
          return `<option value="${idx}">${escapeHtml(label)}</option>`;
        }).join('');
        timelineSelect.value = String(activeIndex);
      };
      const renderSelectedRevision = () => {
        const selected = activeTimeline[activeIndex];
        if (!selected) return;
        const current = activeTimeline[0] || selected;
        const baseline = compareMode === 'current'
          ? (current.after || current.before || {})
          : (activeTimeline[activeIndex + 1]?.after || selected.before || {});
        const compareLabel = compareMode === 'current' ? 'current version' : 'previous revision';
        if (revLabel) revLabel.textContent = selected.label || 'Revision timeline';
        if (revSub) revSub.textContent = `${selected.user || 'Unknown editor'} • ${prettyDate(selected.date || '')}`;
        if (citationLabel) citationLabel.textContent = selected.label || '—';
        if (citationKey) citationKey.textContent = truncateKey(selected.key || '');
        if (citationStyle) citationStyle.textContent = selected.style || '—';
        if (badge) badge.textContent = 'History';
        if (actionPill) actionPill.textContent = summarizeChange(selected.before || {}, selected.after || {}, selected.action);
        if (releasePill) releasePill.textContent = `Comparing with ${compareLabel}`;
        if (selectionMeta) selectionMeta.textContent = `${selected.user || 'Unknown editor'} • ${prettyDate(selected.date || '')}`;
        if (diffTitle) diffTitle.textContent = 'Before vs After';
        if (diffHint) diffHint.textContent = `Showing changed fields only (${compareLabel}).`;
        if (restoreId) restoreId.value = selected.id || '';
        renderRevisionSelect();
        renderDiffRows(selected, baseline);
      };
      const seedEl = document.getElementById('revisionViewerSeed');
      let seedRows = [];
      try { seedRows = JSON.parse(seedEl?.textContent || '[]'); } catch(e) { seedRows = []; }
      const allRevisions = rows.length
        ? rows.map(r => {
            const after = parseJsonSafe(r.dataset.after || 'null');
            const before = parseJsonSafe(r.dataset.before || 'null');
            return {
              id: r.dataset.id || '',
              key: r.dataset.key || '',
              citationId: r.dataset.citationId || '',
              label: r.dataset.label || (after?.label || before?.label || 'Citation revision'),
              style: r.dataset.style || (after?.referencing_style || before?.referencing_style || ''),
              action: (r.dataset.action || '').toLowerCase(),
              user: r.dataset.user || '',
              date: r.dataset.date || '',
              release: r.dataset.release || '',
              before: before || {},
              after: after || {},
            };
          })
        : seedRows;
      const buildTimelineByKey = (key) => allRevisions.filter(r => (r.key || '') === (key || ''));
      const openRevisionFromRecord = (record) => {
        if (!record) return;
        activeTimeline = buildTimelineByKey(record.key);
        if (!activeTimeline.length) activeTimeline = [record];
        activeIndex = 0;
        const idx = activeTimeline.findIndex(r => String(r.id) === String(record.id));
        if (idx >= 0) activeIndex = idx;
        compareMode = 'previous';
        if (compareToggle) compareToggle.textContent = 'Compare with current';
        renderSelectedRevision();
        viewer.classList.add('active');
      };
      if (window.NexusCitationViewer?.keepDrawerScrollPinned) {
        window.NexusCitationViewer.keepDrawerScrollPinned(viewer, () => document.getElementById('revViewBody'));
      }
      const openRevisionByKey = (key) => {
        if (!key) return false;
        const rec = allRevisions.find(r => (r.key || '') === key);
        if (!rec) return false;
        openRevisionFromRecord(rec);
        return true;
      };
      const openRevisionByCitationId = (citationId) => {
        if (!citationId) return false;
        const rec = allRevisions.find(r => String(r.citationId || '') === String(citationId));
        if (!rec) return false;
        openRevisionFromRecord(rec);
        return true;
      };
      const openRevisionById = (revisionId) => {
        if (!revisionId) return false;
        const rec = allRevisions.find(r => String(r.id || '') === String(revisionId));
        if (!rec) return false;
        openRevisionFromRecord(rec);
        return true;
      };
      window.NexusCitationRevisions = {
        openForRevisionId: (revisionId) => openRevisionById(revisionId),
        openForCitation: ({ key = '', citationId = '' } = {}) => {
          if (openRevisionByKey(key)) return true;
          return openRevisionByCitationId(citationId);
        }
      };

      const closeRevision = () => {
        viewer.classList.remove('active');
      };
      closeBtn?.addEventListener('click', closeRevision);
      revCloseBtn?.addEventListener('click', closeRevision);
      document.addEventListener('click', (e) => {
        if (!viewer.classList.contains('active')) return;
        const isRow = e.target.closest?.('[data-revision-row]');
        const insideViewer = viewer.contains(e.target);
        const fromCitationViewer = e.target.closest?.('#citationViewer');
        if (!insideViewer && !isRow && !fromCitationViewer) closeRevision();
      });

      rows.forEach(r => {
        r.addEventListener('click', (e) => {
          if (e.target.closest('button') || e.target.closest('form')) return;
          openRevisionById(r.dataset.id || '');
        });
      });
      compareToggle?.addEventListener('click', () => {
        compareMode = compareMode === 'previous' ? 'current' : 'previous';
        compareToggle.textContent = compareMode === 'previous' ? 'Compare with current' : 'Compare with previous';
        renderSelectedRevision();
      });
      timelineSelect?.addEventListener('change', () => {
        const idx = parseInt(timelineSelect.value || '0', 10);
        if (Number.isNaN(idx)) return;
        activeIndex = Math.max(0, Math.min(idx, activeTimeline.length - 1));
        renderSelectedRevision();
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
            <label>Source Type</label>
            <input id="modalSlugInput" placeholder="blogs">
          </div>
        </div>
        <div class="page-path-builder" style="margin-bottom:12px">
          <div class="row page-path-row page-path-fixed" id="modalPathOptions" style="display:none;">
            <div>
              <label>Style</label>
              <select id="modalPathStyle">
                <option value="">Select style</option>
                <?php foreach ($styleOptions as $styleOption): ?>
                  <option value="<?= Security::e($styleOption) ?>"><?= Security::e($styleOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Topic</label>
              <select id="modalPathTopic">
                <option value="">Select topic</option>
                <?php foreach ($topicOptions as $topicOption): ?>
                  <option value="<?= Security::e($topicOption) ?>"><?= Security::e($topicOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="path-preview-wrap">
            <div class="muted">File Path</div>
            <div class="path-preview" id="modalFullPathPreview"><?= Security::e(strtolower((string)($site['slug'] ?? 'site'))) ?>/</div>
          </div>
        </div>
        <div class="muted" style="margin-bottom:6px">Choose a layout</div>
        <div class="grid layout-grid" id="modalLayoutGrid">
          <?php
            $renderLayoutThumb = static function(string $id, string $label = ''): string {
              $safeId = Security::e($id);
              $safeLabel = Security::e($label);
              return '<div class="layout-thumb layout-thumb--' . $safeId . '">' .
                '<div class="thumb-blueprint thumb-blueprint--' . $safeId . '" aria-hidden="true"></div>' .
                ($safeLabel !== '' ? '<div class="thumb-title">' . $safeLabel . '</div>' : '') .
              '</div>';
            };
          ?>
          <button class="layout-card blank" type="button" data-layout="blank">
            <div class="checkmark">✓</div>
            <?= $renderLayoutThumb('blank', 'B') ?>
            <div class="layout-body">
              <div class="layout-title">Blank page</div>
              <div class="muted">Start from scratch with an empty row.</div>
              <div class="chip">Build manually</div>
            </div>
          </button>
          <?php
            $modalLayouts = [
              ['id' => 'title-page', 'name' => 'Title page', 'desc' => 'Cite Them Right home-inspired default with placeholder-only blocks.'],
              ['id' => 'referencing-browse', 'name' => 'Referencing Browse', 'desc' => 'Browse-by-category scaffold matching the Cite Them Right reference browse layout.'],
              ['id' => 'source-type', 'name' => 'Source Type', 'desc' => 'Two-column source page with stacked link lists and citation content blocks.'],
            ];
          ?>
          <?php foreach ($modalLayouts as $layout): ?>
            <button class="layout-card" type="button" data-layout="<?= Security::e($layout['id']) ?>">
              <div class="checkmark">✓</div>
              <?= $renderLayoutThumb($layout['id'], strtoupper(substr((string)$layout['name'], 0, 1))) ?>
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
    const modalStyleInput = document.getElementById('modalPathStyle');
    const modalTopicInput = document.getElementById('modalPathTopic');
    const modalPathPreview = document.getElementById('modalFullPathPreview');
    const modalPathOptions = document.getElementById('modalPathOptions');
    const modalTitleInput = document.getElementById('modalTitleInput');
    const modalSlugInput = document.getElementById('modalSlugInput');
    const modalLayoutCards = modalBackdrop.querySelectorAll('.layout-card');
    const resetModalState = () => {
      selectedLayout = 'blank';
      modalLayoutCards.forEach(card => card.classList.toggle('active', (card.dataset.layout || 'blank') === 'blank'));
      if (modalStyleInput) modalStyleInput.value = '';
      if (modalTopicInput) modalTopicInput.value = '';
      if (modalTitleInput) modalTitleInput.value = '';
      if (modalSlugInput) modalSlugInput.value = '';
      toggleModalPathOptions();
      updatePathPreview();
    };
    const openModal = () => {
      resetModalState();
      modalBackdrop.style.display = 'flex';
      modalTitleInput?.focus();
    };
    document.getElementById('addPageBtnTop')?.addEventListener('click', openModal);
    const closeModal = () => { modalBackdrop.style.display = 'none'; };
    openModalBtns.forEach(btn => btn.addEventListener('click', openModal));
    modalBackdrop.querySelector('.close-btn')?.addEventListener('click', closeModal);
    modalBackdrop.querySelector('#modalCancelBtn')?.addEventListener('click', closeModal);
    modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeModal(); });

    function slugify(val){return (val||'').toLowerCase().replace(/[^a-z0-9-]+/g,'-').replace(/-+/g,'-').replace(/^-+|-+$/g,'');}
    function toggleModalPathOptions() {
      const show = selectedLayout === 'source-type';
      if (modalPathOptions) modalPathOptions.style.display = show ? '' : 'none';
    }
    function updatePathPreview(){
      const parts = [slugify(modalStyleInput?.value || ''), slugify(modalTopicInput?.value || '')].filter(Boolean);
      const leaf = slugify(document.getElementById('modalSlugInput')?.value || '');
      const fullPath = '<?= Security::e(strtolower((string)($site['slug'] ?? 'site'))) ?>/' + [...parts, leaf].filter(Boolean).join('/');
      if (modalPathPreview) modalPathPreview.textContent = fullPath;
    }
    modalTitleInput?.addEventListener('blur', e => {
      const slug = modalSlugInput;
      if (slug && !slug.value.trim()) {
        slug.value = slugify(e.target.value);
        updatePathPreview();
      }
    });
    modalStyleInput?.addEventListener('change', updatePathPreview);
    modalTopicInput?.addEventListener('change', updatePathPreview);
    modalSlugInput?.addEventListener('input', updatePathPreview);
    updatePathPreview();

    let selectedLayout = 'blank';
    modalLayoutCards.forEach(card => {
      card.addEventListener('click', () => {
        modalLayoutCards.forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        selectedLayout = card.dataset.layout || 'blank';
        toggleModalPathOptions();
      });
    });
    // default selection on blank
    const firstCard = modalBackdrop.querySelector('.layout-card');
    if (firstCard) firstCard.classList.add('active');
    toggleModalPathOptions();

    modalBackdrop.querySelector('#modalCreateBtn')?.addEventListener('click', () => {
      const title = (modalTitleInput?.value || '').trim();
      const slug = slugify(modalSlugInput?.value || '');
      const style = modalStyleInput?.value || '';
      const topic = slugify(modalTopicInput?.value || '');
      const needsSourceTypePath = selectedLayout === 'source-type';
      if (!title || !slug || (needsSourceTypePath && (!style || !topic))) {
        alert(needsSourceTypePath ? 'Enter a title, style, topic, and source type' : 'Enter a title and source type');
        return;
      }
      document.getElementById('modal_title_field').value = title;
      document.getElementById('modal_slug_field').value = slug;
      document.getElementById('modal_layout_field').value = selectedLayout;
      document.getElementById('modal_path_style_field').value = needsSourceTypePath ? style : '';
      document.getElementById('modal_path_topic_field').value = needsSourceTypePath ? topic : '';
      document.getElementById('modalCreateForm').submit();
    });

    const renameBackdrop = document.createElement('div');
    renameBackdrop.className = 'modal-backdrop';
    renameBackdrop.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="renameTitle" style="max-width:620px;width:100%">
        <header style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px">
          <div>
            <h3 id="renameTitle" style="margin:0">Rename page</h3>
            <div class="muted" style="font-size:13px">Update the page title and file path together.</div>
          </div>
          <button class="close-btn" type="button" aria-label="Close">×</button>
        </header>
        <div style="display:grid;gap:14px">
          <label style="display:grid;gap:6px">
            <span class="muted">Page name</span>
            <input id="renamePageTitleInput" type="text" placeholder="Page title">
          </label>
          <label style="display:grid;gap:6px">
            <span class="muted">File path</span>
            <input id="renamePageSlugInput" type="text" placeholder="section/page-name">
          </label>
          <div class="muted" style="font-size:13px">Changing the file path updates the page URL and page folder path.</div>
          <div class="actions" style="justify-content:flex-end">
            <button class="btn text" type="button" id="cancelRenameBtn">Cancel</button>
            <button class="btn primary" type="button" id="confirmRenameBtn">Save changes</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(renameBackdrop);

    // Delete page modal
    const deleteBackdrop = document.createElement('div');
    deleteBackdrop.className = 'modal-backdrop';
    deleteBackdrop.innerHTML = `
      <div class="modal modal-danger" role="dialog" aria-modal="true" aria-labelledby="deleteTitle">
        <header class="danger-modal-head">
          <div class="danger-modal-titlewrap">
            <div class="danger-modal-icon" aria-hidden="true">!</div>
            <div class="danger-modal-titlegroup">
              <div class="danger-modal-eyebrow">Destructive action</div>
              <h3 id="deleteTitle">Delete page</h3>
            </div>
          </div>
          <button class="close-btn" type="button" aria-label="Close">×</button>
        </header>
        <div class="danger-modal-body">
          <div class="danger-modal-copy">This removes the page from the live page list and stores it in Deleted pages for 30 days before permanent purge.</div>
          <div class="danger-page-card">
            <div class="danger-page-label">Selected page</div>
            <div class="danger-page-name" id="deletePageName"></div>
          </div>
          <div class="danger-modal-note">Super admins can review deleted-page records in the site settings area during the 30-day retention window.</div>
          <div class="danger-modal-actions">
            <button class="btn subtle" type="button" id="cancelDeleteBtn">Keep page</button>
            <button class="btn danger-solid" type="button" id="confirmDeleteBtn">Move to Deleted pages</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(deleteBackdrop);

    let renameTargetId = null;
    const renamePageTitleInput = renameBackdrop.querySelector('#renamePageTitleInput');
    const renamePageSlugInput = renameBackdrop.querySelector('#renamePageSlugInput');
    const closeRename = () => { renameBackdrop.style.display = 'none'; renameTargetId = null; };

    document.addEventListener('click', function(e) {
      const btn = e.target.closest('[data-rename-page]');
      if (btn) {
        renameTargetId = btn.dataset.pageId || null;
        if (renamePageTitleInput) renamePageTitleInput.value = btn.dataset.pageTitle || '';
        if (renamePageSlugInput) renamePageSlugInput.value = btn.dataset.pageSlug || '';
        renameBackdrop.style.display = 'flex';
        renamePageTitleInput?.focus();
        renamePageTitleInput?.select();
      }
    });

    let deleteTargetId = null;
    const deletePageName = deleteBackdrop.querySelector('#deletePageName');
    const closeDelete = () => { deleteBackdrop.style.display = 'none'; deleteTargetId = null; };

    // Use event delegation for delete page buttons (robust for dynamic DOM)
    document.addEventListener('click', function(e) {
      const btn = e.target.closest('[data-delete-page]');
      if (btn) {
        deleteTargetId = btn.dataset.pageId || null;
        if (deletePageName) deletePageName.textContent = btn.dataset.pageTitle || '';
        deleteBackdrop.style.display = 'flex';
      }
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

    document.addEventListener('click', function(e) {
      const btn = e.target.closest('[data-lock-page]');
      if (!btn) return;
      const pid = btn.dataset.pageId || '';
      const state = btn.dataset.lockState || '0';
      if (!pid) return;
      document.getElementById('toggle_page_lock_id').value = pid;
      document.getElementById('toggle_page_lock_state').value = state;
      document.getElementById('togglePageLockForm').submit();
    });

    renameBackdrop.querySelector('#cancelRenameBtn')?.addEventListener('click', closeRename);
    renameBackdrop.querySelector('.close-btn')?.addEventListener('click', closeRename);
    renameBackdrop.addEventListener('click', (e) => { if (e.target === renameBackdrop) closeRename(); });
    renameBackdrop.querySelector('#confirmRenameBtn')?.addEventListener('click', () => {
      const title = (renamePageTitleInput?.value || '').trim();
      const slug = (renamePageSlugInput?.value || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9/.-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/\/+/g, '/')
        .replace(/^\/+|\/+$/g, '');
      if (!renameTargetId || !title || !slug) {
        alert('Enter a page name and file path.');
        return;
      }
      document.getElementById('rename_page_id').value = renameTargetId;
      document.getElementById('rename_page_title').value = title;
      document.getElementById('rename_page_slug').value = slug;
      document.getElementById('renamePageForm').submit();
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
    const closeAllKebabs = () => {
      document.querySelectorAll('.kebab-menu').forEach(m => m.style.display = 'none');
      document.querySelectorAll('.kebab-btn[aria-expanded="true"]').forEach(btn => btn.setAttribute('aria-expanded','false'));
    };
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
