<?php
namespace NexusCMS\Core;

final class Security {
  public static function csrfToken(): string {
    if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
      $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
  }

  public static function checkCsrf(?string $token): bool {
    return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
  }

  public static function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
}
