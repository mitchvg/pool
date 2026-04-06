<?php
// debug.php — tijdelijk diagnose bestand
// VERWIJDER DIT NA GEBRUIK (bevat DB gegevens)

define('DB_HOST','localhost');
define('DB_PORT',3306);
define('DB_NAME','pool');
define('DB_USER','pool');
define('DB_PASS','5S!5VcwPbc%v7ofw');

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>PoolCheck Debug</title>
<style>body{font-family:monospace;padding:20px;background:#111;color:#0f0}
h2{color:#5DCAA5;margin-top:20px} .ok{color:#5DCAA5} .err{color:#e24b4a} .warn{color:#f4a261}
table{border-collapse:collapse;margin:10px 0} td,th{border:1px solid #333;padding:5px 10px}
</style></head><body>
<h1>🔍 PoolCheck Diagnose</h1>
<?php

// 1. PHP versie
echo "<h2>PHP</h2>";
echo "Versie: <span class='ok'>".phpversion()."</span><br>";
echo "ZipArchive: ".(class_exists('ZipArchive')?'<span class="ok">Beschikbaar</span>':'<span class="err">NIET beschikbaar</span>')."<br>";
echo "Sessions: ".(session_status()!==PHP_SESSION_DISABLED?'<span class="ok">OK</span>':'<span class="err">Uitgeschakeld</span>')."<br>";

// 2. Database verbinding
echo "<h2>Database verbinding</h2>";
try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );
    echo "<span class='ok'>✓ Verbonden met ".DB_NAME."</span><br>";
} catch(Exception $e) {
    echo "<span class='err'>✗ FOUT: ".$e->getMessage()."</span><br>";
    die();
}

// 3. Tabellen
echo "<h2>Tabellen aanwezig</h2>";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$needed = ['woningen','users','user_woningen','visits'];
$old    = ['clients','technicians'];

foreach($needed as $t) {
    $has = in_array($t,$tables);
    echo ($has?"<span class='ok'>✓":"<span class='err'>✗")." $t (".(
        $has ? $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()." rijen" : "ONTBREEKT"
    ).")</span><br>";
}

echo "<br>";
foreach($old as $t) {
    if(in_array($t,$tables)) {
        $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "<span class='warn'>⚠ Oude tabel aanwezig: $t ($n rijen) — migratie nodig?</span><br>";
    }
}

// 4. Data tonen
echo "<h2>Woningen</h2>";
if(in_array('woningen',$tables)) {
    $rows = $pdo->query("SELECT id,name,pool_code,qr_token,active FROM woningen")->fetchAll();
    if($rows) {
        echo "<table><tr><th>ID</th><th>Naam</th><th>Poolcode</th><th>QR token</th><th>Actief</th></tr>";
        foreach($rows as $r) echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['pool_code']}</td><td>".substr($r['qr_token'],0,8)."...</td><td>{$r['active']}</td></tr>";
        echo "</table>";
    } else echo "<span class='warn'>Tabel leeg</span><br>";
}

echo "<h2>Users</h2>";
if(in_array('users',$tables)) {
    $rows = $pdo->query("SELECT id,name,email,roles,active,LEFT(magic_token,8) as tok FROM users")->fetchAll();
    if($rows) {
        echo "<table><tr><th>ID</th><th>Naam</th><th>Email</th><th>Rollen</th><th>Token (begin)</th><th>Actief</th></tr>";
        foreach($rows as $r) echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['email']}</td><td>{$r['roles']}</td><td>{$r['tok']}...</td><td>{$r['active']}</td></tr>";
        echo "</table>";
    } else echo "<span class='warn'>Tabel leeg</span><br>";
}

echo "<h2>Visits</h2>";
if(in_array('visits',$tables)) {
    $n = $pdo->query("SELECT COUNT(*) FROM visits")->fetchColumn();
    echo "<span class='ok'>$n bezoeken in database</span><br>";
    if($n > 0) {
        // Check column names
        $cols = $pdo->query("SHOW COLUMNS FROM visits")->fetchAll(PDO::FETCH_COLUMN);
        $hasWoning = in_array('woning_id',$cols);
        $hasClient = in_array('client_id',$cols);
        echo "Kolom woning_id: ".($hasWoning?"<span class='ok'>✓</span>":"<span class='err'>✗</span>")."<br>";
        echo "Kolom client_id (oud): ".($hasClient?"<span class='warn'>aanwezig (oud schema)</span>":"<span class='ok'>niet aanwezig (goed)</span>")."<br>";
    }
}

// 5. Test API endpoints
echo "<h2>API endpoint test</h2>";
$actions = ['admin_check','admin_woningen','admin_users','admin_visits'];
foreach($actions as $a) {
    $url = "http://localhost/api.php?action=$a";
    // Can't easily test from here without session, just check if file exists
}
echo "<span class='warn'>Open api.php?action=admin_woningen direct in browser (na inloggen) om te testen</span><br>";

// 6. Migratie aanbod
if(in_array('clients',$tables) && in_array('woningen',$tables)) {
    $oldCount = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $newCount = $pdo->query("SELECT COUNT(*) FROM woningen")->fetchColumn();
    if($oldCount > 0 && $newCount == 0) {
        echo "<h2 style='color:#f4a261'>⚠ Migratie nodig!</h2>";
        echo "<p>Er zijn $oldCount records in de oude 'clients' tabel maar 0 in 'woningen'.</p>";
        echo "<p>Voer <strong>migrate.php</strong> uit om data te migreren.</p>";
    }
}

echo "<br><br><span style='color:#555'>Verwijder dit bestand na gebruik: rm debug.php</span>";
?>
</body></html>
