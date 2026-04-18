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

  public static function notificationRecipientsForRoleAndSite(string $role, int $siteId): array {
    $role = \NexusCMS\Models\PageFlag::canonicalRole($role);
    if ($siteId <= 0) return [];
    if ($role === 'super_admin') {
      $st = DB::pdo()->prepare("SELECT email FROM users WHERE role IN ('super_admin') AND email <> ''");
      $st->execute();
      return array_values(array_unique(array_column($st->fetchAll(), 'email')));
    }

    $st = DB::pdo()->prepare("
      SELECT DISTINCT u.email
      FROM users u
      LEFT JOIN user_site_access usa ON usa.user_id = u.id
      WHERE u.email <> ''
        AND (
          (u.role = ? AND usa.site_id = ?)
          OR (u.role = 'super_admin')
        )
    ");
    $st->execute([$role, $siteId]);
    return array_values(array_unique(array_column($st->fetchAll(), 'email')));
  }
}
