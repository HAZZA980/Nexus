<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class CitationExample {
  private static bool $schemaChecked = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    try {
      $pdo = DB::pdo();
      $pdo->exec("CREATE TABLE IF NOT EXISTS citation_examples (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_slug VARCHAR(190) NOT NULL,
        referencing_style VARCHAR(100) NOT NULL DEFAULT 'Harvard',
        category VARCHAR(80) NOT NULL DEFAULT 'Books',
        sub_category VARCHAR(120) NULL,
        example_key VARCHAR(190) NOT NULL,
        label VARCHAR(190) NOT NULL,
        citation_order TEXT NOT NULL,
        example_heading VARCHAR(255) NOT NULL,
        example_body TEXT NOT NULL,
        you_try TEXT NOT NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_site_example (site_slug, example_key),
        INDEX idx_site_slug (site_slug)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      // Ensure new columns exist for upgraded installs
      $cols = $pdo->query("SHOW COLUMNS FROM citation_examples")->fetchAll(PDO::FETCH_COLUMN);
      $have = array_flip($cols ?: []);
      if (!isset($have['referencing_style'])) {
        $pdo->exec("ALTER TABLE citation_examples ADD COLUMN referencing_style VARCHAR(100) NOT NULL DEFAULT 'Harvard' AFTER site_slug");
      }
      if (!isset($have['category'])) {
        $pdo->exec("ALTER TABLE citation_examples ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT 'Books' AFTER referencing_style");
      }
      if (!isset($have['sub_category'])) {
        $pdo->exec("ALTER TABLE citation_examples ADD COLUMN sub_category VARCHAR(120) NULL AFTER category");
      }
    } catch (\Throwable $e) {
      // best effort; do not block runtime
    }
  }

  public static function listForSiteSlug(string $siteSlug, ?string $referencingStyle = null): array {
    self::ensureSchema();
    $sql = "SELECT id, example_key AS key_alias, example_key, label, referencing_style, category, sub_category, citation_order, example_heading, example_body, you_try, notes FROM citation_examples WHERE site_slug=?";
    $params = [$siteSlug];
    if ($referencingStyle) {
      $sql .= " AND referencing_style = ?";
      $params[] = $referencingStyle;
    }
    $sql .= " ORDER BY label";
    $st = DB::pdo()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(function($r){
      $r['id'] = $r['id'] ?? null;
      $r['example_key'] = $r['example_key'] ?? ($r['key_alias'] ?? null);
      return $r;
    }, $rows);
  }

  public static function find(string $siteSlug, string $exampleKey): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT id, example_key AS key_alias, example_key, label, referencing_style, category, sub_category, citation_order, example_heading, example_body, you_try, notes FROM citation_examples WHERE site_slug=? AND example_key=? LIMIT 1");
    $st->execute([$siteSlug, $exampleKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $row['example_key'] = $row['example_key'] ?? ($row['key_alias'] ?? null);
    }
    return $row ?: null;
  }

  public static function create(array $data): int {
    self::ensureSchema();
    $st = DB::pdo()->prepare("INSERT INTO citation_examples (site_slug, referencing_style, category, sub_category, example_key, label, citation_order, example_heading, example_body, you_try, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
      $data['site_slug'],
      $data['referencing_style'],
      $data['category'],
      $data['sub_category'] ?? null,
      $data['example_key'],
      $data['label'],
      $data['citation_order'],
      $data['example_heading'],
      $data['example_body'],
      $data['you_try'],
      $data['notes'] ?? null
    ]);
    return (int)DB::pdo()->lastInsertId();
  }

  public static function update(int $id, array $data): void {
    self::ensureSchema();
    $st = DB::pdo()->prepare("UPDATE citation_examples SET referencing_style=?, category=?, sub_category=?, example_key=?, label=?, citation_order=?, example_heading=?, example_body=?, you_try=?, notes=? WHERE id=? LIMIT 1");
    $st->execute([
      $data['referencing_style'],
      $data['category'],
      $data['sub_category'] ?? null,
      $data['example_key'],
      $data['label'],
      $data['citation_order'],
      $data['example_heading'],
      $data['example_body'],
      $data['you_try'],
      $data['notes'] ?? null,
      $id
    ]);
  }

  public static function delete(int $id, string $siteSlug): void {
    self::ensureSchema();
    $st = DB::pdo()->prepare("DELETE FROM citation_examples WHERE id=? AND site_slug=? LIMIT 1");
    $st->execute([$id, $siteSlug]);
  }

  public static function findById(int $id): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM citation_examples WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}
