<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class SiteForm {
  private static bool $schemaChecked = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;

    try {
      $pdo = DB::pdo();
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_forms (
          id INT AUTO_INCREMENT PRIMARY KEY,
          site_id INT NOT NULL,
          name VARCHAR(190) NOT NULL,
          description TEXT NULL,
          form_json JSON NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_site_forms_site (site_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");
    } catch (\Throwable $e) {
      // best effort
    }
  }

  private static function normalizeQuestions(array $questions): array {
    $out = [];
    foreach ($questions as $idx => $question) {
      if (!is_array($question)) continue;
      $id = trim((string)($question['id'] ?? ''));
      $label = trim((string)($question['label'] ?? ''));
      $type = strtolower(trim((string)($question['type'] ?? 'text')));
      if ($label === '') continue;
      if (!in_array($type, ['text', 'rating'], true)) $type = 'text';
      if ($id === '') $id = 'q_' . ($idx + 1) . '_' . substr(md5($label . '|' . $type), 0, 8);
      $out[] = [
        'id' => preg_replace('/[^a-z0-9_\-]/i', '_', $id) ?: ('q_' . ($idx + 1)),
        'label' => $label,
        'type' => $type,
      ];
    }
    return array_values($out);
  }

  public static function listBySite(int $siteId): array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM site_forms WHERE site_id=? ORDER BY updated_at DESC, id DESC");
    $st->execute([$siteId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
      $row['questions'] = self::normalizeQuestions(json_decode((string)($row['form_json'] ?? '[]'), true) ?: []);
    }
    unset($row);
    return $rows;
  }

  public static function find(int $id): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM site_forms WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['questions'] = self::normalizeQuestions(json_decode((string)($row['form_json'] ?? '[]'), true) ?: []);
    return $row;
  }

  public static function create(int $siteId, string $name, string $description, array $questions): int {
    self::ensureSchema();
    $payload = self::normalizeQuestions($questions);
    $st = DB::pdo()->prepare("INSERT INTO site_forms (site_id, name, description, form_json) VALUES (?,?,?,?)");
    $st->execute([
      $siteId,
      trim($name),
      trim($description),
      json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)DB::pdo()->lastInsertId();
  }

  public static function update(int $id, int $siteId, string $name, string $description, array $questions): void {
    self::ensureSchema();
    $payload = self::normalizeQuestions($questions);
    $st = DB::pdo()->prepare("UPDATE site_forms SET name=?, description=?, form_json=?, updated_at=NOW() WHERE id=? AND site_id=? LIMIT 1");
    $st->execute([
      trim($name),
      trim($description),
      json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      $id,
      $siteId,
    ]);
  }

  public static function delete(int $id, int $siteId): void {
    self::ensureSchema();
    $st = DB::pdo()->prepare("DELETE FROM site_forms WHERE id=? AND site_id=? LIMIT 1");
    $st->execute([$id, $siteId]);
  }
}
