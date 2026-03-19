<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;

final class User {
  public static function findById(int $id): ?array {
    $st = DB::pdo()->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
  }

  public static function findByEmail(string $email): ?array {
    $st = DB::pdo()->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $u = $st->fetch();
    return $u ?: null;
  }

  /**
   * Returns site slugs the user can access. Super admins get '*'.
   */
  public static function siteAccess(int $userId, string $role): array {
    if ($role === 'super_admin') return ['*'];
    $st = DB::pdo()->prepare("
      SELECT s.slug
      FROM user_site_access usa
      JOIN sites s ON s.id = usa.site_id
      WHERE usa.user_id = ?
    ");
    $st->execute([$userId]);
    return array_column($st->fetchAll(), 'slug');
  }
}
