<?php
function load_env_file(string $path): void {
  if (!is_file($path) || !is_readable($path)) return;
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines)) return;

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    if ($key === '' || getenv($key) !== false) continue;
    if (
      (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
      (str_starts_with($value, "'") && str_ends_with($value, "'"))
    ) {
      $value = substr($value, 1, -1);
    }
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
  }
}

load_env_file(dirname(__DIR__) . '/.env');

$config = require __DIR__ . '/config.php';

session_name($config['app']['session_name']);
session_start();

function send_security_headers(): void {
  if (headers_sent()) return;

  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header(
    "Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' https:; " .
    "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
    "img-src 'self' data: https:; " .
    "font-src 'self' data: https://cdnjs.cloudflare.com; " .
    "connect-src 'self' https://api.openalex.org https://universities.hipolabs.com; " .
    "frame-ancestors 'self'; " .
    "base-uri 'self'; " .
    "form-action 'self'"
  );

  $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
  if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  }
}

send_security_headers();

spl_autoload_register(function ($class) {
  $prefix = 'NexusCMS\\';
  $baseDir = __DIR__ . '/src/';
  if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
  $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (file_exists($file)) require $file;
});

use NexusCMS\Core\DB;
DB::init($config['db']);

function app_config(?string $key = null) {
  static $cached = null;
  if ($cached === null) {
    $cached = require __DIR__ . '/config.php';
  }
  if ($key === null || $key === '') return $cached;
  $parts = explode('.', $key);
  $value = $cached;
  foreach ($parts as $part) {
    if (!is_array($value) || !array_key_exists($part, $value)) return null;
    $value = $value[$part];
  }
  return $value;
}

function base_path(): string {
  return rtrim((string)(app_config('app.base_path') ?? ''), '/');
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

const ROLE_LEVELS = [
  'student' => 10,
  'viewer' => 10,
  'institution_admin' => 20,
  'user_admin' => 20,
  'editor' => 30,
  'website_admin' => 40,
  'admin' => 40,
  'staff_admin' => 40,
  'super_admin' => 50,
];

function role_level(?string $role): int {
  $role = strtolower(trim((string)$role));
  return ROLE_LEVELS[$role] ?? 0;
}

function require_admin(): void {
  $uid = $_SESSION['user_id'] ?? null;
  $role = $_SESSION['user_role'] ?? '';
  if (!$uid || role_level($role) < role_level('institution_admin')) {
    $return = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    redirect('/login.php?return=' . urlencode($return));
  }
}

function require_role(string $minimum): void {
  $uid = $_SESSION['user_id'] ?? null;
  $role = $_SESSION['user_role'] ?? '';
  $minimumLevel = role_level($minimum);
  if (!$uid || $minimumLevel <= 0 || role_level($role) < $minimumLevel) {
    http_response_code(403);
    exit('Access denied.');
  }
}

function establish_user_session(array $user): void {
  $uid = (int)($user['id'] ?? 0);
  $role = (string)($user['role'] ?? '');
  if ($uid <= 0) return;
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
  }
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

function login_captcha_question(bool $reset = false): string {
  if ($reset || empty($_SESSION['login_captcha_question']) || !isset($_SESSION['login_captcha_answer'])) {
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $_SESSION['login_captcha_question'] = "{$a} + {$b}";
    $_SESSION['login_captcha_answer'] = (string)($a + $b);
  }
  return (string)$_SESSION['login_captcha_question'];
}

function login_captcha_check(string $answer): bool {
  $expected = (string)($_SESSION['login_captcha_answer'] ?? '');
  $ok = $expected !== '' && hash_equals($expected, trim($answer));
  login_captcha_question(true);
  return $ok;
}
