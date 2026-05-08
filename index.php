<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/app/bootstrap.php';

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Models\PageFlag;
use NexusCMS\Models\CitationExample;
use NexusCMS\Models\FormResponse;
use NexusCMS\Models\User;
use NexusCMS\Models\SiteForm;
use NexusCMS\Services\Renderer;
use NexusCMS\Services\PageFlagNotifier;
use NexusCMS\Core\Security;
use NexusCMS\Core\DB;
use NexusCMS\Models\Analytics;
use NexusCMS\Support\PagePath;

/**
 * Helpers
 */
function view(string $file, array $vars = []): void {
  extract($vars);
  require __DIR__ . '/public/views/' . $file;
  exit;
}

function current_admin_role(): string {
  return strtolower(trim((string)($_SESSION['user_role'] ?? '')));
}

function require_page_edit_permission(int $pageId): array {
  $page = Page::find($pageId);
  if (!$page) json_response(['ok' => false, 'error' => 'Page not found'], 404);
  if (!Page::canEdit($page, current_admin_role())) {
    json_response(['ok' => false, 'error' => 'This page is locked. Only super admins can edit it.'], 403);
  }
  return $page;
}

function breadcrumb_label_from_segment(string $segment, string $kind = 'generic'): string {
  $segment = PagePath::normalizeSegment($segment);

  $styleMap = [
    'harvard' => 'Harvard',
    'apa-7th' => 'APA 7th',
    'chicago-18th' => 'Chicago 18th',
    'chicago-17th' => 'Chicago 17th',
    'ieee' => 'IEEE',
    'mhra-4th' => 'MHRA 4th',
    'mhra-3rd' => 'MHRA 3rd',
    'mla-9th' => 'MLA 9th',
    'oscola' => 'OSCOLA',
    'vancouver' => 'Vancouver',
  ];
  $topicMap = [
    'books' => 'Books',
    'journals' => 'Journals',
    'digital-and-internet' => 'Digital and Internet',
    'media-and-art' => 'Media and Art',
    'research' => 'Research',
    'legal' => 'Legal',
    'governmental' => 'Governmental',
    'communications' => 'Communications',
  ];

  if ($kind === 'style' && isset($styleMap[$segment])) return $styleMap[$segment];
  if ($kind === 'topic' && isset($topicMap[$segment])) return $topicMap[$segment];

  $label = str_replace('-', ' ', $segment);
  $label = ucwords($label);
  return trim($label);
}

function apply_source_type_breadcrumbs(array $doc, array $page, string $base, string $siteSlug): array {
  if (($page['template_key'] ?? '') !== 'source-type') return $doc;

  $segments = PagePath::split((string)($page['slug'] ?? ''));
  if (count($segments) < 3) return $doc;

  $styleSeg = $segments[0];
  $topicSeg = $segments[1];
  $sourceSeg = $segments[count($segments) - 1];

  $items = [
    [
      'label' => 'Home',
      'href' => PagePath::publicUrl($base, $siteSlug, 'home'),
    ],
    [
      'label' => 'Referencing Styles',
      'href' => PagePath::publicUrl($base, $siteSlug, 'referencing-styles'),
    ],
    [
      'label' => breadcrumb_label_from_segment($styleSeg, 'style'),
      'href' => PagePath::publicUrl($base, $siteSlug, $styleSeg),
    ],
    [
      'label' => breadcrumb_label_from_segment($topicSeg, 'topic'),
      'href' => PagePath::publicUrl($base, $siteSlug, PagePath::join([$styleSeg, $topicSeg])),
    ],
    [
      'label' => trim((string)($page['title'] ?? '')) !== '' ? (string)$page['title'] : breadcrumb_label_from_segment($sourceSeg, 'generic'),
      'href' => PagePath::publicUrl($base, $siteSlug, PagePath::join($segments)),
    ],
  ];

  $parts = [];
  foreach ($items as $idx => $item) {
    if ($idx > 0) {
      $parts[] = '<span style="color:#6b7280;"> &gt; </span>';
    }
    $parts[] = '<a href="' . Security::e($item['href']) . '" style="color:#294a97;text-decoration:none;">'
      . Security::e($item['label'])
      . '</a>';
  }

  $html = '<div class="nx-source-breadcrumbs" style="font-size:14px;line-height:1.5;">' . implode('', $parts) . '</div>';

  $block = [
    'id' => 'st-breadcrumbs',
    'type' => 'text',
    'props' => ['html' => $html],
    'style' => ['marginBottom' => '10px'],
  ];

  $found = false;
  $rows = is_array($doc['rows'] ?? null) ? $doc['rows'] : [];
  foreach ($rows as &$row) {
    $cols = is_array($row['cols'] ?? null) ? $row['cols'] : [];
    foreach ($cols as &$col) {
      $blocks = is_array($col['blocks'] ?? null) ? $col['blocks'] : [];
      foreach ($blocks as &$blk) {
        if (($blk['id'] ?? '') === 'st-breadcrumbs') {
          $blk = $block;
          $found = true;
          break 3;
        }
      }
    }
  }
  unset($row, $col, $blk);

  if (!$found) {
    array_unshift($rows, [
      'cols' => [
        ['span' => 12, 'blocks' => [$block]],
      ],
    ]);
  }

  $doc['rows'] = $rows;
  return $doc;
}

function ctr_normalize_search_text(string $value): string {
  $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $value = strip_tags($value);
  $value = preg_replace('/\[(.*?)\]\((.*?)\)/u', '$1', $value) ?? $value;
  $value = preg_replace('/[*_`>#]+/u', ' ', $value) ?? $value;
  $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
  return trim($value);
}

function ctr_make_search_snippet(string $text, string $query): string {
  $text = ctr_normalize_search_text($text);
  if ($text === '') return '';
  if ($query === '') return mb_substr($text, 0, 220);

  $haystack = mb_strtolower($text);
  $query = trim($query);
  $needle = mb_strtolower($query);
  $pos = mb_strpos($haystack, $needle);
  if ($pos === false) {
    $tokens = preg_split('/\s+/u', $query) ?: [];
    foreach ($tokens as $token) {
      $token = trim($token);
      if ($token === '') continue;
      $pos = mb_strpos($haystack, mb_strtolower($token));
      if ($pos !== false) break;
    }
  }
  if ($pos === false) return mb_substr($text, 0, 220);

  $start = max(0, $pos - 70);
  $snippet = mb_substr($text, $start, 220);
  if ($start > 0) $snippet = '...' . $snippet;
  if ($start + 220 < mb_strlen($text)) $snippet .= '...';
  return $snippet;
}

function ctr_build_citation_page_map(int $siteId, string $siteSlug, string $base): array {
  $publishedPages = Page::listPublishedBySite($siteId);
  $pageMap = [];

  foreach ($publishedPages as $publishedPage) {
    $doc = json_decode((string)($publishedPage['builder_json'] ?? '{}'), true);
    if (!is_array($doc)) continue;

    $refs = [];
    $walk = static function ($node) use (&$walk, &$refs): void {
      if (!is_array($node)) return;
      foreach ($node as $key => $value) {
        if (($key === 'exampleId' || $key === 'citationExampleId') && trim((string)$value) !== '') {
          $refs[] = trim((string)$value);
        }
        if (is_array($value)) $walk($value);
      }
    };
    $walk($doc);
    $refs = array_values(array_unique($refs));
    if (!$refs) continue;

    $link = [
      'url' => PagePath::publicUrl($base, $siteSlug, (string)($publishedPage['slug'] ?? '')),
      'page_title' => (string)($publishedPage['title'] ?? ''),
      'slug' => (string)($publishedPage['slug'] ?? ''),
    ];
    foreach ($refs as $ref) {
      if (!isset($pageMap[$ref])) $pageMap[$ref] = $link;
    }
  }

  return $pageMap;
}

function ctr_score_citation_example(array $row, string $query, string $pageTitle = ''): array {
  $query = trim($query);
  if ($query === '') return ['matched' => true, 'score' => 0];

  $label = ctr_normalize_search_text((string)($row['label'] ?? ''));
  $heading = ctr_normalize_search_text((string)($row['example_heading'] ?? ''));
  $body = ctr_normalize_search_text((string)($row['example_body'] ?? ''));
  $style = ctr_normalize_search_text((string)($row['referencing_style'] ?? ''));
  $category = ctr_normalize_search_text((string)($row['category'] ?? ''));
  $subCategory = ctr_normalize_search_text((string)($row['sub_category'] ?? ''));
  $pageTitle = ctr_normalize_search_text($pageTitle);

  $combined = trim(implode(' ', array_filter([$label, $heading, $body, $style, $category, $subCategory, $pageTitle], static fn($v) => $v !== '')));
  if ($combined === '') return ['matched' => false, 'score' => 0];

  $lowerCombined = mb_strtolower($combined);
  $lowerLabel = mb_strtolower($label);
  $lowerHeading = mb_strtolower($heading);
  $lowerBody = mb_strtolower($body);
  $lowerPageTitle = mb_strtolower($pageTitle);
  $lowerQuery = mb_strtolower($query);

  $score = 0;
  $matched = false;
  $phrasePattern = '/(?<!\pL)' . preg_quote($lowerQuery, '/') . '(?!\pL)/u';

  if (preg_match($phrasePattern, $lowerCombined)) {
    $score += 1200;
    $matched = true;
  } elseif (mb_strpos($lowerCombined, $lowerQuery) !== false) {
    $score += 420;
    $matched = true;
  }

  $tokens = preg_split('/\s+/u', $query) ?: [];
  foreach ($tokens as $token) {
    $token = trim($token);
    if ($token === '') continue;
    $lowerToken = mb_strtolower($token);
    $wholePattern = '/(?<!\pL)' . preg_quote($lowerToken, '/') . '(?!\pL)/u';
    $prefixPattern = '/(?<!\pL)' . preg_quote($lowerToken, '/') . '\pL+/u';

    $counts = [
      'label_whole' => preg_match_all($wholePattern, $lowerLabel, $matches) ?: 0,
      'heading_whole' => preg_match_all($wholePattern, $lowerHeading, $matches) ?: 0,
      'body_whole' => preg_match_all($wholePattern, $lowerBody, $matches) ?: 0,
      'page_whole' => preg_match_all($wholePattern, $lowerPageTitle, $matches) ?: 0,
      'label_prefix' => preg_match_all($prefixPattern, $lowerLabel, $matches) ?: 0,
      'heading_prefix' => preg_match_all($prefixPattern, $lowerHeading, $matches) ?: 0,
      'body_prefix' => preg_match_all($prefixPattern, $lowerBody, $matches) ?: 0,
      'page_prefix' => preg_match_all($prefixPattern, $lowerPageTitle, $matches) ?: 0,
      'label_substr' => substr_count($lowerLabel, $lowerToken),
      'heading_substr' => substr_count($lowerHeading, $lowerToken),
      'body_substr' => substr_count($lowerBody, $lowerToken),
      'page_substr' => substr_count($lowerPageTitle, $lowerToken),
    ];

    $wholeCount = $counts['label_whole'] + $counts['heading_whole'] + $counts['body_whole'] + $counts['page_whole'];
    $prefixCount = max(0, $counts['label_prefix'] + $counts['heading_prefix'] + $counts['body_prefix'] + $counts['page_prefix'] - $wholeCount);
    $substrCount = max(0, $counts['label_substr'] + $counts['heading_substr'] + $counts['body_substr'] + $counts['page_substr'] - $wholeCount - $prefixCount);

    if ($wholeCount > 0 || $prefixCount > 0 || $substrCount > 0) {
      $matched = true;
    }

    $score += ($counts['page_whole'] * 260) + ($counts['label_whole'] * 240) + ($counts['heading_whole'] * 180) + ($counts['body_whole'] * 110);
    $score += ($counts['page_prefix'] * 45) + ($counts['label_prefix'] * 40) + ($counts['heading_prefix'] * 30) + ($counts['body_prefix'] * 18);
    $score += ($counts['page_substr'] * 10) + ($counts['label_substr'] * 9) + ($counts['heading_substr'] * 7) + ($counts['body_substr'] * 4);
  }

  return ['matched' => $matched, 'score' => $score];
}

function ctr_collect_search_matches(array $site, string $base, string $query): array {
  $siteSlug = (string)($site['slug'] ?? '');
  $siteId = (int)($site['id'] ?? 0);
  if ($siteSlug === '' || $siteId <= 0) return [];

  $contentTypeLabel = 'Examples of referencing';
  $pageMap = ctr_build_citation_page_map($siteId, $siteSlug, $base);
  $matched = [];

  foreach (CitationExample::listForSiteSlug($siteSlug) as $row) {
    $exampleId = trim((string)($row['id'] ?? ''));
    $exampleKey = trim((string)($row['example_key'] ?? ''));
    $pageLink = null;
    if ($exampleId !== '' && isset($pageMap[$exampleId])) {
      $pageLink = $pageMap[$exampleId];
    } elseif ($exampleKey !== '' && isset($pageMap[$exampleKey])) {
      $pageLink = $pageMap[$exampleKey];
    }

    $pageTitle = (string)($pageLink['page_title'] ?? '');
    $rank = ctr_score_citation_example($row, $query, $pageTitle);
    if (!$rank['matched']) continue;

    $row['_score'] = (int)$rank['score'];
    $row['_content_type'] = $contentTypeLabel;
    $row['_topic'] = trim((string)($row['sub_category'] ?? ''));
    $row['_snippet'] = ctr_make_search_snippet((string)($row['example_body'] ?? ''), $query);
    $row['_url'] = (string)($pageLink['url'] ?? '');
    $row['_page_title'] = $pageTitle;
    $matched[] = $row;
  }

  return $matched;
}

/**
 * Normalize URI
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$base = base_path();

// strip base path (/phpProjects/NexusCMS)
if ($base !== '' && str_starts_with($uri, $base)) {
  $uri = substr($uri, strlen($base));
  if ($uri === '') $uri = '/';
}

// strip /index.php for non-rewrite mode URLs
if (str_starts_with($uri, '/index.php')) {
  $uri = substr($uri, strlen('/index.php'));
  if ($uri === '') $uri = '/';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Routes
 */

// Public analytics collection endpoint
if ($method === 'POST' && $uri === '/api/analytics/collect') {
  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);

  $siteId = (int)($data['site_id'] ?? 0);
  if ($siteId <= 0) json_response(['ok' => false, 'error' => 'Missing site'], 400);

  $result = Analytics::record($data);
  $status = ($result['error'] ?? '') === 'rate_limited' ? 429 : 200;
  if (!empty($result['ok']) && !empty($result['visitor_key'])) {
    $baseCookiePath = rtrim($base, '/') . '/';
    $longTtl = time() + (86400 * 365 * 2);
    $sessionTtl = time() + 86400; // keep short; server enforces timeout
    setcookie('nx_vid_' . $siteId, $result['visitor_key'], [
      'expires' => $longTtl,
      'path' => $baseCookiePath ?: '/',
      'httponly' => false,
      'samesite' => 'Lax',
    ]);
    if (!empty($result['session_key'])) {
      setcookie('nx_sid_' . $siteId, $result['session_key'], [
        'expires' => $sessionTtl,
        'path' => $baseCookiePath ?: '/',
        'httponly' => false,
        'samesite' => 'Lax',
      ]);
    }
  }
  json_response($result, $status);
}

// Landing: list sites
if ($method === 'GET' && $uri === '/') {
  require_admin();
  $currentUser = isset($_SESSION['user_id']) ? User::findById((int)$_SESSION['user_id']) : null;
  $sessionRole = (string)($_SESSION['user_role'] ?? '');
  $siteAccess = array_map('strval', (array)($_SESSION['site_access'] ?? []));

  $allSites = Site::all();
  $sites = array_values(array_filter($allSites, static function (array $site) use ($siteAccess, $sessionRole): bool {
    if ($sessionRole === 'super_admin' || in_array('*', $siteAccess, true)) return true;
    return in_array((string)($site['slug'] ?? ''), $siteAccess, true);
  }));

  usort($sites, static function (array $a, array $b): int {
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });

  $siteIds = array_values(array_map(static fn(array $site): int => (int)($site['id'] ?? 0), $sites));
  $siteStats = [];
  $recentPages = [];
  $analyticsCards = [];
  $alerts = [];
  $messages = [];
  $totals = [
    'sites' => count($sites),
    'live_sites' => 0,
    'draft_sites' => 0,
    'disabled_sites' => 0,
    'pages_total' => 0,
    'pages_published' => 0,
    'pages_draft' => 0,
    'views' => 0,
    'unique' => 0,
    'sessions' => 0,
    'new_visitors' => 0,
    'avg_load_ms' => 0,
    'avg_ttfb_ms' => 0,
    'four_oh_four' => 0,
  ];

  $flashKeys = ['admin_sites_flash', 'admin_users_flash', 'admin_media_flash', 'admin_db_flash'];
  foreach ($flashKeys as $flashKey) {
    $flash = $_SESSION[$flashKey] ?? null;
    unset($_SESSION[$flashKey]);
    if (is_array($flash) && trim((string)($flash['message'] ?? '')) !== '') {
      $messages[] = [
        'type' => (($flash['type'] ?? 'notice') === 'error') ? 'error' : 'notice',
        'title' => 'System message',
        'body' => trim((string)$flash['message']),
      ];
    }
  }

  $userName = trim((string)($currentUser['display_name'] ?? $currentUser['email'] ?? $_SESSION['user_name'] ?? 'Administrator'));
  if ($userName === '') $userName = 'Administrator';

  $pdo = DB::pdo();
  $pageCountMap = [];
  if ($siteIds) {
    $ph = implode(',', array_fill(0, count($siteIds), '?'));
    $st = $pdo->prepare("
      SELECT site_id,
             COUNT(*) AS total_pages,
             SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) AS published_pages,
             SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS draft_pages
      FROM pages
      WHERE site_id IN ({$ph})
      GROUP BY site_id
    ");
    $st->execute($siteIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $pageCountMap[(int)$row['site_id']] = [
        'total_pages' => (int)($row['total_pages'] ?? 0),
        'published_pages' => (int)($row['published_pages'] ?? 0),
        'draft_pages' => (int)($row['draft_pages'] ?? 0),
      ];
    }

    $st = $pdo->prepare("
      SELECT p.id, p.title, p.slug, p.status, p.updated_at, s.name AS site_name, s.slug AS site_slug
      FROM pages p
      JOIN sites s ON s.id = p.site_id
      WHERE p.site_id IN ({$ph})
      ORDER BY p.updated_at DESC
      LIMIT 8
    ");
    $st->execute($siteIds);
    $recentPages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  $rangeEnd = new DateTimeImmutable('today');
  $rangeStart = $rangeEnd->sub(new DateInterval('P6D'));
  $rangeStartSql = $rangeStart->format('Y-m-d 00:00:00');
  $rangeEndSql = $rangeEnd->format('Y-m-d 23:59:59');
  $perfLoadSamples = 0;
  $perfTtfbSamples = 0;
  $analyticsEnabledCount = 0;

  foreach ($sites as $site) {
    $siteId = (int)($site['id'] ?? 0);
    $status = strtolower(trim((string)($site['status'] ?? 'live')));
    if (!in_array($status, ['live', 'draft', 'disabled'], true)) {
      $published = (int)($pageCountMap[$siteId]['published_pages'] ?? 0);
      $status = $published > 0 ? 'live' : 'draft';
    }
    $totals[$status . '_sites']++;

    $pageCounts = $pageCountMap[$siteId] ?? ['total_pages' => 0, 'published_pages' => 0, 'draft_pages' => 0];
    $totals['pages_total'] += (int)$pageCounts['total_pages'];
    $totals['pages_published'] += (int)$pageCounts['published_pages'];
    $totals['pages_draft'] += (int)$pageCounts['draft_pages'];

    $analyticsEnabled = (int)($site['analytics_enabled'] ?? 1) === 1;
    if ($analyticsEnabled) $analyticsEnabledCount++;

    $analytics = $analyticsEnabled ? Analytics::dashboard($siteId, $rangeStart, $rangeEnd) : [
      'summary' => ['views' => 0, 'unique' => 0, 'sessions' => 0, 'new_visitors' => 0],
      'breakdowns' => ['slow_pages' => []],
    ];
    $summary = is_array($analytics['summary'] ?? null) ? $analytics['summary'] : [];
    $totals['views'] += (int)($summary['views'] ?? 0);
    $totals['unique'] += (int)($summary['unique'] ?? 0);
    $totals['sessions'] += (int)($summary['sessions'] ?? 0);
    $totals['new_visitors'] += (int)($summary['new_visitors'] ?? 0);

    $perf = ['avg_load_ms' => 0, 'avg_ttfb_ms' => 0, 'four_oh_four' => 0];
    if ($analyticsEnabled) {
      try {
        $st = $pdo->prepare("
          SELECT
            ROUND(AVG(CASE WHEN load_ms IS NOT NULL AND load_ms > 0 THEN load_ms END)) AS avg_load_ms,
            ROUND(AVG(CASE WHEN ttfb_ms IS NOT NULL AND ttfb_ms > 0 THEN ttfb_ms END)) AS avg_ttfb_ms,
            SUM(CASE WHEN event_type='404' THEN 1 ELSE 0 END) AS four_oh_four,
            SUM(CASE WHEN load_ms IS NOT NULL AND load_ms > 0 THEN 1 ELSE 0 END) AS load_samples,
            SUM(CASE WHEN ttfb_ms IS NOT NULL AND ttfb_ms > 0 THEN 1 ELSE 0 END) AS ttfb_samples
          FROM analytics_events
          WHERE site_id=? AND occurred_at BETWEEN ? AND ?
        ");
        $st->execute([$siteId, $rangeStartSql, $rangeEndSql]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $perf['avg_load_ms'] = (int)($row['avg_load_ms'] ?? 0);
        $perf['avg_ttfb_ms'] = (int)($row['avg_ttfb_ms'] ?? 0);
        $perf['four_oh_four'] = (int)($row['four_oh_four'] ?? 0);
        $perfLoadSamples += (int)($row['load_samples'] ?? 0);
        $perfTtfbSamples += (int)($row['ttfb_samples'] ?? 0);
      } catch (\Throwable $e) {
        // dashboard remains best-effort if analytics tables are not fully available
      }
    }

    $totals['four_oh_four'] += (int)$perf['four_oh_four'];
    if ($perf['avg_load_ms'] > 0) $totals['avg_load_ms'] += $perf['avg_load_ms'];
    if ($perf['avg_ttfb_ms'] > 0) $totals['avg_ttfb_ms'] += $perf['avg_ttfb_ms'];

    $publicUrl = trim((string)($site['slug'] ?? '')) !== '' ? ($base . '/s/' . trim((string)$site['slug']) . '/home') : ($base . '/');
    $siteStats[] = [
      'id' => $siteId,
      'name' => trim((string)($site['name'] ?? '')) ?: 'Untitled site',
      'slug' => trim((string)($site['slug'] ?? '')),
      'status' => $status,
      'analytics_enabled' => $analyticsEnabled,
      'public_url' => $publicUrl,
      'pages_total' => (int)$pageCounts['total_pages'],
      'pages_published' => (int)$pageCounts['published_pages'],
      'pages_draft' => (int)$pageCounts['draft_pages'],
      'views' => (int)($summary['views'] ?? 0),
      'unique' => (int)($summary['unique'] ?? 0),
      'sessions' => (int)($summary['sessions'] ?? 0),
      'new_visitors' => (int)($summary['new_visitors'] ?? 0),
      'avg_load_ms' => (int)$perf['avg_load_ms'],
      'avg_ttfb_ms' => (int)$perf['avg_ttfb_ms'],
      'four_oh_four' => (int)$perf['four_oh_four'],
      'updated_at' => (string)($site['updated_at'] ?? $site['created_at'] ?? ''),
    ];
  }

  if ($messages === []) {
    $messages[] = [
      'type' => 'notice',
      'title' => 'Welcome',
      'body' => 'Signed in as ' . $userName . '. This dashboard covers your current site access and the latest seven-day metrics.',
    ];
    if ($totals['sites'] > 0) {
      $messages[] = [
        'type' => 'notice',
        'title' => 'Coverage',
        'body' => 'You currently have visibility across ' . $totals['sites'] . ' site' . ($totals['sites'] === 1 ? '' : 's') . '.',
      ];
    }
  }

  if ($totals['draft_sites'] > 0) {
    $alerts[] = [
      'level' => 'warning',
      'title' => 'Draft sites need review',
      'body' => $totals['draft_sites'] . ' site' . ($totals['draft_sites'] === 1 ? ' is' : 's are') . ' still in draft status.',
      'href' => $base . '/admin/index.php',
      'cta' => 'Open sites',
    ];
  }
  if ($totals['disabled_sites'] > 0) {
    $alerts[] = [
      'level' => 'warning',
      'title' => 'Disabled sites detected',
      'body' => $totals['disabled_sites'] . ' site' . ($totals['disabled_sites'] === 1 ? ' is' : 's are') . ' disabled and not serving publicly.',
      'href' => $base . '/admin/index.php',
      'cta' => 'Review status',
    ];
  }
  if ($totals['pages_draft'] > 0) {
    $alerts[] = [
      'level' => 'info',
      'title' => 'Draft pages are pending',
      'body' => $totals['pages_draft'] . ' page' . ($totals['pages_draft'] === 1 ? ' is' : 's are') . ' still in draft across your sites.',
      'href' => $base . '/admin/index.php',
      'cta' => 'Manage pages',
    ];
  }
  if ($totals['four_oh_four'] > 0) {
    $alerts[] = [
      'level' => 'warning',
      'title' => '404 activity recorded',
      'body' => $totals['four_oh_four'] . ' missing-page hit' . ($totals['four_oh_four'] === 1 ? ' was' : 's were') . ' recorded in the last 7 days.',
      'href' => $base . '/admin/site.php',
      'cta' => 'Inspect analytics',
    ];
  }
  if ($totals['sites'] > 0 && $analyticsEnabledCount === 0) {
    $alerts[] = [
      'level' => 'warning',
      'title' => 'Analytics are off',
      'body' => 'Analytics collection is disabled for every site in your dashboard.',
      'href' => $base . '/admin/index.php',
      'cta' => 'Review sites',
    ];
  }

  usort($siteStats, static function (array $a, array $b): int {
    return ($b['views'] <=> $a['views']) ?: strcmp((string)$a['name'], (string)$b['name']);
  });
  $analyticsCards = array_slice($siteStats, 0, 4);

  $loadSiteCount = count(array_filter($siteStats, static fn(array $site): bool => (int)$site['avg_load_ms'] > 0));
  $ttfbSiteCount = count(array_filter($siteStats, static fn(array $site): bool => (int)$site['avg_ttfb_ms'] > 0));
  $totals['avg_load_ms'] = $loadSiteCount > 0 ? (int)round($totals['avg_load_ms'] / $loadSiteCount) : 0;
  $totals['avg_ttfb_ms'] = $ttfbSiteCount > 0 ? (int)round($totals['avg_ttfb_ms'] / $ttfbSiteCount) : 0;

  view('dashboard.php', [
    'userName' => $userName,
    'currentUser' => $currentUser,
    'totals' => $totals,
    'messages' => $messages,
    'alerts' => $alerts,
    'siteStats' => $siteStats,
    'analyticsCards' => $analyticsCards,
    'recentPages' => $recentPages,
    'rangeLabel' => $rangeStart->format('j M') . ' to ' . $rangeEnd->format('j M Y'),
  ]);
}

// Public page flag submission
if ($method === 'POST' && $uri === '/report/page-flag') {
  $returnTarget = (string)($_POST['return_url'] ?? '/');
  if (preg_match('~^https?://~i', $returnTarget)) {
    $path = (string)(parse_url($returnTarget, PHP_URL_PATH) ?? '/');
    $query = (string)(parse_url($returnTarget, PHP_URL_QUERY) ?? '');
    $returnTarget = $path . ($query !== '' ? '?' . $query : '');
  }
  if ($base !== '' && str_starts_with($returnTarget, $base)) {
    $returnTarget = substr($returnTarget, strlen($base));
    if ($returnTarget === '') $returnTarget = '/';
  }

  if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    redirect('/login.php?return=' . urlencode($_SERVER['HTTP_REFERER'] ?? '/'));
  }
  if (!Security::checkCsrf($_POST['_csrf'] ?? null)) {
    $_SESSION['page_flag_flash'] = ['type' => 'error', 'message' => 'Security check failed.'];
    redirect($returnTarget);
  }

  $siteSlug = trim((string)($_POST['site_slug'] ?? ''));
  $site = Site::findBySlug($siteSlug);
  $user = User::findById((int)$_SESSION['user_id']);
  $siteAccess = array_map('strval', (array)($_SESSION['site_access'] ?? []));
  $role = (string)($_SESSION['user_role'] ?? '');

  if (!$site || !$user) {
    $_SESSION['page_flag_flash'] = ['type' => 'error', 'message' => 'Unable to submit this flag.'];
    redirect($returnTarget);
  }
  if (!in_array('*', $siteAccess, true) && !in_array($siteSlug, $siteAccess, true)) {
    $_SESSION['page_flag_flash'] = ['type' => 'error', 'message' => 'You do not have access to flag this site.'];
    redirect($returnTarget);
  }

  $description = trim((string)($_POST['description'] ?? ''));
  if ($description === '') {
    $_SESSION['page_flag_flash'] = ['type' => 'error', 'message' => 'Add a description before sending the flag.'];
    redirect($returnTarget);
  }

  $pageId = (int)($_POST['page_id'] ?? 0);
  $page = $pageId > 0 ? Page::find($pageId) : null;
  if ($page && (int)($page['site_id'] ?? 0) !== (int)$site['id']) {
    $page = null;
    $pageId = 0;
  }

  $returnUrl = $returnTarget;
  $pagePath = trim((string)($_POST['page_path'] ?? ''));
  if ($pagePath === '') $pagePath = parse_url($returnUrl, PHP_URL_PATH) ?: '/';
  $pageTitle = trim((string)($_POST['page_title'] ?? ''));
  if ($pageTitle === '') $pageTitle = trim((string)($page['title'] ?? 'Untitled page'));

  $flagId = PageFlag::createFlag([
    'site_id' => (int)$site['id'],
    'page_id' => $pageId > 0 ? $pageId : null,
    'page_path' => $pagePath,
    'page_title' => $pageTitle,
    'page_url' => $returnUrl,
    'reporter_user_id' => (int)$user['id'],
    'reporter_name' => (string)($user['display_name'] ?? $user['email'] ?? 'User'),
    'reporter_email' => (string)($user['email'] ?? ''),
    'reporter_role' => $role,
    'description' => $description,
  ]);
  $createdFlag = PageFlag::findById($flagId) ?: [];
  $createdFlag['site_name'] = (string)($site['name'] ?? '');
  PageFlagNotifier::notifyCreated($createdFlag);

  $nextRole = PageFlag::roleLabel(PageFlag::nextOwnerRole($role));
  $_SESSION['page_flag_flash'] = ['type' => 'notice', 'message' => 'Flag sent to ' . $nextRole . '.'];
  redirect($returnUrl !== '' ? $returnUrl : '/');
}

// Site search: /s/{site}/search
if ($method === 'GET' && preg_match('#^/s/([^/]+)/search/?$#', $uri, $m)) {
  $siteSlug = $m[1];
  $site = Site::findBySlug($siteSlug);
  if (!$site) { http_response_code(404); echo "Site not found"; exit; }
  $query = trim((string)($_GET['q'] ?? ''));
  $results = $query === '' ? [] : Page::searchPublished((int)$site['id'], $query);
  $searchPayload = null;

  if ($siteSlug === 'cite-them-right') {
    $normalizeList = static function ($value): array {
      $items = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
      $out = [];
      foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item !== '') $out[] = $item;
      }
      return array_values(array_unique($out));
    };

    $selectedStyles = $normalizeList($_GET['style'] ?? []);
    $selectedCategories = $normalizeList($_GET['category'] ?? []);
    $selectedTopics = $normalizeList($_GET['topic'] ?? []);
    $selectedContentTypes = $normalizeList($_GET['content_type'] ?? []);

    $sort = trim((string)($_GET['sort'] ?? 'relevance'));
    if (!in_array($sort, ['relevance', 'title', 'style'], true)) $sort = 'relevance';

    $perPage = (int)($_GET['per_page'] ?? 10);
    if (!in_array($perPage, [10, 20, 50], true)) $perPage = 10;

    $pageNum = max(1, (int)($_GET['page'] ?? 1));
    $contentTypeLabel = 'Examples of referencing';
    $matchedByQuery = ctr_collect_search_matches($site, $base, $query);

    $facetCounts = [
      'style' => [],
      'category' => [],
      'topic' => [],
      'content_type' => [],
    ];
    foreach ($matchedByQuery as $row) {
      $styleKey = (string)($row['referencing_style'] ?? '');
      $categoryKey = (string)($row['category'] ?? '');
      $topicKey = (string)($row['_topic'] ?? '');
      $contentTypeKey = (string)($row['_content_type'] ?? '');
      if ($styleKey !== '') $facetCounts['style'][$styleKey] = ($facetCounts['style'][$styleKey] ?? 0) + 1;
      if ($categoryKey !== '') $facetCounts['category'][$categoryKey] = ($facetCounts['category'][$categoryKey] ?? 0) + 1;
      if ($topicKey !== '') $facetCounts['topic'][$topicKey] = ($facetCounts['topic'][$topicKey] ?? 0) + 1;
      if ($contentTypeKey !== '') $facetCounts['content_type'][$contentTypeKey] = ($facetCounts['content_type'][$contentTypeKey] ?? 0) + 1;
    }
    foreach ($facetCounts as &$groupCounts) {
      uksort($groupCounts, static function (string $a, string $b) use ($groupCounts): int {
        $countCompare = ($groupCounts[$b] ?? 0) <=> ($groupCounts[$a] ?? 0);
        if ($countCompare !== 0) return $countCompare;
        return strcasecmp($a, $b);
      });
    }
    unset($groupCounts);

    $filtered = array_values(array_filter($matchedByQuery, static function (array $row) use ($selectedStyles, $selectedCategories, $selectedTopics, $selectedContentTypes): bool {
      if ($selectedStyles && !in_array((string)($row['referencing_style'] ?? ''), $selectedStyles, true)) return false;
      if ($selectedCategories && !in_array((string)($row['category'] ?? ''), $selectedCategories, true)) return false;
      if ($selectedTopics && !in_array((string)($row['_topic'] ?? ''), $selectedTopics, true)) return false;
      if ($selectedContentTypes && !in_array((string)($row['_content_type'] ?? ''), $selectedContentTypes, true)) return false;
      return true;
    }));

    usort($filtered, static function (array $a, array $b) use ($sort): int {
      if ($sort === 'title') {
        return strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
      }
      if ($sort === 'style') {
        $styleCompare = strcasecmp((string)($a['referencing_style'] ?? ''), (string)($b['referencing_style'] ?? ''));
        if ($styleCompare !== 0) return $styleCompare;
        return strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
      }
      $scoreCompare = (int)($b['_score'] ?? 0) <=> (int)($a['_score'] ?? 0);
      if ($scoreCompare !== 0) return $scoreCompare;
      return strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });

    $totalResults = count($filtered);
    $totalPages = max(1, (int)ceil($totalResults / $perPage));
    if ($pageNum > $totalPages) $pageNum = $totalPages;
    $offset = ($pageNum - 1) * $perPage;
    $pagedResults = array_slice($filtered, $offset, $perPage);

    $searchPayload = [
      'mode' => 'cite-them-right',
      'items' => $pagedResults,
      'total' => $totalResults,
      'page' => $pageNum,
      'per_page' => $perPage,
      'total_pages' => $totalPages,
      'sort' => $sort,
      'selected' => [
        'style' => $selectedStyles,
        'category' => $selectedCategories,
        'topic' => $selectedTopics,
        'content_type' => $selectedContentTypes,
      ],
      'facets' => $facetCounts,
      'query_total' => count($matchedByQuery),
      'content_type_label' => $contentTypeLabel,
    ];
  }

  view('site_search.php', [
    'site' => $site,
    'query' => $query,
    'results' => $results,
    'searchPayload' => $searchPayload,
  ]);
}

if ($method === 'GET' && preg_match('#^/s/([^/]+)/search/suggest/?$#', $uri, $m)) {
  $siteSlug = $m[1];
  $site = Site::findBySlug($siteSlug);
  if (!$site) json_response(['ok' => false, 'error' => 'Site not found'], 404);

  $query = trim((string)($_GET['q'] ?? ''));
  $limit = max(1, min(12, (int)($_GET['limit'] ?? 8)));
  if ($siteSlug !== 'cite-them-right') {
    json_response(['ok' => true, 'items' => [], 'query' => $query]);
  }
  if ($query === '') {
    json_response(['ok' => true, 'items' => [], 'query' => $query]);
  }

  $matches = ctr_collect_search_matches($site, $base, $query);
  $grouped = [];
  foreach ($matches as $row) {
    $url = trim((string)($row['_url'] ?? ''));
    $pageTitle = trim((string)($row['_page_title'] ?? ''));
    if ($url === '' || $pageTitle === '') continue;

    $key = $url;
    $item = [
      'title' => $pageTitle,
      'url' => $url,
      'style' => trim((string)($row['referencing_style'] ?? '')),
      'category' => trim((string)($row['category'] ?? '')),
      'match_label' => trim((string)($row['label'] ?? '')),
      'snippet' => trim((string)($row['_snippet'] ?? '')),
      'score' => (int)($row['_score'] ?? 0),
    ];

    if (!isset($grouped[$key]) || $item['score'] > (int)$grouped[$key]['score']) {
      $grouped[$key] = $item;
    }
  }

  $items = array_values($grouped);
  usort($items, static function (array $a, array $b): int {
    $scoreCompare = (int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0);
    if ($scoreCompare !== 0) return $scoreCompare;
    return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
  });

  $items = array_slice($items, 0, $limit);
  $items = array_map(static function (array $item): array {
    unset($item['score']);
    return $item;
  }, $items);

  json_response(['ok' => true, 'items' => $items, 'query' => $query]);
}

// Homepage redirect: /s/{site}
if ($method === 'GET' && preg_match('#^/s/([^/]+)/?$#', $uri, $m)) {
  $siteSlug = $m[1];
  $site = Site::findBySlug($siteSlug);
  if (!$site) { http_response_code(404); echo "Site not found"; exit; }
  $homeSlug = 'home';
  if (!empty($site['homepage_page_id'])) {
    $homePage = Page::find((int)$site['homepage_page_id']);
    if ($homePage && ($homePage['status'] ?? '') === 'published') {
      $homeSlug = $homePage['slug'];
    }
  }
  header('Location: ' . $base . '/s/' . $siteSlug . '/' . $homeSlug);
  exit;
}

if ($method === 'POST' && preg_match('#^/s/([^/]+)/(.+)$#', $uri, $m) && isset($_POST['_nx_form_submit'])) {
  $siteSlug = $m[1];
  $pageSlug = PagePath::normalizePath(rawurldecode($m[2]));
  $site = Site::findBySlug($siteSlug);
  if (!$site) { http_response_code(404); echo "Site not found"; exit; }

  $page = Page::findPublishedBySlug((int)$site['id'], $pageSlug);
  if (!$page) {
    $page = Page::findBySlugAnyStatus((int)$site['id'], $pageSlug);
  }
  if (!$page) { http_response_code(404); echo "Page not found"; exit; }

  $formId = (int)($_POST['nx_form_id'] ?? 0);
  $form = $formId > 0 ? SiteForm::find($formId) : null;
  $redirectUrl = PagePath::publicUrl($base, $siteSlug, $pageSlug);
  if (!$form || (int)($form['site_id'] ?? 0) !== (int)$site['id']) {
    header('Location: ' . $redirectUrl . '?nx_form_error=failed&nx_form=' . $formId);
    exit;
  }

  $responsesIn = is_array($_POST['responses'] ?? null) ? $_POST['responses'] : [];
  $questions = is_array($form['questions'] ?? null) ? $form['questions'] : [];
  $responses = [];
  $hasInvalid = false;
  foreach ($questions as $question) {
    if (!is_array($question)) continue;
    $qid = preg_replace('/[^a-z0-9_\-]/i', '_', (string)($question['id'] ?? ''));
    if ($qid === '') continue;
    $type = strtolower(trim((string)($question['type'] ?? 'text')));
    $rawValue = $responsesIn[$qid] ?? null;
    if ($type === 'rating') {
      $score = (int)$rawValue;
      if ($score < 1 || $score > 10) {
        $hasInvalid = true;
        break;
      }
      $responses[] = [
        'id' => $qid,
        'label' => trim((string)($question['label'] ?? '')),
        'type' => 'rating',
        'value' => $score,
      ];
    } else {
      $text = trim((string)$rawValue);
      if ($text === '') {
        $hasInvalid = true;
        break;
      }
      $responses[] = [
        'id' => $qid,
        'label' => trim((string)($question['label'] ?? '')),
        'type' => 'text',
        'value' => mb_substr($text, 0, 5000),
      ];
    }
  }

  if ($hasInvalid || !$responses) {
    header('Location: ' . $redirectUrl . '?nx_form_error=invalid&nx_form=' . $formId);
    exit;
  }

  try {
    $currentUser = isset($_SESSION['user_id']) ? User::findById((int)$_SESSION['user_id']) : null;
    $responseUserName = trim((string)($currentUser['display_name'] ?? $currentUser['email'] ?? $_SESSION['user_name'] ?? $_SESSION['username'] ?? ''));
    $responseInstitution = trim((string)($currentUser['institution_name'] ?? ''));
    FormResponse::create(
      (int)$site['id'],
      $formId,
      (int)($page['id'] ?? 0),
      $pageSlug,
      $responses,
      isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
      $responseUserName,
      $responseInstitution
    );
    header('Location: ' . $redirectUrl . '?nx_form_submitted=' . $formId);
    exit;
  } catch (\Throwable $e) {
    header('Location: ' . $redirectUrl . '?nx_form_error=failed&nx_form=' . $formId);
    exit;
  }
}

// Public page: /s/{site}/{page...}
if ($method === 'GET' && preg_match('#^/s/([^/]+)/(.+)$#', $uri, $m)) {
  $siteSlug = $m[1];
  $pageSlug = PagePath::normalizePath(rawurldecode($m[2]));
  $token = $_GET['preview_token'] ?? null;

  $site = Site::findBySlug($siteSlug);
  if (!$site) { http_response_code(404); echo "Site not found"; exit; }

  // ✅ PREVIEW MODE: allow preview even if page is draft/unpublished
  if (is_string($token) && $token !== '' && isset($_SESSION['nx_preview'][$token])) {
    $payload = $_SESSION['nx_preview'][$token];

    // Find page regardless of status
    $page = Page::findBySlugAnyStatus((int)$site['id'], $pageSlug);
    if (!$page) { http_response_code(404); echo "Page not found"; exit; }

    if ((int)$payload['page_id'] === (int)$page['id']) {
      $doc = $payload['doc'];
      $doc = apply_source_type_breadcrumbs($doc, $page, $base, $siteSlug);
      $content = Renderer::render($doc, ['site' => $site, 'page' => $page]);

      view('site_page.php', [
        'site' => $site,
        'page' => $page,
        'content' => $content,
        'is_preview' => true
      ]);
    }
    // token exists but doesn't match this page
    http_response_code(403);
    echo "Invalid preview token";
    exit;
  }

  // ✅ NORMAL MODE: prefer published; fallback to draft if none
  $page = Page::findPublishedBySlug((int)$site['id'], $pageSlug);
  if (!$page) {
    $page = Page::findBySlugAnyStatus((int)$site['id'], $pageSlug);
  }
  if (!$page) {
    Analytics::record404((int)$site['id'], $uri, ['referrer' => $_SERVER['HTTP_REFERER'] ?? '']);
    http_response_code(404);
    echo "Page not found";
    exit;
  }

  $doc = json_decode($page['builder_json'] ?? '{}', true) ?: ['version'=>1,'rows'=>[]];
  $doc = apply_source_type_breadcrumbs($doc, $page, $base, $siteSlug);
  $content = Renderer::render($doc, ['site' => $site, 'page' => $page]);

  view('site_page.php', [
    'site' => $site,
    'page' => $page,
    'content' => $content,
    'is_preview' => false
  ]);
}

// API: Citation examples (admin only, read-only)
if ($method === 'GET' && $uri === '/api/citation/examples') {
  require_admin();

  $siteSlug = trim((string)($_GET['site_slug'] ?? ''));
  if ($siteSlug === '') json_response(['ok' => false, 'error' => 'Missing site'], 400);

  $site = Site::findBySlug($siteSlug);
  if (!$site) json_response(['ok' => false, 'error' => 'Site not found'], 404);

  $rows = [];
  $refStyle = trim((string)($_GET['referencing_style'] ?? ''));
  $category = trim((string)($_GET['category'] ?? ''));
  try {
    $rows = CitationExample::listForSiteSlug(
      $siteSlug,
      $refStyle !== '' ? $refStyle : null,
      $category !== '' ? $category : null
    );
  } catch (\Throwable $e) {
    json_response(['ok' => false, 'error' => 'Unable to load citation examples'], 500);
  }

  $examples = array_map(function ($r) {
    return [
      'id' => $r['example_key'] ?? $r['id'],
      'style' => $r['referencing_style'] ?? '',
      'label' => $r['label'] ?? '',
      'heading' => $r['example_heading'] ?? '',
      'body' => $r['example_body'] ?? '',
      'bodyHtml' => $r['example_body'] ?? '',
      'youTry' => $r['you_try'] ?? '',
      'youTryHtml' => $r['you_try'] ?? '',
      'citationOrder' => $r['citation_order'] ?? '',
      'citationOrderHtml' => $r['citation_order'] ?? '',
      'notes' => $r['notes'] ?? ''
    ];
  }, $rows);

  json_response(['ok' => true, 'examples' => $examples]);
}

// API: Save draft doc
if ($method === 'POST' && $uri === '/api/pages/save') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $pageId = (int)($data['page_id'] ?? 0);
  $doc = $data['doc'] ?? null;
  if ($pageId <= 0 || !is_array($doc)) json_response(['ok'=>false,'error'=>'Missing page/doc'], 400);
  require_page_edit_permission($pageId);

  Page::saveDoc($pageId, $doc);
  json_response(['ok'=>true]);
}

// API: Publish doc
if ($method === 'POST' && $uri === '/api/pages/publish') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $pageId = (int)($data['page_id'] ?? 0);
  $doc = $data['doc'] ?? null;
  if ($pageId <= 0 || !is_array($doc)) json_response(['ok'=>false,'error'=>'Missing page/doc'], 400);
  require_page_edit_permission($pageId);

  Page::publish($pageId, $doc);
  json_response(['ok'=>true]);
}

// API: Unpublish page
if ($method === 'POST' && $uri === '/api/pages/unpublish') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $pageId = (int)($data['page_id'] ?? 0);
  if ($pageId <= 0) json_response(['ok'=>false,'error'=>'Missing page_id'], 400);
  require_page_edit_permission($pageId);

  \NexusCMS\Models\Page::unpublish($pageId);
  json_response(['ok'=>true]);
}

// API: Create preview token storing doc in session (unsaved)
if ($method === 'POST' && $uri === '/api/pages/preview-token') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $pageId = (int)($data['page_id'] ?? 0);
  $doc = $data['doc'] ?? null;
  if ($pageId <= 0 || !is_array($doc)) json_response(['ok'=>false,'error'=>'Missing page/doc'], 400);
  require_page_edit_permission($pageId);

  $token = bin2hex(random_bytes(16));
  $_SESSION['nx_preview'] = $_SESSION['nx_preview'] ?? [];
  $_SESSION['nx_preview'][$token] = [
    'page_id' => $pageId,
    'doc' => $doc,
    'ts' => time()
  ];

  json_response(['ok'=>true,'token'=>$token]);
}

// API: Save as new revision (keeps last 5)
if ($method === 'POST' && $uri === '/api/revisions/create') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $pageId = (int)($data['page_id'] ?? 0);
  $doc = $data['doc'] ?? null;
  if ($pageId <= 0 || !is_array($doc)) json_response(['ok'=>false,'error'=>'Missing page/doc'], 400);
  require_page_edit_permission($pageId);

  $name = isset($data['name']) ? trim((string)$data['name']) : null;
  if ($name === '') $name = null;
  if ($name !== null && strlen($name) > 80) $name = substr($name, 0, 80);

  $note = isset($data['note']) ? trim((string)$data['note']) : null;
  if ($note === '') $note = null;
  if ($note !== null && strlen($note) > 500) $note = substr($note, 0, 500);
  $isMilestone = !empty($data['is_milestone']);
  $userId = $_SESSION['user_id'] ?? null;

  \NexusCMS\Models\Revision::create($pageId, $doc, $name, $note, $isMilestone, $userId ? (int)$userId : null);
  \NexusCMS\Models\Revision::prune($pageId, 5);

  json_response(['ok'=>true]);
}

// API: List revisions (last 5)
// NOTE: simpler + more reliable than regex on REQUEST_URI
if ($method === 'GET' && $uri === '/api/revisions/list') {
  require_admin();

  $pageId = (int)($_GET['page_id'] ?? 0);
  if ($pageId <= 0) json_response(['ok'=>false,'error'=>'Missing page_id'], 400);
  require_page_edit_permission($pageId);

  $items = \NexusCMS\Models\Revision::listByPage($pageId, 5);
  json_response(['ok'=>true,'items'=>$items]);
}

// API: Create preview token from a revision (unsaved preview of that revision)
if ($method === 'POST' && $uri === '/api/revisions/preview-token') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $revisionId = (int)($data['revision_id'] ?? 0);
  if ($revisionId <= 0) json_response(['ok'=>false,'error'=>'Missing revision_id'], 400);

  $rev = \NexusCMS\Models\Revision::get($revisionId);
  if (!$rev) json_response(['ok'=>false,'error'=>'Revision not found'], 404);
  require_page_edit_permission((int)($rev['page_id'] ?? 0));

  $doc = json_decode($rev['doc_json'], true);
  if (!is_array($doc)) $doc = ['version'=>1,'rows'=>[]];

  $token = bin2hex(random_bytes(16));
  $_SESSION['nx_preview'] = $_SESSION['nx_preview'] ?? [];
  $_SESSION['nx_preview'][$token] = [
    'page_id' => (int)$rev['page_id'],
    'doc' => $doc,
    'ts' => time()
  ];

  json_response(['ok'=>true,'token'=>$token]);
}

// API: Delete revision
if ($method === 'POST' && $uri === '/api/revisions/delete') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $id = (int)($data['revision_id'] ?? 0);
  if ($id <= 0) json_response(['ok'=>false,'error'=>'Missing revision_id'], 400);
  $rev = \NexusCMS\Models\Revision::get($id);
  if (!$rev) json_response(['ok'=>false,'error'=>'Revision not found'], 404);
  require_page_edit_permission((int)($rev['page_id'] ?? 0));

  \NexusCMS\Models\Revision::delete($id);
  json_response(['ok'=>true]);
}

// API: Restore revision
if ($method === 'POST' && $uri === '/api/revisions/restore') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $revisionId = (int)($data['revision_id'] ?? 0);
  $mode = (string)($data['mode'] ?? 'replace'); // replace | duplicate
  if ($revisionId <= 0) json_response(['ok'=>false,'error'=>'Missing revision_id'], 400);

  $rev = \NexusCMS\Models\Revision::get($revisionId);
  if (!$rev) json_response(['ok'=>false,'error'=>'Revision not found'], 404);
  require_page_edit_permission((int)($rev['page_id'] ?? 0));

  $doc = json_decode($rev['doc_json'], true);
  if (!is_array($doc)) $doc = ['version'=>1,'rows'=>[]];

  if ($mode === 'replace') {
    \NexusCMS\Models\Page::saveDoc((int)$rev['page_id'], $doc);
    // auto-create revision with note
    $label = $rev['name'] ?? ("Revision #".$rev['id']);
    $note = "Restored from revision '{$label}'";
    $userId = $_SESSION['user_id'] ?? null;
    \NexusCMS\Models\Revision::create((int)$rev['page_id'], $doc, null, $note, false, $userId ? (int)$userId : null);
    json_response(['ok'=>true,'mode'=>'replace']);
  }

  if ($mode === 'duplicate') {
    $newPageId = \NexusCMS\Models\Page::duplicateFromDoc((int)$rev['page_id'], $doc);
    $label = $rev['name'] ?? ("Revision #".$rev['id']);
    $note = "Restored from revision '{$label}'";
    $userId = $_SESSION['user_id'] ?? null;
    \NexusCMS\Models\Revision::create((int)$rev['page_id'], $doc, null, $note, false, $userId ? (int)$userId : null);
    json_response(['ok'=>true,'mode'=>'duplicate','new_page_id'=>$newPageId]);
  }

  json_response(['ok'=>false,'error'=>'Invalid mode'], 400);
}

// API: Toggle milestone
if ($method === 'POST' && $uri === '/api/revisions/milestone') {
  require_admin();

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) json_response(['ok'=>false,'error'=>'Invalid JSON'], 400);
  if (!Security::checkCsrf($data['_csrf'] ?? null)) json_response(['ok'=>false,'error'=>'CSRF failed'], 403);

  $id = (int)($data['revision_id'] ?? 0);
  $flag = !empty($data['flag']);
  if ($id <= 0) json_response(['ok'=>false,'error'=>'Missing revision_id'], 400);
  $rev = \NexusCMS\Models\Revision::get($id);
  if (!$rev) json_response(['ok'=>false,'error'=>'Revision not found'], 404);
  require_page_edit_permission((int)($rev['page_id'] ?? 0));

  \NexusCMS\Models\Revision::setMilestone($id, $flag);
  json_response(['ok'=>true]);
}

// API: Analytics dashboard (admin)
if ($method === 'GET' && $uri === '/api/analytics/dashboard') {
  require_admin();
  $siteId = (int)($_GET['site_id'] ?? 0);
  if ($siteId <= 0) json_response(['ok'=>false,'error'=>'Missing site_id'], 400);

  $range = strtolower((string)($_GET['range'] ?? '7d'));
  $endStr = $_GET['end'] ?? 'today';
  $startStr = $_GET['start'] ?? '';
  $end = new DateTimeImmutable($endStr ?: 'today');
  if (in_array($range, ['7','7d','week'], true)) {
    $start = $end->sub(new DateInterval('P6D'));
  } elseif (in_array($range, ['30','30d','month'], true)) {
    $start = $end->sub(new DateInterval('P29D'));
  } elseif (in_array($range, ['90','90d','quarter'], true)) {
    $start = $end->sub(new DateInterval('P89D'));
  } elseif ($startStr) {
    $start = new DateTimeImmutable($startStr);
  } else {
    $start = $end->sub(new DateInterval('P6D'));
  }

  $data = Analytics::dashboard($siteId, $start, $end);
  json_response(['ok' => true, 'data' => $data]);
}

// API: Analytics CSV export (admin)
if ($method === 'GET' && $uri === '/api/analytics/export') {
  require_admin();
  $siteId = (int)($_GET['site_id'] ?? 0);
  if ($siteId <= 0) json_response(['ok'=>false,'error'=>'Missing site_id'], 400);
  $report = (string)($_GET['report'] ?? 'events');
  $end = new DateTimeImmutable($_GET['end'] ?? 'today');
  $start = $_GET['start'] ? new DateTimeImmutable((string)$_GET['start']) : $end->sub(new DateInterval('P6D'));

  $csv = Analytics::exportCsv($siteId, $start, $end, $report);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="analytics-' . $siteId . '-' . date('Ymd') . '.csv"');
  echo $csv;
  exit;
}

// Fallback
http_response_code(404);
echo "404 Not Found";
