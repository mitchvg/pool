<?php
// PoolCheck Pro — api.php
// Installatie: open setup.php in de browser
// Updates: git pull — config.local.php en app_meta blijven onaangeroerd

// ── DB credentials uit config.local.php (niet in git) ─────────
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Alleen DB-fallbacks hier — alle andere config staat in app_meta
if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_PORT'))    define('DB_PORT',     3306);
if (!defined('DB_NAME'))    define('DB_NAME',    'poolcheck');
if (!defined('DB_USER'))    define('DB_USER',    'poolcheck');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/uploads/');

// ── Bootstrap ─────────────────────────────────────────────────
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Core helpers ──────────────────────────────────────────────
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: array_merge($_GET, $_POST);
}

function reqAdmin(): void {
    if (empty($_SESSION['admin_user_id'])) respond(['error' => 'Niet ingelogd'], 401);
}

function getMeta(string $key, string $fallback = ''): string {
    try {
        $st = db()->prepare("SELECT value FROM app_meta WHERE key_name = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== '') ? $v : $fallback;
    } catch (Throwable $e) { return $fallback; }
}

function setMeta(string $key, string $value): void {
    db()->prepare("INSERT INTO app_meta (key_name, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()")
        ->execute([$key, $value, $value]);
}

// ── Code generators ───────────────────────────────────────────
// Codes: P + 2 alfanumeriek voor zwembaden, U + 2 voor users
// Volgorde: AA, AB ... AZ, A0 ... A9, BA, BB ... 9Z, 99
// Uitbreidbaar: als alle 2-char codes vol zijn, wordt het 3-char

function nextCode(string $prefix, string $table, string $col): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $len = count_chars($chars, 3); // unused, just for reference
    $base = strlen($chars); // 36

    // Haal hoogste bestaande code op voor dit prefix
    $st = db()->prepare("SELECT $col FROM $table WHERE $col LIKE ? ORDER BY LENGTH($col) DESC, $col DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $last = $st->fetchColumn();

    if (!$last) return $prefix . $chars[0] . $chars[0]; // PA of UA

    $suffix = substr($last, strlen($prefix));
    // Increment suffix als base-36 getal
    $next = incrementCode($suffix, $chars);
    return $prefix . $next;
}

function incrementCode(string $code, string $chars): string {
    $base = strlen($chars);
    $pos  = array_flip(str_split($chars));
    $arr  = array_reverse(str_split($code));
    $carry = 1;

    for ($i = 0; $i < count($arr) && $carry; $i++) {
        $val = $pos[$arr[$i]] + $carry;
        $arr[$i] = $chars[$val % $base];
        $carry = intdiv($val, $base);
    }
    if ($carry) $arr[] = $chars[0]; // verleng de code

    return implode('', array_reverse($arr));
}

// ── Auto-migratie ─────────────────────────────────────────────
// Draait bij elke request. Veilig om te herhalen (IF NOT EXISTS).
// Detecteert nieuwe deployment via bestandswijzigingstijd.

function autoMigrate(): void {
    $fileVer = date('YmdHi', filemtime(__FILE__));

    try {
        // Zorg dat app_meta altijd als eerste bestaat
        db()->exec("CREATE TABLE IF NOT EXISTS app_meta (
            key_name   VARCHAR(50)  PRIMARY KEY,
            value      VARCHAR(500) NOT NULL DEFAULT '',
            updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $dbVer = db()->query("SELECT value FROM app_meta WHERE key_name = 'db_version'")->fetchColumn();
        if ($dbVer === $fileVer) return;

        $schema = "
        CREATE TABLE IF NOT EXISTS users (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            code       VARCHAR(10)  UNIQUE NOT NULL,
            name       VARCHAR(100) NOT NULL,
            email      VARCHAR(100) NOT NULL DEFAULT '',
            phone      VARCHAR(30)  DEFAULT '',
            password   VARCHAR(255) DEFAULT NULL,
            magic_token VARCHAR(32) UNIQUE NOT NULL,
            roles      VARCHAR(50)  DEFAULT 'user',
            active     TINYINT(1)   DEFAULT 1,
            created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS zwembaden (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            code           VARCHAR(10)  UNIQUE NOT NULL,
            name           VARCHAR(100) NOT NULL,
            owner_name     VARCHAR(100) DEFAULT '',
            email          VARCHAR(100) DEFAULT '',
            phone          VARCHAR(30)  DEFAULT '',
            address        VARCHAR(200) DEFAULT '',
            pool_type      VARCHAR(50)  DEFAULT 'Privé buitenbad',
            volume_liters  INT          DEFAULT 40000,
            notes          TEXT,
            qr_token       VARCHAR(32)  UNIQUE NOT NULL,
            public_visible TINYINT(1)   DEFAULT 0,
            active         TINYINT(1)   DEFAULT 1,
            created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS user_zwembaden (
            user_id    INT NOT NULL,
            zwembad_id INT NOT NULL,
            PRIMARY KEY (user_id, zwembad_id),
            FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
            FOREIGN KEY (zwembad_id) REFERENCES zwembaden(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS visits (
            id                       INT AUTO_INCREMENT PRIMARY KEY,
            zwembad_id               INT NOT NULL,
            user_id                  INT,
            visit_date               DATE NOT NULL,
            visit_time               TIME NOT NULL,
            ph                       DECIMAL(4,2),
            chlorine                 DECIMAL(5,2),
            alkalinity               INT,
            stabilizer               DECIMAL(5,1),
            volume_used              INT,
            notes                    TEXT,
            advice_json              TEXT,
            strip_photo              VARCHAR(64) DEFAULT '',
            confirm_photo_chemicals  VARCHAR(64) DEFAULT '',
            confirm_photo_pool       VARCHAR(64) DEFAULT '',
            email_sent               TINYINT(1)  DEFAULT 0,
            created_at               TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (zwembad_id) REFERENCES zwembaden(id),
            FOREIGN KEY (user_id)    REFERENCES users(id)
        );
        ";

        foreach (preg_split('/;\s*\n/', $schema, -1, PREG_SPLIT_NO_EMPTY) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            try {
                db()->exec($stmt);
            } catch (PDOException $e) {
                if (!str_contains($e->getMessage(), 'already exists'))
                    error_log("PoolCheck migrate: " . $e->getMessage());
            }
        }

        // ── Kolom-migraties (veilig bij upgrade van bestaande tabellen) ──────
        $dbName   = db()->query("SELECT DATABASE()")->fetchColumn();
        $getColsSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";

        $userCols = db()->prepare($getColsSql);
        $userCols->execute([$dbName, 'users']);
        $userCols = $userCols->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('code', $userCols)) {
            db()->exec("ALTER TABLE users ADD COLUMN code VARCHAR(10) DEFAULT NULL");
            error_log("PoolCheck migrate: kolom 'code' toegevoegd aan users");
        }
        if (!in_array('password', $userCols)) {
            db()->exec("ALTER TABLE users ADD COLUMN password VARCHAR(255) DEFAULT NULL");
            error_log("PoolCheck migrate: kolom 'password' toegevoegd aan users");
        }

        $poolCols = db()->prepare($getColsSql);
        $poolCols->execute([$dbName, 'zwembaden']);
        $poolCols = $poolCols->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('code', $poolCols)) {
            db()->exec("ALTER TABLE zwembaden ADD COLUMN code VARCHAR(10) DEFAULT NULL");
            error_log("PoolCheck migrate: kolom 'code' toegevoegd aan zwembaden");
        }

        // ── Visits kolom-migraties ────────────────────────────────────────────
        $visitCols = db()->prepare($getColsSql);
        $visitCols->execute([$dbName, 'visits']);
        $visitCols = $visitCols->fetchAll(PDO::FETCH_COLUMN);

        $visitAlters = [
            'zwembad_id'              => "ALTER TABLE visits ADD COLUMN zwembad_id INT DEFAULT NULL",
            'user_id'                 => "ALTER TABLE visits ADD COLUMN user_id INT DEFAULT NULL",
            'stabilizer'              => "ALTER TABLE visits ADD COLUMN stabilizer DECIMAL(5,1) DEFAULT NULL",
            'volume_used'             => "ALTER TABLE visits ADD COLUMN volume_used INT DEFAULT NULL",
            'notes'                   => "ALTER TABLE visits ADD COLUMN notes TEXT",
            'advice_json'             => "ALTER TABLE visits ADD COLUMN advice_json TEXT",
            'strip_photo'             => "ALTER TABLE visits ADD COLUMN strip_photo VARCHAR(64) DEFAULT ''",
            'confirm_photo_chemicals' => "ALTER TABLE visits ADD COLUMN confirm_photo_chemicals VARCHAR(64) DEFAULT ''",
            'confirm_photo_pool'      => "ALTER TABLE visits ADD COLUMN confirm_photo_pool VARCHAR(64) DEFAULT ''",
            'email_sent'              => "ALTER TABLE visits ADD COLUMN email_sent TINYINT(1) DEFAULT 0",
        ];
        foreach ($visitAlters as $col => $sql) {
            if (!in_array($col, $visitCols)) {
                db()->exec($sql);
                error_log("PoolCheck migrate: kolom '$col' toegevoegd aan visits");
            }
        }

        // ── Drop legacy FK woning_id → woningen (oud schema) ────────────────
        try {
            $legFKs = db()->prepare(
                "SELECT DISTINCT tc.CONSTRAINT_NAME
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                 JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                   ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                  AND tc.TABLE_SCHEMA    = kcu.TABLE_SCHEMA
                  AND tc.TABLE_NAME      = kcu.TABLE_NAME
                 WHERE tc.TABLE_SCHEMA      = ?
                   AND tc.TABLE_NAME        = 'visits'
                   AND tc.CONSTRAINT_TYPE   = 'FOREIGN KEY'
                   AND (kcu.COLUMN_NAME             = 'woning_id'
                     OR kcu.REFERENCED_TABLE_NAME   = 'woningen')"
            );
            $legFKs->execute([$dbName]);
            foreach ($legFKs->fetchAll(PDO::FETCH_COLUMN) as $fkName) {
                try { db()->exec("ALTER TABLE visits DROP FOREIGN KEY `$fkName`"); } catch (Throwable $e) {}
            }
            if (in_array('woning_id', $visitCols)) {
                db()->exec("ALTER TABLE visits MODIFY COLUMN woning_id INT DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log("PoolCheck migrate drop legacy FK: " . $e->getMessage());
        }

        // ── Auto-assign codes aan bestaande records zonder code ───────────────
        $usersNoCodes = db()->query("SELECT id FROM users WHERE code IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($usersNoCodes as $uid) {
            $c = nextCode('U', 'users', 'code');
            db()->prepare("UPDATE users SET code = ? WHERE id = ?")->execute([$c, $uid]);
        }
        $poolsNoCodes = db()->query("SELECT id FROM zwembaden WHERE code IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($poolsNoCodes as $pid) {
            $c = nextCode('P', 'zwembaden', 'code');
            db()->prepare("UPDATE zwembaden SET code = ? WHERE id = ?")->execute([$c, $pid]);
        }

        // Sla nieuwe versie op
        $ins = "INSERT INTO app_meta (key_name, value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()";
        db()->prepare($ins)->execute(['db_version',  $fileVer,              $fileVer]);
        db()->prepare($ins)->execute(['last_deploy',  date('d-m-Y H:i:s'),  date('d-m-Y H:i:s')]);

    } catch (Throwable $e) {
        error_log("PoolCheck autoMigrate: " . $e->getMessage());
    }
}

autoMigrate();

// ── Router ────────────────────────────────────────────────────
$action = $_GET['action'] ?? input()['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    match (true) {
        // Publiek
        $action === 'zwembad'         && $method === 'GET'  => getZwembad(),
        $action === 'my_zwembaden'    && $method === 'GET'  => getMyZwembaden(),
        $action === 'prev_visit'      && $method === 'GET'  => getPrevVisit(),
        $action === 'zwembad_history' && $method === 'GET'  => getZwembadHistory(),
        $action === 'save_visit'      && $method === 'POST' => saveVisit(),
        $action === 'upload_photo'    && $method === 'POST' => uploadPhoto(),
        $action === 'ai_strip'        && $method === 'POST' => aiStrip(),
        $action === 'get_advice'      && $method === 'POST' => getAdvice(),
        $action === 'get_user'        && $method === 'GET'  => getUser(),

        // Admin auth
        $action === 'admin_login'     && $method === 'POST' => adminLogin(),
        $action === 'admin_logout'    && $method === 'POST' => adminLogout(),
        $action === 'admin_check'     && $method === 'GET'  => adminCheck(),

        // Admin data
        $action === 'admin_visits'    && $method === 'GET'  => adminVisits(),
        $action === 'admin_users'     && $method === 'GET'  => adminUsers(),
        $action === 'admin_zwembaden' && $method === 'GET'  => adminZwembaden(),
        $action === 'add_zwembad'     && $method === 'POST' => addZwembad(),
        $action === 'update_zwembad'  && $method === 'POST' => updateZwembad(),
        $action === 'add_user'        && $method === 'POST' => addUser(),
        $action === 'update_user'     && $method === 'POST' => updateUser(),
        $action === 'delete_user'     && $method === 'POST' => deleteUser(),
        $action === 'link_user_zwembad' && $method === 'POST' => linkUserZwembad(),
        $action === 'unlink_user_zwembad' && $method === 'POST' => unlinkUserZwembad(),
        $action === 'send_magic_link' && $method === 'POST' => sendMagicLink(),

        // Admin instellingen
        $action === 'admin_settings'  && $method === 'GET'  => adminSettings(),
        $action === 'save_settings'   && $method === 'POST' => saveSettings(),
        $action === 'app_status'      && $method === 'GET'  => appStatus(),
        $action === 'load_demo_data'  && $method === 'POST' => loadDemoData(),

        default => respond(['error' => "Onbekende actie: $action"], 404)
    };
} catch (Throwable $e) {
    respond(['error' => $e->getMessage()], 500);
}

// ============================================================
// PUBLIEK: USER
// ============================================================
function getUser(): void {
    $token = $_GET['token'] ?? '';
    if (!$token) respond(['error' => 'Token vereist'], 400);
    $st = db()->prepare("SELECT id, code, name, email, phone, roles FROM users WHERE magic_token = ? AND active = 1");
    $st->execute([$token]);
    $u = $st->fetch();
    if (!$u) respond(['error' => 'Gebruiker niet gevonden'], 404);
    respond(['user' => $u]);
}

// ============================================================
// PUBLIEK: ZWEMBAD
// ============================================================
function getZwembad(): void {
    $token = $_GET['token'] ?? '';
    $code  = strtoupper($_GET['code'] ?? '');

    if ($token) {
        $st = db()->prepare("SELECT * FROM zwembaden WHERE qr_token = ? AND active = 1");
        $st->execute([$token]);
    } elseif ($code) {
        $st = db()->prepare("SELECT * FROM zwembaden WHERE code = ? AND active = 1");
        $st->execute([$code]);
    } else {
        respond(['error' => 'Token of code vereist'], 400);
    }

    $z = $st->fetch();
    if (!$z) respond(['error' => 'Zwembad niet gevonden'], 404);

    // Publieke toegang: verberg gevoelige velden als geen user-token
    $userToken = $_GET['user_token'] ?? '';
    if (!$userToken) {
        if (!$z['public_visible']) respond(['error' => 'Geen toegang'], 403);
        unset($z['email'], $z['phone'], $z['notes'], $z['qr_token']);
    }

    respond(['zwembad' => $z]);
}

function getMyZwembaden(): void {
    $token = $_GET['user_token'] ?? '';
    if (!$token) respond(['zwembaden' => []]);
    $st = db()->prepare("SELECT id, code, name, roles FROM users WHERE magic_token = ? AND active = 1");
    $st->execute([$token]);
    $u = $st->fetch();
    if (!$u) respond(['zwembaden' => []]);

    // Admin en users met koppeling kunnen zwembaden zien
    if (str_contains($u['roles'], 'admin')) {
        $rows = db()->query("SELECT id, code, name, address, pool_type, volume_liters, qr_token, public_visible FROM zwembaden WHERE active = 1 ORDER BY name")->fetchAll();
    } else {
        $st2 = db()->prepare("SELECT z.id, z.code, z.name, z.address, z.pool_type, z.volume_liters, z.qr_token, z.public_visible FROM zwembaden z JOIN user_zwembaden uz ON uz.zwembad_id = z.id WHERE uz.user_id = ? AND z.active = 1 ORDER BY z.name");
        $st2->execute([$u['id']]);
        $rows = $st2->fetchAll();
    }
    respond(['zwembaden' => $rows, 'user' => $u]);
}

function getPrevVisit(): void {
    $zid = (int)($_GET['zwembad_id'] ?? 0);
    if (!$zid) respond(['visit' => null]);
    $st = db()->prepare("SELECT v.*, u.name AS user_name FROM visits v LEFT JOIN users u ON u.id = v.user_id WHERE v.zwembad_id = ? ORDER BY v.visit_date DESC, v.visit_time DESC LIMIT 1");
    $st->execute([$zid]);
    $v = $st->fetch() ?: null;
    if ($v) $v['advice_json'] = json_decode($v['advice_json'] ?? '{}', true);
    respond(['visit' => $v]);
}

function getZwembadHistory(): void {
    $token     = $_GET['token'] ?? '';
    $userToken = $_GET['user_token'] ?? '';
    if (!$token) respond(['error' => 'Token vereist'], 400);

    $st = db()->prepare("SELECT * FROM zwembaden WHERE qr_token = ? AND active = 1");
    $st->execute([$token]);
    $z = $st->fetch();
    if (!$z) respond(['error' => 'Niet gevonden'], 404);

    // Publieke toegang alleen als public_visible aan staat
    if (!$userToken && !$z['public_visible']) respond(['error' => 'Geen toegang'], 403);

    // Bij publieke toegang: alleen laatste bezoek
    $limit = $userToken ? 50 : 1;
    $st2 = db()->prepare("SELECT v.*, u.name AS user_name FROM visits v LEFT JOIN users u ON u.id = v.user_id WHERE v.zwembad_id = ? ORDER BY v.visit_date DESC, v.visit_time DESC LIMIT $limit");
    $st2->execute([$z['id']]);
    $visits = $st2->fetchAll();
    foreach ($visits as &$v) $v['advice_json'] = json_decode($v['advice_json'] ?? '{}', true);

    unset($z['email'], $z['phone'], $z['notes']); // niet publiek
    respond(['zwembad' => $z, 'visits' => $visits]);
}

// ============================================================
// VISIT OPSLAAN
// ============================================================
function saveVisit(): void {
    $d = input();
    foreach (['zwembad_id', 'ph', 'chlorine', 'alkalinity'] as $f) {
        if (!isset($d[$f]) || $d[$f] === '') respond(['error' => "Veld ontbreekt: $f"], 400);
    }

    $st = db()->prepare("SELECT * FROM zwembaden WHERE id = ? AND active = 1");
    $st->execute([$d['zwembad_id']]);
    $z = $st->fetch();
    if (!$z) respond(['error' => 'Zwembad niet gevonden'], 404);

    $user = null;
    if (!empty($d['user_id'])) {
        $st2 = db()->prepare("SELECT * FROM users WHERE id = ?");
        $st2->execute([$d['user_id']]);
        $user = $st2->fetch();
    }

    $advice = buildAdvice(
        (float)$d['ph'],
        (float)$d['chlorine'],
        (int)$d['alkalinity'],
        isset($d['stabilizer']) ? (float)$d['stabilizer'] : null,
        (int)($d['volume_used'] ?? $z['volume_liters'])
    );

    $st3 = db()->prepare("INSERT INTO visits
        (zwembad_id, user_id, visit_date, visit_time, ph, chlorine, alkalinity, stabilizer,
         volume_used, notes, advice_json, strip_photo, confirm_photo_chemicals, confirm_photo_pool)
        VALUES (?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st3->execute([
        $d['zwembad_id'], $user['id'] ?? null,
        $d['ph'], $d['chlorine'], $d['alkalinity'], $d['stabilizer'] ?? null,
        $d['volume_used'] ?? $z['volume_liters'], $d['notes'] ?? '',
        json_encode($advice, JSON_UNESCAPED_UNICODE),
        $d['strip_photo'] ?? '', $d['confirm_photo_chemicals'] ?? '', $d['confirm_photo_pool'] ?? ''
    ]);
    $vid = (int)db()->lastInsertId();

    $mailResult = sendMails($z, $user, $d, $advice, $vid);
    if ($mailResult['any_sent']) {
        db()->prepare("UPDATE visits SET email_sent = 1 WHERE id = ?")->execute([$vid]);
    }

    respond(['success' => true, 'visit_id' => $vid, 'mail_results' => $mailResult]);
}

// ============================================================
// FOTO UPLOAD
// ============================================================
function uploadPhoto(): void {
    if (empty($_FILES['photo'])) respond(['error' => 'Geen bestand'], 400);
    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload fout: ' . $f['error']], 400);

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'])) {
        respond(['error' => 'Ongeldig bestandstype'], 400);
    }
    if ($f['size'] > 15 * 1024 * 1024) respond(['error' => 'Max 15MB'], 400);

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    // Volledig random bestandsnaam — niet te raden
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $filename)) {
        respond(['error' => 'Opslaan mislukt'], 500);
    }

    $baseUrl = getMeta('base_url', '');
    respond(['url' => $baseUrl . '/uploads/' . $filename, 'filename' => $filename]);
}

// ============================================================
// AI STRIP ANALYSE
// ============================================================
function aiStrip(): void {
    $apiKey = getMeta('claude_api_key');
    if (strlen($apiKey) < 10) respond(['error' => 'Claude API key niet ingesteld. Configureer via Admin → Instellingen.'], 503);

    $d   = input();
    $b64 = $d['image'] ?? '';
    $mt  = $d['type']  ?? 'image/jpeg';
    if (!$b64) respond(['error' => 'Geen afbeelding'], 400);

    $prompt = <<<'PROMPT'
You are analyzing a pool water test strip photo.

═══ STEP 1: FIND THE TEST STRIP ═══
The test strip is a narrow white plastic stick (~3–8% of image width) with:
  • 4 small colored square reaction pads in a row
  • One HANDLE end: plain white plastic, no pad, longer blank section

ORIENTATION RULE:
  The HANDLE is the bottom end (longer white section, no color).
  Count pads from the handle upward (or outward):
    pad 1 nearest handle  = STABILIZER
    pad 2                 = TOTAL ALKALINITY
    pad 3                 = FREE CHLORINE
    pad 4 farthest handle = pH (END PAD)
  The strip may be at any angle or position — search the entire image.

Output:
  strip_bbox: tight box around the ENTIRE strip (all pads + handle)
  For each pad: pad_bbox covering ONLY that single pad square

═══ STEP 2: FIND THE REFERENCE CHART ═══
The Aquachek reference chart is printed on the bottle. The bottle may be upright,
rotated 90°, or upside down — use the printed text labels to determine orientation.
Labels to find: "pH (END PAD)", "ppm FREE CHLORINE", "ppm TOTAL ALKALINITY",
                "ppm STABILIZER (PAD NEAREST HANDLE)"

For each of the 4 parameters, find its row (or column) of color cells and return
EACH CELL as a separate entry with its printed numeric value:
  pH:         5 cells — 6.2  6.8  7.2  7.8  8.4
  Chlorine:   6 cells — 0  0.5  1  3  5  10
  Alkalinity: 6 cells — 0  40  80  120  180  240
  Stabilizer: 5 cells — 0  30-50  100  150  300  (use value 40 for the 30-50 cell)

For each cell give a tight bbox covering ONLY that color square (not labels, not gaps).
Then compare the pad color to all cells in that parameter's row and pick the closest match.

═══ OUTPUT — valid JSON only, no markdown ═══
{
  "strip_bbox": [x1,y1,x2,y2],
  "ph":         { "value": 6.8, "pad_bbox": [x1,y1,x2,y2],
                  "ref_cells": [
                    {"v":6.2,"bbox":[x1,y1,x2,y2]},
                    {"v":6.8,"bbox":[x1,y1,x2,y2]},
                    {"v":7.2,"bbox":[x1,y1,x2,y2]},
                    {"v":7.8,"bbox":[x1,y1,x2,y2]},
                    {"v":8.4,"bbox":[x1,y1,x2,y2]}
                  ] },
  "chlorine":   { "value": 3.0, "pad_bbox": [x1,y1,x2,y2],
                  "ref_cells": [
                    {"v":0,  "bbox":[x1,y1,x2,y2]},
                    {"v":0.5,"bbox":[x1,y1,x2,y2]},
                    {"v":1,  "bbox":[x1,y1,x2,y2]},
                    {"v":3,  "bbox":[x1,y1,x2,y2]},
                    {"v":5,  "bbox":[x1,y1,x2,y2]},
                    {"v":10, "bbox":[x1,y1,x2,y2]}
                  ] },
  "alkalinity": { "value": 40, "pad_bbox": [x1,y1,x2,y2],
                  "ref_cells": [
                    {"v":0,  "bbox":[x1,y1,x2,y2]},
                    {"v":40, "bbox":[x1,y1,x2,y2]},
                    {"v":80, "bbox":[x1,y1,x2,y2]},
                    {"v":120,"bbox":[x1,y1,x2,y2]},
                    {"v":180,"bbox":[x1,y1,x2,y2]},
                    {"v":240,"bbox":[x1,y1,x2,y2]}
                  ] },
  "stabilizer": { "value": 40, "pad_bbox": [x1,y1,x2,y2],
                  "ref_cells": [
                    {"v":0,  "bbox":[x1,y1,x2,y2]},
                    {"v":40, "bbox":[x1,y1,x2,y2]},
                    {"v":100,"bbox":[x1,y1,x2,y2]},
                    {"v":150,"bbox":[x1,y1,x2,y2]},
                    {"v":300,"bbox":[x1,y1,x2,y2]}
                  ] }
}
All bbox values: % of FULL image dimensions (0–100), x1<x2, y1<y2.
PROMPT;

    $payload = json_encode([
        'model'      => 'claude-opus-4-5-20251101',
        'max_tokens' => 2000,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mt, 'data' => $b64]],
                ['type' => 'text',  'text'   => $prompt]
            ]
        ]]
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nx-api-key: $apiKey\r\nanthropic-version: 2023-06-01\r\n",
        'content' => $payload,
        'timeout' => 30,
    ]]);

    $raw = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
    if (!$raw) respond(['error' => 'API aanroep mislukt — controleer Claude API key'], 500);

    $resp = json_decode($raw, true);
    if (isset($resp['error'])) respond(['error' => 'Claude: ' . $resp['error']['message']], 500);

    $text = $resp['content'][0]['text'] ?? '';
    preg_match('/\{.*\}/s', $text, $m);
    if (!$m) respond(['error' => 'AI kon strip niet analyseren. Zorg dat strip en kleurenkaart beide goed zichtbaar zijn.', 'raw' => substr($text, 0, 300)], 422);

    $v = json_decode($m[0], true);
    if (!$v) respond(['error' => 'Ongeldige AI response'], 422);

    // Valideer en saniteer waarden
    respond([
        'strip_bbox' => $v['strip_bbox'] ?? null,
        'ph'        => ['value'     => min(9,   max(6,   round((float)($v['ph']['value']         ?? 6.2), 1))),
                        'pad_bbox'  => $v['ph']['pad_bbox']    ?? null,
                        'ref_cells' => $v['ph']['ref_cells']   ?? []],
        'chlorine'  => ['value'     => min(10,  max(0,   round((float)($v['chlorine']['value']   ?? 0),   1))),
                        'pad_bbox'  => $v['chlorine']['pad_bbox']    ?? null,
                        'ref_cells' => $v['chlorine']['ref_cells']   ?? []],
        'alkalinity'=> ['value'     => min(300, max(0,   (int)($v['alkalinity']['value']         ?? 0))),
                        'pad_bbox'  => $v['alkalinity']['pad_bbox']  ?? null,
                        'ref_cells' => $v['alkalinity']['ref_cells'] ?? []],
        'stabilizer'=> ['value'     => min(300, max(0,   (int)($v['stabilizer']['value']         ?? 0))),
                        'pad_bbox'  => $v['stabilizer']['pad_bbox']  ?? null,
                        'ref_cells' => $v['stabilizer']['ref_cells'] ?? []],
    ]);
}

// ============================================================
// DOSERING ADVIES
// ============================================================
function buildAdvice(float $ph, float $cl, int $alk, ?float $stab, int $vol): array {
    $items = [];
    $m     = $vol / 1000; // m³

    // ── pH ──────────────────────────────────────────────────
    if ($ph < 7.2) {
        $diff      = round(7.4 - $ph, 2);
        $todayDiff = min($diff, 0.4);
        $remaining = round($diff - $todayDiff, 2);
        $g         = round($todayDiff * $m * 180);
        $items[]   = [
            'level'      => 'bad', 'param' => 'pH', 'value' => $ph, 'unit' => '',
            'title'      => 'pH te laag',
            'finding'    => "pH $ph ligt onder de norm (7.2–7.6). Corrosief water, chloor minder effectief.",
            'dose_today' => "{$g}g natriumcarbonaat (pH-plus)" . ($diff > 0.2 ? " — voeg helft toe, wacht 4u, voeg rest toe" : ''),
            'dose_next'  => $remaining > 0 ? "Volgende week: ~" . round($remaining * $m * 180) . "g" : null,
            'limit'      => "Max 0.4 pH-eenheden per dag corrigeren.",
        ];
    } elseif ($ph > 7.6) {
        $diff      = round($ph - 7.4, 2);
        $todayDiff = min($diff, 0.4);
        $remaining = round($diff - $todayDiff, 2);
        $ml        = round($todayDiff * $m * 190);
        $items[]   = [
            'level'      => $ph > 7.9 ? 'bad' : 'warn', 'param' => 'pH', 'value' => $ph, 'unit' => '',
            'title'      => 'pH te hoog',
            'finding'    => "pH $ph ligt boven de norm (7.2–7.6). Chloor 70% minder effectief bij pH>7.8.",
            'dose_today' => "{$ml}ml muriatic acid 31,45%" . ($diff > 0.2 ? " — voeg helft toe, wacht 4u, voeg rest toe" : ''),
            'dose_next'  => $remaining > 0 ? "Volgende week: ~" . round($remaining * $m * 190) . "ml" : null,
            'limit'      => "Max {$ml}ml per dag. Pomp aan laten staan.",
        ];
    } else {
        $items[] = ['level' => 'ok', 'param' => 'pH', 'value' => $ph, 'unit' => '',
                    'title' => 'pH perfect', 'finding' => "pH $ph — in de norm.",
                    'dose_today' => null, 'dose_next' => null, 'limit' => null];
    }

    // ── Chloor ──────────────────────────────────────────────
    if ($cl < 0.5) {
        $g       = round(min(2.0, 1.5 - $cl) * $m * 2.5);
        $items[] = [
            'level'      => 'bad', 'param' => 'Chloor', 'value' => $cl, 'unit' => ' ppm',
            'title'      => 'Chloor te laag — gebruik verboden',
            'finding'    => "$cl ppm is onveilig laag. Badwater mag pas gebruikt worden boven 1 ppm.",
            'dose_today' => "{$g}g natriumhypochloriet 10%",
            'dose_next'  => null, 'limit' => "Na 2u opnieuw meten.",
        ];
    } elseif ($cl > 3.0) {
        $items[] = [
            'level'      => 'warn', 'param' => 'Chloor', 'value' => $cl, 'unit' => ' ppm',
            'title'      => 'Chloor te hoog',
            'finding'    => "$cl ppm — te hoog. Irritatie aan ogen/huid mogelijk.",
            'dose_today' => $cl > 5 ? round(($cl - 3) * $m * 0.7) . "g natriumbisulfiet" : "Wacht 24–48u, laat vanzelf dalen",
            'dose_next'  => null, 'limit' => "Niet verder toevoegen.",
        ];
    } else {
        $items[] = ['level' => 'ok', 'param' => 'Chloor', 'value' => $cl, 'unit' => ' ppm',
                    'title' => 'Chloor goed', 'finding' => "$cl ppm — prima.",
                    'dose_today' => null, 'dose_next' => null, 'limit' => null];
    }

    // ── Alkaliniteit ─────────────────────────────────────────
    if ($alk < 80) {
        $diff    = 100 - $alk;
        $add     = min($diff, 25);
        $g       = round($add * $m * 1.4);
        $items[] = [
            'level'      => 'bad', 'param' => 'Alkaliniteit', 'value' => $alk, 'unit' => ' ppm',
            'title'      => 'Alkaliniteit te laag',
            'finding'    => "$alk ppm — pH schommelt instabiel. Norm: 80–120 ppm.",
            'dose_today' => "{$g}g natriumbicarbonaat",
            'dose_next'  => ($diff - $add) > 0 ? "Volgende week: ~" . round(($diff - $add) * $m * 1.4) . "g" : null,
            'limit'      => "Max 25 ppm per sessie verhogen.",
        ];
    } elseif ($alk > 120) {
        $diff    = $alk - 100;
        $lower   = min($diff, 25);
        $ml      = round($lower * $m * 0.8);
        $items[] = [
            'level'      => 'warn', 'param' => 'Alkaliniteit', 'value' => $alk, 'unit' => ' ppm',
            'title'      => 'Alkaliniteit te hoog',
            'finding'    => "$alk ppm — pH-correcties slaan minder goed aan.",
            'dose_today' => "{$ml}ml muriatic acid 31,45%",
            'dose_next'  => ($diff - $lower) > 0 ? "Volgende week: ~" . round(($diff - $lower) * $m * 0.8) . "ml" : null,
            'limit'      => "Max 25 ppm per sessie verlagen.",
        ];
    } else {
        $items[] = ['level' => 'ok', 'param' => 'Alkaliniteit', 'value' => $alk, 'unit' => ' ppm',
                    'title' => 'Alkaliniteit stabiel', 'finding' => "$alk ppm — goed.",
                    'dose_today' => null, 'dose_next' => null, 'limit' => null];
    }

    // ── Stabilizer ───────────────────────────────────────────
    if ($stab !== null) {
        if ($stab < 30) {
            $g       = round((50 - $stab) * $m);
            $items[] = [
                'level'      => 'warn', 'param' => 'Stabilizer', 'value' => $stab, 'unit' => ' ppm',
                'title'      => 'Stabilizer te laag',
                'finding'    => "$stab ppm — chloor verdampt snel in zon. Norm: 30–50 ppm.",
                'dose_today' => "{$g}g cyanurinezuur (oplossen in warm water)",
                'dose_next'  => null, 'limit' => null,
            ];
        } elseif ($stab > 80) {
            $items[] = [
                'level'      => 'warn', 'param' => 'Stabilizer', 'value' => $stab, 'unit' => ' ppm',
                'title'      => 'Stabilizer te hoog',
                'finding'    => "$stab ppm — chloor werkt minder effectief.",
                'dose_today' => "10–20% water verversen om te verdunnen",
                'dose_next'  => null, 'limit' => null,
            ];
        } else {
            $items[] = ['level' => 'ok', 'param' => 'Stabilizer', 'value' => $stab, 'unit' => ' ppm',
                        'title' => 'Stabilizer OK', 'finding' => "$stab ppm — goed.",
                        'dose_today' => null, 'dose_next' => null, 'limit' => null];
        }
    }

    return ['items' => $items];
}

function getAdvice(): void {
    $d    = input();
    $ph   = (float)($d['ph']          ?? 7.4);
    $cl   = (float)($d['chlorine']    ?? 1.5);
    $alk  = (int)  ($d['alkalinity']  ?? 100);
    $stab = isset($d['stabilizer']) && $d['stabilizer'] !== null ? (float)$d['stabilizer'] : null;
    $vol  = (int)  ($d['volume_liters'] ?? 40000);
    respond(['advice' => buildAdvice($ph, $cl, $alk, $stab, $vol)]);
}

// ============================================================
// EMAIL
// ============================================================
function sendMails(array $z, ?array $u, array $d, array $advice, int $vid): array {
    $date     = date('d-m-Y');
    $baseUrl  = getMeta('base_url', '');
    $histLink = $baseUrl . '/?k=' . $z['qr_token'];
    $rows     = '';

    foreach ($advice['items'] as $i) {
        $bg   = $i['level'] === 'ok' ? '#eaf3de' : ($i['level'] === 'warn' ? '#faeeda' : '#fcebeb');
        $col  = $i['level'] === 'ok' ? '#2d6a4f' : ($i['level'] === 'warn' ? '#7a4f00' : '#8b1c1c');
        $dose = $i['dose_today'] ? "<br><strong>Vandaag: {$i['dose_today']}</strong>" : '';
        $next = $i['dose_next']  ? "<br><em>Volgende week: {$i['dose_next']}</em>"   : '';
        $rows .= "<div style='background:{$bg};border-left:4px solid {$col};padding:10px 14px;margin-bottom:8px;border-radius:4px'>
            <strong style='color:{$col}'>{$i['title']}</strong><br>
            <span style='font-size:13px;color:#555'>{$i['finding']}{$dose}{$next}</span>
        </div>";
    }

    $clientHtml = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;max-width:580px;margin:0 auto'>
    <div style='background:#0F6E56;padding:22px;text-align:center;border-radius:10px 10px 0 0'>
        <h1 style='color:#fff;margin:0;font-size:20px'>PoolCheck Pro</h1>
        <p style='color:#9FE1CB;margin:4px 0 0;font-size:13px'>{$z['name']} &mdash; $date</p>
    </div>
    <div style='background:#f9f9f9;padding:22px;border:1px solid #e0e0e0'>
        <p>Geachte {$z['owner_name']},</p>
        <table style='width:100%;font-size:14px;margin:12px 0 16px'>
            <tr><td style='padding:5px 0'><b>pH</b></td><td>{$d['ph']}</td><td style='color:#888;font-size:12px'>7.2–7.6</td></tr>
            <tr style='background:#f0f0f0'><td style='padding:5px 4px'><b>Chloor</b></td><td>{$d['chlorine']} ppm</td><td style='color:#888;font-size:12px'>1–3 ppm</td></tr>
            <tr><td style='padding:5px 0'><b>Alkaliniteit</b></td><td>{$d['alkalinity']} ppm</td><td style='color:#888;font-size:12px'>80–120 ppm</td></tr>
        </table>
        $rows
        <p style='margin-top:16px;font-size:13px'>Bekijk de volledige geschiedenis: <a href='$histLink' style='color:#0F6E56'>$histLink</a></p>
    </div>
    <div style='background:#222;padding:12px;text-align:center;border-radius:0 0 10px 10px'>
        <p style='color:#888;margin:0;font-size:11px'>PoolCheck Pro</p>
    </div></body></html>";

    $techHtml = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;max-width:540px;margin:0 auto'>
    <div style='background:#1a1a2e;padding:16px;border-radius:10px 10px 0 0'>
        <h2 style='color:#fff;margin:0'>Bezoek #{$vid}</h2>
        <p style='color:#aaa;margin:4px 0 0;font-size:12px'>{$z['name']} &mdash; $date &mdash; " . ($u['name'] ?? 'Onbekend') . "</p>
    </div>
    <div style='background:#f5f5f3;padding:18px;border:1px solid #ddd'>
        <p>pH: <b>{$d['ph']}</b> | Cl: <b>{$d['chlorine']} ppm</b> | Alk: <b>{$d['alkalinity']} ppm</b></p>
        $rows
        " . (!empty($d['notes']) ? "<p style='margin-top:10px'><b>Notities:</b> {$d['notes']}</p>" : "") . "
    </div></body></html>";

    $mailName    = getMeta('mail_name',    'PoolCheck Pro');
    $managerMail = getMeta('manager_email', '');

    $cs = smtpSend($z['email'], $z['owner_name'], "Zwembadrapport — {$z['name']} — $date", $clientHtml);
    $ts = $u ? smtpSend($u['email'], $u['name'],   "Bezoek afgerond — {$z['name']} — $date", $techHtml)  : false;
    $ms = $managerMail ? smtpSend($managerMail, 'Manager', "Rapport #{$vid} — {$z['name']} — $date", $techHtml) : false;

    return ['client' => $cs, 'tech' => $ts, 'manager' => $ms, 'any_sent' => $cs || $ts || $ms];
}

// ============================================================
// SMTP (instellingen uit app_meta)
// ============================================================
function smtpSend(string $to, string $toName, string $subject, string $html): bool {
    $host = getMeta('mail_host');
    $port = (int)getMeta('mail_port', '465');
    $user = getMeta('mail_user');
    $pass = getMeta('mail_pass');
    $from = getMeta('mail_from');
    $name = getMeta('mail_name', 'PoolCheck Pro');

    if (!$host || !$user || !$pass || !$from || !$to) return false;

    try {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]);
        $s = stream_socket_client("ssl://$host:$port", $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new Exception("Verbinding mislukt: $es");

        $read = function() use ($s) {
            $out = '';
            while ($l = fgets($s, 515)) { $out .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; }
            return $out;
        };
        $cmd = function(string $c) use ($s, $read) { fputs($s, $c . "\r\n"); return $read(); };

        $read();
        $cmd('EHLO ' . gethostname());
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (!str_contains($r, '235')) throw new Exception("Auth mislukt: $r");

        $cmd("MAIL FROM:<$from>");
        $cmd("RCPT TO:<$to>");
        $cmd('DATA');

        $toNameClean = str_replace(['"', "\r", "\n"], '', $toName);
        $msg = "From: $name <$from>\r\n"
             . "To: \"$toNameClean\" <$to>\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($html))
             . "\r\n.\r\n";

        fputs($s, $msg);
        $sr = $read();
        $cmd('QUIT');
        fclose($s);
        return str_contains($sr, '250');
    } catch (Throwable $e) {
        error_log("SMTP $to: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// ADMIN AUTH
// ============================================================
function adminLogin(): void {
    $d    = input();
    $name = trim($d['username'] ?? '');
    $pass = $d['password'] ?? '';

    if (!$name || !$pass) respond(['error' => 'Gebruikersnaam en wachtwoord vereist'], 400);

    $st = db()->prepare("SELECT id, name, email, roles, password FROM users WHERE (name = ? OR email = ?) AND active = 1");
    $st->execute([$name, $name]);
    $u = $st->fetch();

    if (!$u || !str_contains($u['roles'], 'admin')) respond(['error' => 'Gebruiker niet gevonden of geen admin'], 401);
    if (!$u['password'] || !password_verify($pass, $u['password']))  respond(['error' => 'Verkeerd wachtwoord'], 401);

    $_SESSION['admin_user_id']   = $u['id'];
    $_SESSION['admin_user_name'] = $u['name'];
    respond(['success' => true, 'name' => $u['name']]);
}

function adminLogout(): void {
    session_destroy();
    respond(['success' => true]);
}

function adminCheck(): void {
    respond(['logged_in' => !empty($_SESSION['admin_user_id']), 'name' => $_SESSION['admin_user_name'] ?? '']);
}

// ============================================================
// ADMIN: BEZOEKEN
// ============================================================
function adminVisits(): void {
    reqAdmin();
    $zid = (int)($_GET['zwembad_id'] ?? 0);
    $sql = "SELECT v.*, z.name AS zwembad_name, u.name AS user_name
            FROM visits v
            JOIN zwembaden z ON z.id = v.zwembad_id
            LEFT JOIN users u ON u.id = v.user_id";

    if ($zid) {
        $st = db()->prepare($sql . " WHERE v.zwembad_id = ? ORDER BY v.visit_date DESC LIMIT 100");
        $st->execute([$zid]);
    } else {
        $st = db()->query($sql . " ORDER BY v.visit_date DESC LIMIT 200");
    }

    $visits = $st->fetchAll();
    foreach ($visits as &$v) $v['advice_json'] = json_decode($v['advice_json'] ?? '{}', true);
    respond(['visits' => $visits]);
}

// ============================================================
// ADMIN: GEBRUIKERS
// ============================================================
function adminUsers(): void {
    reqAdmin();
    $baseUrl = getMeta('base_url', '');
    $rows    = db()->query("SELECT id, code, name, email, phone, roles, magic_token, active FROM users ORDER BY name")->fetchAll();
    foreach ($rows as &$r) {
        $r['magic_link'] = $baseUrl . '/?m=' . $r['magic_token'];
        $st = db()->prepare("SELECT z.id, z.code, z.name FROM zwembaden z JOIN user_zwembaden uz ON uz.zwembad_id = z.id WHERE uz.user_id = ?");
        $st->execute([$r['id']]);
        $r['zwembaden'] = $st->fetchAll();
        unset($r['magic_token']); // niet in lijst, alleen via magic_link
    }
    respond(['users' => $rows]);
}

function addUser(): void {
    reqAdmin();
    $d = input();
    if (empty($d['name'])) respond(['error' => 'Naam vereist'], 400);

    $code  = nextCode('U', 'users', 'code');
    $token = bin2hex(random_bytes(16));
    $roles = $d['roles'] ?? 'user';

    $passHash = null;
    if (!empty($d['password'])) $passHash = password_hash($d['password'], PASSWORD_DEFAULT);
    elseif (str_contains($roles, 'admin')) respond(['error' => 'Admin-gebruiker vereist een wachtwoord'], 400);

    $st = db()->prepare("INSERT INTO users (code, name, email, phone, password, magic_token, roles) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$code, $d['name'], $d['email'] ?? '', $d['phone'] ?? '', $passHash, $token, $roles]);
    $id = (int)db()->lastInsertId();

    $baseUrl = getMeta('base_url', '');
    respond(['success' => true, 'id' => $id, 'code' => $code, 'magic_link' => $baseUrl . '/?m=' . $token]);
}

function updateUser(): void {
    reqAdmin();
    $d = input();
    if (empty($d['id'])) respond(['error' => 'ID vereist'], 400);

    // Wachtwoord updaten als meegegeven
    if (!empty($d['password'])) {
        $hash = password_hash($d['password'], PASSWORD_DEFAULT);
        db()->prepare("UPDATE users SET name=?, email=?, phone=?, roles=?, active=?, password=? WHERE id=?")
            ->execute([$d['name'] ?? '', $d['email'] ?? '', $d['phone'] ?? '', $d['roles'] ?? 'user', (int)($d['active'] ?? 1), $hash, (int)$d['id']]);
    } else {
        db()->prepare("UPDATE users SET name=?, email=?, phone=?, roles=?, active=? WHERE id=?")
            ->execute([$d['name'] ?? '', $d['email'] ?? '', $d['phone'] ?? '', $d['roles'] ?? 'user', (int)($d['active'] ?? 1), (int)$d['id']]);
    }
    respond(['success' => true]);
}

function deleteUser(): void {
    reqAdmin();
    $d  = input();
    $id = (int)($d['id'] ?? 0);
    if (!$id) respond(['error' => 'ID vereist'], 400);

    // Verwijder koppelingen eerst (FK-constraint)
    db()->prepare("DELETE FROM user_zwembaden WHERE user_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    respond(['success' => true]);
}

function linkUserZwembad(): void {
    reqAdmin();
    $d = input();
    if (empty($d['user_id']) || empty($d['zwembad_id'])) respond(['error' => 'IDs vereist'], 400);
    db()->prepare("INSERT IGNORE INTO user_zwembaden (user_id, zwembad_id) VALUES (?,?)")
        ->execute([$d['user_id'], $d['zwembad_id']]);
    respond(['success' => true]);
}

function unlinkUserZwembad(): void {
    reqAdmin();
    $d = input();
    if (empty($d['user_id']) || empty($d['zwembad_id'])) respond(['error' => 'IDs vereist'], 400);
    db()->prepare("DELETE FROM user_zwembaden WHERE user_id = ? AND zwembad_id = ?")
        ->execute([$d['user_id'], $d['zwembad_id']]);
    respond(['success' => true]);
}

function sendMagicLink(): void {
    reqAdmin();
    $d = input();
    if (empty($d['user_id'])) respond(['error' => 'user_id vereist'], 400);

    $st = db()->prepare("SELECT name, email, magic_token FROM users WHERE id = ?");
    $st->execute([$d['user_id']]);
    $u = $st->fetch();
    if (!$u) respond(['error' => 'Gebruiker niet gevonden'], 404);

    $baseUrl = getMeta('base_url', '');
    $link    = $baseUrl . '/?m=' . $u['magic_token'];
    $html    = "<p>Hallo {$u['name']},</p>
        <p>Gebruik de onderstaande link om in te loggen bij PoolCheck Pro:</p>
        <p><a href='$link' style='background:#1D9E75;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;display:inline-block'>Open PoolCheck Pro</a></p>
        <p style='color:#888;font-size:12px'>Of kopieer: $link</p>";

    $ok = smtpSend($u['email'], $u['name'], 'Jouw PoolCheck Pro toegangslink', $html);
    respond(['success' => $ok, 'link' => $link]);
}

// ============================================================
// ADMIN: ZWEMBADEN
// ============================================================
function adminZwembaden(): void {
    reqAdmin();
    $baseUrl = getMeta('base_url', '');
    $rows    = db()->query("SELECT * FROM zwembaden ORDER BY name")->fetchAll();
    foreach ($rows as &$z) {
        $z['qr_url'] = $baseUrl . '/?k=' . $z['qr_token'];
    }
    respond(['zwembaden' => $rows]);
}

function addZwembad(): void {
    reqAdmin();
    $d = input();
    if (empty($d['name'])) respond(['error' => 'Naam vereist'], 400);

    $code  = nextCode('P', 'zwembaden', 'code');
    $token = bin2hex(random_bytes(16));

    $st = db()->prepare("INSERT INTO zwembaden
        (code, name, owner_name, email, phone, address, pool_type, volume_liters, notes, qr_token, public_visible)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $code, $d['name'], $d['owner_name'] ?? '', $d['email'] ?? '', $d['phone'] ?? '',
        $d['address'] ?? '', $d['pool_type'] ?? 'Privé buitenbad',
        (int)($d['volume_liters'] ?? 40000), $d['notes'] ?? '',
        $token, (int)($d['public_visible'] ?? 0)
    ]);
    $id = (int)db()->lastInsertId();

    $baseUrl = getMeta('base_url', '');
    respond(['success' => true, 'id' => $id, 'code' => $code, 'qr_url' => $baseUrl . '/?k=' . $token]);
}

function updateZwembad(): void {
    reqAdmin();
    $d = input();
    if (empty($d['id'])) respond(['error' => 'ID vereist'], 400);
    db()->prepare("UPDATE zwembaden SET name=?, owner_name=?, email=?, phone=?, address=?, pool_type=?, volume_liters=?, notes=?, public_visible=?, active=? WHERE id=?")
        ->execute([$d['name'] ?? '', $d['owner_name'] ?? '', $d['email'] ?? '', $d['phone'] ?? '',
                   $d['address'] ?? '', $d['pool_type'] ?? 'Privé buitenbad',
                   (int)($d['volume_liters'] ?? 40000), $d['notes'] ?? '',
                   (int)($d['public_visible'] ?? 0), (int)($d['active'] ?? 1), (int)$d['id']]);
    respond(['success' => true]);
}

// ============================================================
// ADMIN: INSTELLINGEN
// ============================================================
function adminSettings(): void {
    reqAdmin();
    respond([
        'base_url'      => getMeta('base_url'),
        'mail_host'     => getMeta('mail_host'),
        'mail_port'     => getMeta('mail_port', '465'),
        'mail_user'     => getMeta('mail_user'),
        'mail_from'     => getMeta('mail_from'),
        'mail_name'     => getMeta('mail_name', 'PoolCheck Pro'),
        'manager_email' => getMeta('manager_email'),
        'claude_api_key'=> getMeta('claude_api_key') ? '••••••••' : '',
        'api_key_set'   => strlen(getMeta('claude_api_key')) > 10,
    ]);
}

function saveSettings(): void {
    reqAdmin();
    $d = input();

    $allowed = ['base_url', 'mail_host', 'mail_port', 'mail_user', 'mail_from', 'mail_name', 'manager_email'];
    foreach ($allowed as $key) {
        if (isset($d[$key])) setMeta($key, trim($d[$key]));
    }

    // Mail wachtwoord alleen updaten als meegegeven (niet leeg)
    if (!empty($d['mail_pass'])) setMeta('mail_pass', $d['mail_pass']);

    // Claude API key alleen updaten als meegegeven
    if (!empty($d['claude_api_key']) && $d['claude_api_key'] !== '••••••••') {
        setMeta('claude_api_key', $d['claude_api_key']);
    }

    // Eigen wachtwoord wijzigen
    if (!empty($d['new_password'])) {
        $userId = $_SESSION['admin_user_id'];
        $hash   = password_hash($d['new_password'], PASSWORD_DEFAULT);
        db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $userId]);
    }

    respond(['success' => true]);
}

function appStatus(): void {
    reqAdmin();
    respond([
        'file_version' => date('YmdHi', filemtime(__FILE__)),
        'db_version'   => getMeta('db_version', 'Nog niet gemigreerd'),
        'last_deploy'  => getMeta('last_deploy'),
        'api_key_set'  => strlen(getMeta('claude_api_key')) > 10,
    ]);
}

// ============================================================
// DEMO DATA (optioneel, via admin panel)
// ============================================================
function loadDemoData(): void {
    reqAdmin();

    $results = [];

    // Demo zwembad
    try {
        $code  = nextCode('P', 'zwembaden', 'code');
        $token = bin2hex(random_bytes(16));
        db()->prepare("INSERT INTO zwembaden (code, name, owner_name, email, address, pool_type, volume_liters, qr_token, public_visible)
            VALUES (?,?,?,?,?,?,?,?,1)")
            ->execute([$code, 'Demo Zwembad', 'Demo Eigenaar', '', 'Teststraat 1', 'Privé buitenbad', 40000, $token]);
        $baseUrl = getMeta('base_url', '');
        $results[] = "Zwembad aangemaakt: $code — QR: {$baseUrl}/?k=$token";
    } catch (Throwable $e) {
        $results[] = "Zwembad fout: " . $e->getMessage();
    }

    // Demo monteur
    try {
        $code  = nextCode('U', 'users', 'code');
        $token = bin2hex(random_bytes(16));
        db()->prepare("INSERT INTO users (code, name, email, magic_token, roles) VALUES (?,?,?,?,?)")
            ->execute([$code, 'Demo Monteur', 'demo@example.com', $token, 'user']);
        $baseUrl = getMeta('base_url', '');
        $results[] = "Gebruiker aangemaakt: $code — Link: {$baseUrl}/?m=$token";
    } catch (Throwable $e) {
        $results[] = "Gebruiker fout: " . $e->getMessage();
    }

    respond(['success' => true, 'results' => $results]);
}
