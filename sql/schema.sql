-- IBC Office Tracker — database schema
-- Safe to run more than once (uses IF NOT EXISTS / INSERT IGNORE).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  username      VARCHAR(80)  NOT NULL UNIQUE,
  email         VARCHAR(190) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','member') NOT NULL DEFAULT 'member',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(80) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS locations (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL UNIQUE,
  name       VARCHAR(120) NOT NULL,
  maps_url   VARCHAR(500) DEFAULT NULL,
  towers     VARCHAR(255) NOT NULL DEFAULT 'A',   -- comma separated tower labels, e.g. "A,B,C"
  floors     INT NOT NULL DEFAULT 10,
  color      VARCHAR(20)  NOT NULL DEFAULT '#6ea8fe',
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendors (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  invoice_date   DATE DEFAULT NULL,
  vendor_id      INT DEFAULT NULL,
  invoice_number VARCHAR(120) DEFAULT NULL,
  amount         DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency       VARCHAR(8) NOT NULL DEFAULT 'INR',
  category       VARCHAR(80) DEFAULT NULL,
  status         ENUM('draft','submitted','approved','paid','rejected') NOT NULL DEFAULT 'submitted',
  submitted_date DATE DEFAULT NULL,
  notes          TEXT,
  created_by     INT DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_vendor  FOREIGN KEY (vendor_id)  REFERENCES vendors(id) ON DELETE SET NULL,
  CONSTRAINT fk_inv_creator FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE SET NULL,
  INDEX idx_inv_date   (invoice_date),
  INDEX idx_inv_vendor (vendor_id),
  INDEX idx_inv_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_files (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id    INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,
  mime          VARCHAR(120) DEFAULT NULL,
  size          INT DEFAULT 0,
  uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invfile FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(190) NOT NULL,
  location_id   INT DEFAULT NULL,
  tower         VARCHAR(40) DEFAULT NULL,
  floor         INT DEFAULT NULL,
  handover_date DATE DEFAULT NULL,
  status        ENUM('open','in_progress','completed','on_hold') NOT NULL DEFAULT 'open',
  area_sqft     INT DEFAULT NULL,
  client        VARCHAR(190) DEFAULT NULL,
  notes         TEXT,
  created_by    INT DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prj_loc     FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_prj_creator FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL,
  INDEX idx_prj_loc    (location_id),
  INDEX idx_prj_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_files (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  project_id    INT NOT NULL,
  doc_type      VARCHAR(40) NOT NULL DEFAULT 'Document',  -- LOI, Layout, Agreement, Other
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,
  mime          VARCHAR(120) DEFAULT NULL,
  size          INT DEFAULT 0,
  uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prjfile FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT DEFAULT NULL,
  action     VARCHAR(120) NOT NULL,
  detail     TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the four buildings (edit towers/floors/colours later in Settings → Buildings)
INSERT IGNORE INTO locations (code, name, maps_url, towers, floors, color, sort_order) VALUES
  ('DD',    'DD',    'https://maps.app.goo.gl/hsSoK8svoUvTbM7s6', 'A',   10, '#6ea8fe', 1),
  ('1-OAR', '1-OAR', 'https://maps.app.goo.gl/eJqbFtLEVf4vr1VA6', 'A',   10, '#a78bfa', 2),
  ('GE',    'GE',    'https://maps.app.goo.gl/EfuXFdUBstGaVN3H8', 'A',   10, '#34d399', 3),
  ('KP',    'KP',    'https://maps.app.goo.gl/BcXhoTejHwMxqWmeA', 'A',   10, '#fbbf24', 4);
