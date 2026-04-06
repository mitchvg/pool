<?php
/**
 * PoolCheck Pro — update.php
 * Wachtwoord: PoolAdmin2024!
 */

define('UPDATE_PASS', 'PoolAdmin2024!');
define('APP_DIR',     __DIR__);
define('BACKUP_DIR',  __DIR__ . '/_backups/');

$submitted_pw = trim($_POST['pw'] ?? '');
$authed = ($submitted_pw === UPDATE_PASS);
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$authed && $submitted_pw !== '') {
        $error = 'Verkeerd wachtwoord.';
    } elseif ($authed && isset($_FILES['zipfile'])) {
        if ($_FILES['zipfile']['error'] === UPLOAD_ERR_OK) {
            try { $msg = processZip($_FILES['zipfile']['tmp_name']); }
            catch (Exception $e) { $error = $e->getMessage(); }
        } elseif ($_FILES['zipfile']['error'] !== UPLOAD_ERR_NO_FILE) {
            $codes = [1=>'Te groot (php.ini)',2=>'Te groot (form)',3=>'Gedeeltelijk',6=>'Geen temp map',7=>'Schrijffout'];
            $error = 'Upload fout: '.($codes[$_FILES['zipfile']['error']] ?? 'code '.$_FILES['zipfile']['error']);
        }
    }
}

function processZip(string $tmp): string {
    if (!class_exists('ZipArchive')) throw new Exception('ZipArchive niet beschikbaar — installeer php-zip extensie.');
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) throw new Exception('Kon ZIP niet openen.');
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);

    $log = []; $sqlFiles = []; $n = 0;
    $ok = ['php','html','css','js','sql','txt','svg','png','jpg','jpeg','webp','ico'];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!$name || substr($name,-1)==='/') continue;
        $parts = explode('/', $name);
        if (count($parts)>1) { array_shift($parts); $name=implode('/',$parts); }
        if (!$name) continue;
        $ext = strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if (!in_array($ext,$ok)) { continue; }
        if (basename($name)==='update.php') { $log[]='update.php overgeslagen (beschermd)'; continue; }
        $content = $zip->getFromIndex($i);
        if ($ext==='sql') { $sqlFiles[$name]=$content; $log[]="SQL gevonden: $name"; continue; }
        $dest = APP_DIR.'/'.$name;
        $dir = dirname($dest);
        if (!is_dir($dir)) mkdir($dir,0755,true);
        file_put_contents($dest, $content);
        $log[] = "&#10003; $name"; $n++;
    }
    $zip->close();

    foreach ($sqlFiles as $fname => $sql) {
        try {
            $pdo = new PDO('mysql:host=localhost;dbname=pool;charset=utf8mb4','pool','5S!5VcwPbc%v7ofw',
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $stmts = preg_split('/;\s*[\r\n]+/',$sql,-1,PREG_SPLIT_NO_EMPTY);
            $c=0;
            foreach(array_filter(array_map('trim',$stmts)) as $s) {
                if(!$s||str_starts_with(ltrim($s),'--')) continue;
                try{$pdo->exec($s);$c++;}catch(PDOException $e){
                    if(strpos($e->getMessage(),'already exists')===false&&strpos($e->getMessage(),'Duplicate')===false)
                        $log[]='SQL: '.$e->getMessage();
                }
            }
            $log[]="&#10003; SQL $fname ($c statements)";
        } catch(Exception $e){$log[]='DB: '.$e->getMessage();}
    }

    file_put_contents(BACKUP_DIR.'update_'.date('Ymd_His').'.log',implode("\n",$log));
    return '<strong>'.$n.' bestand(en) bijgewerkt'.(count($sqlFiles)?', '.count($sqlFiles).' SQL uitgevoerd':'').'</strong><br><br>'.implode('<br>',array_map('htmlspecialchars',$log));
}
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PoolCheck Updater</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f2;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:2rem 1rem}
.c{background:#fff;border-radius:14px;padding:2rem;width:100%;max-width:500px;border:1px solid rgba(0,0,0,.1)}
h1{font-size:20px;font-weight:700;color:#0F6E56;margin-bottom:4px}
.s{font-size:13px;color:#666;margin-bottom:1.5rem}
label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:5px;margin-top:12px}
input[type=password],input[type=file]{width:100%;padding:11px 13px;border:1.5px solid rgba(0,0,0,.15);border-radius:8px;font-size:14px;outline:none}
input[type=password]:focus{border-color:#1D9E75}
input[type=file]{border-style:dashed;cursor:pointer;padding:10px}
button{width:100%;padding:13px;background:#1D9E75;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;margin-top:14px}
button:hover{opacity:.9}
.ok{background:#eaf3de;border-left:4px solid #52b788;padding:12px 14px;border-radius:6px;font-size:13px;margin-bottom:14px;line-height:1.9}
.er{background:#fcebeb;border-left:4px solid #e24b4a;padding:12px 14px;border-radius:6px;font-size:13px;margin-bottom:14px}
.hi{font-size:12px;color:#999;line-height:1.7;margin-top:12px}
a{color:#1D9E75}
</style>
</head>
<body>
<div class="c">
  <h1>💧 PoolCheck Updater</h1>
  <p class="s">Upload een nieuwe versie als ZIP</p>
  <?php if($msg):?><div class="ok"><?=$msg?></div><?php endif;?>
  <?php if($error):?><div class="er"><?=htmlspecialchars($error)?></div><?php endif;?>
  <form method="POST" enctype="multipart/form-data">
    <label>Wachtwoord</label>
    <input type="password" name="pw" placeholder="PoolAdmin2024!" value="<?=$authed?htmlspecialchars(UPDATE_PASS):''?>" <?=$authed?'':'autofocus'?>>
    <?php if($authed):?>
    <label>ZIP bestand</label>
    <input type="file" name="zipfile" accept=".zip">
    <div class="hi">&#10003; Bestanden worden bijgewerkt &nbsp; &#10003; SQL uitgevoerd &nbsp; &#10003; update.php nooit overschreven</div>
    <?php endif;?>
    <button type="submit"><?=$authed?'&#8679; Uploaden &amp; installeren':'Inloggen'?></button>
  </form>
  <?php if($msg):?><p style="margin-top:1rem;text-align:center"><a href="admin.html">&#8592; Admin panel</a></p><?php endif;?>
</div>
</body>
</html>
