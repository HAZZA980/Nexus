<?php
use NexusCMS\Core\DB;
use NexusCMS\Core\Security;

if (!function_exists('base_path')) {
  require_once __DIR__ . '/../../app/bootstrap.php';
}

$base = $base ?? base_path();
$nav = $activeNav ?? '';

// Fetch current user if not provided
if (!isset($currentUser) && isset($_SESSION['user_id'])) {
  try {
    $stmt = DB::pdo()->prepare("SELECT id, email, display_name, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();
  } catch (\Throwable $e) {
    $currentUser = null;
  }
}

$userInitial = 'U';
$userLabel = 'User';
$userRole = '';
if ($currentUser) {
  $name = $currentUser['display_name'] ?? $currentUser['email'] ?? 'User';
  $userInitial = strtoupper(mb_substr($name, 0, 1));
  $userLabel = $name;
  $userRole = $currentUser['role'] ?? '';
}

$uiTheme = ui_theme_mode();
$themeIsLight = $uiTheme === 'light';
$themeEndpoint = $base . '/admin/theme.php';
$csrfToken = Security::csrfToken();
?>
<header class="top-bar" role="banner">
  <div class="brand" aria-label="NexusCMS">
    <div class="brand-mark" aria-hidden="true">N</div>
    <div class="brand-text">
      <span>NexusCMS</span>
      <small>Admin</small>
    </div>
  </div>
  <nav class="top-nav" aria-label="Admin navigation">
    <a class="nav-link <?= $nav === 'sites' ? 'active' : '' ?>" href="<?= $base ?>/admin/index.php">Sites</a>
    <a class="nav-link <?= $nav === 'databases' ? 'active' : '' ?>" href="<?= $base ?>/admin/databases.php">Databases</a>
    <a class="nav-link <?= $nav === 'users' ? 'active' : '' ?>" href="<?= $base ?>/admin/users.php">Users</a>
    <a class="nav-link <?= $nav === 'images' ? 'active' : '' ?>" href="<?= $base ?>/admin/images.php">Images</a>
  </nav>
  <div class="top-actions">
    <a class="btn primary" href="<?= $base ?>/admin/site_new.php">+ Create new website</a>
    <div class="user-menu">
      <details>
        <summary aria-haspopup="menu">
          <span class="user-avatar" aria-hidden="true"><?= Security::e($userInitial) ?></span>
          <span>
            <?= Security::e($userLabel) ?>
            <?php if ($userRole): ?>
              <small style="display:block;color:var(--muted);font-weight:500;"><?= Security::e(ucfirst((string)$userRole)) ?></small>
            <?php endif; ?>
          </span>
        </summary>
        <div class="menu" role="menu">
          <div class="user-meta">Logged in <?= Security::e($currentUser['email'] ?? 'user') ?></div>
          <button type="button" class="theme-toggle" id="themeToggleBtn" role="menuitem">Switch theme</button>
          <a role="menuitem" href="<?= $base ?>/admin/logout.php">Logout</a>
        </div>
      </details>
    </div>
  </div>
</header>
<script>
  (function() {
    var root = document.documentElement;
    var btn = document.getElementById('themeToggleBtn');
    var userMenuDetails = document.querySelector('.user-menu details');
    var endpoint = <?= json_encode($themeEndpoint, JSON_UNESCAPED_SLASHES) ?>;
    var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;

    function setTheme(mode) {
      var next = mode === 'light' ? 'light' : 'dark';
      root.classList.toggle('theme-light', next === 'light');
      try { localStorage.setItem('nexusTheme', next); } catch (e) {}
      if (btn) {
        btn.dataset.mode = next;
        btn.textContent = next === 'light' ? 'Switch to dark' : 'Switch to light';
      }
      return next;
    }

    function persistTheme(mode) {
      if (!endpoint) return;
      try {
        fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf
          },
          body: JSON.stringify({ mode: mode, _csrf: csrf })
        });
      } catch (e) {}
    }

    setTheme(<?= $themeIsLight ? "'light'" : "'dark'" ?>);
    window.NexusTheme = {
      setTheme: function(mode) {
        var next = setTheme(mode);
        persistTheme(next);
        return next;
      },
      getTheme: function() {
        return root.classList.contains('theme-light') ? 'light' : 'dark';
      }
    };

    if (btn) {
      btn.addEventListener('click', function() {
        var next = root.classList.contains('theme-light') ? 'dark' : 'light';
        window.NexusTheme.setTheme(next);
      });
    }

    if (userMenuDetails) {
      var closeUserMenu = function() {
        if (userMenuDetails.open) userMenuDetails.open = false;
      };
      document.addEventListener('click', function(event) {
        if (!userMenuDetails.open) return;
        if (!userMenuDetails.contains(event.target)) {
          closeUserMenu();
        }
      });
      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && userMenuDetails.open) {
          closeUserMenu();
        }
      });
      document.querySelectorAll('iframe').forEach(function(frame) {
        frame.addEventListener('pointerdown', closeUserMenu, true);
        frame.addEventListener('focus', closeUserMenu, true);
        frame.addEventListener('load', function() {
          try {
            var fd = frame.contentDocument;
            if (fd) {
              fd.addEventListener('pointerdown', closeUserMenu, true);
              fd.addEventListener('focusin', closeUserMenu, true);
            }
          } catch (e) {}
        });
      });
    }
  })();
</script>
