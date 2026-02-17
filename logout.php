<?php
require __DIR__ . '/app/bootstrap.php';

/**
 * Keep redirects local and base-path aware.
 */
function normalize_return_path(string $candidate): string {
  $base = rtrim(base_path(), '/');
  $to = trim($candidate);
  if ($to === '') return '/';

  if (preg_match('#^https?://#i', $to)) {
    $path = (string)(parse_url($to, PHP_URL_PATH) ?? '/');
    $query = (string)(parse_url($to, PHP_URL_QUERY) ?? '');
    $to = $path . ($query !== '' ? ('?' . $query) : '');
  }

  if ($base !== '' && str_starts_with($to, $base)) {
    $to = substr($to, strlen($base));
  }

  if ($to === '' || $to[0] !== '/') $to = '/' . ltrim($to, '/');
  return $to;
}

$return = isset($_GET['return']) ? (string)$_GET['return'] : '/';
$to = normalize_return_path($return);

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $params['path'] ?: '/', $params['domain'] ?: '', (bool)$params['secure'], (bool)$params['httponly']);
}
session_destroy();

header('Location: ' . rtrim(base_path(), '/') . $to);
exit;

