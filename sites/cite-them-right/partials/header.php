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
      --btn:#7b8293;           /* slate button */
      --btn-hover:#6e7586;
      --field:#ffffff;
      --field-border:#cfcfcf;
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
      border-bottom:1px solid #e6e6e6;
    }

    /* TOP (logo + nav) */
    .header-top{
      background:#fff;
      border-bottom:1px solid #e6e6e6;
    }

    .header-inner{
      max-width:1200px;
      margin:0 auto;
      padding:18px 20px 0;
      display:grid;
      grid-template-columns: 240px 1fr 200px; /* keeps nav centered with space for login */
      align-items:end;
    }

    .brand{
      display:flex;
      align-items:flex-end;
      padding-bottom:10px;
    }
    .brand img{
      display:block;
      height:48px;
      width:auto;
    }

    nav{
      justify-self:center;
      width:100%;
    }

    .nav{
      display:flex;
      justify-content:center;
      align-items:flex-end;
      gap:0;
      margin:0;
      padding:0;
      list-style:none;
    }

    .nav li{
      position:relative;
      padding:0 12px;
    }

    /* separators between items */
    .nav li + li::before{
      content:"";
      position:absolute;
      left:0;
      bottom:16px;
      width:1px;
      height:18px;
      background:var(--rule);
    }

    .nav a{
      display:inline-block;
      padding:10px 4px 14px;   /* gives room for underline */
      font-size:13px;
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
      bottom:-1px;            /* sits on the header divider */
      height:3px;
      background:var(--active);
    }

    /* subtle hover */
    .nav a:hover{
      color:#1a1a1a;
    }

    .login-wrap{display:flex;justify-content:flex-end;align-items:center;padding-bottom:10px;}
    .login-btn{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:10px 14px;
      border-radius:12px;
      border:1px solid #1f3b87;
      color:#1f3b87;
      background:#fff;
      font-weight:700;
      text-decoration:none;
      transition:all .15s ease;
    }
    .login-btn:hover{background:#f1f4ff;}
    .user-dd{position:relative;}
    .user-dd summary{
      list-style:none;
      cursor:pointer;
      border:1px solid #1f3b87;
      border-radius:12px;
      padding:10px 12px;
      font-weight:700;
      color:#1f3b87;
      background:#fff;
    }
    .user-dd[open] summary{background:#f1f4ff;}
    .user-dd-menu{
      position:absolute;
      right:0;
      margin-top:6px;
      background:#fff;
      border:1px solid #d1d5db;
      border-radius:12px;
      box-shadow:0 8px 24px rgba(0,0,0,0.15);
      padding:10px;
      min-width:180px;
      display:grid;
      gap:6px;
      z-index:20;
    }
    .user-dd-menu button{
      width:100%;
      text-align:left;
      border:1px solid #e5e7eb;
      background:#f8fafc;
      border-radius:10px;
      padding:8px 10px;
      cursor:pointer;
    }

    /* SEARCH ROW */
    .header-search{
      background:var(--bar);
    }

    .search-inner{
      max-width:1200px;
      margin:0 auto;
      padding:14px 20px 16px;
      display:flex;
      justify-content:center;
    }

    .search-form{
      width:min(860px, 100%);
      display:flex;
      align-items:stretch;
    }

    .search-input{
      flex:1;
      height:42px;                      /* closer to original proportions */
      padding:0 18px;
      border:1px solid var(--field-border);
      border-right:none;
      border-top-left-radius:24px;
      border-bottom-left-radius:24px;
      outline:none;
      font-size:13.5px;
      background:var(--field);
    }
    .search-input::placeholder{ color:#6e6e6e; }

    .search-btn{
      width:64px;
      height:42px;
      border:none;
      background:var(--btn);
      color:#fff;
      cursor:pointer;
      border-top-right-radius:24px;
      border-bottom-right-radius:24px;
      display:grid;
      place-items:center;
    }
    .search-btn:hover{ background:var(--btn-hover); }

    /* Responsive: remove the "centering columns" when tight */
    @media (max-width: 900px){
      .header-inner{
        grid-template-columns: 1fr;
        padding:16px 16px 0;
        row-gap:6px;
      }
      nav{ justify-self:start; }
      .nav{ justify-content:flex-start; flex-wrap:wrap; }
      .nav li{ padding:0 10px; }
      .nav li + li::before{ bottom:14px; height:16px; }
      .brand{ padding-bottom:4px; }
      .search-inner{ padding:12px 16px 14px; }
    }
  </style>
</head>

<body>
  <?php
    $currentUser = null;
    if (!empty($_SESSION['user_id'])) {
      try {
        $currentUser = \NexusCMS\Models\User::findById((int)$_SESSION['user_id']);
      } catch (\Throwable $e) {
        $currentUser = null;
      }
    }
  ?>
  <header class="site-header">
    <div class="header-top">
      <div class="header-inner">
        <a class="brand" href="#" aria-label="Cite Them Right">
          <img
            src="https://res.cloudinary.com/bloomsbury-publishing-public/image/upload/f_auto%2Cq_auto/CTRCOL/citethemrightlogo.png"
            alt="Bloomsbury Cite Them Right"
          />
        </a>

        <nav aria-label="Primary">
          <ul class="nav" id="primaryNav">
            <li><a class="is-active" href="#">Home</a></li>
            <li><a href="#">Choose Referencing Style</a></li>
            <li><a href="#">Browse Categories</a></li>
            <li><a href="#">Basics of Referencing</a></li>
            <li><a href="#">Tutorial</a></li>
            <li><a href="#">Videos</a></li>
          </ul>
        </nav>

        <div class="login-wrap">
          <?php if ($currentUser): ?>
            <details class="user-dd">
              <summary><?= htmlspecialchars($currentUser['display_name'] ?: $currentUser['email'], ENT_QUOTES, 'UTF-8') ?> ▾</summary>
              <div class="user-dd-menu">
                <button type="button" data-action="toggle-dark">Dark mode (soon)</button>
                <form method="post" action="<?= base_path() ?>/admin/logout.php">
                  <button type="submit">Log out</button>
                </form>
              </div>
            </details>
          <?php else: ?>
            <?php
              $returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? '/');
              $loginHref = rtrim(base_path(), '/') . "/site-login.php?site=cite-them-right&return={$returnUrl}";
            ?>
            <a class="login-btn" href="<?= htmlspecialchars($loginHref, ENT_QUOTES, 'UTF-8') ?>">Login</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="header-search">
      <div class="search-inner">
        <form class="search-form" id="searchForm" role="search">
          <input class="search-input" id="q" name="q" type="search" placeholder="Search Cite Them Right" />
          <button class="search-btn" type="submit" aria-label="Search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2"/>
              <path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
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
