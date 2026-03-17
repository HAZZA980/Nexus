<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Models\Page;
use NexusCMS\Models\Site;
use NexusCMS\Core\Security;
use NexusCMS\Core\DB;

$pageId = (int)($_GET['id'] ?? 0);
$page = Page::find($pageId);
if (!$page) { http_response_code(404); echo "Page not found"; exit; }

$site = Site::find((int)$page['site_id']);
if (!$site) { http_response_code(404); echo "Site not found"; exit; }
$pageSlug = strtolower((string)($page['slug'] ?? ''));
$isHomeVariantContext = in_array($pageSlug, ['home', 'home-signed-in'], true);
$homeVariantPublic = null;
$homeVariantSignedIn = null;
if ($isHomeVariantContext) {
  $homeVariantPublic = Page::findBySlugAnyStatus((int)$page['site_id'], 'home');
  $homeVariantSignedIn = Page::findBySlugAnyStatus((int)$page['site_id'], 'home-signed-in');
}

$theme = json_decode($site['theme_json'] ?? '', true) ?: [];
$shape = is_array($theme['shape'] ?? null) ? $theme['shape'] : [];
$themeRadius = (int)($shape['radius'] ?? ($theme['radius'] ?? 16));

$pageCollection = null;
$pageCollectionStyle = '';
// Normalize a collection label to a referencing style string used by citations
$normalizeStyle = function(string $val): string {
  $clean = trim($val);
  if ($clean === '') return '';
  // strip common suffixes
  $clean = preg_replace('/\b(page|pages|collection|collections)\b/i', '', $clean);
  $clean = preg_replace('/[-_]+/', ' ', $clean);
  $clean = preg_replace('/\s+/', ' ', $clean);
  $clean = trim($clean);
  $map = [
    'apa7' => 'APA 7th',
    'apa 7' => 'APA 7th',
    'apa 7th' => 'APA 7th',
    'apa7th' => 'APA 7th',
    'harvard' => 'Harvard',
    'vancouver' => 'Vancouver',
    'ieee' => 'IEEE',
    'oscola' => 'OSCOLA',
    'bluebook' => 'Bluebook',
    'ama' => 'AMA',
    'mla9' => 'MLA 9th',
    'mla 9' => 'MLA 9th',
    'mla' => 'MLA 9th',
    'chicago 18' => 'Chicago 18th',
    'chicago 18th' => 'Chicago 18th',
    'chicago 17' => 'Chicago 17th',
    'chicago 17th' => 'Chicago 17th',
    'mhra 3' => 'MHRA 3rd',
    'mhra 3rd' => 'MHRA 3rd',
    'mhra3' => 'MHRA 3rd',
    'mhra 4' => 'MHRA 4th',
    'mhra 4th' => 'MHRA 4th',
    'mhra4' => 'MHRA 4th'
  ];
  $key = strtolower($clean);
  if (isset($map[$key])) return $map[$key];
  return $clean;
};
try {
  if (!empty($page['collection_id'])) {
    $stmt = DB::pdo()->prepare("SELECT * FROM site_collections WHERE id=? LIMIT 1");
    $stmt->execute([(int)$page['collection_id']]);
    $pageCollection = $stmt->fetch();
    if ($pageCollection) {
      $pageCollectionStyle = $normalizeStyle((string)($pageCollection['name'] ?? ''));
      if ($pageCollectionStyle === '') {
        $pageCollectionStyle = $normalizeStyle((string)($pageCollection['slug'] ?? ''));
      }
    }
  }
} catch (\Throwable $e) {}

$doc = json_decode($page['builder_json'] ?? '{}', true) ?: ['version'=>1,'rows'=>[]];
$csrf = Security::csrfToken();
$base = base_path();
$uiTheme = ui_theme_mode();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script>
    (function() {
      var theme = <?= json_encode($uiTheme, JSON_UNESCAPED_SLASHES) ?>;
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
  <title>Builder — <?= Security::e($page['title']) ?></title>
  <style>
    :root{
      --bg:#0f172a;--panel:#111827;--card:#111827;--border:#1f2937;--muted:#9ca3af;--text:#e5e7eb;
      --primary:#5b21b6;--primary-strong:#4c1d95;--focus:0 0 0 3px rgba(91,33,182,.35);
      --nexus-radius: <?= (int)$themeRadius ?>px;
      --r: var(--nexus-radius, 0px);
    }
    body{background:var(--bg);color:var(--text);transition:background .2s ease,color .2s ease;}
  </style>
  <link rel="stylesheet" href="/assets/nexus-page.css">
  <link rel="stylesheet" href="<?= $base ?>/public/assets/builder.css">
  <link rel="stylesheet" href="<?= $base ?>/public/assets/builder.css">
<link rel="stylesheet" href="<?= $base ?>/public/assets/nexus-page.css">

</head>
<body>
<div class="nx-shell"
  data-page-id="<?= (int)$pageId ?>"
  data-page-updated-at="<?= Security::e((string)($page['updated_at'] ?? '')) ?>"
  data-csrf="<?= Security::e($csrf) ?>"
  data-base="<?= Security::e($base) ?>"
  data-site-slug="<?= Security::e($site['slug']) ?>"
  data-page-slug="<?= Security::e($page['slug']) ?>"
  data-collection-style="<?= Security::e($pageCollectionStyle) ?>"
  data-api-rev-preview-token="<?= $base ?>/api/revisions/preview-token"

  data-api-save="<?= $base ?>/api/pages/save"
  data-api-publish="<?= $base ?>/api/pages/publish"
  data-api-unpublish="<?= $base ?>/api/pages/unpublish"
  data-api-preview-token="<?= $base ?>/api/pages/preview-token"

  data-api-rev-create="<?= $base ?>/api/revisions/create"
  data-api-rev-list="<?= $base ?>/api/revisions/list"
  data-api-rev-delete="<?= $base ?>/api/revisions/delete"
  data-api-rev-restore="<?= $base ?>/api/revisions/restore"

  data-preview="<?= $base ?>/s/<?= Security::e($site['slug']) ?>/<?= Security::e($page['slug']) ?>"
  data-api-rev-preview-token="<?= $base ?>/api/revisions/preview-token"

  >


<aside class="nx-left nx-fixed">
  <button type="button" class="panel-toggle" id="toggleLeft" title="Collapse sidebar">−</button>

  <div class="nx-page-id left">
    <div class="nx-strong"><?= Security::e($site['name']) ?></div>
  </div>

  <a class="nx-link" href="<?= $base ?>/admin/site.php?id=<?= (int)$site['id'] ?>">← Back to Site</a>

  <div class="nx-sep"></div>
  <div class="nx-left-title">Structure</div>

  <!-- BLOCKS ACCORDION -->
 <button class="nx-acc-h" type="button" data-acc="blocks">
  <span>Blocks</span>
  <span class="nx-acc-arrow" aria-hidden="true">▾</span>
</button>

  <div class="nx-acc-b open" id="acc-blocks">
    <div class="nx-palette">
      <div class="nx-item" draggable="true" data-type="heading" tabindex="0" title="Heading">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6v12M18 6v12M6 12h12"/></svg>
        </span>
        <span class="nx-item-label">Heading</span>
      </div>
      <div class="nx-item" draggable="true" data-type="text" tabindex="0" title="Text">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 7h14M5 12h10M5 17h8"/></svg>
        </span>
        <span class="nx-item-label">Text</span>
      </div>
      <div class="nx-item" draggable="true" data-type="heroCard" tabindex="0" title="Hero Card">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="7" width="16" height="10" rx="2"/><path d="M10 7v10M7 10h3"/></svg>
        </span>
        <span class="nx-item-label">Hero Card</span>
      </div>
      <div class="nx-item" draggable="true" data-type="heroBanner" tabindex="0" title="Hero Banner">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M6 10h12M6 14h8"/></svg>
        </span>
        <span class="nx-item-label">Hero Banner</span>
      </div>
      <div class="nx-item" draggable="true" data-type="image" tabindex="0" title="Image">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 13l3-3 5 5"/><circle cx="9" cy="9" r="1.2"/></svg>
        </span>
        <span class="nx-item-label">Image</span>
      </div>
      <div class="nx-item" draggable="true" data-type="panel" tabindex="0" title="Panel">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M12 6v12"/></svg>
        </span>
        <span class="nx-item-label">Panel</span>
      </div>
      <div class="nx-item" draggable="true" data-type="testimonial" tabindex="0" title="Testimonial">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 6h7l-2 5h3l-4 7H5l2-7H5zM16 6h4l-2 5h3l-4 7h-5l2-7h-3z"/></svg>
        </span>
        <span class="nx-item-label">Testimonial</span>
      </div>
      <div class="nx-item" draggable="true" data-type="video" tabindex="0" title="Video">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="13" height="14" rx="2"/><path d="M17 9l3-2v10l-3-2"/></svg>
        </span>
        <span class="nx-item-label">Video</span>
      </div>
      <div class="nx-item" draggable="true" data-type="divider" tabindex="0" title="Divider">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h14"/></svg>
        </span>
        <span class="nx-item-label">Divider</span>
      </div>
    </div>
  </div>

  <div class="nx-sep"></div>

  <!-- INTERACTIVES ACCORDION -->
  <button class="nx-acc-h" type="button" data-acc="interactives">
    <span>Interactives</span>
    <span class="nx-acc-arrow" aria-hidden="true">▾</span>
  </button>
  <div class="nx-acc-b open" id="acc-interactives">
    <div class="nx-palette">
      <div class="nx-item" draggable="true" data-type="flipCard" tabindex="0" title="Dialogue card">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5h10a2 2 0 0 1 2 2v12l-4-3-4 3V7a2 2 0 0 1 2-2Z"/><path d="M14 5V3a2 2 0 0 1 2-2h3v14"/></svg>
        </span>
        <span class="nx-item-label">Dialogue card</span>
      </div>
      <div class="nx-item" draggable="true" data-type="carousel" tabindex="0" title="Carousel">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h16M4 17h16M9 5v4M15 15v4"/></svg>
        </span>
        <span class="nx-item-label">Carousel</span>
      </div>
      <div class="nx-item" draggable="true" data-type="accordionTabs" tabindex="0" title="Accordion/Tabs">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 8h12M6 12h12M6 16h12"/><path d="M9 10l-2-2 2-2M15 14l2 2-2 2"/></svg>
        </span>
        <span class="nx-item-label">Accordion/Tabs</span>
      </div>
      <div class="nx-item" draggable="true" data-type="download" tabindex="0" title="Download">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12"/><path d="M7 12l5 5 5-5"/><rect x="4" y="19" width="16" height="2" rx="1"/></svg>
        </span>
        <span class="nx-item-label">Download</span>
      </div>
    </div>
  </div>

  <div class="nx-sep"></div>

  <!-- ACTIVITIES ACCORDION -->
  <button class="nx-acc-h" type="button" data-acc="activities">
    <span>Activities</span>
    <span class="nx-acc-arrow" aria-hidden="true">▾</span>
  </button>
  <div class="nx-acc-b open" id="acc-activities">
    <div class="nx-palette">
      <div class="nx-item" draggable="true" data-type="dragWords" tabindex="0" title="Drag words">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9h18M3 15h18"/><path d="M9 5l-2 2 2 2M15 19l2-2-2-2"/></svg>
        </span>
        <span class="nx-item-label">Drag words</span>
      </div>
      <div class="nx-item" draggable="true" data-type="trueFalse" tabindex="0" title="True/False">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 13l4 4L19 7"/><path d="M5 7l4 4M13 13l6 6"/></svg>
        </span>
        <span class="nx-item-label">True/False</span>
      </div>
      <div class="nx-item" draggable="true" data-type="multipleChoiceQuiz" tabindex="0" title="Multiple Choice Quiz">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="7" r="2"/><circle cx="6" cy="13" r="2"/><circle cx="6" cy="19" r="2"/><path d="M11 7h9M11 13h9M11 19h9"/></svg>
        </span>
        <span class="nx-item-label">Multiple Choice Quiz</span>
      </div>
    </div>
  </div>

  <div class="nx-sep"></div>

  <!-- CITE THEM RIGHT ACCORDION -->
  <button class="nx-acc-h" type="button" data-acc="ctr">
    <span>Cite Them Right</span>
    <span class="nx-acc-arrow" aria-hidden="true">▾</span>
  </button>
  <div class="nx-acc-b" id="acc-ctr">
    <div class="nx-palette">
      <div class="nx-item" draggable="true" data-type="citationOrder" tabindex="0" title="Citation order">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 7h10M7 12h6M7 17h10"/><path d="M5 5v14a2 2 0 0 0 2 2h10"/></svg>
        </span>
        <span class="nx-item-label">Citation order</span>
      </div>
      <div class="nx-item" draggable="true" data-type="exampleCard" tabindex="0" title="Example">
        <span class="nx-item-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5h14v14H5z"/><path d="M9 9h6M9 13h4"/></svg>
        </span>
        <span class="nx-item-label">Example</span>
      </div>
    </div>
  </div>

</aside>


    <main class="nx-main">
      <header class="nx-top">
        <div class="nx-top-left">
          <div class="nx-page-heading">
            <div class="nx-strong"><?= Security::e($page['title']) ?></div>
            <?php if ($isHomeVariantContext): ?>
              <div class="nx-variant-switcher" aria-label="Home variants">
                <span class="nx-variant-label">Variant</span>
                <?php if ($homeVariantPublic): ?>
                  <a
                    class="nx-variant-chip <?= $pageSlug === 'home' ? 'active' : '' ?>"
                    href="<?= $base ?>/admin/page_builder.php?id=<?= (int)$homeVariantPublic['id'] ?>"
                  >Public</a>
                <?php else: ?>
                  <span class="nx-variant-chip disabled">Public</span>
                <?php endif; ?>
                <?php if ($homeVariantSignedIn): ?>
                  <a
                    class="nx-variant-chip <?= $pageSlug === 'home-signed-in' ? 'active' : '' ?>"
                    href="<?= $base ?>/admin/page_builder.php?id=<?= (int)$homeVariantSignedIn['id'] ?>"
                  >Signed-in users</a>
                <?php else: ?>
                  <span class="nx-variant-chip disabled">Signed-in users</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <span id="statusBadge" class="nx-status nx-status-<?= Security::e($page['status']) ?>"><?= ucfirst(Security::e($page['status'])) ?></span>
        </div>

        <div class="nx-top-center">
          <div id="saveState" class="nx-savestate">Unsaved changes</div>
          <div id="saveError" class="nx-saveerror" style="display:none;">
            <span>Save failed</span>
            <button type="button" id="retrySave" class="nx-btnlink">Retry</button>
          </div>
        </div>

        <div class="nx-top-actions">
          <button id="preview" type="button">Preview</button>

          <div class="nx-dd" id="saveDD">
            <button id="save" type="button">Save</button>
            <div class="nx-dd-menu" role="menu">
              <button id="saveAsRevision" type="button" class="nx-dd-action nx-dd-action-primary" role="menuitem">
                <span class="nx-dd-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M5 3a2 2 0 0 0-2 2v14l5-3 5 3 5-3 3 1.8V5a2 2 0 0 0-2-2H5Zm0 2h14v10.44l-1-.6-4 2.4-5-3-4 2.4V5Z"/><path d="M7 7h10v2H7V7Zm0 4h6v2H7v-2Z"/></svg>
                </span>
                <span class="nx-dd-label">Save as new revision…</span>
              </button>
              <button id="openRevisionsTop" type="button" class="nx-dd-action nx-dd-action-secondary" role="menuitem">
                <span class="nx-dd-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M4 4h16v2H4V4Zm0 7h16v2H4v-2Zm0 7h16v2H4v-2Z"/></svg>
                </span>
                <span class="nx-dd-label">View revisions</span>
              </button>
            </div>
          </div>

          <div class="nx-dd" id="publishDD">
            <button id="publishBtn" type="button"><?= $page['status'] === 'published' ? 'Update' : 'Publish' ?></button>
            <div class="nx-dd-menu" role="menu">
              <button id="publishToggleBtn" type="button" class="nx-dd-action nx-dd-action-primary" role="menuitem">
                <span class="nx-dd-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M5 21h14l1-6h-5l-3 3-3-3H4l1 6Zm7-5 3-3h4l-3-9-4 3-4-3-3 9h4l3 3Z"/></svg>
                </span>
                <span class="nx-dd-label"><?= $page['status'] === 'published' ? 'Unpublish' : 'Publish now' ?></span>
              </button>
              <button id="publishSchedule" type="button" class="nx-dd-action nx-dd-action-secondary" role="menuitem">
                <span class="nx-dd-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M7 3a1 1 0 0 0-1 1v1H5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V4a1 1 0 1 0-2 0v1H9V4a1 1 0 0 0-1-1Zm13 6H4v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9Z"/></svg>
                </span>
                <span class="nx-dd-label">Schedule publish…</span>
              </button>
            </div>
          </div>

          <button id="themeToggle" type="button" class="nx-theme-toggle" aria-label="Toggle theme">
            <svg class="nx-theme-icon nx-theme-icon-sun" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <circle cx="12" cy="12" r="5" fill="#FACC15" stroke="#F59E0B" stroke-width="2"/>
              <path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M15.66 15.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M15.66 8.34l2.12-2.12" stroke="#F59E0B" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <svg class="nx-theme-icon nx-theme-icon-moon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="currentColor" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
          </button>
        </div>
      </header>

<div id="textToolbar" class="nx-toolbar nx-toolbar-top" style="display:none;">
    <div class="nx-toolrow">
      <button type="button" class="nx-toolbtn" data-tcmd="bold" title="Bold"><b>B</b></button>
      <button type="button" class="nx-toolbtn" data-tcmd="italic" title="Italic"><i>I</i></button>
      <button type="button" class="nx-toolbtn" data-tcmd="underline" title="Underline"><u>U</u></button>
      <button type="button" class="nx-toolbtn nx-iconbtn" data-tcmd="ul" title="Bullet list" aria-label="Bullet list">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <path fill="currentColor" d="M5 6a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm0 6a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm0 6a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/>
          <path fill="currentColor" d="M7 5h13v2H7V5Zm0 6h13v2H7v-2Zm0 6h13v2H7v-2Z"/>
        </svg>
      </button>
      <button type="button" class="nx-toolbtn nx-iconbtn" data-tcmd="ol" title="Numbered list" aria-label="Numbered list">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <path fill="currentColor" d="M5 6.75V5H3v1h1v3H3v1h3V9H5V6.75ZM4 15h1.43L4.5 16.08c-.3.39-.5.77-.5 1.25 0 .93.72 1.67 1.73 1.67.52 0 1.03-.2 1.41-.56l-.55-.83c-.18.17-.41.27-.63.27-.32 0-.56-.22-.56-.53 0-.17.05-.3.19-.47l1.36-1.69V13H4v1Z"/>
          <path fill="currentColor" d="M8 5h12v2H8V5Zm0 6h12v2H8v-2Zm0 6h12v2H8v-2Z"/>
        </svg>
      </button>

      <button type="button" class="nx-toolbtn nx-iconbtn" id="t_link" title="Insert hyperlink" aria-label="Insert hyperlink">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <path fill="currentColor" d="M10.6 13.4a1 1 0 0 1 0-1.4l2.8-2.8a1 1 0 1 1 1.4 1.4l-2.8 2.8a1 1 0 0 1-1.4 0z"/>
          <path fill="currentColor" d="M8 17a4 4 0 0 1 0-5.7l1.4-1.4a4 4 0 0 1 5.7 0 1 1 0 1 1-1.4 1.4 2 2 0 0 0-2.8 0L9.4 13a2 2 0 0 0 2.8 2.8 1 1 0 1 1 1.4 1.4A4 4 0 0 1 8 17z"/>
          <path fill="currentColor" d="M16 7a4 4 0 0 1 0 5.7l-1.4 1.4a4 4 0 0 1-5.7 0 1 1 0 1 1 1.4-1.4 2 2 0 0 0 2.8 0L14.6 11A2 2 0 0 0 11.8 8.2 1 1 0 1 1 10.4 6.8 4 4 0 0 1 16 7z"/>
        </svg>
      </button>

      <button class="nx-toolbtn" id="t_unlink" type="button" title="Remove Link">⛓️‍💥</button>


      <select id="t_fontFamily" class="nx-toolsel" title="Font family">
        <option value="">Font</option>
        <option value="system-ui">System</option>
        <option value="Inter, system-ui">Inter</option>
        <option value="Arial, sans-serif">Arial</option>
        <option value="Georgia, serif">Georgia</option>
        <option value="'Times New Roman', serif">Times</option>
      </select>

      <select id="t_fontSize" class="nx-toolsel" title="Font size">
        <option value="">Size</option>
        <?php foreach (array_merge(range(10, 40), [44, 48, 56, 64, 72]) as $fs): ?>
          <option value="<?= (int)$fs ?>"><?= (int)$fs ?></option>
        <?php endforeach; ?>
      </select>

<div class="nx-toolgroup">
  <div id="t_color_palette"></div>
</div>

      <button type="button" class="nx-toolbtn nx-iconbtn" data-tcmd="alignLeft" title="Align left" aria-label="Align left">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M4 6h16v2H4V6zm0 4h10v2H4v-2zm0 4h16v2H4v-2zm0 4h10v2H4v-2z"/></svg>
      </button>
      <button type="button" class="nx-toolbtn nx-iconbtn" data-tcmd="alignCenter" title="Align center" aria-label="Align center">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M4 6h16v2H4V6zm3 4h10v2H7v-2zM4 14h16v2H4v-2zm3 4h10v2H7v-2z"/></svg>
      </button>
      <button type="button" class="nx-toolbtn nx-iconbtn" data-tcmd="alignRight" title="Align right" aria-label="Align right">
        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M4 6h16v2H4V6zm6 4h10v2H10v-2zM4 14h16v2H4v-2zm6 4h10v2H10v-2z"/></svg>
      </button>

      <div class="nx-toolbar-end">
        <button id="undo" type="button" class="nx-toolbtn" title="Undo">↺</button>
        <button id="redo" type="button" class="nx-toolbtn" title="Redo">↻</button>
        <button id="addRowToolbar" type="button" class="nx-toolbtn" title="Add Row">+</button>
      </div>
    </div>

    <div class="nx-toolrow" style="margin-top:8px">
      <button type="button" class="nx-btnwide" id="backToInspector" style="display:none;">Back to Inspector</button>
    </div>
  </div>

<div class="nx-canvas">
  <div id="canvas" class="nexus-page"></div>
</div>
<!-- Save revision modal -->
<div id="revModal" class="nx-modal" style="display:none;">
  <div class="nx-modal-dialog">
    <div class="nx-modal-head">
      <div class="nx-modal-title">Save new revision</div>
      <button type="button" class="nx-modal-close" id="revModalClose">×</button>
    </div>
    <div class="nx-modal-body">
      <label class="nx-muted">Name (optional)</label>
      <input id="revName" maxlength="80" placeholder="e.g., ‘Homepage hero refresh’">
      <label class="nx-muted" style="margin-top:12px">Notes (optional)</label>
      <textarea id="revNote" rows="5" maxlength="500" placeholder="Briefly describe what changed and why."></textarea>
      <label class="nx-toggle" style="margin-top:10px;display:inline-flex;align-items:center;gap:8px">
        <input id="revMilestone" type="checkbox">
        <span class="nx-toggle-ui"></span>
        <span class="nx-toggle-label">Mark as milestone</span>
      </label>
    </div>
    <div class="nx-modal-footer">
      <button type="button" class="nx-btnlink" id="revCancel">Cancel</button>
      <button type="button" class="nx-btn-green" id="revSave">Save revision</button>
    </div>
  </div>
</div>

<!-- Schedule publish modal -->
<div id="scheduleModal" class="nx-modal" style="display:none;">
  <div class="nx-modal-dialog">
    <div class="nx-modal-head">
      <div class="nx-modal-title">Schedule publish</div>
      <button type="button" class="nx-modal-close" id="scheduleClose">×</button>
    </div>
    <div class="nx-modal-body">
      <label class="nx-muted">Publish at (date &amp; time)</label>
      <input id="scheduleWhen" type="datetime-local">
      <div class="nx-muted" style="font-size:12px;">Uses your local timezone.</div>
    </div>
    <div class="nx-modal-footer">
      <button type="button" class="nx-btnlink" id="scheduleCancel">Cancel</button>
      <button type="button" class="nx-btn-green" id="scheduleSave">Schedule</button>
    </div>
  </div>
</div>
    </main>

  <aside class="nx-right nx-fixed">
  <button type="button" class="panel-toggle" id="toggleRight" title="Collapse inspector">−</button>
  <div class="nx-left-title" id="rightTitle">Inspector</div>

  <!-- INSPECTOR VIEW -->
  <div id="inspectorView">
    <div id="inspHint" class="nx-muted">Select a block to edit.</div>
    <div id="insp"></div>
  </div>

  <!-- REVISIONS VIEW -->
  <div id="revisionsView" style="display:none;">
    <div class="nx-muted" id="currentRevLine" style="margin-bottom:12px"></div>
    <div id="revList"></div>
  </div>
</aside>


  </div>

  <script>window.NX_DOC = <?= json_encode($doc, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
  <script src="<?= $base ?>/public/assets/builder.js"></script>
  <script>
    (function() {
      var root = document.documentElement;
      var btn = document.getElementById('themeToggle');
      var sun = btn ? btn.querySelector('.nx-theme-icon-sun') : null;
      var moon = btn ? btn.querySelector('.nx-theme-icon-moon') : null;
      var endpoint = <?= json_encode($base . '/admin/theme.php', JSON_UNESCAPED_SLASHES) ?>;
      var csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;

      var initial = root.getAttribute('data-theme') || 'dark';

      function setTheme(next) {
        next = next === 'light' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        if (btn) {
          btn.setAttribute('data-mode', next);
          btn.setAttribute('aria-pressed', next === 'light' ? 'true' : 'false');
        }
        if (sun) sun.style.display = next === 'dark' ? 'block' : 'none';
        if (moon) moon.style.display = next === 'light' ? 'block' : 'none';
        try { localStorage.setItem('nexusTheme', next); } catch (e) {}
        try {
          fetch(endpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrf
            },
            body: JSON.stringify({ mode: next, _csrf: csrf })
          });
        } catch (e) {}
      }

      setTheme(initial);

      if (btn) {
        btn.addEventListener('click', function() {
          var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
          setTheme(next);
        });
      }
    })();
  </script>
</body>
</html>
