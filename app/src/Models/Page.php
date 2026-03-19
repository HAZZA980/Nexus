<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class Page {
  private static bool $schemaChecked = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    try {
      $pdo = DB::pdo();
      $cols = $pdo->query("SHOW COLUMNS FROM pages")->fetchAll(PDO::FETCH_COLUMN);
      $have = array_flip($cols ?: []);
      $alter = [];
      if (!isset($have['template_key'])) $alter[] = "ADD COLUMN template_key VARCHAR(100) DEFAULT 'landing'";
      if (!isset($have['shell_override_json'])) $alter[] = "ADD COLUMN shell_override_json JSON NULL";
      if (!isset($have['search_text'])) $alter[] = "ADD COLUMN search_text TEXT NULL";
      if (!isset($have['collection_id'])) $alter[] = "ADD COLUMN collection_id INT NULL";
      if ($alter) $pdo->exec("ALTER TABLE pages " . implode(',', $alter));
    } catch (\Throwable $e) {
      // best effort to align schema; do not block runtime
    }
  }

  private static function computeSearchText(string $title, string $slug, array $doc): string {
    $parts = [$title, $slug];
    $walker = function ($node) use (&$walker, &$parts) {
      if (is_array($node)) {
        foreach ($node as $v) $walker($v);
      } elseif (is_string($node)) {
        $parts[] = $node;
      }
    };
    $walker($doc);
    $text = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    return substr($text, 0, 8000);
  }

  public static function listBySite(int $siteId): array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM pages WHERE site_id=? ORDER BY updated_at DESC");
    $st->execute([$siteId]);
    return $st->fetchAll();
  }

  public static function create(int $siteId, string $title, string $slug, array $doc, string $templateKey = 'landing', ?array $shellOverride = null, ?int $collectionId = null): int {
    self::ensureSchema();
    $search = self::computeSearchText($title, $slug, $doc);
    $st = DB::pdo()->prepare("INSERT INTO pages(site_id,title,slug,status,builder_json,template_key,shell_override_json,search_text,collection_id) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([
      $siteId,
      $title,
      $slug,
      'draft',
      json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      $templateKey ?: 'landing',
      $shellOverride ? json_encode($shellOverride, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
      $search,
      $collectionId
    ]);
    return (int)DB::pdo()->lastInsertId();
  }

  public static function find(int $id): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM pages WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
  }

  public static function findPublishedBySlug(int $siteId, string $slug): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM pages WHERE site_id=? AND slug=? AND status='published' LIMIT 1");
    $st->execute([$siteId,$slug]);
    $r = $st->fetch();
    return $r ?: null;
  }

  public static function saveDoc(int $pageId, array $doc): void {
    self::ensureSchema();
    $st = DB::pdo()->prepare("UPDATE pages SET builder_json=?, updated_at=NOW() WHERE id=?");
    $st->execute([json_encode($doc, JSON_UNESCAPED_SLASHES), $pageId]);
  }

  public static function publish(int $pageId, array $doc): void {
    self::ensureSchema();
    $row = self::find($pageId);
    $title = $row['title'] ?? '';
    $slug  = $row['slug'] ?? '';
    $search = self::computeSearchText($title, $slug, $doc);
    $st = DB::pdo()->prepare("UPDATE pages SET builder_json=?, search_text=?, status='published', updated_at=NOW() WHERE id=?");
    $st->execute([json_encode($doc, JSON_UNESCAPED_SLASHES), $search, $pageId]);
  }


  public static function duplicateFromDoc(int $pageId, array $doc): int {
  $pdo = \NexusCMS\Core\DB::pdo();

  // fetch original
  $stmt = $pdo->prepare("SELECT site_id, title, slug FROM pages WHERE id=?");
  $stmt->execute([$pageId]);
  $src = $stmt->fetch(\PDO::FETCH_ASSOC);
  if (!$src) throw new \RuntimeException("Page not found");

  $siteId = (int)$src['site_id'];
  $baseTitle = (string)$src['title'];
  $baseSlug = (string)$src['slug'];

  $newTitle = $baseTitle . " (Restored)";
  $newSlug = $baseSlug . "-restored-" . date('His');

  $docJson = json_encode($doc, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

  $stmt2 = $pdo->prepare("INSERT INTO pages (site_id, title, slug, status, builder_json, published_json, created_at, updated_at)
                          VALUES (?, ?, ?, 'draft', ?, NULL, NOW(), NOW())");
  $stmt2->execute([$siteId, $newTitle, $newSlug, $docJson]);

  return (int)$pdo->lastInsertId();
}

public static function unpublish(int $pageId): void {
  $pdo = \NexusCMS\Core\DB::pdo();
  $stmt = $pdo->prepare("UPDATE pages SET status='draft', updated_at=NOW() WHERE id=?");
  $stmt->execute([$pageId]);
}

public static function findBySlugAnyStatus(int $siteId, string $slug): ?array {
  self::ensureSchema();
  $pdo = \NexusCMS\Core\DB::pdo();
  $stmt = $pdo->prepare("SELECT * FROM pages WHERE site_id=? AND slug=? LIMIT 1");
  $stmt->execute([$siteId, $slug]);
  $row = $stmt->fetch(\PDO::FETCH_ASSOC);
  return $row ?: null;
}

  public static function searchPublished(int $siteId, string $query): array {
    self::ensureSchema();
    $q = '%' . $query . '%';
    $st = DB::pdo()->prepare("SELECT id, title, slug, search_text FROM pages WHERE site_id=? AND status='published' AND search_text LIKE ? ORDER BY updated_at DESC LIMIT 20");
    $st->execute([$siteId, $q]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

}
