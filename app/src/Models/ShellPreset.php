<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class ShellPreset {
  private static bool $schemaChecked = false;

  private static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    try {
      $pdo = DB::pdo();
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS shell_presets (
          id INT AUTO_INCREMENT PRIMARY KEY,
          site_id INT NULL,
          type ENUM('header','footer') NOT NULL,
          preset_key VARCHAR(100) NOT NULL,
          name VARCHAR(190) NOT NULL,
          config_json JSON NOT NULL,
          is_default TINYINT(1) NOT NULL DEFAULT 0,
          is_system TINYINT(1) NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_site_preset (site_id, type, preset_key)
        )
      ");
    } catch (\Throwable $e) {
      // best effort
    }
  }

  public static function listBySite(int $siteId, string $type): array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM shell_presets WHERE (site_id IS NULL OR site_id=?) AND type=? ORDER BY is_system DESC, name ASC");
    $st->execute([$siteId, $type]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public static function findByKey(int $siteId, string $type, string $presetKey): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM shell_presets WHERE site_id=? AND type=? AND preset_key=? LIMIT 1");
    $st->execute([$siteId, $type, $presetKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $st2 = DB::pdo()->prepare("SELECT * FROM shell_presets WHERE site_id IS NULL AND type=? AND preset_key=? LIMIT 1");
    $st2->execute([$type, $presetKey]);
    $row2 = $st2->fetch(PDO::FETCH_ASSOC);
    return $row2 ?: null;
  }

  public static function save(int $siteId, string $type, string $presetKey, string $name, array $config, bool $isSystem = false, bool $isDefault = false): int {
    self::ensureSchema();
    $st = DB::pdo()->prepare("INSERT INTO shell_presets (site_id,type,preset_key,name,config_json,is_system,is_default) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), config_json=VALUES(config_json), is_system=VALUES(is_system), is_default=VALUES(is_default)");
    $st->execute([
      $siteId ?: null,
      $type,
      $presetKey,
      $name,
      json_encode($config, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
      $isSystem ? 1 : 0,
      $isDefault ? 1 : 0,
    ]);
    return (int)DB::pdo()->lastInsertId();
  }

  public static function duplicate(int $id, string $newKey, string $newName): ?int {
    self::ensureSchema();
    $src = self::find($id);
    if (!$src) return null;
    return self::save((int)$src['site_id'], $src['type'], $newKey, $newName, json_decode($src['config_json'], true) ?: [], false, false);
  }

  public static function find(int $id): ?array {
    self::ensureSchema();
    $st = DB::pdo()->prepare("SELECT * FROM shell_presets WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  public static function setDefault(int $siteId, string $type, string $presetKey): void {
    self::ensureSchema();
    $pdo = DB::pdo();
    $pdo->prepare("UPDATE shell_presets SET is_default=0 WHERE site_id=? AND type=?")->execute([$siteId, $type]);
    $pdo->prepare("UPDATE shell_presets SET is_default=1 WHERE site_id=? AND type=? AND preset_key=?")->execute([$siteId, $type, $presetKey]);
  }

  public static function bootstrapSystemPresets(): void {
    self::ensureSchema();
    $headerDefault = [
      'preset' => 'nav-left',
      'brandText' => 'Site',
      'logoUrl' => '',
      'cta' => ['label' => '', 'href' => ''],
      'items' => [
        ['label' => 'Home', 'href' => '/'],
        ['label' => 'About', 'href' => '/about'],
      ],
      'style' => ['variant' => 'light', 'sticky' => true],
    ];
    $footerDefault = [
      'preset' => 'footer-minimal',
      'brandText' => 'Site',
      'links' => [
        ['label' => 'About', 'href' => '/about'],
        ['label' => 'Contact', 'href' => '/contact'],
      ],
      'social' => [],
      'legal' => '© ' . date('Y') . ' Site',
      'style' => ['variant' => 'dark'],
    ];

    self::save(0, 'header', 'nav-left', 'Logo + nav', $headerDefault, true, true);
    self::save(0, 'footer', 'footer-minimal', 'Simple footer', $footerDefault, true, true);
  }
}
