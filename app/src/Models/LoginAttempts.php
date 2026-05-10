<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class LoginAttempts {
  private const LOCK_AFTER = 5;
  private const CAPTCHA_AFTER = 3;
  private const LOCK_MINUTES = 15;
  private static bool $schemaChecked = false;

  private static function db(): PDO {
    return DB::pdo();
  }

  public static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    $pdo = self::db();
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        ip_hash CHAR(64) NOT NULL PRIMARY KEY,
        attempts INT NOT NULL DEFAULT 0,
        first_attempt_at DATETIME NULL,
        last_attempt_at DATETIME NULL,
        locked_until DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_locked_until (locked_until)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempt_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        ip_hash CHAR(64) NOT NULL,
        username_hash CHAR(64) NULL,
        endpoint VARCHAR(40) NOT NULL DEFAULT 'login',
        success TINYINT(1) NOT NULL DEFAULT 0,
        reason VARCHAR(80) NOT NULL DEFAULT '',
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_time (ip_hash, occurred_at),
        INDEX idx_username_time (username_hash, occurred_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
      // Authentication must not fail closed because a migration could not run.
    }
  }

  public static function ipHash(?string $ip = null): string {
    $ip = trim((string)($ip ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
    return hash('sha256', $ip !== '' ? $ip : 'unknown');
  }

  public static function usernameHash(string $username): ?string {
    $username = strtolower(trim($username));
    return $username !== '' ? hash('sha256', $username) : null;
  }

  public static function status(string $ipHash): array {
    self::ensureSchema();
    try {
      $st = self::db()->prepare("SELECT attempts, locked_until FROM login_attempts WHERE ip_hash=? LIMIT 1");
      $st->execute([$ipHash]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) return ['locked' => false, 'attempts' => 0, 'captcha_required' => false, 'locked_until' => null];

      $lockedUntil = (string)($row['locked_until'] ?? '');
      $locked = $lockedUntil !== '' && strtotime($lockedUntil) > time();
      $attempts = (int)($row['attempts'] ?? 0);
      return [
        'locked' => $locked,
        'attempts' => $attempts,
        'captcha_required' => $attempts >= self::CAPTCHA_AFTER,
        'locked_until' => $locked ? $lockedUntil : null,
      ];
    } catch (\Throwable $e) {
      return ['locked' => false, 'attempts' => 0, 'captcha_required' => false, 'locked_until' => null];
    }
  }

  public static function recordFailure(string $ipHash, ?string $usernameHash, string $endpoint, string $reason = 'invalid_credentials'): array {
    self::ensureSchema();
    $now = date('Y-m-d H:i:s');
    try {
      self::db()->prepare(
        "INSERT INTO login_attempt_events (ip_hash, username_hash, endpoint, success, reason, occurred_at)
         VALUES (?,?,?,?,?,?)"
      )->execute([$ipHash, $usernameHash, self::cleanEndpoint($endpoint), 0, substr($reason, 0, 80), $now]);

      $st = self::db()->prepare("SELECT attempts, locked_until FROM login_attempts WHERE ip_hash=? LIMIT 1");
      $st->execute([$ipHash]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      $attempts = $row ? ((int)$row['attempts'] + 1) : 1;
      $lockedUntil = $attempts >= self::LOCK_AFTER ? date('Y-m-d H:i:s', time() + (self::LOCK_MINUTES * 60)) : null;

      if ($row) {
        self::db()->prepare(
          "UPDATE login_attempts
           SET attempts=?, last_attempt_at=?, locked_until=?
           WHERE ip_hash=? LIMIT 1"
        )->execute([$attempts, $now, $lockedUntil, $ipHash]);
      } else {
        self::db()->prepare(
          "INSERT INTO login_attempts (ip_hash, attempts, first_attempt_at, last_attempt_at, locked_until)
           VALUES (?,?,?,?,?)"
        )->execute([$ipHash, $attempts, $now, $now, $lockedUntil]);
      }

      return [
        'locked' => $lockedUntil !== null,
        'attempts' => $attempts,
        'captcha_required' => $attempts >= self::CAPTCHA_AFTER,
        'locked_until' => $lockedUntil,
      ];
    } catch (\Throwable $e) {
      return ['locked' => false, 'attempts' => 0, 'captcha_required' => false, 'locked_until' => null];
    }
  }

  public static function reset(string $ipHash, ?string $usernameHash, string $endpoint): void {
    self::ensureSchema();
    try {
      self::db()->prepare("DELETE FROM login_attempts WHERE ip_hash=? LIMIT 1")->execute([$ipHash]);
      self::db()->prepare(
        "INSERT INTO login_attempt_events (ip_hash, username_hash, endpoint, success, reason)
         VALUES (?,?,?,?,?)"
      )->execute([$ipHash, $usernameHash, self::cleanEndpoint($endpoint), 1, 'login_success']);
    } catch (\Throwable $e) {}
  }

  private static function cleanEndpoint(string $endpoint): string {
    $endpoint = preg_replace('/[^a-z0-9_-]/i', '', $endpoint) ?: 'login';
    return substr($endpoint, 0, 40);
  }
}
