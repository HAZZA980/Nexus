<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class CitationRevision {
  public static function ensureSchema(): void {
    try {
      $pdo = DB::pdo();
      $pdo->exec("CREATE TABLE IF NOT EXISTS citation_revisions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        site_slug VARCHAR(190) NOT NULL,
        citation_id INT NULL,
        citation_key VARCHAR(190) NOT NULL,
        action ENUM('create','update','delete','rollback') NOT NULL,
        user_id INT NULL,
        user_email VARCHAR(190) NULL,
        release_tag VARCHAR(50) NULL,
        before_json JSON NULL,
        after_json JSON NULL,
        diff_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_site_action (site_slug, action),
        INDEX idx_site_release (site_slug, release_tag),
        INDEX idx_site_citation (site_slug, citation_key)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
      // best effort
    }
  }

  public static function record(array $payload): void {
    self::ensureSchema();
    $pdo = DB::pdo();
    $st = $pdo->prepare("INSERT INTO citation_revisions (site_slug,citation_id,citation_key,action,user_id,user_email,release_tag,before_json,after_json,diff_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
    $st->execute([
      $payload['site_slug'],
      $payload['citation_id'] ?? null,
      $payload['citation_key'] ?? '',
      $payload['action'],
      $payload['user_id'] ?? null,
      $payload['user_email'] ?? null,
      $payload['release_tag'] ?? null,
      $payload['before_json'] ?? null,
      $payload['after_json'] ?? null,
      $payload['diff_json'] ?? null,
    ]);
  }

  public static function recent(string $siteSlug, int $limit = 50): array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM citation_revisions WHERE site_slug=? ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $siteSlug, PDO::PARAM_STR);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public static function listByRelease(string $siteSlug, string $tag): array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM citation_revisions WHERE site_slug=? AND release_tag=? ORDER BY id ASC");
    $st->execute([$siteSlug, $tag]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
