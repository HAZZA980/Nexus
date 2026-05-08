<?php
$isSignedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
$returnPath = $_SERVER['REQUEST_URI'] ?? '/';
$basePrefix = rtrim((string)($base ?? (function_exists('base_path') ? base_path() : '')), '/');
$siteSlug = (string)($safeSlug ?? ($site['slug'] ?? 'cite-them-right'));
$siteBase = $basePrefix . '/s/' . rawurlencode($siteSlug);
$harvardBrowseHref = $siteBase . '/ctr-harvard';
$pageSlug = (string)($page['slug'] ?? '');
$signInHref = $basePrefix . '/login.php?mode=login&return=' . urlencode($returnPath);
$logoutHref = $basePrefix . '/logout.php?return=' . urlencode($returnPath);
$searchQuery = isset($_GET['q']) ? (string)$_GET['q'] : '';
$advancedSearchHref = $siteBase . '/search';
$referenceItems = [
  ['label' => 'Harvard', 'href' => $siteBase . '/harvard'],
  ['label' => 'APA 7th', 'href' => $siteBase . '/APA7th'],
  ['label' => 'Chicago 18th', 'href' => $siteBase . '/Chicago18th'],
  ['label' => 'Chicago 17th', 'href' => $siteBase . '/Chicago17th'],
  ['label' => 'IEEE', 'href' => $siteBase . '/IEEE'],
  ['label' => 'MHRA 4th', 'href' => $siteBase . '/MHRA4th'],
  ['label' => 'MHRA 3rd', 'href' => $siteBase . '/MHRA3rd'],
  ['label' => 'MLA 9th', 'href' => $siteBase . '/MLA9th'],
  ['label' => 'OSCOLA', 'href' => $siteBase . '/OSCOLA'],
  ['label' => 'Vancouver', 'href' => $siteBase . '/Vancouver'],
];
$categoryItems = [
  ['label' => 'Books', 'href' => $siteBase . '/search?q=Books'],
  ['label' => 'Journals', 'href' => $siteBase . '/search?q=Journals'],
  ['label' => 'Digital & Internet', 'href' => $siteBase . '/search?q=Digital+%26+Internet'],
  ['label' => 'Media & Art', 'href' => $siteBase . '/search?q=Media+%26+Art'],
  ['label' => 'Research', 'href' => $siteBase . '/search?q=Research'],
  ['label' => 'Legal', 'href' => $siteBase . '/search?q=Legal'],
  ['label' => 'Governmental', 'href' => $siteBase . '/search?q=Governmental'],
  ['label' => 'Communications', 'href' => $siteBase . '/search?q=Communications'],
];
$navItems = [
  ['label' => 'Home', 'href' => $siteBase . '/home', 'active' => in_array($pageSlug, ['home', 'home-signed-in'], true)],
  ['label' => 'Choose Referencing Style', 'href' => $siteBase . '/referencing-styles', 'active' => in_array($pageSlug, ['referencing-styles', 'ctr-harvard'], true), 'children' => $referenceItems],
  ['label' => 'Browse Categories', 'href' => $siteBase . '/browse-categories', 'active' => $pageSlug === 'browse-categories', 'children' => $categoryItems],
  ['label' => 'Basics of Referencing', 'href' => $siteBase . '/basics-of-referencing', 'active' => $pageSlug === 'basics-of-referencing'],
  ['label' => 'Tutorial', 'href' => $siteBase . '/tutorial', 'active' => $pageSlug === 'tutorial'],
  ['label' => 'Videos', 'href' => $siteBase . '/videos', 'active' => $pageSlug === 'videos'],
];
?>
<header class="ctr-site-header">
  <div class="ctr-utility-bar">
    <div class="ctr-shell ctr-utility-inner">
      <div class="ctr-utility-spacer"></div>
      <div class="ctr-utility-links" aria-label="Account and access">
        <span class="ctr-utility-access">Access Provided by Bloomsbury</span>
        <?php if ($isSignedIn): ?>
          <a href="<?= htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8') ?>">Sign out</a>
        <?php else: ?>
          <a href="<?= htmlspecialchars($signInHref, ENT_QUOTES, 'UTF-8') ?>">Sign in</a>
        <?php endif; ?>
        <span class="ctr-utility-text">to your personal or admin account</span>
      </div>
    </div>
  </div>

  <div class="ctr-main-nav-wrap">
    <div class="ctr-shell ctr-main-nav-inner">
      <a class="ctr-brand" href="<?= htmlspecialchars($siteBase . '/home', ENT_QUOTES, 'UTF-8') ?>" aria-label="Cite Them Right home">
        <img src="https://res.cloudinary.com/bloomsbury-publishing-public/image/upload/f_auto%2Cq_auto/CTRCOL/citethemrightlogo.png" alt="Cite Them Right" />
      </a>

      <nav class="ctr-nav" aria-label="Main">
        <?php foreach ($navItems as $item): ?>
          <?php $hasChildren = !empty($item['children']) && is_array($item['children']); ?>
          <div class="ctr-nav-item<?= !empty($item['active']) ? ' is-active' : '' ?><?= $hasChildren ? ' has-children' : '' ?>">
            <a class="ctr-nav-link" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php if ($hasChildren): ?>
              <div class="ctr-subnav" role="menu">
                <?php foreach ($item['children'] as $child): ?>
                  <a href="<?= htmlspecialchars($child['href'], ENT_QUOTES, 'UTF-8') ?>" role="menuitem"><?= htmlspecialchars($child['label'], ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </nav>
    </div>
  </div>

  <div class="ctr-search-wrap">
    <div class="ctr-shell ctr-search-inner">
      <form
        class="ctr-search-form"
        action="<?= htmlspecialchars($siteBase . '/search', ENT_QUOTES, 'UTF-8') ?>"
        method="get"
        role="search"
        autocomplete="off"
        data-autocomplete-endpoint="<?= htmlspecialchars($siteBase . '/search/suggest', ENT_QUOTES, 'UTF-8') ?>"
      >
        <input
          class="ctr-search-input"
          name="q"
          type="search"
          placeholder="Search Cite Them Right"
          value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
          autocomplete="off"
          aria-autocomplete="list"
          aria-expanded="false"
          aria-controls="ctr-search-suggestions"
        />
        <button class="ctr-search-btn" type="submit" aria-label="Search">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2"/>
            <path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
        <div class="ctr-search-suggestions" id="ctr-search-suggestions" hidden></div>
      </form>
      <a class="ctr-advanced-search" href="<?= htmlspecialchars($advancedSearchHref, ENT_QUOTES, 'UTF-8') ?>">Advanced Search</a>
    </div>
  </div>
</header>
