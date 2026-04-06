<?php
// migrate.php — eenmalig uitvoeren om v3 data naar v4 te migreren
// Daarna verwijderen!

define('DB_HOST','localhost'); define('DB_PORT',3306);
define('DB_NAME','pool'); define('DB_USER','pool'); define('DB_PASS','5S!5VcwPbc%v7ofw');

$pdo = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$log = [];
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

// ── Maak v4 tabellen aan als ze nog niet bestaan ─────────────
$pdo->exec("
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
  PRIMARY KEY (user_id,woning_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (woning_id) REFERENCES woningen(id) ON DELETE CASCADE
);
");
$log[] = "✓ v4 tabellen aangemaakt (of bestonden al)";

// ── Migreer technicians → users ──────────────────────────────
if(in_array('technicians',$tables)) {
    $techs = $pdo->query("SELECT * FROM technicians")->fetchAll();
    $inserted = 0;
    foreach($techs as $t) {
        $check = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $check->execute([$t['email']]);
        if($check->fetch()) { $log[]="⚠ User al aanwezig: {$t['name']} ({$t['email']})"; continue; }
        $pdo->prepare("INSERT INTO users (name,email,phone,magic_token,roles,active,created_at) VALUES (?,?,?,?,'monteur',?,?)")
            ->execute([$t['name'],$t['email'],$t['phone']??'',$t['magic_token'],$t['active']??1,$t['created_at']??date('Y-m-d H:i:s')]);
        $inserted++;
    }
    $log[] = "✓ Technicians gemigreerd: $inserted van ".count($techs);
}

// ── Migreer clients → woningen ───────────────────────────────
$clientToWoning = []; // old client_id → new woning_id
if(in_array('clients',$tables)) {
    $clients = $pdo->query("SELECT * FROM clients")->fetchAll();
    $inserted = 0;
    foreach($clients as $c) {
        $check = $pdo->prepare("SELECT id FROM woningen WHERE qr_token=?");
        $check->execute([$c['qr_token']]);
        if($existing = $check->fetch()) {
            $clientToWoning[$c['id']] = $existing['id'];
            $log[]="⚠ Woning al aanwezig: {$c['name']}";
            continue;
        }
        // Generate pool_code if not exists
        $code = strtoupper(substr(md5($c['id'].'_code'),0,6));
        // Ensure unique
        while(true) {
            $ck = $pdo->prepare("SELECT id FROM woningen WHERE pool_code=?");
            $ck->execute([$code]);
            if(!$ck->fetch()) break;
            $code = strtoupper(substr(bin2hex(random_bytes(3)),0,6));
        }
        $histToken = $c['history_token'] ?? bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO woningen (name,owner_name,email,phone,address,pool_type,volume_liters,notes,qr_token,history_token,pool_code,active,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $c['name'],$c['contact_name']??'',$c['email']??'',$c['phone']??'',
                $c['address']??'',$c['pool_type']??'Privé buitenbad',$c['volume_liters']??40000,
                $c['notes']??'',$c['qr_token'],$histToken,$code,$c['active']??1,
                $c['created_at']??date('Y-m-d H:i:s')
            ]);
        $newId = (int)$pdo->lastInsertId();
        $clientToWoning[$c['id']] = $newId;
        $inserted++;
    }
    $log[] = "✓ Clients gemigreerd naar woningen: $inserted van ".count($clients);
}

// ── Migreer visits ────────────────────────────────────────────
if(in_array('visits',$tables)) {
    $visitCols = $pdo->query("SHOW COLUMNS FROM visits")->fetchAll(PDO::FETCH_COLUMN);
    $hasOldCols = in_array('client_id',$visitCols) && !in_array('woning_id',$visitCols);
    $hasBothCols = in_array('client_id',$visitCols) && in_array('woning_id',$visitCols);

    if($hasOldCols) {
        // Old visits table: rename client_id → woning_id, technician_id → user_id
        // First add new columns
        try { $pdo->exec("ALTER TABLE visits ADD COLUMN woning_id INT AFTER id"); $log[]="✓ Kolom woning_id toegevoegd"; } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE visits ADD COLUMN user_id INT AFTER woning_id"); $log[]="✓ Kolom user_id toegevoegd"; } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE visits ADD COLUMN stabilizer DECIMAL(5,1) AFTER alkalinity"); } catch(Exception $e) {}

        // Copy values
        foreach($clientToWoning as $oldCid => $newWid) {
            $pdo->prepare("UPDATE visits SET woning_id=? WHERE client_id=?")->execute([$newWid,$oldCid]);
        }
        // Map technician_id to user_id via magic_token
        if(in_array('technician_id',$visitCols)) {
            $techMap = [];
            if(in_array('technicians',$tables)) {
                $techs = $pdo->query("SELECT t.id AS tid, u.id AS uid FROM technicians t JOIN users u ON u.email=t.email")->fetchAll();
                foreach($techs as $t) $techMap[$t['tid']] = $t['uid'];
            }
            foreach($techMap as $oldTid => $newUid) {
                $pdo->prepare("UPDATE visits SET user_id=? WHERE technician_id=?")->execute([$newUid,$oldTid]);
            }
        }
        $n = $pdo->query("UPDATE visits SET woning_id=1 WHERE woning_id IS NULL OR woning_id=0")->rowCount();
        if($n>0) $log[]="⚠ $n bezoeken hadden geen woning_id — toegewezen aan eerste woning";
        $log[] = "✓ Visits gemigreerd";
    } elseif($hasBothCols) {
        $log[] = "ℹ Visits heeft al woning_id kolom";
    } else {
        $log[] = "✓ Visits tabel is al v4 formaat";
    }

    // Add missing columns to visits if needed
    $visitCols2 = $pdo->query("SHOW COLUMNS FROM visits")->fetchAll(PDO::FETCH_COLUMN);
    foreach(['stabilizer','confirm_photo_chemicals','confirm_photo_pool'] as $col) {
        if(!in_array($col,$visitCols2)) {
            try {
                $type = $col==='stabilizer'?'DECIMAL(5,1)':'VARCHAR(255) DEFAULT ""';
                $pdo->exec("ALTER TABLE visits ADD COLUMN $col $type");
                $log[]="✓ Kolom $col toegevoegd aan visits";
            } catch(Exception $e) {}
        }
    }
}

// ── Controleer admin user ─────────────────────────────────────
$adminCheck = $pdo->prepare("SELECT id FROM users WHERE roles LIKE '%admin%'");
$adminCheck->execute();
if(!$adminCheck->fetch()) {
    // Add default admin
    $token = bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO users (name,email,magic_token,roles) VALUES (?,?,?,?)")
        ->execute(['Manager','pool@villaparkfontein.com',$token,'monteur,admin']);
    $log[] = "✓ Admin gebruiker aangemaakt (pool@villaparkfontein.com) — magic link: https://pool.villaparkfontein.com/?m=$token";
} else {
    $log[] = "✓ Admin gebruiker aanwezig";
}

// Output
?><!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Migratie</title>
<style>body{font-family:monospace;padding:20px;background:#111;color:#eee;line-height:2}
h1{color:#5DCAA5} .ok{color:#5DCAA5} a{color:#5DCAA5}</style>
</head><body>
<h1>✅ Migratie voltooid</h1>
<?php foreach($log as $l) echo "<div>$l</div>"; ?>
<br>
<div>→ <a href="admin.html">Ga naar admin panel</a></div>
<div style="color:#555;margin-top:20px">Verwijder dit bestand na gebruik!</div>
</body></html>
