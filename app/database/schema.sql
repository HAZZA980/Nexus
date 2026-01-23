CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE,
  password_hash VARCHAR(255),
  display_name VARCHAR(190),
  role ENUM('super_admin','admin','student') DEFAULT 'student',
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
