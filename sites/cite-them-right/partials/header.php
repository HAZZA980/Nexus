<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>CTR Header (Closer Match)</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">

  <style>
    :root{
      --text:#2f2f2f;
      --rule:#d6d6d6;
      --bar:#efefef;
      --active:#1f3b87;        /* deep blue underline */
      --btn:#7e879d;           /* slate button */
      --btn-hover:#6e7586;
      --field:#ffffff;
      --field-border:#cfcfcf;
      --link:#203d84;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family:"Open Sans", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color:var(--text);
      background:#fff;
    }

    .site-header{
      width:100%;
      border-top:2px solid #222;
      border-bottom:0;
    }

    .header-utility{
      background:#fff;
      border-bottom:1px solid #d9d9d9;
      width:100%;
    }

    .utility-inner{
      max-width:var(--nexus-max-width, 1200px);
      margin:0 auto;
      padding:10px 20px;
      display:flex;
      justify-content:flex-end;
      align-items:center;
      gap:14px;
      color:#222;
      font-size:14px;
      line-height:1.2;
      white-space:nowrap;
    }

    .utility-item{
      display:inline-flex;
      align-items:center;
      gap:8px;
    }

    .utility-icon{
      color:#9a9a9a;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      line-height:0;
    }

    .utility-sep{
      width:1px;
      height:20px;
      background:var(--rule);
    }

    .utility-link{
      color:var(--link);
      text-decoration:none;
      font-weight:600;
    }

    .utility-help{
      display:inline-grid;
      place-items:center;
      width:20px;
      height:20px;
      border-radius:999px;
      background:var(--link);
      color:#fff;
      font-size:12px;
      font-weight:700;
      line-height:1;
    }

    /* TOP (logo + nav) */
    .header-top{
      background:#fff;
      border-bottom:1px solid #e6e6e6;
      width:100%;
    }

    .header-inner{
      max-width:var(--nexus-max-width, 1200px);
      margin:0 auto;
      padding:14px 20px 0;
      display:grid;
      grid-template-columns: 300px 1fr;
      align-items:end;
      column-gap:12px;
    }

    .brand{
      display:flex;
      align-items:flex-end;
      padding-bottom:10px;
    }
    .brand img{
      display:block;
      height:56px;
      width:auto;
    }

    nav{
      justify-self:start;
      width:100%;
    }

    .nav{
      display:flex;
      justify-content:flex-start;
      align-items:flex-end;
      gap:0;
      margin:0;
      padding:0;
      list-style:none;
    }

    .nav li{
      position:relative;
      padding:0 20px;
    }

    /* separators between items */
    .nav li + li::before{
      content:"";
      position:absolute;
      left:0;
      bottom:18px;
      width:1px;
      height:19px;
      background:var(--rule);
    }

    .nav a{
      display:inline-block;
      padding:12px 4px 18px;   /* gives room for underline */
      font-size:15px;
      color:var(--text);
      text-decoration:none;
      white-space:nowrap;
    }

    /* active underline like the original */
    .nav a.is-active{
      position:relative;
    }
    .nav a.is-active::after{
      content:"";
      position:absolute;
      left:0;
      right:0;
      bottom:0;
      height:4px;
      background:var(--active);
    }

    /* subtle hover */
    .nav a:hover{
      color:#1a1a1a;
    }

    /* SEARCH ROW */
    .header-search{
      background:var(--bar);
      width:100%;
    }

    .search-inner{
      max-width:var(--nexus-max-width, 1200px);
      margin:0 auto;
      padding:14px 20px 18px;
      display:flex;
      justify-content:center;
    }

    .search-form{
      width:100%;
      display:flex;
      align-items:stretch;
    }

    .search-input{
      flex:1;
      height:56px;
      padding:0 24px;
      border:1px solid var(--field-border);
      border-right:none;
      border-top-left-radius:28px;
      border-bottom-left-radius:28px;
      outline:none;
      font-size:13px;
      background:var(--field);
    }
    .search-input::placeholder{ color:#6e6e6e; }

    .search-btn{
      width:84px;
      height:56px;
      border:none;
      background:var(--btn);
      color:#fff;
      cursor:pointer;
      border-top-right-radius:28px;
      border-bottom-right-radius:28px;
      display:grid;
      place-items:center;
    }
    .search-btn:hover{ background:var(--btn-hover); }

    .advanced-link{
      display:inline-flex;
      align-items:center;
      margin-left:20px;
      color:var(--link);
      font-size:13px;
      font-weight:600;
      text-decoration:none;
      white-space:nowrap;
    }

    /* Responsive: remove the "centering columns" when tight */
    @media (max-width: 900px){
      .header-utility{ display:none; }
      .header-inner{
        grid-template-columns: 1fr;
        padding:16px 16px 0;
        row-gap:6px;
      }
      nav{ justify-self:start; }
      .nav{ justify-content:flex-start; flex-wrap:wrap; }
      .nav li{ padding:0 10px; }
      .nav li + li::before{ bottom:14px; height:16px; }
      .nav a{ font-size:17px; padding:10px 4px 14px; }
      .brand{ padding-bottom:4px; }
      .brand img{ height:52px; }
      .search-inner{ padding:12px 16px 14px; }
      .search-form{ width:100%; }
      .search-input{ height:46px; font-size:17px; padding:0 16px; border-top-left-radius:23px; border-bottom-left-radius:23px; }
      .search-btn{ height:46px; width:62px; border-top-right-radius:23px; border-bottom-right-radius:23px; }
      .advanced-link{ display:none; }
    }
  </style>
</head>

<body>
  <header class="site-header">
    <?php
      $isSignedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
      $signedInName = trim((string)($_SESSION['user_name'] ?? ''));
      if ($signedInName === '') $signedInName = 'Account';
      $returnPath = $_SERVER['REQUEST_URI'] ?? '/';
      $basePrefix = rtrim((string)($base ?? (function_exists('base_path') ? base_path() : '')), '/');
      $siteSlug = (string)($safeSlug ?? ($site['slug'] ?? 'cite-them-right'));
      $siteBase = $basePrefix . '/s/' . rawurlencode($siteSlug);
      $pageSlug = (string)($page['slug'] ?? '');
      $signInHref = rtrim((string)($base ?? (function_exists('base_path') ? base_path() : '')), '/') . '/login.php?mode=login&return=' . urlencode($returnPath);
      $logoutHref = $basePrefix . '/logout.php?return=' . urlencode($returnPath);
    ?>
    <div class="header-utility">
      <div class="utility-inner">
        <span class="utility-item">
          <span class="utility-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
              <path d="M3 20h18M5 20v-7m4 7v-7m4 7v-7m4 7v-7M2 10h20M12 4l10 6H2l10-6Z" stroke="#9a9a9a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
          <span>Access Provided by Bloomsbury</span>
          <span class="utility-icon" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
              <path d="m6 9 6 6 6-6" stroke="#9a9a9a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </span>
        </span>
        <span class="utility-sep" aria-hidden="true"></span>
        <span class="utility-item">
          <?php if ($isSignedIn): ?>
            <span>Signed in as <?= htmlspecialchars($signedInName, ENT_QUOTES, 'UTF-8') ?></span>
            <a href="<?= htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8') ?>" class="utility-link">Logout</a>
          <?php else: ?>
            <a href="<?= htmlspecialchars($signInHref, ENT_QUOTES, 'UTF-8') ?>" class="utility-link">Sign in</a>
            <span>to your personal or admin account</span>
            <span class="utility-help">?</span>
          <?php endif; ?>
        </span>
      </div>
    </div>
    <div class="header-top">
      <div class="header-inner">
        <a class="brand" href="<?= htmlspecialchars($siteBase . '/home', ENT_QUOTES, 'UTF-8') ?>" aria-label="Cite Them Right">
          <img
            src="https://res.cloudinary.com/bloomsbury-publishing-public/image/upload/f_auto%2Cq_auto/CTRCOL/citethemrightlogo.png"
            alt="Bloomsbury Cite Them Right"
          />
        </a>

        <nav aria-label="Primary">
          <ul class="nav" id="primaryNav">
            <li><a class="<?= ($pageSlug === 'home' || $pageSlug === 'home-signed-in') ? 'is-active' : '' ?>" href="<?= htmlspecialchars($siteBase . '/home', ENT_QUOTES, 'UTF-8') ?>">Home</a></li>
            <li><a class="<?= $pageSlug === 'referencing-styles' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($siteBase . '/referencing-styles', ENT_QUOTES, 'UTF-8') ?>">Choose Referencing Style</a></li>
            <li><a class="<?= $pageSlug === 'browse-categories' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($siteBase . '/browse-categories', ENT_QUOTES, 'UTF-8') ?>">Browse Categories</a></li>
            <li><a href="/basics-of-referencing">Basics of Referencing</a></li>
            <li><a href="/tutorial">Tutorial</a></li>
            <li><a href="/videos">Videos</a></li>
          </ul>
        </nav>
      </div>
    </div>

    <div class="header-search">
      <div class="search-inner">
        <form class="search-form" id="searchForm" role="search">
          <input class="search-input" id="q" name="q" type="search" placeholder="Search Cite Them Right" />
          <button class="search-btn" type="submit" aria-label="Search">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2"/>
              <path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
          <a href="#" class="advanced-link">Advanced Search</a>
        </form>
      </div>
    </div>
  </header>

  <script>
    // Click-to-activate underline (optional, but matches the "Home" underline behavior)
    const nav = document.getElementById("primaryNav");
    nav.addEventListener("click", (e) => {
      const a = e.target.closest("a");
      if (!a) return;
      nav.querySelectorAll("a").forEach(x => x.classList.remove("is-active"));
      a.classList.add("is-active");
    });

    // Minimal demo submit handler
    document.getElementById("searchForm").addEventListener("submit", (e) => {
      e.preventDefault();
      const q = document.getElementById("q").value.trim();
      if (!q) return;
      console.log("Search:", q);
    });
  </script>
</body>
</html>
