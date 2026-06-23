-- IBC Office Tracker — database schema
-- Safe to run more than once (uses IF NOT EXISTS / INSERT IGNORE).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  username      VARCHAR(80)  NOT NULL UNIQUE,
  email         VARCHAR(190) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','member','viewer') NOT NULL DEFAULT 'member',
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
  project_type  VARCHAR(40) DEFAULT NULL,   -- e.g. CS, Fit-out, Other (free text + suggestions)
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

-- People directory: staff who can be assigned assets.
-- Synced from Microsoft 365 (m365_id set) or added manually (m365_id NULL).
CREATE TABLE IF NOT EXISTS people (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  m365_id      VARCHAR(64)  DEFAULT NULL UNIQUE,
  display_name VARCHAR(190) NOT NULL,
  email        VARCHAR(190) DEFAULT NULL,
  upn          VARCHAR(190) DEFAULT NULL,
  job_title    VARCHAR(120) DEFAULT NULL,
  department   VARCHAR(120) DEFAULT NULL,
  source       ENUM('manual','m365') NOT NULL DEFAULT 'manual',
  active       TINYINT(1) NOT NULL DEFAULT 1,
  synced_at    DATETIME DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_people_active (active),
  INDEX idx_people_name   (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Assets: equipment tracked + who it's assigned to and when.
CREATE TABLE IF NOT EXISTS assets (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  asset_tag          VARCHAR(80)  DEFAULT NULL,
  name               VARCHAR(190) NOT NULL,
  category           VARCHAR(80)  DEFAULT NULL,   -- Laptop, Monitor, Phone, Access Card, …
  serial_no          VARCHAR(120) DEFAULT NULL,
  status             ENUM('in_use','in_stock','repair','retired') NOT NULL DEFAULT 'in_stock',
  assigned_person_id INT DEFAULT NULL,
  assigned_on        DATE DEFAULT NULL,
  location_id        INT DEFAULT NULL,
  notes              TEXT,
  created_by         INT DEFAULT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_asset_person  FOREIGN KEY (assigned_person_id) REFERENCES people(id)    ON DELETE SET NULL,
  CONSTRAINT fk_asset_loc     FOREIGN KEY (location_id)        REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_asset_creator FOREIGN KEY (created_by)         REFERENCES users(id)     ON DELETE SET NULL,
  INDEX idx_asset_status   (status),
  INDEX idx_asset_person   (assigned_person_id),
  INDEX idx_asset_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Asset history: durable record of assignments / returns / status changes.
CREATE TABLE IF NOT EXISTS asset_events (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  asset_id   INT NOT NULL,
  event_type VARCHAR(40) NOT NULL,           -- created, assigned, returned, status_change, note
  person_id  INT DEFAULT NULL,
  detail     VARCHAR(255) DEFAULT NULL,
  event_date DATE DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aevt_asset  FOREIGN KEY (asset_id)  REFERENCES assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_aevt_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
  INDEX idx_aevt_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Brochures: provider marketing/reference documents (no pricing). One file per row.
CREATE TABLE IF NOT EXISTS brochures (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  provider      VARCHAR(80)  NOT NULL,        -- Spintly, TATA, ACT, …
  title         VARCHAR(190) NOT NULL,
  notes         TEXT,
  original_name VARCHAR(255) DEFAULT NULL,
  stored_name   VARCHAR(255) DEFAULT NULL,
  mime          VARCHAR(120) DEFAULT NULL,
  size          INT DEFAULT 0,
  created_by    INT DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_broch_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_broch_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the four buildings (edit towers/floors/colours later in Settings → Buildings)
INSERT IGNORE INTO locations (code, name, maps_url, towers, floors, color, sort_order) VALUES
  ('DD',    'DD',    'https://maps.app.goo.gl/hsSoK8svoUvTbM7s6', 'A,B,C,D', 8,  '#6ea8fe', 1),
  ('1-OAR', '1-OAR', 'https://maps.app.goo.gl/eJqbFtLEVf4vr1VA6', 'A',       9,  '#a78bfa', 2),
  ('GE',    'GE',    'https://maps.app.goo.gl/EfuXFdUBstGaVN3H8', 'A',       8,  '#34d399', 3),
  ('KP',    'KP',    'https://maps.app.goo.gl/BcXhoTejHwMxqWmeA', 'C,D',     13, '#fbbf24', 4);
