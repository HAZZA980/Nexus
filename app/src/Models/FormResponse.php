<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;

final class FormResponse {
  private static bool $schemaChecked = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;

    try {
      $pdo = DB::pdo();
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS form_responses (
          id INT AUTO_INCREMENT PRIMARY KEY,
          site_id INT NOT NULL,
          form_id INT NOT NULL,
          user_id INT NULL,
          user_name VARCHAR(190) NULL,
          institution_name VARCHAR(190) NULL,
          page_id INT NULL,
          page_slug VARCHAR(190) NULL,
          response_json JSON NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_form_responses_form (form_id, created_at),
          INDEX idx_form_responses_site (site_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      ");
      $cols = [];
      foreach ($pdo->query("SHOW COLUMNS FROM form_responses") ?: [] as $col) {
        $name = (string)($col['Field'] ?? '');
        if ($name !== '') $cols[$name] = true;
      }
      if (!isset($cols['user_id'])) $pdo->exec("ALTER TABLE form_responses ADD COLUMN user_id INT NULL AFTER form_id");
      if (!isset($cols['user_name'])) $pdo->exec("ALTER TABLE form_responses ADD COLUMN user_name VARCHAR(190) NULL AFTER user_id");
      if (!isset($cols['institution_name'])) $pdo->exec("ALTER TABLE form_responses ADD COLUMN institution_name VARCHAR(190) NULL AFTER user_name");
    } catch (\Throwable $e) {
      // best effort
    }
  }

  public static function create(int $siteId, int $formId, ?int $pageId, string $pageSlug, array $responses, ?int $userId = null, string $userName = '', string $institutionName = ''): int {
    self::ensureSchema();
    $st = DB::pdo()->prepare("INSERT INTO form_responses (site_id, form_id, user_id, user_name, institution_name, page_id, page_slug, response_json) VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([
      $siteId,
      $formId,
      $userId,
      trim($userName) !== '' ? trim($userName) : null,
      trim($institutionName) !== '' ? trim($institutionName) : null,
      $pageId,
      trim($pageSlug) !== '' ? trim($pageSlug) : null,
      json_encode($responses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)DB::pdo()->lastInsertId();
  }

  public static function listByForm(int $siteId, int $formId): array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("
      SELECT id, site_id, form_id, user_id, user_name, institution_name, page_id, page_slug, response_json, created_at
      FROM form_responses
      WHERE site_id = ? AND form_id = ?
      ORDER BY created_at DESC, id DESC
    ");
    $st->execute([$siteId, $formId]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return array_map(static function (array $row): array {
      $responses = json_decode((string)($row['response_json'] ?? '[]'), true);
      return [
        'id' => (int)($row['id'] ?? 0),
        'site_id' => (int)($row['site_id'] ?? 0),
        'form_id' => (int)($row['form_id'] ?? 0),
        'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
        'user_name' => (string)($row['user_name'] ?? ''),
        'institution_name' => (string)($row['institution_name'] ?? ''),
        'page_id' => isset($row['page_id']) ? (int)$row['page_id'] : null,
        'page_slug' => (string)($row['page_slug'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'responses' => is_array($responses) ? array_values($responses) : [],
      ];
    }, $rows);
  }
}
