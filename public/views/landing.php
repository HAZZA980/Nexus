<?php
use NexusCMS\Core\Security;
use NexusCMS\Core\DB;
$base = base_path();

$stats = [
  'total' => count($sites),
  'live' => 0,
  'draft' => 0,
  'disabled' => 0,
  'published_pages' => 0,
];
$siteRows = [];
foreach ($sites as $s) {
  $status = strtolower(trim((string)($s['status'] ?? '')));
  if (isset($stats[$status])) $stats[$status] += 1;

  $domain = trim((string)($s['domain'] ?? $s['primary_domain'] ?? ''));
  $slug = trim((string)($s['slug'] ?? ''));
  $domainDisplay = $domain !== '' ? $domain : ($slug !== '' ? '/s/' . $slug : '—');
  $domainUrl = $domain !== '' ? (preg_match('~^https?://~i', $domain) ? $domain : 'https://' . $domain) : ($slug !== '' ? $base . '/s/' . $slug . '/home' : $base . '/');

  $last = $s['updated_at'] ?? $s['created_at'] ?? null;
  $stats['published_pages'] += (int)($s['_published'] ?? 0);
  $siteRows[] = [
    'id' => (int)$s['id'],
    'name' => $s['name'] ?: 'Untitled site',
    'slug' => $slug,
    'status' => $status !== '' ? $status : 'live',
    'domain_display' => $domainDisplay,
    'domain_url' => $domainUrl,
    'updated' => $last ?: '',
  ];
}

$recentSites = $siteRows;
usort($recentSites, fn($a,$b) => strcmp((string)$b['updated'], (string)$a['updated']));
$recentSites = array_slice($recentSites, 0, 3);

$userCounts = ['total' => 0];
try {
  $rs = DB::pdo()->query("SELECT role, COUNT(*) c FROM users GROUP BY role");
  foreach ($rs as $row) {
    $role = strtolower(trim((string)$row['role']));
    $userCounts['total'] += (int)$row['c'];
    $userCounts[$role] = (int)$row['c'];
  }
} catch (\Throwable $e) {}

$mediaCount = 0;
$mediaSize = 0;
$uploadDir = __DIR__ . '/../uploads';
if (is_dir($uploadDir)) {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, \FilesystemIterator::SKIP_DOTS));
  foreach ($files as $file) {
    if ($file->isFile()) { $mediaCount++; $mediaSize += $file->getSize(); }
  }
}
$mediaSizeMb = $mediaSize > 0 ? round($mediaSize/1024/1024, 1) : 0;

$recentPages = [];
try {
  $stmt = DB::pdo()->prepare("SELECT p.title, p.slug, p.updated_at, s.id as site_id, s.name as site_name FROM pages p JOIN sites s ON p.site_id = s.id ORDER BY p.updated_at DESC LIMIT 6");
  $stmt->execute();
  $recentPages = $stmt->fetchAll();
} catch (\Throwable $e) {}

$env = (stripos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false) ? 'Local' : 'Production';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NexusCMS — Sites</title>
  <script>
    (function() {
      try {
        const stored = localStorage.getItem('nexusTheme');
        const theme = stored === 'light' ? 'light' : 'dark';
        document.documentElement.classList.toggle('theme-light', theme === 'light');
      } catch(e) {}
    })();
  </script>
  <style>
    :root {
      --bg: #0b1020;
      --panel: #0f172a;
      --surface: #111827;
      --border: #1e293b;
      --muted: #94a3b8;
      --text: #e2e8f0;
      --primary: #7c3aed;
      --primary-strong: #5b21b6;
      --radius: 10px;
      --focus: 0 0 0 2px rgba(124,58,237,0.35);
    }
    .theme-light {
      --bg: #f5f7fb;
      --panel: #ffffff;
      --surface: #ffffff;
      --border: #d6dee9;
      --muted: #4b5563;
      --text: #0f172a;
      --primary: #4f46e5;
      --primary-strong: #3730a3;
      --focus: 0 0 0 2px rgba(79,70,229,0.25);
    }
    *{box-sizing:border-box;}
    body{
      margin:0;
      font-family:"Inter","Helvetica Neue",system-ui,-apple-system,sans-serif;
      background:var(--bg);
      color:var(--text);
      line-height:1.5;
    }
    a{color:inherit;text-decoration:none;}
    a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,summary:focus-visible{
      outline:none;
      box-shadow:var(--focus);
      border-color:var(--primary);
    }
    header.top-bar{display:flex;align-items:center;gap:16px;padding:14px 18px;background:linear-gradient(90deg, rgba(91,33,182,0.12), rgba(91,33,182,0));border-bottom:1px solid var(--border);position:sticky;top:0;backdrop-filter:blur(10px);z-index:10;}
    .brand{display:inline-flex;align-items:center;gap:10px;font-weight:600;}
    .brand-mark{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg, var(--primary), #22c55e);display:grid;place-items:center;font-weight:700;letter-spacing:-0.02em;}
    .brand-text{display:flex;flex-direction:column;line-height:1.2;}
    .brand-text small{color:var(--muted);font-size:12px;font-weight:500;}
    nav.top-nav{display:flex;align-items:center;gap:10px;margin-left:auto;flex-wrap:wrap;}
    nav.top-nav .nav-link{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;min-height:40px;border:1px solid var(--border);border-radius:10px;font-weight:700;background:rgba(255,255,255,0.05);color:var(--text);}
    .nav-link.active{background:linear-gradient(135deg, var(--primary), var(--primary-strong));color:#fff;border-color:transparent;box-shadow:0 10px 30px rgba(0,0,0,0.08);}
    .nav-link:hover{background:rgba(255,255,255,0.1);}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;min-height:44px;border-radius:12px;border:1px solid var(--border);background:rgba(255,255,255,0.06);color:var(--text);font-weight:700;cursor:pointer;}
    .btn.primary{background:linear-gradient(135deg, var(--primary), var(--primary-strong));border:none;color:#f8fbff;box-shadow:0 10px 30px rgba(37,99,235,0.35);}
    .btn:hover{background:rgba(255,255,255,0.1);}
    .user-menu{position:relative;min-width:180px;}
    .user-menu summary{
      list-style:none;cursor:pointer;
      display:inline-flex;align-items:center;gap:10px;
      padding:10px 12px;min-height:38px;
      border-radius:8px;border:1px solid var(--border);background:var(--panel);font-weight:600;
    }
    .user-menu summary::-webkit-details-marker{display:none;}
    .user-avatar{width:32px;height:32px;border-radius:8px;background:var(--primary);display:grid;place-items:center;color:#fff;font-weight:700;}
    .user-menu .menu{position:absolute;right:0;top:calc(100% + 6px);background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:8px;min-width:200px;z-index:5;}
    .user-menu .menu a,.user-menu .menu button{display:block;width:100%;text-align:left;padding:10px;border-radius:8px;border:none;background:transparent;color:var(--text);cursor:pointer;}
    .user-menu .menu a:hover,.user-menu .menu button:hover{background:rgba(255,255,255,0.04);}

    main{max-width:1200px;margin:0 auto;padding:18px 18px 48px;}
    .page-head{display:grid;grid-template-columns:1fr auto;gap:14px;align-items:end;margin:20px 0 12px;}
    .page-head h1{margin:0;font-size:26px;font-weight:800;letter-spacing:-0.02em;}
    .page-head p{margin:4px 0 0;font-size:14px;color:var(--muted);}

    .action-row{display:flex;flex-wrap:wrap;gap:10px;}
    .summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:18px 0 12px;}
    .tile{border:1px solid var(--border);border-radius:12px;padding:14px;background:var(--panel);display:flex;flex-direction:column;gap:6px;box-shadow:0 14px 36px rgba(0,0,0,0.2);}
    .tile .label{font-size:13px;color:var(--muted);}
    .tile .value{font-size:22px;font-weight:800;letter-spacing:-0.02em;}
    .tile .sub{font-size:13px;color:var(--muted);}

    .panel-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin:18px 0;}
    .panel{border:1px solid var(--border);border-radius:12px;padding:14px;background:var(--panel);box-shadow:0 10px 28px rgba(0,0,0,0.18);display:flex;flex-direction:column;gap:10px;}
    .panel h3{margin:0;font-size:16px;letter-spacing:-0.01em;}
    .panel p{margin:0;color:var(--muted);font-size:14px;}
    .pill{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.06);color:var(--text);font-weight:700;text-decoration:none;}
    .pill.primary{background:linear-gradient(135deg,var(--primary),var(--primary-strong));border:none;color:#f8fbff;}
    .list{display:grid;gap:10px;}
    .list-item{border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:rgba(255,255,255,0.03);display:flex;justify-content:space-between;align-items:center;gap:10px;}
    .list-item .meta{color:var(--muted);font-size:13px;}
    .status{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-weight:600;font-size:12px;border:1px solid var(--border);text-transform:capitalize;}
    .status.live{color:#0f5132;background:rgba(34,197,94,0.12);border-color:rgba(34,197,94,0.3);}
    .status.draft{color:#92400e;background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.3);}
    .status.disabled{color:#7f1d1d;background:rgba(239,68,68,0.12);border-color:rgba(239,68,68,0.3);}

    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}

    @media(max-width:720px){
      .page-head{grid-template-columns:1fr;}
      header.top-bar{flex-direction:column;align-items:flex-start;}
    }
  </style>
</head>
<body>
  <header class="top-bar" role="banner">
    <div class="brand" aria-label="NexusCMS">
      <div class="brand-mark" aria-hidden="true">N</div>
      <div class="brand-text">
        <span>NexusCMS</span>
        <small>Sites</small>
      </div>
    </div>
    <nav class="top-nav" aria-label="Admin navigation">
      <a class="nav-link active" href="<?= $base ?>/admin/index.php">Sites</a>
      <a class="nav-link" href="<?= $base ?>/admin/users.php">Users</a>
      <a class="nav-link" href="<?= $base ?>/admin/images.php">Images</a>
      <a class="btn primary" href="<?= $base ?>/admin/site_new.php">+ Create new website</a>
    </nav>
    <div class="user-menu">
      <details>
        <summary aria-haspopup="menu">
          <span class="user-avatar" aria-hidden="true">U</span>
          <span>Admin</span>
        </summary>
        <div class="menu" role="menu">
          <button type="button" class="theme-toggle" id="themeToggleBtn" role="menuitem">Switch theme</button>
          <a role="menuitem" href="<?= $base ?>/admin/logout.php">Logout</a>
        </div>
      </details>
    </div>
  </header>
  <main>
    <div class="page-head">
      <div>
        <h1>NexusCMS dashboard</h1>
        <p>Manage content, users, media, and publishing from one place.</p>
      </div>
      <div class="action-row">
        <a class="btn primary" href="<?= $base ?>/admin/site_new.php">+ Create new website</a>
        <a class="btn" href="<?= $base ?>/admin/users.php">Add user</a>
        <a class="btn" href="<?= $base ?>/admin/images.php">Upload images</a>
      </div>
    </div>

    <div class="summary" aria-label="Overview metrics">
      <div class="tile">
        <div class="label">Sites</div>
        <div class="value"><?= (int)$stats['total']; ?></div>
        <div class="sub">Live <?= (int)$stats['live']; ?> · Draft <?= (int)$stats['draft']; ?> · Disabled <?= (int)$stats['disabled']; ?></div>
      </div>
      <div class="tile">
        <div class="label">Users</div>
        <div class="value"><?= (int)($userCounts['total'] ?? 0); ?></div>
        <div class="sub">Admins <?= (int)($userCounts['admin'] ?? 0); ?> · Super <?= (int)($userCounts['super_admin'] ?? 0); ?></div>
      </div>
      <div class="tile">
        <div class="label">Media</div>
        <div class="value"><?= (int)$mediaCount; ?></div>
        <div class="sub"><?= $mediaSizeMb ?> MB stored</div>
      </div>
      <div class="tile">
        <div class="label">Published pages</div>
        <div class="value"><?= (int)$stats['published_pages']; ?></div>
        <div class="sub">Updated last 7d: <?= count($recentPages); ?></div>
      </div>
      <div class="tile">
        <div class="label">Environment</div>
        <div class="value"><?= Security::e($env); ?></div>
        <div class="sub">Staff access only</div>
      </div>
    </div>

    <div class="panel-grid">
      <section class="panel" aria-labelledby="quick-actions">
        <h3 id="quick-actions">Quick actions</h3>
        <div class="list">
          <div class="list-item">
            <div>
              <strong>Create new website</strong>
              <div class="meta">Start a new site with defaults</div>
            </div>
            <a class="pill primary" href="<?= $base ?>/admin/site_new.php">Create</a>
          </div>
          <div class="list-item">
            <div>
              <strong>Add user</strong>
              <div class="meta">Invite or provision access</div>
            </div>
            <a class="pill" href="<?= $base ?>/admin/users.php">Open Users</a>
          </div>
          <div class="list-item">
            <div>
              <strong>Upload images</strong>
              <div class="meta">Central media library</div>
            </div>
            <a class="pill" href="<?= $base ?>/admin/images.php">Open Images</a>
          </div>
          <div class="list-item">
            <div>
              <strong>View activity</strong>
              <div class="meta">Recent publishes and edits</div>
            </div>
            <a class="pill" href="<?= $base ?>/admin/revision_view.php">Activity</a>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="recent-sites">
        <h3 id="recent-sites">Recent sites</h3>
        <?php if (!$recentSites): ?>
          <p>No sites yet.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach ($recentSites as $s): ?>
              <div class="list-item">
                <div>
                  <strong><?= Security::e($s['name']) ?></strong>
                  <div class="meta"><?= Security::e($s['domain_display']) ?></div>
                </div>
                <div style="text-align:right;display:grid;gap:6px;justify-items:end;">
                  <span class="status <?= Security::e($s['status']) ?>"><?= Security::e($s['status']) ?></span>
                  <a class="pill" href="<?= $base ?>/admin/site.php?id=<?= (int)$s['id'] ?>">Manage</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel" aria-labelledby="recent-updates">
        <h3 id="recent-updates">Recent page updates</h3>
        <?php if (!$recentPages): ?>
          <p>No recent changes.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach ($recentPages as $p): ?>
              <div class="list-item">
                <div>
                  <strong><?= Security::e($p['title'] ?: $p['slug']) ?></strong>
                  <div class="meta"><?= Security::e($p['site_name']) ?> · <?= Security::e($p['updated_at']) ?></div>
                </div>
                <a class="pill" href="<?= $base ?>/admin/site.php?id=<?= (int)($p['site_id'] ?? 0) ?>">Open</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel" aria-labelledby="shortcuts">
        <h3 id="shortcuts">Sections</h3>
        <div class="action-row">
          <a class="pill primary" href="<?= $base ?>/admin/index.php">Sites</a>
          <a class="pill" href="<?= $base ?>/admin/users.php">Users</a>
          <a class="pill" href="<?= $base ?>/admin/images.php">Images</a>
        </div>
      </section>
    </div>
  </main>
  <script>
    (function(){
      const themeBtn = document.getElementById('themeToggleBtn');

      function setTheme(mode){
        if(mode==='light'){
          document.documentElement.classList.add('theme-light');
          if(themeBtn) themeBtn.textContent='Switch to dark';
        }else{
          document.documentElement.classList.remove('theme-light');
          if(themeBtn) themeBtn.textContent='Switch to light';
        }
        try{localStorage.setItem('nexusTheme',mode);}catch(e){}
      }
      if(themeBtn){
        themeBtn.addEventListener('click',()=>{
          const isLight=document.documentElement.classList.contains('theme-light');
          setTheme(isLight?'dark':'light');
        });
        const stored=(()=>{try{return localStorage.getItem('nexusTheme');}catch(e){return null;}})();
        if(stored==='light') setTheme('light'); else setTheme('dark');
      }
    })();
  </script>
</body>
</html>
