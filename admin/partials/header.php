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
