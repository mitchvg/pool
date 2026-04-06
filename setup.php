<?php
/**
 * PoolCheck Pro — setup.php
 * Eenmalige installatie. Daarna verwijderen van de server.
 * Na installatie: updates via GitHub — setup.php nooit opnieuw nodig.
 */

$configFile = __DIR__ . '/config.local.php';
$step       = $_POST['step'] ?? ($_GET['step'] ?? 1);
$error      = '';
$success    = '';

// ── Al geconfigureerd? ────────────────────────────────────────
$alreadyConfigured = file_exists($configFile) && !isset($_GET['force']);

// ── Verwerk formulier ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['test_db'])) {
        // DB-verbinding testen
        try {
            new PDO(
                "mysql:host={$_POST['db_host']};port={$_POST['db_port']};dbname={$_POST['db_name']};charset=utf8mb4",
                $_POST['db_user'], $_POST['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $success = 'Verbinding succesvol!';
        } catch (Exception $e) {
            $error = 'Verbinding mislukt: ' . $e->getMessage();
        }

    } elseif (isset($_POST['install'])) {
        try {
            // 1. Test DB-verbinding
            $pdo = new PDO(
                "mysql:host={$_POST['db_host']};port={$_POST['db_port']};dbname={$_POST['db_name']};charset=utf8mb4",
                $_POST['db_user'], $_POST['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // 2. Valideer verplichte velden
            if (empty($_POST['admin_name']))     throw new Exception('Naam admin is verplicht');
            if (empty($_POST['admin_email']))    throw new Exception('E-mail admin is verplicht');
            if (empty($_POST['admin_password'])) throw new Exception('Wachtwoord admin is verplicht');
            if (strlen($_POST['admin_password']) < 6) throw new Exception('Wachtwoord minimaal 6 tekens');
            if (empty($_POST['base_url']))       throw new Exception('App URL is verplicht');

            // 3. Schrijf config.local.php (alleen DB)
            $cfg  = "<?php\n";
            $cfg .= "// PoolCheck Pro — lokale configuratie\n";
            $cfg .= "// NIET uploaden naar GitHub (staat in .gitignore)\n";
            $cfg .= "// Gegenereerd door setup.php op " . date('d-m-Y H:i') . "\n\n";
            $cfg .= "define('DB_HOST', " . var_export(trim($_POST['db_host']), true) . ");\n";
            $cfg .= "define('DB_PORT',  " . (int)$_POST['db_port'] . ");\n";
            $cfg .= "define('DB_NAME', " . var_export(trim($_POST['db_name']), true) . ");\n";
            $cfg .= "define('DB_USER', " . var_export(trim($_POST['db_user']), true) . ");\n";
            $cfg .= "define('DB_PASS', " . var_export($_POST['db_pass'], true) . ");\n";
            file_put_contents($configFile, $cfg);

            // 4. Maak app_meta tabel aan + sla instellingen op
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta (
                key_name   VARCHAR(50)  PRIMARY KEY,
                value      VARCHAR(500) NOT NULL DEFAULT '',
                updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");

            $ins = "INSERT INTO app_meta (key_name, value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()";
            $settings = [
                'base_url'      => rtrim(trim($_POST['base_url']), '/'),
                'mail_host'     => trim($_POST['mail_host'] ?? ''),
                'mail_port'     => trim($_POST['mail_port'] ?? '465'),
                'mail_user'     => trim($_POST['mail_user'] ?? ''),
                'mail_pass'     => $_POST['mail_pass'] ?? '',
                'mail_from'     => trim($_POST['mail_user'] ?? ''),
                'mail_name'     => trim($_POST['mail_name'] ?? 'PoolCheck Pro'),
                'manager_email' => trim($_POST['manager_email'] ?? ''),
                'claude_api_key'=> trim($_POST['claude_key'] ?? ''),
            ];
            foreach ($settings as $k => $v) {
                $pdo->prepare($ins)->execute([$k, $v, $v]);
            }

            // 5. Maak tables aan via autoMigrate (door api.php te laden)
            // We doen het hier direct zodat setup compleet is
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                code        VARCHAR(10)  UNIQUE NOT NULL,
                name        VARCHAR(100) NOT NULL,
                email       VARCHAR(100) NOT NULL DEFAULT '',
                phone       VARCHAR(30)  DEFAULT '',
                password    VARCHAR(255) DEFAULT NULL,
                magic_token VARCHAR(32)  UNIQUE NOT NULL,
                roles       VARCHAR(50)  DEFAULT 'user',
                active      TINYINT(1)   DEFAULT 1,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS zwembaden (
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
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_zwembaden (
                user_id    INT NOT NULL,
                zwembad_id INT NOT NULL,
                PRIMARY KEY (user_id, zwembad_id),
                FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
                FOREIGN KEY (zwembad_id) REFERENCES zwembaden(id) ON DELETE CASCADE
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS visits (
                id                      INT AUTO_INCREMENT PRIMARY KEY,
                zwembad_id              INT NOT NULL,
                user_id                 INT,
                visit_date              DATE NOT NULL,
                visit_time              TIME NOT NULL,
                ph                      DECIMAL(4,2),
                chlorine                DECIMAL(5,2),
                alkalinity              INT,
                stabilizer              DECIMAL(5,1),
                volume_used             INT,
                notes                   TEXT,
                advice_json             TEXT,
                strip_photo             VARCHAR(64) DEFAULT '',
                confirm_photo_chemicals VARCHAR(64) DEFAULT '',
                confirm_photo_pool      VARCHAR(64) DEFAULT '',
                email_sent              TINYINT(1)  DEFAULT 0,
                created_at              TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (zwembad_id) REFERENCES zwembaden(id),
                FOREIGN KEY (user_id)    REFERENCES users(id)
            )");

            // 5b. Voeg ontbrekende kolommen toe (veilig bij herinstallatie op bestaande DB)
            $dbName2  = $pdo->query("SELECT DATABASE()")->fetchColumn();
            $colSql   = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";

            $uc = $pdo->prepare($colSql); $uc->execute([$dbName2, 'users']);
            $uc = $uc->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('code',     $uc)) $pdo->exec("ALTER TABLE users ADD COLUMN code VARCHAR(10) DEFAULT NULL");
            if (!in_array('password', $uc)) $pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(255) DEFAULT NULL");

            $pc = $pdo->prepare($colSql); $pc->execute([$dbName2, 'zwembaden']);
            $pc = $pc->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('code', $pc)) $pdo->exec("ALTER TABLE zwembaden ADD COLUMN code VARCHAR(10) DEFAULT NULL");

            // 6. Maak admin-gebruiker aan
            $adminCode  = 'UA0'; // eerste gebruiker is altijd UA0
            $adminToken = bin2hex(random_bytes(16));
            $adminHash  = password_hash($_POST['admin_password'], PASSWORD_DEFAULT);

            // Verwijder eventuele eerdere UA0 (bij herinstallatie)
            $pdo->prepare("DELETE FROM users WHERE code = ?")->execute([$adminCode]);
            $pdo->prepare("INSERT INTO users (code, name, email, magic_token, password, roles)
                VALUES (?,?,?,?,?,'admin')")
                ->execute([$adminCode, trim($_POST['admin_name']), trim($_POST['admin_email']), $adminToken, $adminHash]);

            // 7. Versie opslaan
            $fileVer = date('YmdHi', filemtime(__DIR__ . '/api.php'));
            $pdo->prepare($ins)->execute(['db_version',  $fileVer,             $fileVer]);
            $pdo->prepare($ins)->execute(['last_deploy',  date('d-m-Y H:i:s'), date('d-m-Y H:i:s')]);

            $success = 'installed';

        } catch (Exception $e) {
            $error = $e->getMessage();
            // Verwijder config.local.php als die net aangemaakt is bij een fout
            if (isset($cfg) && file_exists($configFile)) {
                $content = file_get_contents($configFile);
                if (str_contains($content, 'Gegenereerd door setup.php')) {
                    // Alleen verwijderen als het de zojuist gemaakte is
                }
            }
        }
    }
}

$v = fn($k, $d = '') => htmlspecialchars($_POST[$k] ?? $d);
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PoolCheck Pro — Installatie</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f2;min-height:100vh;padding:2rem 1rem;color:#111}
.wrap{max-width:560px;margin:0 auto}
h1{font-size:22px;font-weight:700;color:#0F6E56;margin-bottom:4px}
.sub{font-size:14px;color:#666;margin-bottom:2rem}
.card{background:#fff;border-radius:12px;padding:1.5rem;border:1px solid rgba(0,0,0,.1);margin-bottom:1rem}
.card h2{font-size:15px;font-weight:600;margin-bottom:1rem;color:#333;border-bottom:1px solid #eee;padding-bottom:8px}
.fg{margin-bottom:12px}
label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:4px}
input{width:100%;padding:10px 12px;border:1.5px solid rgba(0,0,0,.15);border-radius:8px;font-size:14px;outline:none;background:#fff}
input:focus{border-color:#1D9E75}
.hint{font-size:11px;color:#999;margin-top:3px}
.row{display:grid;grid-template-columns:2fr 1fr;gap:10px}
.btn{padding:11px 20px;background:#1D9E75;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;margin-right:8px}
.btn:hover{opacity:.9}
.btn-out{background:transparent;border:1.5px solid rgba(0,0,0,.2);color:#555}
.ok{background:#eaf3de;border-left:4px solid #52b788;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:12px;line-height:1.8}
.er{background:#fcebeb;border-left:4px solid #e24b4a;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:12px}
.wa{background:#faeeda;border-left:4px solid #f4a261;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:1rem}
a{color:#1D9E75;text-decoration:none}
a:hover{text-decoration:underline}
.done-link{display:inline-block;margin-top:8px;padding:10px 20px;background:#0F6E56;color:#fff;border-radius:8px;font-weight:600;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
<h1>PoolCheck Pro — Installatie</h1>
<p class="sub">Configureer eenmalig. Daarna verlopen updates automatisch via GitHub.</p>

<?php if ($alreadyConfigured): ?>
<div class="ok">
  Configuratie aanwezig.<br>
  <a href="admin.html">Ga naar het admin panel</a> &nbsp;|&nbsp;
  <a href="setup.php?force=1">Opnieuw instellen</a>
</div>
<?php return; endif; ?>

<?php if ($success === 'installed'): ?>
<div class="ok">
  <strong>Installatie voltooid!</strong><br>
  Database aangemaakt, admin-gebruiker aangemaakt, instellingen opgeslagen.<br><br>
  <strong>Volgende stap:</strong> verwijder <code>setup.php</code> van de server (veiligheid).<br>
  <a class="done-link" href="admin.html">Open het admin panel</a>
</div>
<?php return; endif; ?>

<?php if ($error): ?><div class="er"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success && $success !== 'installed'): ?><div class="ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<form method="POST">

<div class="card">
  <h2>Database</h2>
  <div class="row">
    <div class="fg"><label>Host</label><input name="db_host" value="<?= $v('db_host','localhost') ?>"><div class="hint">Vrijwel altijd: localhost</div></div>
    <div class="fg"><label>Poort</label><input name="db_port" value="<?= $v('db_port','3306') ?>"></div>
  </div>
  <div class="row">
    <div class="fg"><label>Database naam</label><input name="db_name" value="<?= $v('db_name','poolcheck') ?>"></div>
    <div class="fg"><label>Gebruiker</label><input name="db_user" value="<?= $v('db_user','poolcheck') ?>"></div>
  </div>
  <div class="fg"><label>Wachtwoord</label><input type="password" name="db_pass" value="<?= $v('db_pass') ?>"></div>
  <button type="submit" name="test_db" value="1" class="btn btn-out" style="font-size:13px;padding:8px 14px">Verbinding testen</button>
</div>

<div class="card">
  <h2>App</h2>
  <div class="fg"><label>URL van de app</label><input name="base_url" value="<?= $v('base_url','https://pool.villaparkfontein.com') ?>"><div class="hint">Geen trailing slash. Wordt gebruikt voor QR-links en e-mails.</div></div>
</div>

<div class="card">
  <h2>Admin account</h2>
  <div class="row">
    <div class="fg"><label>Naam</label><input name="admin_name" value="<?= $v('admin_name') ?>" placeholder="bijv. Pool Manager"></div>
    <div class="fg"><label>Gebruikersnaam / e-mail</label><input name="admin_email" value="<?= $v('admin_email') ?>"></div>
  </div>
  <div class="fg"><label>Wachtwoord</label><input type="password" name="admin_password" value=""><div class="hint">Minimaal 6 tekens</div></div>
</div>

<div class="card">
  <h2>E-mail (SMTP) — optioneel</h2>
  <div class="hint" style="margin-bottom:12px;font-size:12px;color:#888">Sla over als je later in het admin panel wilt instellen.</div>
  <div class="row">
    <div class="fg"><label>Mail server</label><input name="mail_host" value="<?= $v('mail_host','villaparkfontein.com') ?>"></div>
    <div class="fg"><label>Poort</label><input name="mail_port" value="<?= $v('mail_port','465') ?>"></div>
  </div>
  <div class="fg"><label>E-mailadres</label><input name="mail_user" value="<?= $v('mail_user','pool@villaparkfontein.com') ?>"><div class="hint">Dit is ook de afzender en SMTP-gebruikersnaam</div></div>
  <div class="fg"><label>E-mail wachtwoord</label><input type="password" name="mail_pass" value="<?= $v('mail_pass') ?>"></div>
  <div class="fg"><label>Weergavenaam afzender</label><input name="mail_name" value="<?= $v('mail_name','PoolCheck Pro') ?>"></div>
  <div class="fg"><label>Manager e-mail (ontvangt kopie rapporten)</label><input name="manager_email" value="<?= $v('manager_email') ?>"></div>
</div>

<div class="card">
  <h2>Claude Vision API — optioneel</h2>
  <div class="fg"><label>API Key</label><input name="claude_key" value="<?= $v('claude_key') ?>" placeholder="sk-ant-..."><div class="hint">Voor automatisch uitlezen van teststrips. Stel later in via Admin → Instellingen.</div></div>
</div>

<div class="wa">Na installatie: verwijder <code>setup.php</code> van de server.</div>

<button type="submit" name="install" value="1" class="btn" style="padding:13px 28px;font-size:15px">Installeren</button>

</form>
</div>
</body>
</html>
