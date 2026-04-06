<?php
/**
 * PoolCheck Pro — deploy.php
 * Plesk deployment action:
 *   php /var/www/vhosts/pool.villaparkfontein.com/httpdocs/deploy.php 2>&1
 *
 * Versie wordt automatisch bepaald uit de git commit datum — nooit handmatig aanpassen.
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ── Alleen CLI ───────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Alleen via command line.';
    exit;
}

// ── DB verbinding — uit config.local.php ─────────────────────
if (file_exists(__DIR__.'/config.local.php')) {
    require_once __DIR__.'/config.local.php';
}
$DB_HOST = defined('DB_HOST') ? DB_HOST : 'localhost';
$DB_NAME = defined('DB_NAME') ? DB_NAME : 'pool';
$DB_USER = defined('DB_USER') ? DB_USER : 'pool';
$DB_PASS = defined('DB_PASS') ? DB_PASS : '';

// ── Versie: automatisch uit git commit datum ─────────────────
function getVersion(): string {
    $dir = __DIR__;
    // Probeer git commit timestamp
    $git = @shell_exec("cd {$dir} && git log -1 --format='%ci' 2>/dev/null");
    if ($git && strlen(trim($git)) > 10) {
        return substr(trim($git), 0, 16); // "2026-04-06 14:30"
    }
    // Fallback: wijzigingsdatum van dit bestand
    return date('Y-m-d H:i', filemtime(__FILE__));
}
$VERSION = getVersion();

// ── Manifest: alle bestanden die in de repo HOREN te zitten ──
// Alles wat NIET in deze lijst staat en WEL een .php of .html is
// wordt verwijderd (beschermt uploads/, _backups/ etc.)
$MANIFEST = [
    'index.html',
    'admin.html',
    'history.html',
    'api.php',
    'deploy.php',
    'README.md',
    '.gitignore',
];

// ── Schema SQL (ingebed — geen aparte schema.sql nodig) ──────
$SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL,
  phone VARCHAR(30) DEFAULT '',
  magic_token VARCHAR(32) UNIQUE NOT NULL,
  roles VARCHAR(50) DEFAULT 'monteur',
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS woningen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  owner_name VARCHAR(100) DEFAULT '',
  email VARCHAR(100) DEFAULT '',
  phone VARCHAR(30) DEFAULT '',
  address VARCHAR(200) DEFAULT '',
  pool_type VARCHAR(50) DEFAULT 'Privé buitenbad',
  volume_liters INT DEFAULT 40000,
  notes TEXT,
  qr_token VARCHAR(32) UNIQUE NOT NULL,
  history_token VARCHAR(32) UNIQUE NOT NULL,
  pool_code VARCHAR(8) UNIQUE NOT NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS user_woningen (
  user_id INT NOT NULL, woning_id INT NOT NULL,
  PRIMARY KEY (user_id, woning_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (woning_id) REFERENCES woningen(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  woning_id INT NOT NULL,
  user_id INT,
  visit_date DATE NOT NULL,
  visit_time TIME NOT NULL,
  ph DECIMAL(4,2), chlorine DECIMAL(5,2), alkalinity INT, stabilizer DECIMAL(5,1),
  volume_used INT, notes TEXT, advice_json TEXT,
  strip_photo VARCHAR(255) DEFAULT '',
  confirm_photo_chemicals VARCHAR(255) DEFAULT '',
  confirm_photo_pool VARCHAR(255) DEFAULT '',
  email_sent TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (woning_id) REFERENCES woningen(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS ai_corrections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visit_id INT, photo_url VARCHAR(255),
  ai_ph DECIMAL(4,2), ai_cl DECIMAL(5,2), ai_alk INT, ai_stab DECIMAL(5,1),
  human_ph DECIMAL(4,2), human_cl DECIMAL(5,2), human_alk INT, human_stab DECIMAL(5,1),
  ai_reasoning TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS app_meta (
  key_name VARCHAR(50) PRIMARY KEY,
  value VARCHAR(200) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO users (name, email, phone, magic_token, roles) VALUES
  ('Tycho Hombergen', 'tycho.hombergen@villaparkfontein.com', '', MD5('tycho_v4_2024'), 'monteur'),
  ('Manager', 'pool@villaparkfontein.com', '', MD5('manager_v4_2024'), 'monteur,admin');
INSERT IGNORE INTO woningen (name, owner_name, email, address, pool_type, volume_liters, qr_token, history_token, pool_code) VALUES
  ('Villa Janssen',  'Peter Janssen',  'mitchvg@gmail.com', 'Koningslaan 12, Amsterdam', 'Privé buitenbad', 40000,  MD5('jan_qr_v4'), MD5('jan_hist_v4'), LEFT(MD5('jan_code_v4'),6)),
  ('Hotel Metropol', 'Receptie',       'mitchvg@gmail.com', 'Stationsplein 3, Utrecht',  'Hotel binnenbad', 120000, MD5('met_qr_v4'), MD5('met_hist_v4'), LEFT(MD5('met_code_v4'),6)),
  ('Villa De Vries', 'Sandra de Vries','mitchvg@gmail.com', 'Parkweg 7, Haarlem',        'Privé spa',       25000,  MD5('dev_qr_v4'), MD5('dev_hist_v4'), LEFT(MD5('dev_code_v4'),6));
SQL;

// ════════════════════════════════════════════════════════════
$errors = 0;
function out(string $msg, bool $err = false): void {
    global $errors;
    echo $msg . PHP_EOL;
    if ($err) $errors++;
}

out('');
out('╔══════════════════════════════════════╗');
out("║  PoolCheck Deploy                    ║");
out("║  Versie: {$VERSION}          ║");
out('╚══════════════════════════════════════╝');
out('');

// ── Stap 1: Manifest cleanup ─────────────────────────────────
out('[ 1/3 ] Opschonen verwijderde bestanden...');
$deleted = 0;
foreach (glob(__DIR__ . '/*.{php,html}', GLOB_BRACE) as $filepath) {
    $filename = basename($filepath);
    if ($filename === 'deploy.php') continue; // zichzelf nooit verwijderen
    if (!in_array($filename, $MANIFEST)) {
        if (unlink($filepath)) {
            out("  ✓ Verwijderd: {$filename}");
            $deleted++;
        } else {
            out("  ✗ Kon niet verwijderen: {$filename}", true);
        }
    }
}
if ($deleted === 0) out('  Geen verouderde bestanden gevonden.');
out('');

// ── Stap 2: Database schema ──────────────────────────────────
out('[ 2/3 ] Database schema bijwerken...');
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmts = preg_split('/;\s*[\r\n]+/', $SCHEMA, -1, PREG_SPLIT_NO_EMPTY);
    $ok = $skip = 0;
    foreach (array_filter(array_map('trim', $stmts)) as $stmt) {
        if (!$stmt || str_starts_with(ltrim($stmt), '--')) continue;
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate entry')) {
                $skip++;
            } else {
                out("  ✗ SQL: {$msg}", true);
            }
        }
    }
    out("  ✓ {$ok} statements, {$skip} overgeslagen");

    // ── Stap 3: Versie opslaan ───────────────────────────────
    out('');
    out('[ 3/3 ] Versie registreren...');
    $ins = "INSERT INTO app_meta (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=?, updated_at=NOW()";
    $pdo->prepare($ins)->execute(['db_version',   $VERSION,            $VERSION]);
    $pdo->prepare($ins)->execute(['last_deploy',   date('d-m-Y H:i:s'), date('d-m-Y H:i:s')]);
    out("  ✓ Versie {$VERSION} opgeslagen");

    // Sla API key op in DB (alleen als die nog niet in DB staat)
    $existing = $pdo->query("SELECT value FROM app_meta WHERE key_name='claude_api_key'")->fetchColumn();
    if (!$existing) {
        // Lees uit api.php (tussen aanhalingstekens achter CLAUDE_API_KEY)
        $apiPhp = @file_get_contents(__DIR__.'/api.php');
        if (preg_match("/define\('CLAUDE_API_KEY',\s*'([^']+)'\)/", $apiPhp, $m) && strlen($m[1]) > 10) {
            $pdo->prepare($ins)->execute(['claude_api_key', $m[1], $m[1]]);
            out("  ✓ API key opgeslagen in database");
        } else {
            out("  ℹ API key nog niet ingesteld in api.php");
        }
    } else {
        out("  ✓ API key al aanwezig in database");
    }

} catch (PDOException $e) {
    out('  ✗ DB verbinding mislukt: ' . $e->getMessage(), true);
}

// ── Resultaat ────────────────────────────────────────────────
out('');
if ($errors === 0) {
    out("✅ Deploy succesvol — {$VERSION}");
    exit(0);
} else {
    out("❌ {$errors} fout(en) — zie hierboven");
    exit(1);
}
