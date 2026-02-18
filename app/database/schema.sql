CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE,
  password_hash VARCHAR(255),
  display_name VARCHAR(190),
  role ENUM('super_admin','website_admin','editor','institution_admin','student') DEFAULT 'student',
  access JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_site_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  site_id INT NOT NULL,
  UNIQUE KEY uniq_user_site (user_id, site_id),
  INDEX idx_site (site_id),
  CONSTRAINT fk_user_site_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_site_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190),
  slug VARCHAR(190) UNIQUE,
  description TEXT,
  analytics_enabled TINYINT(1) NOT NULL DEFAULT 1,
  analytics_privacy_mode TINYINT(1) NOT NULL DEFAULT 0,
  analytics_retention_days INT NOT NULL DEFAULT 180,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_id INT,
  title VARCHAR(190),
  slug VARCHAR(190),
  status ENUM('draft','published') DEFAULT 'draft',
  template_key VARCHAR(100) DEFAULT 'landing',
  shell_override_json JSON NULL,
  builder_json JSON,
  search_text TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Citation examples (Cite Them Right)
CREATE TABLE IF NOT EXISTS citation_examples (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_slug VARCHAR(190) NOT NULL,
  referencing_style VARCHAR(100) NOT NULL DEFAULT 'Harvard',
  category VARCHAR(80) NOT NULL DEFAULT 'Books',
  sub_category VARCHAR(120) NULL,
  example_key VARCHAR(190) NOT NULL,
  label VARCHAR(190) NOT NULL,
  citation_order TEXT NOT NULL,
  example_heading VARCHAR(255) NOT NULL,
  example_body TEXT NOT NULL,
  you_try TEXT NOT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_site_example (site_slug, example_key),
  INDEX idx_site_slug (site_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Citation revisions (append-only audit trail)
CREATE TABLE IF NOT EXISTS citation_revisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_slug VARCHAR(190) NOT NULL,
  citation_id INT NULL,
  citation_key VARCHAR(190) NOT NULL,
  action ENUM('create','update','delete','rollback') NOT NULL,
  user_id INT NULL,
  user_email VARCHAR(190) NULL,
  release_tag VARCHAR(50) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  diff_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_site_action (site_slug, action),
  INDEX idx_site_release (site_slug, release_tag),
  INDEX idx_site_citation (site_slug, citation_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Citation releases (staging batches)
CREATE TABLE IF NOT EXISTS citation_releases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  site_slug VARCHAR(190) NOT NULL,
  tag VARCHAR(50) NOT NULL,
  status ENUM('open','exported') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  exported_at DATETIME NULL,
  exported_by_email VARCHAR(190) NULL,
  UNIQUE KEY uniq_site_tag (site_slug, tag),
  INDEX idx_site_status (site_slug, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Analytics (per site)
CREATE TABLE IF NOT EXISTS analytics_visitors (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  site_id INT NOT NULL,
  visitor_key VARCHAR(64) NOT NULL,
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_site_visitor (site_id, visitor_key),
  INDEX idx_site_last_seen (site_id, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_daily_uniques (
  site_id INT NOT NULL,
  day DATE NOT NULL,
  visitor_key VARCHAR(64) NOT NULL,
  PRIMARY KEY (site_id, day, visitor_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_rollups_daily (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_rate_limits (
  site_id INT NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  window_start DATETIME NOT NULL,
  count INT NOT NULL DEFAULT 0,
  PRIMARY KEY (site_id, ip_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
