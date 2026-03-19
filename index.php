<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/app/bootstrap.php';

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Models\CitationExample;
use NexusCMS\Services\Renderer;
use NexusCMS\Core\Security;
use NexusCMS\Models\Analytics;

/**
 * Helpers
 */
function view(string $file, array $vars = []): void {
  extract($vars);
  require __DIR__ . '/public/views/' . $file;
  exit;
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
  $sites = Site::all();
  view('landing.php', ['sites' => $sites]);
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

// Public page: /s/{site}/{page}

if ($method === 'GET' && preg_match('#^/s/([^/]+)/([^/]+)$#', $uri, $m)) {
  $siteSlug = $m[1];
  $pageSlug = $m[2];
  $token = $_GET['preview_token'] ?? null;

  $site = Site::findBySlug($siteSlug);
  if (!$site) { http_response_code(404); echo "Site not found"; exit; }

  // Cite Them Right: signed-in users see the signed-in home variant.
  // Do NOT rewrite during preview-token requests or page_id checks will fail.
  if ($siteSlug === 'cite-them-right' && $pageSlug === 'home' && !(is_string($token) && $token !== '')) {
    $isSignedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    if ($isSignedIn) {
      $signedHome = Page::findPublishedBySlug((int)$site['id'], 'home-signed-in');
      if ($signedHome) $pageSlug = 'home-signed-in';
    }
  }

  // ✅ PREVIEW MODE: allow preview even if page is draft/unpublished
  if (is_string($token) && $token !== '' && isset($_SESSION['nx_preview'][$token])) {
    $payload = $_SESSION['nx_preview'][$token];

    // Find page regardless of status
    $page = Page::findBySlugAnyStatus((int)$site['id'], $pageSlug);
    if (!$page) { http_response_code(404); echo "Page not found"; exit; }

    if ((int)$payload['page_id'] === (int)$page['id']) {
      $doc = $payload['doc'];
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
  try {
    $rows = CitationExample::listForSiteSlug($siteSlug, $refStyle !== '' ? $refStyle : null);
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
