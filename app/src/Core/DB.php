<?php
namespace NexusCMS\Core;
use PDO;

class DB {
  private static PDO $pdo;

  public static function init(array $cfg): void {
    self::$pdo = new PDO(
      "mysql:host={$cfg['host']};dbname={$cfg['name']};charset={$cfg['charset']}",
      $cfg['user'],
      $cfg['pass'],
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
  }

  public static function pdo(): PDO {
    return self::$pdo;
  }
}
