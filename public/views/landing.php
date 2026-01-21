<?php
use NexusCMS\Core\Security;
$base = base_path();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>NexusCMS — Website Hub</title>
  <link rel="stylesheet" href="<?= $base ?>/public/assets/site.css">
</head>
<body class="landing">
  <div class="landing-shell">
    <header class="landing-top">
      <div class="landing-brand">
        <div class="logo-dot"></div>
        <div>
          <div class="brand-title">NexusCMS</div>
          <div class="brand-sub">Staff website console</div>
        </div>
      </div>
      <div class="landing-actions">
        <a class="ghost-link" href="<?= $base ?>/admin/">Admin</a>
        <a class="pill-btn" href="<?= $base ?>/admin/site_new.php">Create website</a>
      </div>
    </header>

    <main class="landing-main">
      <section class="hero">
        <div>
          <p class="eyebrow">Web portfolio</p>
          <h1>All websites on this architecture</h1>
          <p class="lede">Browse, preview, and launch. Staff-only access keeps your workspace focused.</p>
          <div class="hero-cta">
            <a class="pill-btn" href="<?= $base ?>/admin/site_new.php">Create website</a>
            <a class="ghost-link" href="<?= $base ?>/admin/">Go to admin</a>
          </div>
        </div>
        <div class="hero-card">
          <div class="hero-card-title">At a glance</div>
          <div class="hero-card-metrics">
            <div>
              <div class="metric-number"><?= count($sites) ?></div>
              <div class="metric-label">Websites</div>
            </div>
            <div>
              <div class="metric-number">Preview</div>
              <div class="metric-label">Live snapshots</div>
            </div>
          </div>
        </div>
      </section>

      <?php if (!$sites): ?>
        <div class="empty-state">
          <div class="empty-pill">No websites yet</div>
          <p>Start your first site and it will appear here with a snapshot and quick actions.</p>
          <a class="pill-btn" href="<?= $base ?>/admin/site_new.php">Create website</a>
        </div>
      <?php else: ?>
        <div class="site-list">
          <?php
            $swatches = [
              'linear-gradient(135deg,#7c3aed,#4338ca)',
              'linear-gradient(135deg,#2563eb,#1d4ed8)',
              'linear-gradient(135deg,#16a34a,#15803d)',
              'linear-gradient(135deg,#db2777,#be185d)'
            ];
          ?>
          <?php foreach ($sites as $i => $s): ?>
            <?php
              $bg = $swatches[$i % count($swatches)];
              $name = Security::e($s['name']);
              $slug = Security::e($s['slug']);
              $desc = Security::e($s['description'] ?? 'A NexusCMS website ready to publish.');
              $initial = mb_strtoupper(mb_substr($name, 0, 1));
            ?>
            <article class="site-card">
              <div class="site-shot" style="background:<?= $bg ?>;">
                <div class="shot-glow"></div>
                <div class="shot-content">
                  <div class="shot-dot"></div>
                  <div class="shot-title"><?= $initial ?></div>
                  <div class="shot-sub">Snapshot</div>
                </div>
              </div>
              <div class="site-info">
                <div class="site-head">
                  <div>
                    <div class="site-name"><?= $name ?></div>
                    <div class="site-slug">/<?= $slug ?></div>
                  </div>
                  <div class="site-actions">
                    <a class="ghost-link" href="<?= $base ?>/admin/site.php?id=<?= (int)$s['id'] ?>">Manage</a>
                    <a class="pill-btn small" href="<?= $base ?>/s/<?= $slug ?>/home" target="_blank">Open</a>
                  </div>
                </div>
                <p class="site-desc"><?= $desc ?></p>
                <div class="site-tags">
                  <span class="tag">Published page: /home</span>
                  <span class="tag">Staff access only</span>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </main>
  </div>
</body>
</html>
