<?php
require __DIR__ . '/../app/bootstrap.php';
require_admin();

use NexusCMS\Core\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
  $payload = [];
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ($payload['_csrf'] ?? null));
if (!Security::checkCsrf($token)) {
  json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
}

$mode = (string)($_POST['mode'] ?? ($payload['mode'] ?? ''));
$mode = $mode === 'light' ? 'light' : ($mode === 'dark' ? 'dark' : '');
if ($mode === '') {
  json_response(['ok' => false, 'error' => 'Invalid mode'], 422);
}

$_SESSION['ui_theme_mode'] = $mode;
json_response(['ok' => true, 'mode' => $mode]);
