<?php
use NexusCMS\Core\DB;
use NexusCMS\Core\Security;

if (!function_exists('base_path')) {
  require_once __DIR__ . '/../../app/bootstrap.php';
}

$base = $base ?? base_path();
$nav = $activeNav ?? '';
if ($nav === '') {
  $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
  if (strpos($path, '/admin/users') !== false) $nav = 'users';
  elseif (strpos($path, '/admin/images') !== false) $nav = 'images';
  elseif (strpos($path, '/admin/databases') !== false || strpos($path, '/admin/database_') !== false) $nav = 'databases';
  else $nav = 'sites';
}

$currentUser = $currentUser ?? null;
if (!$currentUser && isset($_SESSION['user_id'])) {
  try {
    $stmt = DB::pdo()->prepare("SELECT id, email, display_name, role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();
  } catch (\Throwable $e) {
    $currentUser = null;
  }
}

$userLabel = trim((string)($currentUser['display_name'] ?? $currentUser['email'] ?? $_SESSION['username'] ?? 'Administrator'));
if ($userLabel === '') $userLabel = 'Administrator';

$rawRole = strtolower(trim((string)($currentUser['role'] ?? $_SESSION['role'] ?? '')));
$roleMap = [
  'super_admin' => 'Super Admin',
  'website_admin' => 'Website Admin',
  'editor' => 'Editor',
  'institution_admin' => 'Institution Admin',
  'student' => 'Student',
  'admin' => 'Website Admin',
  'staff_admin' => 'Website Admin',
  'user_admin' => 'Institution Admin',
  'viewer' => 'Student',
];
$topbarRoleLabel = $roleMap[$rawRole] ?? ($rawRole !== '' ? ucwords(str_replace('_', ' ', $rawRole)) : 'Administrator');
$themeEndpoint = $base . '/admin/theme.php';
$csrfToken = Security::csrfToken();
?>
<aside class="nx-admin-sidebar" aria-label="Primary navigation">
  <a class="nx-admin-brand" href="<?= $base ?>/admin/index.php">NexusCMS</a>

  <div class="nx-admin-nav-label">Content Management</div>
  <a class="nx-admin-nav-link <?= $nav === 'sites' ? 'active' : '' ?>" href="<?= $base ?>/admin/index.php">Sites</a>
  <a class="nx-admin-nav-link <?= $nav === 'users' ? 'active' : '' ?>" href="<?= $base ?>/admin/users.php">Users</a>
  <a class="nx-admin-nav-link <?= $nav === 'images' ? 'active' : '' ?>" href="<?= $base ?>/admin/images.php">Media</a>
  <a class="nx-admin-nav-link <?= $nav === 'databases' ? 'active' : '' ?>" href="<?= $base ?>/admin/databases.php">Databases</a>

  <div class="nx-admin-nav-label">Create</div>
  <a class="nx-admin-nav-link" href="<?= $base ?>/admin/site_new.php">New Site</a>
</aside>

<header class="nx-admin-topbar" role="banner">
  <div class="nx-admin-top-title">
    <span>Admin Dashboard</span>
    <span class="nx-admin-top-role"><?= Security::e($topbarRoleLabel) ?></span>
  </div>
  <div class="nx-admin-top-actions">
    <details class="nx-user-menu" id="nxUserMenu">
      <summary aria-haspopup="menu" aria-label="Open account menu">
        <span class="nx-user-label"><?= Security::e($userLabel) ?></span>
        <span class="nx-user-arrow" aria-hidden="true">▾</span>
      </summary>
      <div class="nx-user-dropdown" role="menu">
        <a role="menuitem" href="<?= $base ?>/admin/users.php">
          <span class="nx-menu-icon" aria-hidden="true">⚙</span>
          <span>Settings</span>
        </a>
        <button type="button" id="nxThemeToggle" role="menuitem">
          <span class="nx-menu-icon" aria-hidden="true">◐</span>
          <span id="nxThemeToggleLabel">Dark mode</span>
        </button>
        <a class="logout" role="menuitem" href="<?= $base ?>/admin/logout.php">
          <span class="nx-menu-icon" aria-hidden="true">↪</span>
          <span>Logout</span>
        </a>
      </div>
    </details>
  </div>
</header>

<script>
  document.body.classList.add('nx-admin-layout');
  (function () {
    var root = document.documentElement;
    var menu = document.getElementById('nxUserMenu');
    var toggle = document.getElementById('nxThemeToggle');
    var label = document.getElementById('nxThemeToggleLabel');
    var endpoint = <?= json_encode($themeEndpoint, JSON_UNESCAPED_SLASHES) ?>;
    var csrf = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;

    function currentTheme() {
      return root.classList.contains('theme-light') ? 'light' : 'dark';
    }

    function updateLabel() {
      if (!label) return;
      label.textContent = currentTheme() === 'light' ? 'Dark mode' : 'Light mode';
    }

    function persistTheme(mode) {
      try { localStorage.setItem('nexusTheme', mode); } catch (e) {}
      try {
        fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ mode: mode, _csrf: csrf })
        });
      } catch (e) {}
    }

    updateLabel();

    toggle?.addEventListener('click', function (e) {
      e.preventDefault();
      var next = currentTheme() === 'light' ? 'dark' : 'light';
      root.classList.toggle('theme-light', next === 'light');
      updateLabel();
      persistTheme(next);
      if (menu) menu.open = false;
    });

    document.addEventListener('click', function (e) {
      if (!menu || !menu.open) return;
      if (!menu.contains(e.target)) menu.open = false;
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu && menu.open) menu.open = false;
    });
  })();
</script>
