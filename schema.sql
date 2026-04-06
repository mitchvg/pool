-- PoolCheck Pro v4 — Schema
-- Veilig te herhalen: gebruik IF NOT EXISTS + INSERT IGNORE
-- Voer uit via Plesk > Databases > phpMyAdmin > SQL tab

-- ============================================================
-- USERS (monteur + klant + admin, combineerbaar)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(100) NOT NULL,
  phone         VARCHAR(30)  DEFAULT '',
  magic_token   VARCHAR(32)  UNIQUE NOT NULL,
  -- rollen: komma-gescheiden 'monteur','klant','admin'
  roles         VARCHAR(50)  DEFAULT 'monteur',
  active        TINYINT(1)   DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- WONINGEN (was: clients)
-- ============================================================
CREATE TABLE IF NOT EXISTS woningen (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL,
  owner_name     VARCHAR(100) DEFAULT '',
  email          VARCHAR(100) DEFAULT '',
  phone          VARCHAR(30)  DEFAULT '',
  address        VARCHAR(200) DEFAULT '',
  pool_type      VARCHAR(50)  DEFAULT 'Privé buitenbad',
  volume_liters  INT          DEFAULT 40000,
  notes          TEXT,
  qr_token       VARCHAR(32)  UNIQUE NOT NULL,  -- URL ?k=TOKEN
  history_token  VARCHAR(32)  UNIQUE NOT NULL,  -- URL history.html?h=TOKEN
  pool_code      VARCHAR(8)   UNIQUE NOT NULL,  -- kort: handmatig invoeren
  active         TINYINT(1)   DEFAULT 1,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- USER_WONINGEN: koppelt klanten aan hun woning(en)
-- Monteurs met rol 'monteur' kunnen alle woningen bezoeken
-- Klanten zien alleen hun eigen gekoppelde woningen
-- ============================================================
CREATE TABLE IF NOT EXISTS user_woningen (
  user_id    INT NOT NULL,
  woning_id  INT NOT NULL,
  PRIMARY KEY (user_id, woning_id),
  FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (woning_id) REFERENCES woningen(id) ON DELETE CASCADE
);

-- ============================================================
-- VISITS
-- ============================================================
CREATE TABLE IF NOT EXISTS visits (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  woning_id                INT NOT NULL,
  user_id                  INT,
  visit_date               DATE      NOT NULL,
  visit_time               TIME      NOT NULL,
  ph                       DECIMAL(4,2),
  chlorine                 DECIMAL(5,2),
  alkalinity               INT,
  stabilizer               DECIMAL(5,1),
  volume_used              INT,
  notes                    TEXT,
  advice_json              TEXT,
  strip_photo              VARCHAR(255) DEFAULT '',
  confirm_photo_chemicals  VARCHAR(255) DEFAULT '',
  confirm_photo_pool       VARCHAR(255) DEFAULT '',
  email_sent               TINYINT(1)  DEFAULT 0,
  created_at               TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (woning_id) REFERENCES woningen(id),
  FOREIGN KEY (user_id)   REFERENCES users(id)
);

-- ============================================================
-- STARTDATA — pas aan naar echte gegevens
-- ============================================================
INSERT IGNORE INTO users (name, email, phone, magic_token, roles) VALUES
('Tycho Hombergen',  'tycho.hombergen@villaparkfontein.com', '', MD5('tycho_v4_2024'), 'monteur'),
('Manager',          'pool@villaparkfontein.com',            '', MD5('manager_v4_2024'), 'monteur,admin');

INSERT IGNORE INTO woningen (name, owner_name, email, address, pool_type, volume_liters, qr_token, history_token, pool_code) VALUES
('Villa Janssen',   'Peter Janssen',  'mitchvg@gmail.com', 'Koningslaan 12, Amsterdam', 'Privé buitenbad', 40000,  MD5('jan_qr_v4'), MD5('jan_hist_v4'), LEFT(MD5('jan_code_v4'),6)),
('Hotel Metropol',  'Receptie',       'mitchvg@gmail.com', 'Stationsplein 3, Utrecht',  'Hotel binnenbad', 120000, MD5('met_qr_v4'), MD5('met_hist_v4'), LEFT(MD5('met_code_v4'),6)),
('Villa De Vries',  'Sandra de Vries','mitchvg@gmail.com', 'Parkweg 7, Haarlem',        'Privé spa',       25000,  MD5('dev_qr_v4'), MD5('dev_hist_v4'), LEFT(MD5('dev_code_v4'),6));

-- AI training data — opgeslagen voor toekomstige verbetering
CREATE TABLE IF NOT EXISTS ai_corrections (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  visit_id     INT,
  photo_url    VARCHAR(255),
  ai_ph        DECIMAL(4,2), ai_cl DECIMAL(5,2), ai_alk INT, ai_stab DECIMAL(5,1),
  human_ph     DECIMAL(4,2), human_cl DECIMAL(5,2), human_alk INT, human_stab DECIMAL(5,1),
  ai_reasoning TEXT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
