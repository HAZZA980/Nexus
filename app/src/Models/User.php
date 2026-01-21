<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;

final class User {
  public static function findByEmail(string $email): ?array {
    $st = DB::pdo()->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();
    return $u ?: null;
  }
}
