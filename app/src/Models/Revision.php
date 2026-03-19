<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;

final class Revision {
  private static bool $schemaChecked = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    try {
      $pdo = DB::pdo();
      $cols = $pdo->query("SHOW COLUMNS FROM page_revisions")->fetchAll(\PDO::FETCH_COLUMN);
      $have = array_flip($cols ?: []);
      $alter = [];
      if (!isset($have['name'])) $alter[] = "ADD COLUMN name VARCHAR(100) NULL";
      if (!isset($have['note'])) $alter[] = "ADD COLUMN note TEXT NULL";
      if (!isset($have['is_milestone'])) $alter[] = "ADD COLUMN is_milestone TINYINT(1) NOT NULL DEFAULT 0";
      if (!isset($have['created_by_user_id'])) $alter[] = "ADD COLUMN created_by_user_id INT NULL";
      if ($alter) $pdo->exec("ALTER TABLE page_revisions " . implode(',', $alter));
    } catch (\Throwable $e) {
      // Best effort; if it fails, legacy behaviour continues.
    }
  }

  public static function listByPage(int $pageId, int $limit = 5): array {
    self::ensureSchema();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id, page_id, created_at, name, note, is_milestone, created_by_user_id FROM page_revisions WHERE page_id=? ORDER BY id DESC LIMIT ?");
    $stmt->bindValue(1, $pageId, \PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
  }

  public static function create(int $pageId, array $doc, ?string $name = null, ?string $note = null, bool $isMilestone = false, ?int $userId = null): int {
    self::ensureSchema();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("INSERT INTO page_revisions (page_id, doc_json, name, note, is_milestone, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
      $pageId,
      json_encode($doc, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
      $name,
      $note,
      $isMilestone ? 1 : 0,
      $userId
    ]);
    return (int)$pdo->lastInsertId();
  }

  public static function get(int $id): ?array {
    self::ensureSchema();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("SELECT id, page_id, doc_json, created_at, name, note, is_milestone, created_by_user_id FROM page_revisions WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  public static function delete(int $id): void {
    self::ensureSchema();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("DELETE FROM page_revisions WHERE id=?");
    $stmt->execute([$id]);
  }

  public static function setMilestone(int $id, bool $flag): void {
    self::ensureSchema();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("UPDATE page_revisions SET is_milestone=? WHERE id=?");
    $stmt->execute([$flag ? 1 : 0, $id]);
  }

  /** Keep newest N revisions; delete older ones */
  public static function prune(int $pageId, int $keep = 5): void {
    self::ensureSchema();
    $pdo = DB::pdo();
    $stmt = $pdo->prepare("
      DELETE FROM page_revisions
      WHERE page_id=?
        AND id NOT IN (
          SELECT id FROM (
            SELECT id FROM page_revisions WHERE page_id=? ORDER BY id DESC LIMIT ?
          ) t
        )
    ");
    $stmt->execute([$pageId, $pageId, $keep]);
  }
}
