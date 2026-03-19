<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class Site {
  private static bool $schemaChecked = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    try {
      $pdo = DB::pdo();
      $cols = $pdo->query("SHOW COLUMNS FROM sites")->fetchAll(PDO::FETCH_COLUMN);
      $have = array_flip($cols ?: []);
      $alter = [];
      if (!isset($have['homepage_page_id'])) $alter[] = "ADD COLUMN homepage_page_id INT NULL";
      if (!isset($have['header_default_key'])) $alter[] = "ADD COLUMN header_default_key VARCHAR(100) DEFAULT 'nav-left'";
      if (!isset($have['footer_default_key'])) $alter[] = "ADD COLUMN footer_default_key VARCHAR(100) DEFAULT 'footer-minimal'";
      if (!isset($have['analytics_enabled'])) $alter[] = "ADD COLUMN analytics_enabled TINYINT(1) NOT NULL DEFAULT 1";
      if (!isset($have['analytics_privacy_mode'])) $alter[] = "ADD COLUMN analytics_privacy_mode TINYINT(1) NOT NULL DEFAULT 0";
      if (!isset($have['analytics_retention_days'])) $alter[] = "ADD COLUMN analytics_retention_days INT NOT NULL DEFAULT 180";
      if ($alter) $pdo->exec("ALTER TABLE sites " . implode(',', $alter));
    } catch (\Throwable $e) {
      // best effort
    }
  }

  public static function all(): array {
    self::ensureSchema();
    return DB::pdo()->query("SELECT * FROM sites ORDER BY id DESC")->fetchAll();
  }

  public static function create(string $name, string $slug, string $description=''): int {
    self::ensureSchema();
    $st = DB::pdo()->prepare("INSERT INTO sites(name,slug,description) VALUES (?,?,?)");
    $st->execute([$name,$slug,$description]);
    return (int)DB::pdo()->lastInsertId();
  }

  public static function find(int $id): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM sites WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
  }

  public static function findBySlug(string $slug): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM sites WHERE slug=? LIMIT 1");
    $st->execute([$slug]);
    $r = $st->fetch();
    return $r ?: null;
  }

  public static function setHomepage(int $siteId, int $pageId): void {
    self::ensureSchema();
    $st = DB::pdo()->prepare("UPDATE sites SET homepage_page_id=? WHERE id=? LIMIT 1");
    $st->execute([$pageId, $siteId]);
  }
}
