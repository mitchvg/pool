<?php
/**
 * PoolCheck Pro — setup.php
 * Eenmalige installatie-wizard. Verwijder dit bestand na gebruik.
 * Toegang: https://pool.villaparkfontein.com/setup.php
 */

$configFile = __DIR__ . '/config.local.php';
$done = false;
$error = '';
$testResult = '';

// ── Already configured? ───────────────────────────────────────
if (file_exists($configFile) && !isset($_GET['force'])) {
    // Try to load and test existing config
    require_once $configFile;
    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $configured = true;
        $tableCount = count($tables);
    } catch (Exception $e) {
        $configured = false;
        $configError = $e->getMessage();
    }
}

// ── Handle form submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'test') {
        // Test DB connection only
        try {
            $pdo = new PDO(
                "mysql:host={$_POST['db_host']};port={$_POST['db_port']};dbname={$_POST['db_name']};charset=utf8mb4",
                $_POST['db_user'], $_POST['db_pass'],
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
            );
            $testResult = '✓ Verbinding succesvol!';
        } catch (Exception $e) {
            $testResult = '✗ Fout: ' . $e->getMessage();
        }
    }

    if ($_POST['action'] === 'save') {
        try {
            // Test connection first
            $pdo = new PDO(
                "mysql:host={$_POST['db_host']};port={$_POST['db_port']};dbname={$_POST['db_name']};charset=utf8mb4",
                $_POST['db_user'], $_POST['db_pass'],
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
            );

            // Generate config.local.php
            $cfg = "<?php\n// PoolCheck Pro — lokale configuratie\n// NIET uploaden naar GitHub (staat in .gitignore)\n// Gegenereerd door setup.php op " . date('d-m-Y H:i') . "\n\n";
            $cfg .= "// Database\n";
            $cfg .= "define('DB_HOST', " . var_export($_POST['db_host'], true) . ");\n";
            $cfg .= "define('DB_PORT',  " . (int)$_POST['db_port'] . ");\n";
            $cfg .= "define('DB_NAME', " . var_export($_POST['db_name'], true) . ");\n";
            $cfg .= "define('DB_USER', " . var_export($_POST['db_user'], true) . ");\n";
            $cfg .= "define('DB_PASS', " . var_export($_POST['db_pass'], true) . ");\n\n";
            $cfg .= "// E-mail (SMTP)\n";
            $cfg .= "define('MAIL_HOST', " . var_export($_POST['mail_host'], true) . ");\n";
            $cfg .= "define('MAIL_PORT',  " . (int)$_POST['mail_port'] . ");\n";
            $cfg .= "define('MAIL_USER', " . var_export($_POST['mail_user'], true) . ");\n";
            $cfg .= "define('MAIL_PASS', " . var_export($_POST['mail_pass'], true) . ");\n";
            $cfg .= "define('MAIL_FROM', " . var_export($_POST['mail_user'], true) . ");\n";
            $cfg .= "define('MANAGER_EMAIL', " . var_export($_POST['manager_email'], true) . ");\n\n";
            $cfg .= "// Beveiliging\n";
            $cfg .= "define('ADMIN_PASS', " . var_export($_POST['admin_pass'], true) . ");\n\n";
            $cfg .= "// Claude Vision API (optioneel — voor automatisch stripuitlezen)\n";
            $cfg .= "define('CLAUDE_API_KEY', " . var_export($_POST['claude_key'], true) . ");\n\n";
            $cfg .= "// App URL\n";
            $cfg .= "define('BASE_URL', " . var_export(rtrim($_POST['base_url'],'/'), true) . ");\n";
            $cfg .= "define('UPLOAD_DIR', __DIR__ . '/uploads/');\n";
            $cfg .= "define('UPLOAD_URL', BASE_URL . '/uploads/');\n";

            file_put_contents($configFile, $cfg);

            // Run initial migrations via api.php logic
            // (api.php autoMigrate will run on next request)

            $done = true;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$v = fn($k, $d='') => htmlspecialchars($_POST[$k] ?? $d);
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PoolCheck Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f2;min-height:100vh;padding:2rem 1rem}
.wrap{max-width:540px;margin:0 auto}
h1{font-size:22px;font-weight:700;color:#0F6E56;margin-bottom:4px}
.sub{font-size:14px;color:#666;margin-bottom:2rem}
.card{background:#fff;border-radius:12px;padding:1.5rem;border:1px solid rgba(0,0,0,.1);margin-bottom:1rem}
.card h2{font-size:15px;font-weight:600;margin-bottom:1rem;color:#333;border-bottom:1px solid #eee;padding-bottom:8px}
.fg{margin-bottom:12px}
label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:4px}
input{width:100%;padding:10px 12px;border:1.5px solid rgba(0,0,0,.15);border-radius:8px;font-size:14px;outline:none}
input:focus{border-color:#1D9E75}
.hint{font-size:11px;color:#999;margin-top:3px}
.row{display:grid;grid-template-columns:2fr 1fr;gap:10px}
.btn{padding:12px 20px;background:#1D9E75;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;margin-right:8px}
.btn:hover{opacity:.9}
.btn-out{background:transparent;border:1.5px solid rgba(0,0,0,.2);color:#555}
.ok{background:#eaf3de;border-left:4px solid #52b788;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:12px;line-height:1.7}
.er{background:#fcebeb;border-left:4px solid #e24b4a;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:12px}
.wa{background:#faeeda;border-left:4px solid #f4a261;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:12px}
a{color:#1D9E75}
</style>
</head>
<body>
<div class="wrap">
<h1>💧 PoolCheck Pro — Installatie</h1>
<p class="sub">Configureer de verbindingsgegevens. Dit bestand wordt opgeslagen als <code>config.local.php</code> op de server — niet in GitHub.</p>

<?php if (isset($configured) && $configured): ?>
<div class="ok">
  ✓ Configuratie al aanwezig — <?= $tableCount ?> tabellen gevonden in de database.<br>
  <a href="setup.php?force=1">Opnieuw configureren</a> &nbsp;|&nbsp; <a href="admin.html">Naar admin panel</a>
</div>
<?php return; endif; ?>

<?php if ($done): ?>
<div class="ok">
  <strong>✓ Installatie voltooid!</strong><br>
  config.local.php aangemaakt. De database wordt automatisch bijgewerkt bij de eerste API-aanroep.<br><br>
  <strong>Volgende stap:</strong> <a href="admin.html">Open het admin panel</a> en log in.<br>
  <strong>Daarna:</strong> verwijder setup.php van de server voor de veiligheid.
</div>
<?php return; endif; ?>

<?php if ($error): ?><div class="er"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($testResult): ?>
  <div class="<?= str_starts_with($testResult,'✓')?'ok':'er' ?>"><?= htmlspecialchars($testResult) ?></div>
<?php endif; ?>

<form method="POST">
<div class="card">
  <h2>🗄 Database</h2>
  <div class="row">
    <div class="fg"><label>Host</label><input name="db_host" value="<?= $v('db_host','localhost') ?>"><div class="hint">Vrijwel altijd: localhost</div></div>
    <div class="fg"><label>Poort</label><input name="db_port" value="<?= $v('db_port','3306') ?>"></div>
  </div>
  <div class="row">
    <div class="fg"><label>Database naam</label><input name="db_name" value="<?= $v('db_name','pool') ?>"></div>
    <div class="fg"><label>Gebruiker</label><input name="db_user" value="<?= $v('db_user','pool') ?>"></div>
  </div>
  <div class="fg"><label>Wachtwoord</label><input type="password" name="db_pass" value="<?= $v('db_pass') ?>" placeholder="Database wachtwoord"></div>
  <button type="submit" name="action" value="test" class="btn btn-out">Verbinding testen</button>
</div>

<div class="card">
  <h2>📧 E-mail (SMTP)</h2>
  <div class="row">
    <div class="fg"><label>Mail server</label><input name="mail_host" value="<?= $v('mail_host','villaparkfontein.com') ?>"></div>
    <div class="fg"><label>Poort</label><input name="mail_port" value="<?= $v('mail_port','465') ?>"></div>
  </div>
  <div class="fg"><label>E-mailadres (afzender)</label><input name="mail_user" value="<?= $v('mail_user','pool@villaparkfontein.com') ?>"><div class="hint">Dit is ook de gebruikersnaam voor SMTP</div></div>
  <div class="fg"><label>E-mail wachtwoord</label><input type="password" name="mail_pass" value="<?= $v('mail_pass') ?>"></div>
  <div class="fg"><label>Manager e-mail (ontvangt rapporten)</label><input name="manager_email" value="<?= $v('manager_email','pool@villaparkfontein.com') ?>"></div>
</div>

<div class="card">
  <h2>🔐 Beveiliging</h2>
  <div class="fg"><label>Admin panel wachtwoord</label><input type="password" name="admin_pass" value="<?= $v('admin_pass','PoolCheck2024!') ?>"><div class="hint">Minimaal 8 tekens — kies iets sterks</div></div>
  <div class="fg"><label>App URL</label><input name="base_url" value="<?= $v('base_url','https://pool.villaparkfontein.com') ?>"></div>
</div>

<div class="card">
  <h2>🤖 Claude Vision API (optioneel)</h2>
  <div class="fg"><label>API Key</label><input name="claude_key" value="<?= $v('claude_key') ?>" placeholder="sk-ant-..."><div class="hint">Voor automatisch uitlezen van teststrips. Gratis credits op console.anthropic.com</div></div>
</div>

<div class="wa">⚠ <strong>Na installatie:</strong> verwijder dit bestand via Plesk File Manager voor de veiligheid.</div>

<button type="submit" name="action" value="save" class="btn">Installeren →</button>
</form>
</div>
</body>
</html>
