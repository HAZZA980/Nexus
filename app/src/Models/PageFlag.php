<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class PageFlag {
  private static bool $schemaChecked = false;

  private static function db(): PDO {
    return DB::pdo();
  }

  public static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    $pdo = self::db();
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS page_flags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        page_id INT NULL,
        page_path VARCHAR(255) NOT NULL DEFAULT '',
        page_title VARCHAR(255) NOT NULL DEFAULT '',
        page_url VARCHAR(255) NOT NULL DEFAULT '',
        reporter_user_id INT NOT NULL,
        reporter_name VARCHAR(190) NOT NULL DEFAULT '',
        reporter_email VARCHAR(190) NOT NULL DEFAULT '',
        reporter_role VARCHAR(50) NOT NULL DEFAULT '',
        current_owner_role VARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(30) NOT NULL DEFAULT 'open',
        description TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        resolved_by_user_id INT NULL,
        escalated_at DATETIME NULL,
        escalated_by_user_id INT NULL,
        INDEX idx_flags_site (site_id),
        INDEX idx_flags_owner (current_owner_role, status),
        INDEX idx_flags_reporter (reporter_user_id),
        INDEX idx_flags_updated (updated_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS page_flag_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        flag_id INT NOT NULL,
        user_id INT NULL,
        user_name VARCHAR(190) NOT NULL DEFAULT '',
        user_role VARCHAR(50) NOT NULL DEFAULT '',
        action_type VARCHAR(30) NOT NULL DEFAULT 'comment',
        body TEXT NULL,
        from_role VARCHAR(50) NOT NULL DEFAULT '',
        to_role VARCHAR(50) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_flag_events_flag (flag_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
      // best effort
    }
  }

  public static function canonicalRole(string $role): string {
    $role = strtolower(trim($role));
    $map = [
      'admin' => 'website_admin',
      'staff_admin' => 'website_admin',
      'user_admin' => 'institution_admin',
      'viewer' => 'student',
    ];
    return $map[$role] ?? $role;
  }

  public static function nextOwnerRole(string $reporterRole): string {
    $role = self::canonicalRole($reporterRole);
    return match ($role) {
      'student', 'institution_admin' => 'editor',
      'editor' => 'website_admin',
      'website_admin' => 'super_admin',
      'super_admin' => 'super_admin',
      default => 'editor',
    };
  }

  public static function roleLabel(string $role): string {
    $role = self::canonicalRole($role);
    $map = [
      'student' => 'Student',
      'institution_admin' => 'Institution Admin',
      'editor' => 'Editor',
      'website_admin' => 'Website Admin',
      'super_admin' => 'Super Admin',
    ];
    return $map[$role] ?? ucwords(str_replace('_', ' ', $role));
  }

  private static function visibleWhere(string $role, array $siteAccess, bool $includeReporter = true): array {
    $role = self::canonicalRole($role);
    $params = [];
    $clauses = [];

    if ($role === 'super_admin') {
      $clauses[] = '1=1';
    } else {
      $siteAccess = array_values(array_filter(array_map('strval', $siteAccess)));
      if ($siteAccess) {
        $sitePlaceholders = implode(',', array_fill(0, count($siteAccess), '?'));
        $clauses[] = "s.slug IN ({$sitePlaceholders})";
        $params = array_merge($params, $siteAccess);
      } else {
        $clauses[] = '1=0';
      }
    }

    return ['sql' => '(' . implode(' AND ', $clauses) . ')', 'params' => $params];
  }

  public static function createFlag(array $data): int {
    self::ensureSchema();
    $pdo = self::db();
    $ownerRole = self::nextOwnerRole((string)($data['reporter_role'] ?? ''));
    $now = date('Y-m-d H:i:s');

    $st = $pdo->prepare("INSERT INTO page_flags (
      site_id, page_id, page_path, page_title, page_url,
      reporter_user_id, reporter_name, reporter_email, reporter_role,
      current_owner_role, status, description, created_at, updated_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
      (int)($data['site_id'] ?? 0),
      !empty($data['page_id']) ? (int)$data['page_id'] : null,
      substr(trim((string)($data['page_path'] ?? '')), 0, 255),
      substr(trim((string)($data['page_title'] ?? '')), 0, 255),
      substr(trim((string)($data['page_url'] ?? '')), 0, 255),
      (int)($data['reporter_user_id'] ?? 0),
      substr(trim((string)($data['reporter_name'] ?? '')), 0, 190),
      substr(trim((string)($data['reporter_email'] ?? '')), 0, 190),
      self::canonicalRole((string)($data['reporter_role'] ?? '')),
      $ownerRole,
      'open',
      trim((string)($data['description'] ?? '')),
      $now,
      $now,
    ]);
    $flagId = (int)$pdo->lastInsertId();
    self::addEvent($flagId, [
      'user_id' => (int)($data['reporter_user_id'] ?? 0),
      'user_name' => (string)($data['reporter_name'] ?? ''),
      'user_role' => (string)($data['reporter_role'] ?? ''),
      'action_type' => 'created',
      'body' => trim((string)($data['description'] ?? '')),
      'from_role' => self::canonicalRole((string)($data['reporter_role'] ?? '')),
      'to_role' => $ownerRole,
    ]);
    return $flagId;
  }

  public static function addEvent(int $flagId, array $event): void {
    self::ensureSchema();
    $st = self::db()->prepare("INSERT INTO page_flag_events (
      flag_id, user_id, user_name, user_role, action_type, body, from_role, to_role, created_at
    ) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([
      $flagId,
      !empty($event['user_id']) ? (int)$event['user_id'] : null,
      substr(trim((string)($event['user_name'] ?? '')), 0, 190),
      self::canonicalRole((string)($event['user_role'] ?? '')),
      substr(trim((string)($event['action_type'] ?? 'comment')), 0, 30),
      ($event['body'] ?? null) !== null ? trim((string)$event['body']) : null,
      self::canonicalRole((string)($event['from_role'] ?? '')),
      self::canonicalRole((string)($event['to_role'] ?? '')),
      date('Y-m-d H:i:s'),
    ]);
  }

  public static function inboxCountForUser(int $userId, string $role, array $siteAccess): int {
    self::ensureSchema();
    $role = self::canonicalRole($role);
    if (!in_array($role, ['editor', 'website_admin', 'super_admin'], true)) return 0;
    $visible = self::visibleWhere($role, $siteAccess, false);
    $params = [$role, 'resolved'];
    $params = array_merge($params, $visible['params']);
    $st = self::db()->prepare("SELECT COUNT(*)
      FROM page_flags f
      JOIN sites s ON s.id = f.site_id
      WHERE f.current_owner_role = ? AND f.status <> ? AND {$visible['sql']}");
    $st->execute($params);
    return (int)$st->fetchColumn();
  }

  public static function inboxForUser(int $userId, string $role, array $siteAccess): array {
    self::ensureSchema();
    $role = self::canonicalRole($role);
    $visible = self::visibleWhere($role, $siteAccess, false);
    $params = [$role, 'resolved'];
    $params = array_merge($params, $visible['params']);
    $st = self::db()->prepare("SELECT f.*, s.name AS site_name, s.slug AS site_slug
      FROM page_flags f
      JOIN sites s ON s.id = f.site_id
      WHERE f.current_owner_role = ? AND f.status <> ? AND {$visible['sql']}
      ORDER BY f.updated_at DESC, f.created_at DESC");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public static function reportedByUser(int $userId, string $role, array $siteAccess): array {
    self::ensureSchema();
    $role = self::canonicalRole($role);
    $visible = self::visibleWhere($role, $siteAccess, true);
    $params = [$userId];
    $params = array_merge($params, $visible['params']);
    $st = self::db()->prepare("SELECT f.*, s.name AS site_name, s.slug AS site_slug
      FROM page_flags f
      JOIN sites s ON s.id = f.site_id
      WHERE f.reporter_user_id = ? AND {$visible['sql']}
      ORDER BY f.updated_at DESC, f.created_at DESC");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public static function findVisibleToUser(int $flagId, int $userId, string $role, array $siteAccess): ?array {
    self::ensureSchema();
    $role = self::canonicalRole($role);
    $visible = self::visibleWhere($role, $siteAccess, true);
    $params = [$flagId];
    $params = array_merge($params, $visible['params']);
    $st = self::db()->prepare("SELECT f.*, s.name AS site_name, s.slug AS site_slug
      FROM page_flags f
      JOIN sites s ON s.id = f.site_id
      WHERE f.id = ? AND {$visible['sql']}
      LIMIT 1");
    $st->execute($params);
    $flag = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$flag) return null;
    if ($role === 'super_admin') return $flag;
    if ((int)($flag['reporter_user_id'] ?? 0) === $userId) return $flag;
    if (($flag['current_owner_role'] ?? '') === $role) return $flag;
    return null;
  }

  public static function eventsForFlag(int $flagId): array {
    self::ensureSchema();
    $st = self::db()->prepare("SELECT * FROM page_flag_events WHERE flag_id = ? ORDER BY created_at ASC, id ASC");
    $st->execute([$flagId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public static function addComment(int $flagId, array $actor, string $body): void {
    self::ensureSchema();
    $body = trim($body);
    if ($body === '') return;
    $pdo = self::db();
    $pdo->prepare("UPDATE page_flags SET updated_at = ? WHERE id = ? LIMIT 1")->execute([date('Y-m-d H:i:s'), $flagId]);
    self::addEvent($flagId, [
      'user_id' => (int)($actor['id'] ?? 0),
      'user_name' => (string)($actor['name'] ?? ''),
      'user_role' => (string)($actor['role'] ?? ''),
      'action_type' => 'comment',
      'body' => $body,
      'from_role' => self::canonicalRole((string)($actor['role'] ?? '')),
      'to_role' => self::canonicalRole((string)($actor['role'] ?? '')),
    ]);
  }

  public static function escalate(int $flagId, array $actor, string $note = ''): void {
    self::ensureSchema();
    $pdo = self::db();
    $flag = self::findById($flagId);
    if (!$flag) throw new \RuntimeException('Flag not found.');
    $currentOwner = self::canonicalRole((string)($flag['current_owner_role'] ?? ''));
    $nextRole = self::nextOwnerRole($currentOwner);
    if ($nextRole === $currentOwner) throw new \RuntimeException('This issue cannot be escalated further.');
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE page_flags
      SET current_owner_role = ?, status = 'escalated', escalated_at = ?, escalated_by_user_id = ?, updated_at = ?
      WHERE id = ? LIMIT 1")
      ->execute([$nextRole, $now, (int)($actor['id'] ?? 0), $now, $flagId]);
    self::addEvent($flagId, [
      'user_id' => (int)($actor['id'] ?? 0),
      'user_name' => (string)($actor['name'] ?? ''),
      'user_role' => (string)($actor['role'] ?? ''),
      'action_type' => 'escalated',
      'body' => trim($note),
      'from_role' => $currentOwner,
      'to_role' => $nextRole,
    ]);
  }

  public static function resolve(int $flagId, array $actor, string $note = ''): void {
    self::ensureSchema();
    $pdo = self::db();
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE page_flags
      SET status = 'resolved', resolved_at = ?, resolved_by_user_id = ?, updated_at = ?
      WHERE id = ? LIMIT 1")
      ->execute([$now, (int)($actor['id'] ?? 0), $now, $flagId]);
    self::addEvent($flagId, [
      'user_id' => (int)($actor['id'] ?? 0),
      'user_name' => (string)($actor['name'] ?? ''),
      'user_role' => (string)($actor['role'] ?? ''),
      'action_type' => 'resolved',
      'body' => trim($note),
      'from_role' => self::canonicalRole((string)($actor['role'] ?? '')),
      'to_role' => self::canonicalRole((string)($actor['role'] ?? '')),
    ]);
  }

  public static function findById(int $flagId): ?array {
    self::ensureSchema();
    $st = self::db()->prepare("SELECT * FROM page_flags WHERE id = ? LIMIT 1");
    $st->execute([$flagId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}
