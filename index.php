<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/app/bootstrap.php';

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Models\PageFlag;
use NexusCMS\Models\CitationExample;
use NexusCMS\Models\User;
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
  view('site_search.php', [
    'site' => $site,
    'query' => $query,
    'results' => $results,
  ]);
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
      $content = Renderer::render($doc);

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
  $content = Renderer::render($doc);

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
