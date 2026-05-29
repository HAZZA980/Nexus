<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class CitationStyleDocument {
  private static bool $schemaChecked = false;

  public static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    try {
      $pdo = DB::pdo();
      $pdo->exec("CREATE TABLE IF NOT EXISTS citation_style_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_slug VARCHAR(190) NOT NULL,
        referencing_style VARCHAR(100) NOT NULL,
        doc_type VARCHAR(80) NOT NULL DEFAULT 'Style guide',
        category VARCHAR(80) NULL,
        sub_category VARCHAR(120) NULL,
        title VARCHAR(190) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        updated_by_email VARCHAR(190) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_site_style (site_slug, referencing_style),
        INDEX idx_site_type (site_slug, doc_type),
        INDEX idx_site_category (site_slug, category, sub_category)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
      // best effort
    }
  }

  public static function listForSiteSlug(string $siteSlug): array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM citation_style_documents WHERE site_slug=? ORDER BY referencing_style, category, sub_category, doc_type, title");
    $st->execute([$siteSlug]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public static function findById(int $id, string $siteSlug): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM citation_style_documents WHERE id=? AND site_slug=? LIMIT 1");
    $st->execute([$id, $siteSlug]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  public static function create(array $data): int {
    self::ensureSchema();
    $st = DB::pdo()->prepare("INSERT INTO citation_style_documents (site_slug, referencing_style, doc_type, category, sub_category, title, body, updated_by_email) VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([
      $data['site_slug'],
      $data['referencing_style'],
      $data['doc_type'],
      $data['category'] ?? null,
      $data['sub_category'] ?? null,
      $data['title'],
      $data['body'],
      $data['updated_by_email'] ?? null,
    ]);
    return (int)DB::pdo()->lastInsertId();
  }

  public static function update(int $id, string $siteSlug, array $data): void {
    self::ensureSchema();
    $st = DB::pdo()->prepare("UPDATE citation_style_documents SET referencing_style=?, doc_type=?, category=?, sub_category=?, title=?, body=?, updated_by_email=? WHERE id=? AND site_slug=? LIMIT 1");
    $st->execute([
      $data['referencing_style'],
      $data['doc_type'],
      $data['category'] ?? null,
      $data['sub_category'] ?? null,
      $data['title'],
      $data['body'],
      $data['updated_by_email'] ?? null,
      $id,
      $siteSlug,
    ]);
  }

  public static function delete(int $id, string $siteSlug): void {
    self::ensureSchema();
    $st = DB::pdo()->prepare("DELETE FROM citation_style_documents WHERE id=? AND site_slug=? LIMIT 1");
    $st->execute([$id, $siteSlug]);
  }
}
