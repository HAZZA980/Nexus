<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use NexusCMS\Support\PagePath;
use PDO;

final class DeletedPage {
  private static bool $schemaChecked = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;

    try {
      $pdo = DB::pdo();
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS deleted_pages (
          id INT AUTO_INCREMENT PRIMARY KEY,
          original_page_id INT NOT NULL,
          site_id INT NOT NULL,
          title VARCHAR(190) NOT NULL,
          slug VARCHAR(190) NOT NULL,
          status VARCHAR(20) DEFAULT 'draft',
          template_key VARCHAR(100) DEFAULT 'landing',
          shell_override_json JSON NULL,
          builder_json JSON NULL,
          search_text TEXT NULL,
          collection_id INT NULL,
          original_created_at DATETIME NULL,
          original_updated_at DATETIME NULL,
          deleted_by_user_id INT NULL,
          deleted_by_email VARCHAR(190) NULL,
          deleted_by_name VARCHAR(190) NULL,
          deleted_by_role VARCHAR(50) NULL,
          deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          purge_after DATETIME NOT NULL,
          INDEX idx_deleted_pages_site_deleted (site_id, deleted_at),
          INDEX idx_deleted_pages_purge_after (purge_after),
          INDEX idx_deleted_pages_original (original_page_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");
    } catch (\Throwable $e) {
      // Best effort for mixed local schemas.
    }
  }

  public static function purgeExpired(): int {
    self::ensureSchema();
    try {
      $stmt = DB::pdo()->prepare("DELETE FROM deleted_pages WHERE purge_after <= NOW()");
      $stmt->execute();
      return (int)$stmt->rowCount();
    } catch (\Throwable $e) {
      return 0;
    }
  }

  public static function listBySite(int $siteId): array {
    self::ensureSchema();
    $stmt = DB::pdo()->prepare("
      SELECT *
      FROM deleted_pages
      WHERE site_id = ?
      ORDER BY deleted_at DESC, id DESC
    ");
    $stmt->execute([$siteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public static function find(int $deletedPageId): ?array {
    self::ensureSchema();
    $stmt = DB::pdo()->prepare("
      SELECT *
      FROM deleted_pages
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->execute([$deletedPageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  public static function restore(int $deletedPageId): array {
    self::ensureSchema();

    $row = self::find($deletedPageId);
    if (!$row) {
      throw new \RuntimeException('Deleted page not found.');
    }

    $siteId = (int)($row['site_id'] ?? 0);
    if ($siteId <= 0) {
      throw new \RuntimeException('Deleted page site is invalid.');
    }

    $baseSlug = PagePath::normalizePath((string)($row['slug'] ?? ''));
    if ($baseSlug === '') {
      throw new \RuntimeException('Deleted page slug is invalid.');
    }

    $restoredSlug = $baseSlug;
    $suffix = 2;
    while (Page::findBySlugAnyStatus($siteId, $restoredSlug)) {
      $restoredSlug = $baseSlug . '-restored-' . $suffix;
      $suffix++;
    }

    $title = trim((string)($row['title'] ?? 'Untitled page'));
    if ($restoredSlug !== $baseSlug && stripos($title, '(Restored)') === false) {
      $title .= ' (Restored)';
    }

    $pdo = DB::pdo();
    $startedTxn = !$pdo->inTransaction();
    if ($startedTxn) $pdo->beginTransaction();

    try {
      $insert = $pdo->prepare("
        INSERT INTO pages (
          site_id,
          title,
          slug,
          status,
          template_key,
          shell_override_json,
          builder_json,
          search_text,
          collection_id,
          created_at,
          updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
      ");
      $insert->execute([
        $siteId,
        $title,
        $restoredSlug,
        (string)($row['status'] ?? 'draft'),
        (string)($row['template_key'] ?? 'landing'),
        $row['shell_override_json'] ?? null,
        $row['builder_json'] ?? null,
        $row['search_text'] ?? null,
        isset($row['collection_id']) ? (int)$row['collection_id'] : null,
      ]);

      $restoredPageId = (int)$pdo->lastInsertId();

      $delete = $pdo->prepare("DELETE FROM deleted_pages WHERE id = ? LIMIT 1");
      $delete->execute([$deletedPageId]);
      if ((int)$delete->rowCount() !== 1) {
        throw new \RuntimeException('Deleted page record could not be cleared after restore.');
      }

      if ($startedTxn) $pdo->commit();

      return [
        'id' => $restoredPageId,
        'site_id' => $siteId,
        'title' => $title,
        'slug' => $restoredSlug,
      ];
    } catch (\Throwable $e) {
      if ($startedTxn && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

  public static function softDelete(array $page, array $actor = []): void {
    self::ensureSchema();

    $pageId = (int)($page['id'] ?? 0);
    $siteId = (int)($page['site_id'] ?? 0);
    if ($pageId <= 0 || $siteId <= 0) {
      throw new \RuntimeException('Invalid page payload.');
    }

    $pdo = DB::pdo();
    $startedTxn = !$pdo->inTransaction();
    if ($startedTxn) $pdo->beginTransaction();

    try {
      $insert = $pdo->prepare("
        INSERT INTO deleted_pages (
          original_page_id,
          site_id,
          title,
          slug,
          status,
          template_key,
          shell_override_json,
          builder_json,
          search_text,
          collection_id,
          original_created_at,
          original_updated_at,
          deleted_by_user_id,
          deleted_by_email,
          deleted_by_name,
          deleted_by_role,
          deleted_at,
          purge_after
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
      ");
      $insert->execute([
        $pageId,
        $siteId,
        (string)($page['title'] ?? ''),
        (string)($page['slug'] ?? ''),
        (string)($page['status'] ?? 'draft'),
        (string)($page['template_key'] ?? 'landing'),
        $page['shell_override_json'] ?? null,
        $page['builder_json'] ?? null,
        $page['search_text'] ?? null,
        isset($page['collection_id']) ? (int)$page['collection_id'] : null,
        $page['created_at'] ?? null,
        $page['updated_at'] ?? null,
        isset($actor['user_id']) ? (int)$actor['user_id'] : null,
        $actor['email'] ?? null,
        $actor['name'] ?? null,
        $actor['role'] ?? null,
      ]);

      $delete = $pdo->prepare("DELETE FROM pages WHERE id = ? AND site_id = ? LIMIT 1");
      $delete->execute([$pageId, $siteId]);
      if ((int)$delete->rowCount() !== 1) {
        throw new \RuntimeException('Page could not be removed from active pages.');
      }

      if ($startedTxn) $pdo->commit();
    } catch (\Throwable $e) {
      if ($startedTxn && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }
}
