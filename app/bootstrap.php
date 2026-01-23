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
  $allowedRoles = ['admin', 'super_admin', 'staff_admin', 'user_admin'];
  if (!$uid || !in_array($role, $allowedRoles, true)) {
    $return = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    redirect('/login.php?return=' . urlencode($return));
  }
}
