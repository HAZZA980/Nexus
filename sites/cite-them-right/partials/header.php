<?php
$isSignedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
$returnPath = $_SERVER['REQUEST_URI'] ?? '/';
$basePrefix = rtrim((string)($base ?? (function_exists('base_path') ? base_path() : '')), '/');
$siteSlug = (string)($safeSlug ?? ($site['slug'] ?? 'cite-them-right'));
$siteBase = $basePrefix . '/s/' . rawurlencode($siteSlug);
$pageSlug = (string)($page['slug'] ?? '');
$signInHref = $basePrefix . '/login.php?mode=login&return=' . urlencode($returnPath);
$logoutHref = $basePrefix . '/logout.php?return=' . urlencode($returnPath);
?>
<header class="ctr-site-header">
  <div class="ctr-utility-bar">
    <div class="ctr-shell ctr-utility-inner">
      <div class="ctr-utility-right">
        <?php if ($isSignedIn): ?>
          <a href="<?= htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8') ?>" class="ctr-utility-link">Log Out</a>
        <?php else: ?>
          <a href="<?= htmlspecialchars($signInHref, ENT_QUOTES, 'UTF-8') ?>" class="ctr-utility-link">Log In</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="ctr-main-nav-wrap">
    <div class="ctr-shell ctr-main-nav-inner">
      <a class="ctr-brand" href="<?= htmlspecialchars($siteBase . '/home', ENT_QUOTES, 'UTF-8') ?>" aria-label="Cite Them Right home">
        <img src="https://res.cloudinary.com/bloomsbury-publishing-public/image/upload/f_auto%2Cq_auto/CTRCOL/citethemrightlogo.png" alt="Cite Them Right" />
      </a>

      <nav class="ctr-nav" aria-label="Main">
        <a class="<?= ($pageSlug === 'home' || $pageSlug === 'home-signed-in') ? 'is-active' : '' ?>" href="<?= htmlspecialchars($siteBase . '/home', ENT_QUOTES, 'UTF-8') ?>">Home</a>
        <a class="<?= $pageSlug === 'referencing-styles' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($siteBase . '/referencing-styles', ENT_QUOTES, 'UTF-8') ?>">Choose Referencing Style</a>
        <a class="<?= $pageSlug === 'browse-categories' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($siteBase . '/browse-categories', ENT_QUOTES, 'UTF-8') ?>">Browse Categories</a>
        <a href="<?= htmlspecialchars($siteBase . '/basics-of-referencing', ENT_QUOTES, 'UTF-8') ?>">Basics of Referencing</a>
        <a href="<?= htmlspecialchars($siteBase . '/tutorial', ENT_QUOTES, 'UTF-8') ?>">Tutorial</a>
        <a href="<?= htmlspecialchars($siteBase . '/videos', ENT_QUOTES, 'UTF-8') ?>">Videos</a>
      </nav>
    </div>
  </div>

  <div class="ctr-search-wrap">
    <div class="ctr-shell ctr-search-inner">
      <form class="ctr-search-form" action="<?= htmlspecialchars($siteBase . '/search', ENT_QUOTES, 'UTF-8') ?>" method="get" role="search">
        <input class="ctr-search-input" name="q" type="search" placeholder="Search Cite Them Right" />
        <button class="ctr-search-btn" type="submit" aria-label="Search">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2"/>
            <path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
      </form>
    </div>
  </div>
</header>
