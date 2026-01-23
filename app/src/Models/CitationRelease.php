<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class CitationRelease {
  public static function ensureSchema(): void {
    try {
      $pdo = DB::pdo();
      $pdo->exec("CREATE TABLE IF NOT EXISTS citation_releases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_slug VARCHAR(190) NOT NULL,
        tag VARCHAR(50) NOT NULL,
        status ENUM('open','exported') NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        exported_at DATETIME NULL,
        exported_by_email VARCHAR(190) NULL,
        UNIQUE KEY uniq_site_tag (site_slug, tag),
        INDEX idx_site_status (site_slug, status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
      // best effort
    }
  }

  public static function upsertOpen(string $siteSlug, string $tag): void {
    self::ensureSchema();
    $pdo = DB::pdo();
    $st = $pdo->prepare("INSERT INTO citation_releases (site_slug, tag, status) VALUES (?,?, 'open') ON DUPLICATE KEY UPDATE status='open'");
    $st->execute([$siteSlug, $tag]);
  }

  public static function markExported(string $siteSlug, string $tag, string $email): void {
    self::ensureSchema();
    $st = DB::pdo()->prepare("UPDATE citation_releases SET status='exported', exported_at=NOW(), exported_by_email=? WHERE site_slug=? AND tag=? LIMIT 1");
    $st->execute([$email, $siteSlug, $tag]);
  }

  public static function listAll(string $siteSlug): array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM citation_releases WHERE site_slug=? ORDER BY created_at DESC");
    $st->execute([$siteSlug]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
