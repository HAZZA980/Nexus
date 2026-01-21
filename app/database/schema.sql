CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE,
  password_hash VARCHAR(255),
  display_name VARCHAR(190),
  role VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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
