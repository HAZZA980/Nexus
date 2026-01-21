<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/app/bootstrap.php';

use NexusCMS\Models\Site;
use NexusCMS\Models\Page;
use NexusCMS\Services\Renderer;
use NexusCMS\Core\Security;

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

  $site = Site::findBySlug($siteSlug);
  if (!$site) { http_response_code(404); echo "Site not found"; exit; }

  $token = $_GET['preview_token'] ?? null;

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
  if (!$page) { http_response_code(404); echo "Page not found"; exit; }

  $doc = json_decode($page['builder_json'] ?? '{}', true) ?: ['version'=>1,'rows'=>[]];
  $content = Renderer::render($doc);

  view('site_page.php', [
    'site' => $site,
    'page' => $page,
    'content' => $content,
    'is_preview' => false
  ]);
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

// Fallback
http_response_code(404);
echo "404 Not Found";
