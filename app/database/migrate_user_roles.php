<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use NexusCMS\Core\DB;

$pdo = DB::pdo();

$canonicalRoles = ['super_admin', 'website_admin', 'editor', 'institution_admin', 'student'];
$legacyMap = [
  'admin' => 'website_admin',
  'staff_admin' => 'website_admin',
  'user_admin' => 'institution_admin',
  'viewer' => 'student',
];
$allAllowedDuringMigration = array_values(array_unique(array_merge($canonicalRoles, array_keys($legacyMap))));

try {
  $pdo->beginTransaction();

  // 1) Temporarily allow both legacy + canonical roles for safe updates.
  $enumAll = "'" . implode("','", $allAllowedDuringMigration) . "'";
  $pdo->exec("ALTER TABLE users MODIFY role ENUM({$enumAll}) NOT NULL DEFAULT 'student'");

  // 2) Detect unsupported roles before narrowing enum.
  $enumKnown = "'" . implode("','", $allAllowedDuringMigration) . "'";
  $unknownStmt = $pdo->query("SELECT DISTINCT role FROM users WHERE role NOT IN ({$enumKnown})");
  $unknownRoles = $unknownStmt ? array_values(array_filter(array_map(static fn($r) => (string)($r['role'] ?? ''), $unknownStmt->fetchAll()))) : [];
  if ($unknownRoles) {
    throw new RuntimeException('Unknown roles found in users table: ' . implode(', ', $unknownRoles));
  }

  // 3) Remap legacy roles to canonical roles.
  $update = $pdo->prepare("UPDATE users SET role = ? WHERE role = ?");
  $changes = 0;
  foreach ($legacyMap as $from => $to) {
    $update->execute([$to, $from]);
    $changes += $update->rowCount();
  }

  // 4) Lock enum to canonical roles only.
  $enumCanonical = "'" . implode("','", $canonicalRoles) . "'";
  $pdo->exec("ALTER TABLE users MODIFY role ENUM({$enumCanonical}) NOT NULL DEFAULT 'student'");

  $pdo->commit();

  echo "User role migration complete.\n";
  echo "Updated legacy rows: {$changes}\n";
  echo "Canonical roles: " . implode(', ', $canonicalRoles) . "\n";
} catch (\Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
  exit(1);
}

