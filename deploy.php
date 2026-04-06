<?php
/**
 * PoolCheck Pro — deploy.php
 * Wordt automatisch aangeroepen door Plesk na elke git pull.
 * Alleen uitvoerbaar via command line.
 *
 * Plesk deployment action:
 *   php /var/www/vhosts/pool.villaparkfontein.com/httpdocs/deploy.php
 */

// ── Alleen CLI ────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Alleen via command line toegankelijk.');
}

// ── Versie — pas dit aan bij elke release ─────────────────────
const APP_VERSION = '4.6';

// ── DB verbinding ─────────────────────────────────────────────
// Credentials staan ook in api.php — hier hardcoded want deploy.php
// draait voor api.php geladen is
$DB = ['host'=>'localhost','name'=>'pool','user'=>'pool','pass'=>'5S!5VcwPbc%v7ofw'];

// ── Bestanden die NIET meer bestaan en verwijderd moeten worden ─
// Voeg hier toe als je ooit een bestand hernoemt of verwijdert
$REMOVE_OLD_FILES = [
    'debug.php',
    'migrate.php',
    'update.php',
    'qrcodes.html',
    'landing.html',
    'schema.sql',   // SQL zit voortaan in deploy.php
];

// ── Schema SQL ────────────────────────────────────────────────
// Alle CREATE TABLE IF NOT EXISTS en INSERT IGNORE zijn veilig om te herhalen
$SCHEMA_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(100) NOT NULL,
  phone         VARCHAR(30)  DEFAULT '',
  magic_token   VARCHAR(32)  UNIQUE NOT NULL,
  roles         VARCHAR(50)  DEFAULT 'monteur',
  active        TINYINT(1)   DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

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
  qr_token       VARCHAR(32)  UNIQUE NOT NULL,
  history_token  VARCHAR(32)  UNIQUE NOT NULL,
  pool_code      VARCHAR(8)   UNIQUE NOT NULL,
  active         TINYINT(1)   DEFAULT 1,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_woningen (
  user_id    INT NOT NULL,
  woning_id  INT NOT NULL,
  PRIMARY KEY (user_id, woning_id),
  FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (woning_id) REFERENCES woningen(id) ON DELETE CASCADE
);

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

CREATE TABLE IF NOT EXISTS ai_corrections (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  visit_id     INT,
  photo_url    VARCHAR(255),
  ai_ph        DECIMAL(4,2), ai_cl DECIMAL(5,2), ai_alk INT, ai_stab DECIMAL(5,1),
  human_ph     DECIMAL(4,2), human_cl DECIMAL(5,2), human_alk INT, human_stab DECIMAL(5,1),
  ai_reasoning TEXT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS app_meta (
  key_name   VARCHAR(50) PRIMARY KEY,
  value      VARCHAR(200) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO users (name, email, phone, magic_token, roles) VALUES
('Tycho Hombergen',  'tycho.hombergen@villaparkfontein.com', '', MD5('tycho_v4_2024'), 'monteur'),
('Manager',          'pool@villaparkfontein.com',            '', MD5('manager_v4_2024'), 'monteur,admin');

INSERT IGNORE INTO woningen (name, owner_name, email, address, pool_type, volume_liters, qr_token, history_token, pool_code) VALUES
('Villa Janssen',   'Peter Janssen',  'mitchvg@gmail.com', 'Koningslaan 12, Amsterdam', 'Privé buitenbad', 40000,  MD5('jan_qr_v4'),  MD5('jan_hist_v4'),  LEFT(MD5('jan_code_v4'),6)),
('Hotel Metropol',  'Receptie',       'mitchvg@gmail.com', 'Stationsplein 3, Utrecht',  'Hotel binnenbad', 120000, MD5('met_qr_v4'),  MD5('met_hist_v4'),  LEFT(MD5('met_code_v4'),6)),
('Villa De Vries',  'Sandra de Vries','mitchvg@gmail.com', 'Parkweg 7, Haarlem',        'Privé spa',       25000,  MD5('dev_qr_v4'),  MD5('dev_hist_v4'),  LEFT(MD5('dev_code_v4'),6));
SQL;

// ══════════════════════════════════════════════════════════════
// DEPLOY LOGIC
// ══════════════════════════════════════════════════════════════

$errors = 0;
$log    = [];

function out(string $msg, bool $isError = false): void {
    global $errors;
    echo $msg . PHP_EOL;
    if ($isError) $errors++;
}

out('╔══════════════════════════════════╗');
out('║  PoolCheck Deploy v' . APP_VERSION . '           ║');
out('║  ' . date('d-m-Y H:i:s') . '              ║');
out('╚══════════════════════════════════╝');
out('');

// ── 1. Verwijder oude bestanden ───────────────────────────────
out('[ 1/3 ] Opschonen oude bestanden...');
$webRoot = __DIR__;
foreach ($REMOVE_OLD_FILES as $file) {
    $path = $webRoot . '/' . $file;
    if (file_exists($path)) {
        if (unlink($path)) {
            out("  ✓ Verwijderd: $file");
        } else {
            out("  ✗ Kon niet verwijderen: $file", true);
        }
    }
}
out('  Klaar.');
out('');

// ── 2. Database schema bijwerken ─────────────────────────────
out('[ 2/3 ] Database schema bijwerken...');
try {
    $pdo = new PDO(
        "mysql:host={$DB['host']};dbname={$DB['name']};charset=utf8mb4",
        $DB['user'], $DB['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $statements = preg_split('/;\s*[\r\n]+/', $SCHEMA_SQL, -1, PREG_SPLIT_NO_EMPTY);
    $ok = 0; $skipped = 0;

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (!$stmt || str_starts_with(ltrim($stmt), '--')) continue;
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate entry')) {
                $skipped++;
            } else {
                out("  ✗ SQL fout: $msg", true);
            }
        }
    }

    out("  ✓ $ok statements uitgevoerd, $skipped overgeslagen (al aanwezig)");

    // ── 3. Versie opslaan in DB ───────────────────────────────
    out('');
    out('[ 3/3 ] Versie opslaan...');
    $pdo->prepare("INSERT INTO app_meta (key_name, value) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE value=?, updated_at=NOW()")
        ->execute([APP_VERSION, APP_VERSION]);
    $pdo->prepare("INSERT INTO app_meta (key_name, value) VALUES ('last_deploy', ?) ON DUPLICATE KEY UPDATE value=?, updated_at=NOW()")
        ->execute([date('d-m-Y H:i:s'), date('d-m-Y H:i:s')]);
    out('  ✓ Versie ' . APP_VERSION . ' opgeslagen in database');

} catch (PDOException $e) {
    out('  ✗ Database verbinding mislukt: ' . $e->getMessage(), true);
}

// ── Eindresultaat ─────────────────────────────────────────────
out('');
if ($errors === 0) {
    out('✅ Deploy succesvol — versie ' . APP_VERSION);
    exit(0);
} else {
    out("❌ Deploy voltooid met $errors fout(en) — controleer hierboven");
    exit(1);  // Non-zero exit = Plesk toont dit als fout
}
