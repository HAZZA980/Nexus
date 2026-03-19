<?php
$config = require __DIR__ . '/config.php';

session_name($config['app']['session_name']);
session_start();

spl_autoload_register(function ($class) {
  $prefix = 'NexusCMS\\';
  $baseDir = __DIR__ . '/src/';
  if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
  $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (file_exists($file)) require $file;
});

use NexusCMS\Core\DB;
DB::init($config['db']);

function base_path(): string {
  return rtrim((require __DIR__ . '/config.php')['app']['base_path'], '/');
}

function redirect(string $path): void {
  header('Location: ' . base_path() . $path);
  exit;
}

function json_response($data, int $status=200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function require_admin(): void {
  $uid = $_SESSION['user_id'] ?? null;
  $role = $_SESSION['user_role'] ?? '';
  // Canonical hierarchy:
  // student < institution_admin < editor < website_admin < super_admin
  // Legacy roles remain accepted for backward compatibility.
  $allowedRoles = ['super_admin', 'website_admin', 'editor', 'institution_admin', 'admin', 'staff_admin', 'user_admin'];
  if (!$uid || !in_array($role, $allowedRoles, true)) {
    $return = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    redirect('/login.php?return=' . urlencode($return));
  }
}

function establish_user_session(array $user): void {
  $uid = (int)($user['id'] ?? 0);
  $role = (string)($user['role'] ?? '');
  if ($uid <= 0) return;
  $_SESSION['user_id'] = $uid;
  $_SESSION['user_role'] = $role;
  $_SESSION['user_name'] = (string)($user['display_name'] ?? '');
  $_SESSION['site_access'] = \NexusCMS\Models\User::siteAccess($uid, $role);
}

function ui_theme_mode(): string {
  $mode = $_SESSION['ui_theme_mode'] ?? 'dark';
  return $mode === 'light' ? 'light' : 'dark';
}

function ui_theme_is_light(): bool {
  return ui_theme_mode() === 'light';
}
