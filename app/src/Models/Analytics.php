<?php
namespace NexusCMS\Models;

use NexusCMS\Core\DB;
use PDO;

final class Analytics {
  private const SESSION_TIMEOUT_SECONDS = 1800; // 30 minutes
  private const MAX_RANGE_DAYS = 180;
  private static bool $schemaChecked = false;

  private static function db(): PDO {
    return DB::pdo();
  }

  public static function ensureSchema(): void {
    if (self::$schemaChecked) return;
    self::$schemaChecked = true;
    $pdo = self::db();
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_visitors (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        visitor_key VARCHAR(64) NOT NULL,
        is_bot TINYINT(1) NOT NULL DEFAULT 0,
        first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_site_visitor (site_id, visitor_key),
        INDEX idx_site_last_seen (site_id, last_seen)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_sessions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        visitor_id BIGINT NOT NULL,
        session_key VARCHAR(64) NOT NULL,
        is_new_visitor TINYINT(1) NOT NULL DEFAULT 0,
        started_at DATETIME NOT NULL,
        ended_at DATETIME NULL,
        last_seen DATETIME NOT NULL,
        entry_path VARCHAR(255) DEFAULT '',
        exit_path VARCHAR(255) DEFAULT '',
        entry_referrer VARCHAR(255) DEFAULT '',
        referrer_domain VARCHAR(190) DEFAULT '',
        utm_source VARCHAR(100) DEFAULT '',
        utm_medium VARCHAR(100) DEFAULT '',
        utm_campaign VARCHAR(120) DEFAULT '',
        pageviews INT NOT NULL DEFAULT 0,
        bounce TINYINT(1) NOT NULL DEFAULT 1,
        device VARCHAR(50) DEFAULT '',
        browser VARCHAR(80) DEFAULT '',
        os VARCHAR(80) DEFAULT '',
        country CHAR(2) DEFAULT NULL,
        user_agent TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_site_session (site_id, session_key),
        INDEX idx_site_started (site_id, started_at),
        INDEX idx_site_last (site_id, last_seen),
        INDEX idx_site_visitor (site_id, visitor_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        site_id INT NOT NULL,
        session_id BIGINT NULL,
        visitor_id BIGINT NULL,
        event_type ENUM('view','404','perf') NOT NULL DEFAULT 'view',
        path VARCHAR(255) DEFAULT '',
        title VARCHAR(255) DEFAULT '',
        referrer VARCHAR(255) DEFAULT '',
        referrer_domain VARCHAR(190) DEFAULT '',
        utm_source VARCHAR(100) DEFAULT '',
        utm_medium VARCHAR(100) DEFAULT '',
        utm_campaign VARCHAR(120) DEFAULT '',
        device VARCHAR(50) DEFAULT '',
        browser VARCHAR(80) DEFAULT '',
        os VARCHAR(80) DEFAULT '',
        country CHAR(2) DEFAULT NULL,
        status_code SMALLINT DEFAULT NULL,
        load_ms INT DEFAULT NULL,
        ttfb_ms INT DEFAULT NULL,
        is_new_visitor TINYINT(1) DEFAULT 0,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_site_time (site_id, occurred_at),
        INDEX idx_site_type (site_id, event_type),
        INDEX idx_site_path (site_id, path)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_daily_uniques (
        site_id INT NOT NULL,
        day DATE NOT NULL,
        visitor_key VARCHAR(64) NOT NULL,
        PRIMARY KEY (site_id, day, visitor_key)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_rollups_daily (
        site_id INT NOT NULL,
        day DATE NOT NULL,
        views INT NOT NULL DEFAULT 0,
        unique_visitors INT NOT NULL DEFAULT 0,
        sessions INT NOT NULL DEFAULT 0,
        bounces INT NOT NULL DEFAULT 0,
        total_session_seconds BIGINT NOT NULL DEFAULT 0,
        pageviews INT NOT NULL DEFAULT 0,
        new_visitors INT NOT NULL DEFAULT 0,
        PRIMARY KEY (site_id, day)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_rate_limits (
        site_id INT NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        window_start DATETIME NOT NULL,
        count INT NOT NULL DEFAULT 0,
        PRIMARY KEY (site_id, ip_hash)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
      // do not block runtime if schema alignment fails
    }
  }

  private static function normalizeKey(?string $key): ?string {
    if (!is_string($key)) return null;
    $clean = preg_replace('/[^a-f0-9]/i', '', $key);
    if ($clean === '' || strlen($clean) < 8) return null;
    return substr($clean, 0, 64);
  }

  private static function randomKey(int $len = 32): string {
    return substr(bin2hex(random_bytes((int)max(4, ceil($len / 2)))), 0, $len);
  }

  private static function normalizePath(?string $path): string {
    if (!is_string($path) || $path === '') return '/';
    $clean = strtok($path, "\n\r");
    return substr($clean, 0, 250);
  }

  private static function sanitize(?string $val, int $max = 190): string {
    if (!is_string($val)) return '';
    $val = trim(str_replace(["\r", "\n"], ' ', $val));
    if (strlen($val) > $max) $val = substr($val, 0, $max);
    return $val;
  }

  private static function refDomain(string $referrer): string {
    if ($referrer === '') return '';
    $host = parse_url($referrer, PHP_URL_HOST) ?? '';
    return $host ?: '';
  }

  private static function uaMeta(string $ua): array {
    $ua = strtolower($ua);
    $device = 'desktop';
    if (preg_match('/(iphone|android|phone)/', $ua)) $device = 'mobile';
    if (preg_match('/(ipad|tablet)/', $ua)) $device = 'tablet';

    $browser = 'Other';
    if (strpos($ua, 'chrome') !== false && strpos($ua, 'edge') === false) $browser = 'Chrome';
    elseif (strpos($ua, 'safari') !== false && strpos($ua, 'chrome') === false) $browser = 'Safari';
    elseif (strpos($ua, 'firefox') !== false) $browser = 'Firefox';
    elseif (strpos($ua, 'edge') !== false) $browser = 'Edge';

    $os = 'Other';
    if (strpos($ua, 'windows') !== false) $os = 'Windows';
    elseif (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) $os = 'macOS';
    elseif (strpos($ua, 'android') !== false) $os = 'Android';
    elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) $os = 'iOS';
    elseif (strpos($ua, 'linux') !== false) $os = 'Linux';

    return ['device' => $device, 'browser' => $browser, 'os' => $os];
  }

  private static function isRateLimited(int $siteId, string $ip): bool {
    $ipHash = hash('sha256', $ip ?: 'unknown');
    $windowStart = date('Y-m-d H:i:00', floor(time() / 300) * 300); // 5-minute buckets
    $pdo = self::db();
    try {
      $st = $pdo->prepare("SELECT window_start, count FROM analytics_rate_limits WHERE site_id=? AND ip_hash=? LIMIT 1");
      $st->execute([$siteId, $ipHash]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      $count = 1;
      if ($row && ($row['window_start'] ?? '') === $windowStart) {
        $count = (int)$row['count'] + 1;
        $upd = $pdo->prepare("UPDATE analytics_rate_limits SET count=? WHERE site_id=? AND ip_hash=? LIMIT 1");
        $upd->execute([$count, $siteId, $ipHash]);
      } else {
        $ins = $pdo->prepare("REPLACE INTO analytics_rate_limits (site_id, ip_hash, window_start, count) VALUES (?,?,?,1)");
        $ins->execute([$siteId, $ipHash, $windowStart]);
      }
      return $count > 200; // ~40 events/minute cap
    } catch (\Throwable $e) {
      return false;
    }
  }

  private static function pruneOld(int $retentionDays): void {
    // light-touch pruning to keep dashboards fast
    if (random_int(1, 50) !== 10) return;
    $retentionDays = max(30, min(self::MAX_RANGE_DAYS * 2, $retentionDays));
    $pdo = self::db();
    try {
      $pdo->prepare("DELETE FROM analytics_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([$retentionDays]);
      $pdo->prepare("DELETE FROM analytics_sessions WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([$retentionDays + 90]);
      $pdo->prepare("DELETE FROM analytics_daily_uniques WHERE day < DATE_SUB(CURDATE(), INTERVAL ? DAY)")->execute([$retentionDays]);
      $pdo->prepare("DELETE FROM analytics_rollups_daily WHERE day < DATE_SUB(CURDATE(), INTERVAL ? DAY)")->execute([$retentionDays + 180]);
      $pdo->prepare("DELETE FROM analytics_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)")->execute();
    } catch (\Throwable $e) {}
  }

  /**
   * Record a page view or 404. Returns visitor/session identifiers so the caller can persist cookies.
   */
  public static function record(array $payload): array {
    self::ensureSchema();
    $siteId = (int)($payload['site_id'] ?? 0);
    if ($siteId <= 0) return ['ok' => false, 'error' => 'invalid_site'];

    $site = Site::find($siteId);
    if (!$site) return ['ok' => false, 'error' => 'site_not_found'];
    if (!($site['analytics_enabled'] ?? 1)) return ['ok' => false, 'error' => 'disabled'];

    $respectDnt = !empty($payload['dnt']) || (($_SERVER['HTTP_DNT'] ?? '') === '1');
    if ($respectDnt) return ['ok' => false, 'error' => 'dnt'];

    if (self::isRateLimited($siteId, $_SERVER['REMOTE_ADDR'] ?? '')) {
      return ['ok' => false, 'error' => 'rate_limited'];
    }

    $privacy = (bool)($site['analytics_privacy_mode'] ?? false);
    $retentionDays = (int)($site['analytics_retention_days'] ?? 180);
    $now = new \DateTimeImmutable('now');
    $nowStr = $now->format('Y-m-d H:i:s');
    $day = $now->format('Y-m-d');

    $visitorKey = self::normalizeKey($payload['visitor_key'] ?? '') ?? self::randomKey();
    [$visitorId, $isNewVisitor] = self::upsertVisitor($siteId, $visitorKey, $nowStr);
    $countedUnique = self::markDailyUnique($siteId, $day, $visitorKey);

    $sessionKey = self::normalizeKey($payload['session_key'] ?? '');
    $session = $sessionKey ? self::findSession($siteId, $sessionKey) : null;
    $sessionExpired = !$session || (strtotime((string)$session['last_seen']) < ($now->getTimestamp() - self::SESSION_TIMEOUT_SECONDS));

    $meta = self::uaMeta($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device = $meta['device'] ?? 'desktop';
    $browser = $meta['browser'] ?? '';
    $os = $meta['os'] ?? '';
    if ($privacy) {
      $browser = $browser ? $browser : '';
      $os = '';
    }

    $utmSource = self::sanitize($payload['utm_source'] ?? '', 100);
    $utmMedium = self::sanitize($payload['utm_medium'] ?? '', 100);
    $utmCampaign = self::sanitize($payload['utm_campaign'] ?? '', 120);
    $path = self::normalizePath($payload['path'] ?? '/');
    $title = self::sanitize($payload['title'] ?? '', 240);
    $referrer = self::sanitize($payload['referrer'] ?? '', 240);
    $refDomain = $referrer !== '' ? self::refDomain($referrer) : '';
    if ($privacy && $referrer !== '') {
      $referrer = $refDomain;
    }

    $country = '';
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
      $country = substr($_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2);
    }

    $pdo = self::db();
    $sessionId = null;
    $isNewSession = false;
    $previousLastSeen = $session['last_seen'] ?? $nowStr;
    if ($sessionExpired) {
      $sessionKey = self::randomKey(24);
      $isNewSession = true;
      $stmt = $pdo->prepare("INSERT INTO analytics_sessions
        (site_id, visitor_id, session_key, is_new_visitor, started_at, ended_at, last_seen, entry_path, exit_path, entry_referrer, referrer_domain, utm_source, utm_medium, utm_campaign, pageviews, bounce, device, browser, os, country, user_agent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([
        $siteId,
        $visitorId,
        $sessionKey,
        $isNewVisitor ? 1 : 0,
        $nowStr,
        null,
        $nowStr,
        $path,
        $path,
        $referrer,
        $refDomain,
        $utmSource,
        $utmMedium,
        $utmCampaign,
        0,
        1,
        $device,
        $browser,
        $os,
        $country !== '' ? $country : null,
        $privacy ? null : ($_SERVER['HTTP_USER_AGENT'] ?? null)
      ]);
      $sessionId = (int)$pdo->lastInsertId();
      $session = [
        'id' => $sessionId,
        'pageviews' => 0,
        'bounce' => 1,
        'last_seen' => $nowStr,
        'started_at' => $nowStr,
      ];
      $previousLastSeen = $nowStr;
    } else {
      $sessionId = (int)$session['id'];
      $stmt = $pdo->prepare("UPDATE analytics_sessions
        SET pageviews = pageviews + 1,
            exit_path = :exit_path,
            last_seen = :last_seen,
            ended_at = :ended_at,
            bounce = IF(pageviews + 1 > 1, 0, bounce)
        WHERE id = :id LIMIT 1");
      $stmt->execute([
        ':exit_path' => $path,
        ':last_seen' => $nowStr,
        ':ended_at' => $nowStr,
        ':id' => $sessionId,
      ]);
    }

    $eventType = !empty($payload['is_404']) ? '404' : 'view';
    $loadMs = isset($payload['load_ms']) ? (int)$payload['load_ms'] : null;
    $ttfbMs = isset($payload['ttfb_ms']) ? (int)$payload['ttfb_ms'] : null;

    try {
      $stmt = $pdo->prepare("INSERT INTO analytics_events
        (site_id, session_id, visitor_id, event_type, path, title, referrer, referrer_domain, utm_source, utm_medium, utm_campaign, device, browser, os, country, status_code, load_ms, ttfb_ms, is_new_visitor, occurred_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([
        $siteId,
        $sessionId,
        $visitorId,
        $eventType,
        $path,
        $title,
        $referrer,
        $refDomain,
        $utmSource,
        $utmMedium,
        $utmCampaign,
        $device,
        $browser,
        $os,
        $country !== '' ? $country : null,
        isset($payload['status_code']) ? (int)$payload['status_code'] : null,
        $loadMs,
        $ttfbMs,
        $isNewVisitor ? 1 : 0,
        $nowStr
      ]);
    } catch (\Throwable $e) {
      // avoid breaking public pages
    }

    $durationAdd = max(0, $now->getTimestamp() - strtotime((string)$previousLastSeen));
    $isBounce = ($session['pageviews'] ?? 0) === 0;
    self::updateRollup($siteId, $day, [
      'views' => $eventType === 'view' ? 1 : 0,
      'unique' => $countedUnique ? 1 : 0,
      'sessions' => $isNewSession ? 1 : 0,
      'bounces' => $isBounce ? 1 : 0,
      'duration' => $durationAdd,
      'pageviews' => 1,
      'new_visitors' => $isNewVisitor ? 1 : 0,
    ]);

    self::pruneOld($retentionDays);

    return [
      'ok' => true,
      'visitor_key' => $visitorKey,
      'session_key' => $sessionKey,
      'is_new_session' => $isNewSession,
    ];
  }

  public static function record404(int $siteId, string $path, array $meta = []): void {
    $payload = array_merge($meta, [
      'site_id' => $siteId,
      'path' => $path,
      'title' => $meta['title'] ?? '404 Not Found',
      'referrer' => $meta['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? ''),
      'is_404' => true,
      'visitor_key' => $meta['visitor_key'] ?? null,
      'session_key' => $meta['session_key'] ?? null,
      'status_code' => 404,
      'dnt' => $meta['dnt'] ?? false,
    ]);
    self::record($payload);
  }

  private static function upsertVisitor(int $siteId, string $visitorKey, string $now): array {
    $pdo = self::db();
    $isNew = false;
    $visitorId = null;
    try {
      $stmt = $pdo->prepare("INSERT INTO analytics_visitors (site_id, visitor_key, first_seen, last_seen) VALUES (?,?,?,?)");
      $stmt->execute([$siteId, $visitorKey, $now, $now]);
      $visitorId = (int)$pdo->lastInsertId();
      $isNew = true;
    } catch (\Throwable $e) {
      $stmt = $pdo->prepare("SELECT id FROM analytics_visitors WHERE site_id=? AND visitor_key=? LIMIT 1");
      $stmt->execute([$siteId, $visitorKey]);
      $visitorId = (int)$stmt->fetchColumn();
      if ($visitorId) {
        $pdo->prepare("UPDATE analytics_visitors SET last_seen=? WHERE id=?")->execute([$now, $visitorId]);
      }
    }
    return [$visitorId ?: 0, $isNew];
  }

  private static function findSession(int $siteId, string $sessionKey): ?array {
    try {
      $stmt = self::db()->prepare("SELECT * FROM analytics_sessions WHERE site_id=? AND session_key=? LIMIT 1");
      $stmt->execute([$siteId, $sessionKey]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (\Throwable $e) {
      return null;
    }
  }

  private static function markDailyUnique(int $siteId, string $day, string $visitorKey): bool {
    try {
      $stmt = self::db()->prepare("INSERT IGNORE INTO analytics_daily_uniques (site_id, day, visitor_key) VALUES (?,?,?)");
      $stmt->execute([$siteId, $day, $visitorKey]);
      return $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
      return false;
    }
  }

  private static function updateRollup(int $siteId, string $day, array $delta): void {
    $pdo = self::db();
    $fields = array_merge([
      'views' => 0,
      'unique' => 0,
      'sessions' => 0,
      'bounces' => 0,
      'duration' => 0,
      'pageviews' => 0,
      'new_visitors' => 0,
    ], $delta);
    try {
      $stmt = $pdo->prepare("INSERT INTO analytics_rollups_daily (site_id, day, views, unique_visitors, sessions, bounces, total_session_seconds, pageviews, new_visitors)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          views = views + VALUES(views),
          unique_visitors = unique_visitors + VALUES(unique_visitors),
          sessions = sessions + VALUES(sessions),
          bounces = bounces + VALUES(bounces),
          total_session_seconds = total_session_seconds + VALUES(total_session_seconds),
          pageviews = pageviews + VALUES(pageviews),
          new_visitors = new_visitors + VALUES(new_visitors)");
      $stmt->execute([
        $siteId,
        $day,
        (int)$fields['views'],
        (int)$fields['unique'],
        (int)$fields['sessions'],
        (int)$fields['bounces'],
        (int)$fields['duration'],
        (int)$fields['pageviews'],
        (int)$fields['new_visitors'],
      ]);
    } catch (\Throwable $e) {}
  }

  private static function clampRange(\DateTimeInterface $start, \DateTimeInterface $end): array {
    $maxStart = (new \DateTimeImmutable('now'))->sub(new \DateInterval('P' . self::MAX_RANGE_DAYS . 'D'));
    if ($start < $maxStart) $start = $maxStart;
    if ($start > $end) $start = $end;
    return [$start, $end];
  }

  public static function dashboard(int $siteId, \DateTimeInterface $start, \DateTimeInterface $end): array {
    self::ensureSchema();
    [$start, $end] = self::clampRange($start, $end);
    $pdo = self::db();
    $startStr = $start->format('Y-m-d 00:00:00');
    $endStr = $end->format('Y-m-d 23:59:59');

    $summary = [
      'views' => 0,
      'unique' => 0,
      'sessions' => 0,
      'bounces' => 0,
      'pages_per_session' => 0,
      'avg_session_seconds' => 0,
      'new_visitors' => 0,
    ];

    try {
      $st = $pdo->prepare("SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS uniq
        FROM analytics_events
        WHERE site_id=? AND event_type='view' AND occurred_at BETWEEN ? AND ?");
      $st->execute([$siteId, $startStr, $endStr]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $summary['views'] = (int)($row['views'] ?? 0);
      $summary['unique'] = (int)($row['uniq'] ?? 0);
    } catch (\Throwable $e) {}

    try {
      $st = $pdo->prepare("SELECT COUNT(*) AS sessions, SUM(pageviews) AS pageviews, SUM(bounce) AS bounces,
        SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, last_seen))) AS duration,
        SUM(is_new_visitor) AS new_visitors
        FROM analytics_sessions
        WHERE site_id=? AND started_at BETWEEN ? AND ?");
      $st->execute([$siteId, $startStr, $endStr]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $summary['sessions'] = (int)($row['sessions'] ?? 0);
      $summary['bounces'] = (int)($row['bounces'] ?? 0);
      $summary['pages_per_session'] = $summary['sessions'] > 0 ? round(((int)($row['pageviews'] ?? 0)) / $summary['sessions'], 2) : 0;
      $summary['avg_session_seconds'] = $summary['sessions'] > 0 ? (int)ceil(((int)($row['duration'] ?? 0)) / $summary['sessions']) : 0;
      $summary['new_visitors'] = (int)($row['new_visitors'] ?? 0);
    } catch (\Throwable $e) {}

    $trend = ['views' => [], 'unique' => []];
    try {
      $st = $pdo->prepare("SELECT DATE(occurred_at) as d, COUNT(*) as views, COUNT(DISTINCT visitor_id) as uniq
        FROM analytics_events
        WHERE site_id=? AND event_type='view' AND occurred_at BETWEEN ? AND ?
        GROUP BY d ORDER BY d ASC");
      $st->execute([$siteId, $startStr, $endStr]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $trend['views'][] = ['day' => $r['d'], 'value' => (int)$r['views']];
        $trend['unique'][] = ['day' => $r['d'], 'value' => (int)$r['uniq']];
      }
    } catch (\Throwable $e) {}

    $breakdowns = [
      'pages' => [],
      'referrers' => [],
      'campaigns' => [],
      'devices' => [],
      'browsers' => [],
      'oses' => [],
      'new_vs_returning' => [],
      'four_oh_four' => [],
      'slow_pages' => [],
    ];

    try {
      $st = $pdo->prepare("SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS uniq
        FROM analytics_events
        WHERE site_id=? AND event_type='view' AND occurred_at BETWEEN ? AND ?
        GROUP BY path ORDER BY views DESC LIMIT 20");
      $st->execute([$siteId, $startStr, $endStr]);
      $breakdowns['pages'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}

    try {
      $st = $pdo->prepare("SELECT referrer_domain as referrer, COUNT(*) AS views
        FROM analytics_events
        WHERE site_id=? AND event_type='view' AND referrer_domain <> '' AND occurred_at BETWEEN ? AND ?
        GROUP BY referrer_domain ORDER BY views DESC LIMIT 15");
      $st->execute([$siteId, $startStr, $endStr]);
      $breakdowns['referrers'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}

    try {
      $st = $pdo->prepare("SELECT CONCAT_WS(' / ', NULLIF(utm_source,''), NULLIF(utm_medium,''), NULLIF(utm_campaign,'')) AS campaign,
        COUNT(*) AS views
        FROM analytics_events
        WHERE site_id=? AND event_type='view' AND (utm_source<>'' OR utm_medium<>'' OR utm_campaign<>'') AND occurred_at BETWEEN ? AND ?
        GROUP BY utm_source, utm_medium, utm_campaign
        ORDER BY views DESC LIMIT 15");
      $st->execute([$siteId, $startStr, $endStr]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $breakdowns['campaigns'] = array_map(function($r){
        return ['campaign' => $r['campaign'] ?: 'utm / (unspecified)', 'views' => (int)$r['views']];
      }, $rows);
    } catch (\Throwable $e) {}

    foreach (['device' => 'devices', 'browser' => 'browsers', 'os' => 'oses'] as $col => $key) {
      try {
        $st = $pdo->prepare("SELECT {$col} as label, COUNT(*) AS views
          FROM analytics_events
          WHERE site_id=? AND event_type='view' AND occurred_at BETWEEN ? AND ?
          GROUP BY {$col} ORDER BY views DESC LIMIT 10");
        $st->execute([$siteId, $startStr, $endStr]);
        $breakdowns[$key] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (\Throwable $e) {}
    }

    try {
      $st = $pdo->prepare("SELECT
        SUM(is_new_visitor) AS new_visitors,
        SUM(CASE WHEN is_new_visitor=0 THEN 1 ELSE 0 END) AS returning_visitors
        FROM analytics_sessions WHERE site_id=? AND started_at BETWEEN ? AND ?");
      $st->execute([$siteId, $startStr, $endStr]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $breakdowns['new_vs_returning'] = [
        'new' => (int)($row['new_visitors'] ?? 0),
        'returning' => (int)($row['returning_visitors'] ?? 0),
      ];
    } catch (\Throwable $e) {}

    try {
      $st = $pdo->prepare("SELECT path, COUNT(*) AS hits
        FROM analytics_events
        WHERE site_id=? AND event_type='404' AND occurred_at BETWEEN ? AND ?
        GROUP BY path ORDER BY hits DESC LIMIT 20");
      $st->execute([$siteId, $startStr, $endStr]);
      $breakdowns['four_oh_four'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}

    try {
      $st = $pdo->prepare("SELECT path, ROUND(AVG(load_ms)) AS load_ms, COUNT(*) AS samples
        FROM analytics_events
        WHERE site_id=? AND load_ms IS NOT NULL AND load_ms > 0 AND occurred_at BETWEEN ? AND ?
        GROUP BY path HAVING samples >= 3
        ORDER BY load_ms DESC LIMIT 10");
      $st->execute([$siteId, $startStr, $endStr]);
      $breakdowns['slow_pages'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}

    // Simple period-over-period comparison
    $spanDays = max(1, (int)ceil(($end->getTimestamp() - $start->getTimestamp()) / 86400));
    $prevStart = (clone $start)->sub(new \DateInterval('P' . $spanDays . 'D'));
    $prevEnd = (clone $end)->sub(new \DateInterval('P' . $spanDays . 'D'));
    $comparison = ['views' => 0, 'sessions' => 0];
    try {
      $st = $pdo->prepare("SELECT COUNT(*) FROM analytics_events WHERE site_id=? AND event_type='view' AND occurred_at BETWEEN ? AND ?");
      $st->execute([$siteId, $prevStart->format('Y-m-d 00:00:00'), $prevEnd->format('Y-m-d 23:59:59')]);
      $comparison['views'] = (int)$st->fetchColumn();
    } catch (\Throwable $e) {}
    try {
      $st = $pdo->prepare("SELECT COUNT(*) FROM analytics_sessions WHERE site_id=? AND started_at BETWEEN ? AND ?");
      $st->execute([$siteId, $prevStart->format('Y-m-d 00:00:00'), $prevEnd->format('Y-m-d 23:59:59')]);
      $comparison['sessions'] = (int)$st->fetchColumn();
    } catch (\Throwable $e) {}

    return [
      'summary' => $summary,
      'trend' => $trend,
      'breakdowns' => $breakdowns,
      'period' => [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'previous_start' => $prevStart->format('Y-m-d'),
        'previous_end' => $prevEnd->format('Y-m-d'),
        'comparison' => $comparison,
      ],
    ];
  }

  public static function exportCsv(int $siteId, \DateTimeInterface $start, \DateTimeInterface $end, string $report): string {
    [$start, $end] = self::clampRange($start, $end);
    $pdo = self::db();
    $startStr = $start->format('Y-m-d 00:00:00');
    $endStr = $end->format('Y-m-d 23:59:59');
    $lines = [];
    $report = strtolower($report);

    $addLines = function(array $rows, array $headers) use (&$lines) {
      $lines[] = implode(',', $headers);
      foreach ($rows as $r) {
        $values = [];
        foreach ($headers as $h) {
          $values[] = '"' . str_replace('"', '""', (string)($r[$h] ?? '')) . '"';
        }
        $lines[] = implode(',', $values);
      }
    };

    if ($report === 'pages') {
      $st = $pdo->prepare("SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS unique_visitors
        FROM analytics_events WHERE site_id=? AND event_type='view' AND occurred_at BETWEEN ? AND ?
        GROUP BY path ORDER BY views DESC");
      $st->execute([$siteId, $startStr, $endStr]);
      $addLines($st->fetchAll(PDO::FETCH_ASSOC) ?: [], ['path','views','unique_visitors']);
    } elseif ($report === 'referrers') {
      $st = $pdo->prepare("SELECT referrer_domain, COUNT(*) AS views
        FROM analytics_events WHERE site_id=? AND event_type='view' AND occurred_at BETWEEN ? AND ?
        GROUP BY referrer_domain ORDER BY views DESC");
      $st->execute([$siteId, $startStr, $endStr]);
      $addLines($st->fetchAll(PDO::FETCH_ASSOC) ?: [], ['referrer_domain','views']);
    } elseif ($report === 'sessions') {
      $st = $pdo->prepare("SELECT session_key, started_at, ended_at, entry_path, exit_path, pageviews, bounce, device, browser, os
        FROM analytics_sessions WHERE site_id=? AND started_at BETWEEN ? AND ? ORDER BY started_at DESC");
      $st->execute([$siteId, $startStr, $endStr]);
      $addLines($st->fetchAll(PDO::FETCH_ASSOC) ?: [], ['session_key','started_at','ended_at','entry_path','exit_path','pageviews','bounce','device','browser','os']);
    } else {
      $st = $pdo->prepare("SELECT occurred_at, path, title, referrer, utm_source, utm_medium, utm_campaign, device, browser, os, event_type
        FROM analytics_events WHERE site_id=? AND occurred_at BETWEEN ? AND ? ORDER BY occurred_at DESC");
      $st->execute([$siteId, $startStr, $endStr]);
      $addLines($st->fetchAll(PDO::FETCH_ASSOC) ?: [], ['occurred_at','path','title','referrer','utm_source','utm_medium','utm_campaign','device','browser','os','event_type']);
    }

    return implode("\n", $lines);
  }

  public static function preview(int $siteId, int $days = 7): array {
    self::ensureSchema();
    $days = max(1, min(90, $days));
    $start = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P' . ($days - 1) . 'D'));
    $end = new \DateTimeImmutable('today');
    $pdo = self::db();
    $st = $pdo->prepare("SELECT COUNT(*) FROM analytics_events WHERE site_id=? AND event_type='view' AND occurred_at BETWEEN ? AND ?");
    $st->execute([$siteId, $start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]);
    $recent = (int)$st->fetchColumn();

    $prevStart = (clone $start)->sub(new \DateInterval('P' . $days . 'D'));
    $prevEnd = (clone $end)->sub(new \DateInterval('P' . $days . 'D'));
    $st->execute([$siteId, $prevStart->format('Y-m-d 00:00:00'), $prevEnd->format('Y-m-d 23:59:59')]);
    $prev = (int)$st->fetchColumn();

    $delta = $recent - $prev;
    $pct = $prev > 0 ? round(($delta / $prev) * 100, 1) : ($recent > 0 ? 100 : 0);

    return [
      'views' => $recent,
      'previous' => $prev,
      'delta' => $delta,
      'percent' => $pct,
    ];
  }
}
