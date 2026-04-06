<?php
// PoolCheck Pro v4 — api.php
// =====================================================

define('DB_HOST',    'localhost');
define('DB_PORT',     3306);
define('DB_NAME',   'pool');
define('DB_USER',   'pool');
define('DB_PASS',   '5S!5VcwPbc%v7ofw');

define('MAIL_HOST', 'villaparkfontein.com');
define('MAIL_PORT',  465);
define('MAIL_USER', 'pool@villaparkfontein.com');
define('MAIL_PASS', '28?wuY71y');
define('MAIL_FROM', 'pool@villaparkfontein.com');
define('MAIL_NAME', 'PoolCheck Pro');

define('MANAGER_EMAIL', 'pool@villaparkfontein.com');
define('ADMIN_PASS',    'PoolAdmin2024!');   // wijzig dit
define('BASE_URL',      'https://pool.villaparkfontein.com');
define('UPLOAD_DIR',    __DIR__ . '/uploads/');
define('UPLOAD_URL',    BASE_URL . '/uploads/');

// Claude Vision: vul in na console.anthropic.com
define('CLAUDE_API_KEY', '');
define('APP_VERSION',   '4.6');  // Moet matchen met deploy.php

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
set_error_handler(fn($no,$str) => respond(['error'=>"PHP $no: $str"],500));

function db(): PDO {
    static $p;
    if (!$p) $p = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    return $p;
}
function respond(array $d,int $c=200):void { http_response_code($c); echo json_encode($d,JSON_UNESCAPED_UNICODE); exit; }
function input():array { $r=file_get_contents('php://input'); return json_decode($r,true)?:array_merge($_GET,$_POST); }
function reqAdmin():void { if(empty($_SESSION['admin'])) respond(['error'=>'Niet ingelogd'],401); }
function hasRole(array $user,string $role):bool { return in_array($role,explode(',',$user['roles'])); }

$action = $_GET['action'] ?? input()['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
try {
    match(true) {
        $action==='user'           &&$method==='GET'  => getUser(),
        $action==='woning'         &&$method==='GET'  => getWoning(),
        $action==='my_woningen'    &&$method==='GET'  => getMyWoningen(),
        $action==='prev_visit'     &&$method==='GET'  => getPrevVisit(),
        $action==='save_visit'     &&$method==='POST' => saveVisit(),
        $action==='upload_photo'   &&$method==='POST' => uploadPhoto(),
        $action==='woning_history' &&$method==='GET'  => woningHistory(),
        $action==='ai_strip'       &&$method==='POST' => aiStrip(),

        $action==='admin_login'    &&$method==='POST' => adminLogin(),
        $action==='admin_logout'   &&$method==='POST' => adminLogout(),
        $action==='admin_check'    &&$method==='GET'  => adminCheck(),
        $action==='admin_visits'   &&$method==='GET'  => adminVisits(),
        $action==='admin_users'    &&$method==='GET'  => adminUsers(),
        $action==='admin_woningen' &&$method==='GET'  => adminWoningen(),
        $action==='add_woning'     &&$method==='POST' => addWoning(),
        $action==='update_woning'  &&$method==='POST' => updateWoning(),
        $action==='add_user'       &&$method==='POST' => addUser(),
        $action==='update_user'    &&$method==='POST' => updateUser(),
        $action==='link_user_woning'&&$method==='POST'=> linkUserWoning(),

        $action==='save_correction' &&$method==='POST' => saveCorrection(),
        default => respond(['error'=>"Onbekend: $action"],404)
    };
} catch(Throwable $e) { respond(['error'=>$e->getMessage()],500); }

// ============================================================
// PUBLIC: USER
// ============================================================
function getUser():void {
    $token=$_GET['token']??'';
    if(!$token) respond(['error'=>'Token vereist'],400);
    $st=db()->prepare("SELECT id,name,email,phone,roles FROM users WHERE magic_token=? AND active=1");
    $st->execute([$token]);
    $u=$st->fetch();
    if(!$u) respond(['error'=>'Gebruiker niet gevonden'],404);
    // If klant: also load linked woningen
    $u['woningen']=[];
    if(strpos($u['roles'],'klant')!==false || strpos($u['roles'],'admin')!==false) {
        $st2=db()->prepare("SELECT w.* FROM woningen w JOIN user_woningen uw ON uw.woning_id=w.id WHERE uw.user_id=? AND w.active=1");
        $st2->execute([$u['id']]);
        $u['woningen']=$st2->fetchAll();
    }
    respond(['user'=>$u]);
}

// ============================================================
// PUBLIC: WONING
// ============================================================
function getWoning():void {
    $token=$_GET['token']??'';
    $code =strtolower($_GET['code']??'');
    $id   =(int)($_GET['id']??0);
    if($token)     { $st=db()->prepare("SELECT * FROM woningen WHERE qr_token=? AND active=1");  $st->execute([$token]); }
    elseif($code)  { $st=db()->prepare("SELECT * FROM woningen WHERE pool_code=? AND active=1"); $st->execute([$code]); }
    else           { $st=db()->prepare("SELECT * FROM woningen WHERE id=? AND active=1");         $st->execute([$id]); }
    $w=$st->fetch();
    if(!$w) respond(['error'=>'Woning niet gevonden'],404);
    // Strip sensitive tokens from public response
    unset($w['history_token']);
    respond(['woning'=>$w]);
}

function getMyWoningen():void {
    // Returns all woningen for admins/monteurs (they can visit all)
    $token=$_GET['user_token']??'';
    if(!$token) respond(['woningen'=>[]]);
    $st=db()->prepare("SELECT id,name,email,roles FROM users WHERE magic_token=? AND active=1");
    $st->execute([$token]); $u=$st->fetch();
    if(!$u) respond(['woningen'=>[]]);
    if(hasRole($u,'monteur')||hasRole($u,'admin')) {
        $all=db()->query("SELECT id,name,address,pool_type,volume_liters,pool_code,qr_token FROM woningen WHERE active=1 ORDER BY name")->fetchAll();
        respond(['woningen'=>$all]);
    }
    respond(['woningen'=>[]]);
}

function getPrevVisit():void {
    $wid=(int)($_GET['woning_id']??0);
    if(!$wid) respond(['visit'=>null]);
    $st=db()->prepare("SELECT v.*,u.name AS user_name FROM visits v LEFT JOIN users u ON u.id=v.user_id WHERE v.woning_id=? ORDER BY v.visit_date DESC,v.visit_time DESC LIMIT 1");
    $st->execute([$wid]);
    respond(['visit'=>$st->fetch()?:null]);
}

function woningHistory():void {
    $token=$_GET['token']??'';
    $userToken=$_GET['user_token']??'';
    if(!$token) respond(['error'=>'Token vereist'],400);

    $st=db()->prepare("SELECT * FROM woningen WHERE history_token=? AND active=1");
    $st->execute([$token]); $w=$st->fetch();
    if(!$w) respond(['error'=>'Niet gevonden'],404);

    // Check if user has access
    $canView=true; // history token = public read access by design

    $st2=db()->prepare("SELECT v.*,u.name AS user_name FROM visits v LEFT JOIN users u ON u.id=v.user_id WHERE v.woning_id=? ORDER BY v.visit_date DESC,v.visit_time DESC LIMIT 50");
    $st2->execute([$w['id']]);
    $visits=$st2->fetchAll();
    foreach($visits as &$v) $v['advice_json']=json_decode($v['advice_json']??'{}',true);
    unset($w['qr_token']); // don't expose QR token in history
    respond(['woning'=>$w,'visits'=>$visits]);
}

// ============================================================
// SAVE VISIT
// ============================================================
function saveVisit():void {
    $d=input();
    foreach(['woning_id','ph','chlorine','alkalinity'] as $f) {
        if(!isset($d[$f])||$d[$f]==='') respond(['error'=>"Veld ontbreekt: $f"],400);
    }
    $st=db()->prepare("SELECT * FROM woningen WHERE id=? AND active=1");
    $st->execute([$d['woning_id']]); $w=$st->fetch();
    if(!$w) respond(['error'=>'Woning niet gevonden'],404);

    $user=null;
    if(!empty($d['user_id'])) {
        $st2=db()->prepare("SELECT * FROM users WHERE id=?"); $st2->execute([$d['user_id']]); $user=$st2->fetch();
    }

    $advice=buildAdvice((float)$d['ph'],(float)$d['chlorine'],(int)$d['alkalinity'],
        isset($d['stabilizer'])?(float)$d['stabilizer']:null,(int)($d['volume_used']??$w['volume_liters']));

    $st4=db()->prepare("INSERT INTO visits (woning_id,user_id,visit_date,visit_time,ph,chlorine,alkalinity,stabilizer,volume_used,notes,advice_json,strip_photo,confirm_photo_chemicals,confirm_photo_pool) VALUES (?,?,CURDATE(),CURTIME(),?,?,?,?,?,?,?,?,?,?)");
    $st4->execute([$d['woning_id'],$user['id']??null,$d['ph'],$d['chlorine'],$d['alkalinity'],$d['stabilizer']??null,
        $d['volume_used']??$w['volume_liters'],$d['notes']??'',json_encode($advice,JSON_UNESCAPED_UNICODE),
        $d['strip_photo']??'',$d['confirm_photo_chemicals']??'',$d['confirm_photo_pool']??'']);
    $vid=(int)db()->lastInsertId();

    $ml=sendMails($w,$user,$d,$advice,$vid);
    if($ml['any_sent']) db()->prepare("UPDATE visits SET email_sent=1 WHERE id=?")->execute([$vid]);
    respond(['success'=>true,'visit_id'=>$vid,'mail_results'=>$ml]);
}

function uploadPhoto():void {
    if(empty($_FILES['photo'])) respond(['error'=>'Geen bestand'],400);
    $f=$_FILES['photo'];
    if($f['error']!==UPLOAD_ERR_OK) respond(['error'=>'Upload fout '.$f['error']],400);
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    if(!in_array($ext,['jpg','jpeg','png','webp','gif','heic'])) respond(['error'=>'Ongeldig type'],400);
    if($f['size']>15*1024*1024) respond(['error'=>'Max 15MB'],400);
    if(!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR,0755,true);
    $fn=date('Ymd_His_').uniqid().'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],UPLOAD_DIR.$fn)) respond(['error'=>'Opslaan mislukt'],500);
    respond(['url'=>UPLOAD_URL.$fn]);
}

function aiStrip():void {
    if(!CLAUDE_API_KEY) respond(['error'=>'AI key niet geconfigureerd. Voeg CLAUDE_API_KEY toe in api.php.'],503);
    $d=input(); $b64=$d['image']??''; $mt=$d['type']??'image/jpeg';
    if(!$b64) respond(['error'=>'Geen afbeelding'],400);
    $prompt=<<<'PROMPT'
You are analyzing a pool water test strip photo. The image contains TWO distinct objects — identify them carefully before reading any values.

OBJECT 1 — REFERENCE CHART (do NOT read test values from this):
This is a printed color grid on the Aquacheck bottle or container. It shows multiple columns of small colored squares with numbers printed next to them (like 6.2, 6.8, 7.2, 7.8, 8.4 for pH). This is the comparison scale only.

OBJECT 2 — THE TEST STRIP (read values ONLY from this):
This is a separate, long narrow white plastic strip (approximately 5cm long, 0.8cm wide). It has exactly 4 small individual colored reaction pads embedded in it. These 4 pads show the actual pool water chemistry.

STEP 1 — FIND THE TEST STRIP:
Look for the thin elongated white plastic stick lying separately from the bottle. It is NOT the bottle and NOT the color grid on the bottle.

STEP 2 — DETERMINE ORIENTATION using the white extension rule:
The Stabilizer pad (pad 4) ALWAYS has a longer white plastic section extending beyond it (the handle end of the strip). Identify which end has this white extension:
- White extension at BOTTOM → top-to-bottom order: pH(1st), Chlorine(2nd), Alkalinity(3rd), Stabilizer(4th)
- White extension at TOP → reversed order: Stabilizer(1st), Alkalinity(2nd), Chlorine(3rd), pH(4th)

STEP 3 — READ EACH PAD by comparing its color to the matching column on the reference chart:
- pH pad: orange/tan spectrum. Reference: 6.2=bright orange, 7.2=medium orange-tan, 7.8=lighter orange, 8.4=dark reddish. NORMAL: medium orange = 7.2–7.6
- Free Chlorine pad: white-to-purple spectrum. Reference: 0=colorless/white, 0.5=faint pink, 1=light pink, 3=medium pink, 5=pink-purple, 10=dark purple. NORMAL: light pink = 1–3 ppm. If nearly colorless = 0 ppm (DANGER)
- Total Alkalinity pad: yellow-green-to-dark-green spectrum. Reference: 0=pale yellow, 40=light green, 80=medium green, 120=dark green, 180=very dark green. NORMAL: 80–120 = medium green
- Stabilizer pad: white-to-dark-purple spectrum. Reference: 0=white, 30=faint mauve, 50=light mauve, 100=medium purple, 150=dark purple, 300=very dark. NORMAL: 30–50 = faint mauve. Dark maroon/purple = 100+ ppm (TOO HIGH)

STEP 4 — Output ONLY this JSON, no other text:
{"ph": 7.4, "cl": 0.5, "alk": 120, "stab": 100, "pad_colors": {"ph": "#E8952A", "cl": "#F8F0F0", "alk": "#5A7A42", "stab": "#8B1A4A"}, "reasoning": {"ph": "medium orange matches ~7.4", "cl": "nearly colorless = ~0 ppm", "alk": "dark green = ~120", "stab": "dark maroon = ~100-150, white extension confirms this is stabilizer pad"}}

For pad_colors: report the actual dominant hex color you observe on each test pad on the strip (NOT from the reference chart). This is what you literally see on the plastic strip after it was dipped in water.
PROMPT;
    $payload=json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>400,'messages'=>[['role'=>'user','content'=>[['type'=>'image','source'=>['type'=>'base64','media_type'=>$mt,'data'=>$b64]],['type'=>'text','text'=>$prompt]]]]]);
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nx-api-key: ".CLAUDE_API_KEY."\r\nanthropic-version: 2023-06-01\r\n",'content'=>$payload,'timeout'=>30]]);
    $raw=@file_get_contents('https://api.anthropic.com/v1/messages',false,$ctx);
    if(!$raw) respond(['error'=>'API aanroep mislukt - controleer CLAUDE_API_KEY'],500);
    $resp=json_decode($raw,true);
    if(isset($resp['error'])) respond(['error'=>'Claude: '.$resp['error']['message']],500);
    $text=$resp['content'][0]['text']??'';
    // Extract JSON even if wrapped in markdown
    preg_match('/\{.*\}/s',$text,$m);
    if(!$m) respond(['error'=>'AI kon strip niet herkennen. Zorg dat strip en kleurenkaart beide scherp in beeld zijn.','raw'=>substr($text,0,200)],422);
    $v=json_decode($m[0],true);
    if(!$v) respond(['error'=>'Ongeldige AI response'],422);
    respond([
        'ph'        =>min(9, max(6,  round((float)($v['ph']  ??7.4),1))),
        'cl'        =>min(10,max(0,  round((float)($v['cl']  ??1.5),1))),
        'alk'       =>min(300,max(0, (int)($v['alk'] ??100))),
        'stab'      =>min(200,max(0, (int)($v['stab']??40))),
        'pad_colors'=>$v['pad_colors']??null,
        'reasoning' =>$v['reasoning']??null,
    ]);
}

// ============================================================
// DOSERING — wekelijks schema, muriatic acid 31.45%, veilige maxima
// ============================================================
function buildAdvice(float $ph,float $cl,int $alk,?float $stab,int $vol):array {
    $items=[]; $m=$vol/1000;

    // ---- pH ----
    // Muriatic acid 31.45%: ~190ml per 10m³ per 0.1 eenheid verlaging
    // Natriumcarbonaat (pH-plus): ~18g per 10m³ per 0.1 eenheid verhoging
    // Wekelijks schema: max veilige correctie per sessie = 0.4 eenheden
    // Maar: als diff <= 0.6, voeg alles toe in 2 doses (4h tussentijd)
    // Als diff > 0.6: vandaag 0.4, volgende week rest
    if($ph<7.2) {
        $diff=round(7.4-$ph,2); $target=7.4;
        $todayDiff=min($diff,0.4);
        $remaining=round($diff-$todayDiff,2);
        $gToday=round($todayDiff*$m*180);
        $gTotal=round($diff*$m*180);
        $split=$diff>0.2;
        $items[]=['level'=>'bad','param'=>'pH','value'=>$ph,'unit'=>'',
            'title'=>'pH te laag',
            'finding'=>"pH $ph is onder de norm (7.2–7.6). Corrosief water, chloor minder effectief.",
            'dose_today'=>"{$gToday}g natriumcarbonaat (pH-plus)".($split?" — voeg de helft toe, wacht 4u, voeg rest toe":''),
            'dose_next'=>$remaining>0?"{$remaining} eenheden te corrigeren volgende week (~".round($remaining*$m*180)."g)":null,
            'limit'=>"Max 0.4 pH-eenheden per dag corrigeren. Wacht minimaal 4 uur tussen doses.",
            'anomaly'=>null];
    } elseif($ph>7.6) {
        $diff=round($ph-7.4,2);
        $todayDiff=min($diff,0.4);
        $remaining=round($diff-$todayDiff,2);
        $mlToday=round($todayDiff*$m*190);
        $mlTotal=round($diff*$m*190);
        $split=$diff>0.2;
        $items[]=['level'=>$ph>7.9?'bad':'warn','param'=>'pH','value'=>$ph,'unit'=>'',
            'title'=>'pH te hoog',
            'finding'=>"pH $ph is boven de norm (7.2–7.6). Chloor 70% minder effectief bij pH>7.8.",
            'dose_today'=>"{$mlToday}ml muriatic acid 31,45%".($split?" — voeg de helft toe, wacht 4u, voeg rest toe":''),
            'dose_next'=>$remaining>0?"{$remaining} eenheid te corrigeren volgende week (~".round($remaining*$m*190)."ml)":null,
            'limit'=>"Nooit meer dan {$mlToday}ml per dag. Pomp laten draaien. 4u wachten tussen doses.",
            'anomaly'=>null];
    } else {
        $items[]=['level'=>'ok','param'=>'pH','value'=>$ph,'unit'=>'','title'=>'pH perfect','finding'=>"pH $ph — in de norm.","dose_today"=>null,'dose_next'=>null,'limit'=>null,'anomaly'=>null];
    }

    // ---- Chloor ----
    // Max shockbehandeling: 5 ppm per keer (veilig, geen irritatie)
    if($cl<0.5) {
        $target=1.5; $diff=$target-$cl;
        $add=min($diff,2.0); // max 2ppm toe per dosis
        $g=round($add*$m*2.5);
        $items[]=['level'=>'bad','param'=>'Chloor','value'=>$cl,'unit'=>' ppm',
            'title'=>'Chloor te laag — gebruik verboden',
            'finding'=>"$cl ppm is onveilig laag. Badwater mag pas gebruikt worden boven 1 ppm.",
            'dose_today'=>"{$g}g natriumhypochloriet 10%",
            'dose_next'=>null,'limit'=>"Na 2u opnieuw meten. Herhaal indien nog steeds laag.",'anomaly'=>null];
    } elseif($cl>3.0) {
        $over=$cl>5?round(($cl-3)*$m*0.7).'g natriumbisulfiet':'Wacht 24–48u — laat vanzelf dalen';
        $items[]=['level'=>'warn','param'=>'Chloor','value'=>$cl,'unit'=>' ppm',
            'title'=>'Chloor te hoog',
            'finding'=>"$cl ppm — te hoog voor gebruik. Irritatie aan ogen/huid.",
            'dose_today'=>$over,'dose_next'=>null,
            'limit'=>"Niet verder toevoegen. Eerst controleren of waarde gedaald is.",'anomaly'=>null];
    } else {
        $items[]=['level'=>'ok','param'=>'Chloor','value'=>$cl,'unit'=>' ppm','title'=>'Chloor goed','finding'=>"$cl ppm — prima.","dose_today"=>null,'dose_next'=>null,'limit'=>null,'anomaly'=>null];
    }

    // ---- Alkaliniteit ----
    // Muriatic acid voor verlaging, natriumbicarbonaat voor verhoging
    // Max 25 ppm per sessie om yo-yo te vermijden
    if($alk<80) {
        $diff=100-$alk; $todayAdd=min($diff,25); $remaining=$diff-$todayAdd;
        $g=round($todayAdd*$m*1.4);
        $items[]=['level'=>'bad','param'=>'Alkaliniteit','value'=>$alk,'unit'=>' ppm',
            'title'=>'Alkaliniteit te laag',
            'finding'=>"$alk ppm — pH schommelt instabiel. Norm: 80–120 ppm.",
            'dose_today'=>"{$g}g natriumbicarbonaat",
            'dose_next'=>$remaining>0?"{$remaining} ppm nog nodig (~".round($remaining*$m*1.4)."g volgende week)":null,
            'limit'=>"Max 25 ppm per sessie verhogen. Verdeel over meerdere weken.",'anomaly'=>null];
    } elseif($alk>120) {
        $diff=$alk-100; $todayLower=min($diff,25); $remaining=$diff-$todayLower;
        $ml=round($todayLower*$m*0.8);
        $items[]=['level'=>'warn','param'=>'Alkaliniteit','value'=>$alk,'unit'=>' ppm',
            'title'=>'Alkaliniteit te hoog',
            'finding'=>"$alk ppm — pH-correcties slaan minder goed aan.",
            'dose_today'=>"{$ml}ml muriatic acid 31,45%",
            'dose_next'=>$remaining>0?"{$remaining} ppm nog te verlagen volgende week (~".round($remaining*$m*0.8)."ml)":null,
            'limit'=>"Max 25 ppm per sessie verlagen. Geleidelijk toevoegen.",'anomaly'=>null];
    } else {
        $items[]=['level'=>'ok','param'=>'Alkaliniteit','value'=>$alk,'unit'=>' ppm','title'=>'Alkaliniteit stabiel','finding'=>"$alk ppm — goed.","dose_today"=>null,'dose_next'=>null,'limit'=>null,'anomaly'=>null];
    }

    // ---- Stabilizer ----
    if($stab!==null) {
        if($stab<30) {
            $g=round((50-$stab)*$m);
            $items[]=['level'=>'warn','param'=>'Stabilizer','value'=>$stab,'unit'=>' ppm','title'=>'Stabilizer laag','finding'=>"$stab ppm — chloor verdampt snel in felle zon. Norm: 30–50 ppm.",'dose_today'=>"{$g}g cyanurinezuur",'dose_next'=>null,'limit'=>"Oplossen in emmer warm water voor toevoegen.",'anomaly'=>null];
        } elseif($stab>80) {
            $items[]=['level'=>'warn','param'=>'Stabilizer','value'=>$stab,'unit'=>' ppm','title'=>'Stabilizer te hoog','finding'=>"$stab ppm — chloor werkt minder. Deels water verversen om te verdunnen.",'dose_today'=>null,'dose_next'=>null,'limit'=>"10–20% water vervangen.",'anomaly'=>null];
        } else {
            $items[]=['level'=>'ok','param'=>'Stabilizer','value'=>$stab,'unit'=>' ppm','title'=>'Stabilizer OK','finding'=>"$stab ppm — goed.","dose_today"=>null,'dose_next'=>null,'limit'=>null,'anomaly'=>null];
        }
    }

    return ['items'=>$items];
}

// ============================================================
// EMAIL
// ============================================================
function sendMails(array $w,?array $u,array $d,array $advice,int $vid):array {
    $date=date('d-m-Y');
    $histLink=BASE_URL.'/history.html?h='.$w['history_token'];
    $rows='';
    foreach($advice['items'] as $i) {
        $bg=$i['level']==='ok'?'#eaf3de':($i['level']==='warn'?'#faeeda':'#fcebeb');
        $col=$i['level']==='ok'?'#2d6a4f':($i['level']==='warn'?'#7a4f00':'#8b1c1c');
        $dose=$i['dose_today']?"<br><strong>Vandaag: {$i['dose_today']}</strong>":'';;
        $next=$i['dose_next']?"<br><em>Volgende week: {$i['dose_next']}</em>":'';
        $rows.="<div style='background:{$bg};border-left:4px solid {$col};padding:10px 14px;margin-bottom:8px;border-radius:4px'><strong style='color:{$col}'>{$i['title']}</strong><br><span style='font-size:13px;color:#555'>{$i['finding']}{$dose}{$next}</span></div>";
    }
    $clientHtml="<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;max-width:580px;margin:0 auto'>
    <div style='background:#0F6E56;padding:22px;text-align:center;border-radius:10px 10px 0 0'><h1 style='color:#fff;margin:0;font-size:20px'>PoolCheck Pro</h1><p style='color:#9FE1CB;margin:4px 0 0;font-size:13px'>{$w['name']} &mdash; {$date}</p></div>
    <div style='background:#f9f9f9;padding:22px;border:1px solid #e0e0e0'>
    <p>Geachte {$w['owner_name']},</p>
    <table style='width:100%;font-size:14px;margin:12px 0 16px'><tr><td style='padding:5px 0'><b>pH</b></td><td>{$d['ph']}</td><td style='color:#888;font-size:12px'>7.2–7.6</td></tr><tr style='background:#f0f0f0'><td style='padding:5px 4px'><b>Chloor</b></td><td>{$d['chlorine']} ppm</td><td style='color:#888;font-size:12px'>1–3 ppm</td></tr><tr><td style='padding:5px 0'><b>Alkaliniteit</b></td><td>{$d['alkalinity']} ppm</td><td style='color:#888;font-size:12px'>80–120 ppm</td></tr></table>
    {$rows}
    <p style='margin-top:16px;font-size:13px'>Volledige geschiedenis: <a href='{$histLink}' style='color:#0F6E56'>{$histLink}</a></p>
    </div><div style='background:#222;padding:12px;text-align:center;border-radius:0 0 10px 10px'><p style='color:#888;margin:0;font-size:11px'>PoolCheck Pro &mdash; Villa Park Fontein</p></div></body></html>";

    $techHtml="<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;max-width:540px;margin:0 auto'>
    <div style='background:#1a1a2e;padding:16px;border-radius:10px 10px 0 0'><h2 style='color:#fff;margin:0'>Bezoek afgerond #{$vid}</h2><p style='color:#aaa;margin:4px 0 0;font-size:12px'>{$w['name']} &mdash; {$date} &mdash; ".($u['name']??'Onbekend')."</p></div>
    <div style='background:#f5f5f3;padding:18px;border:1px solid #ddd'><p>pH: <b>{$d['ph']}</b> | Cl: <b>{$d['chlorine']} ppm</b> | Alk: <b>{$d['alkalinity']} ppm</b></p>{$rows}
    ".(!empty($d['notes'])?"<p style='margin-top:10px'><b>Notities:</b> {$d['notes']}</p>":"")."</div></body></html>";

    $cs=smtpSend($w['email'],$w['owner_name'],"Zwembadrapport — {$w['name']} — $date",$clientHtml);
    $ts=$u?smtpSend($u['email'],$u['name'],"Bezoek afgerond — {$w['name']} — $date",$techHtml):false;
    $ms=smtpSend(MANAGER_EMAIL,'Manager',"Rapport #{$vid} — {$w['name']} — $date",$techHtml);
    return['client'=>$cs,'tech'=>$ts,'manager'=>$ms,'any_sent'=>$cs||$ts||$ms];
}

// ============================================================
// ADMIN
// ============================================================
function adminLogin():void { if((input()['password']??'')==ADMIN_PASS){$_SESSION['admin']=true;respond(['success'=>true]);}respond(['error'=>'Verkeerd wachtwoord'],401); }
function adminLogout():void { session_destroy();respond(['success'=>true]); }
function adminCheck():void  { respond(['logged_in'=>!empty($_SESSION['admin'])]); }

function adminVisits():void {
    reqAdmin();
    $wid=(int)($_GET['woning_id']??0);
    $sql="SELECT v.*,w.name AS woning_name,u.name AS user_name FROM visits v JOIN woningen w ON w.id=v.woning_id LEFT JOIN users u ON u.id=v.user_id";
    if($wid){$st=db()->prepare($sql." WHERE v.woning_id=? ORDER BY v.visit_date DESC LIMIT 100");$st->execute([$wid]);}
    else{$st=db()->query($sql." ORDER BY v.visit_date DESC LIMIT 200");}
    $vs=$st->fetchAll();
    foreach($vs as &$v) $v['advice_json']=json_decode($v['advice_json']??'{}',true);
    respond(['visits'=>$vs]);
}

function adminUsers():void {
    reqAdmin();
    $rows=db()->query("SELECT id,name,email,phone,roles,magic_token,active FROM users ORDER BY name")->fetchAll();
    foreach($rows as &$r){
        $r['magic_link']=BASE_URL.'/?m='.$r['magic_token'];
        // Get linked woningen for klant users
        $st=db()->prepare("SELECT w.id,w.name FROM woningen w JOIN user_woningen uw ON uw.woning_id=w.id WHERE uw.user_id=?");
        $st->execute([$r['id']]); $r['woningen']=$st->fetchAll();
    }
    respond(['users'=>$rows]);
}

function adminWoningen():void {
    reqAdmin();
    $rows=db()->query("SELECT * FROM woningen ORDER BY name")->fetchAll();
    foreach($rows as &$w){
        $w['qr_url']=BASE_URL.'/?k='.$w['qr_token'];
        $w['history_url']=BASE_URL.'/history.html?h='.$w['history_token'];
    }
    respond(['woningen'=>$rows]);
}

function addWoning():void {
    reqAdmin(); $d=input();
    if(empty($d['name'])) respond(['error'=>'Naam vereist'],400);
    $qr=bin2hex(random_bytes(8)); $hist=bin2hex(random_bytes(8));
    $code=strtoupper(substr(bin2hex(random_bytes(3)),0,6));
    $st=db()->prepare("INSERT INTO woningen (name,owner_name,email,phone,address,pool_type,volume_liters,notes,qr_token,history_token,pool_code) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$d['name'],$d['owner_name']??'',$d['email']??'',$d['phone']??'',$d['address']??'',$d['pool_type']??'Privé buitenbad',(int)($d['volume_liters']??40000),$d['notes']??'',$qr,$hist,$code]);
    $id=(int)db()->lastInsertId();
    respond(['success'=>true,'id'=>$id,'qr_url'=>BASE_URL.'/?k='.$qr,'history_url'=>BASE_URL.'/history.html?h='.$hist,'pool_code'=>$code]);
}

function updateWoning():void {
    reqAdmin(); $d=input();
    if(empty($d['id'])) respond(['error'=>'ID vereist'],400);
    // Note: volume_liters only editable by admin (not monteur)
    $st=db()->prepare("UPDATE woningen SET name=?,owner_name=?,email=?,phone=?,address=?,pool_type=?,volume_liters=?,notes=?,active=? WHERE id=?");
    $st->execute([$d['name']??'',$d['owner_name']??'',$d['email']??'',$d['phone']??'',$d['address']??'',$d['pool_type']??'Privé buitenbad',(int)($d['volume_liters']??40000),$d['notes']??'',isset($d['active'])?(int)$d['active']:1,(int)$d['id']]);
    respond(['success'=>true]);
}

function addUser():void {
    reqAdmin(); $d=input();
    if(empty($d['name'])||empty($d['email'])) respond(['error'=>'Naam en email vereist'],400);
    $token=bin2hex(random_bytes(8));
    $roles=$d['roles']??'monteur';
    db()->prepare("INSERT INTO users (name,email,phone,magic_token,roles) VALUES (?,?,?,?,?)")->execute([$d['name'],$d['email'],$d['phone']??'',$token,$roles]);
    $id=(int)db()->lastInsertId();
    // If klant role: link to specified woningen
    if(!empty($d['woning_ids']) && strpos($roles,'klant')!==false) {
        foreach((array)$d['woning_ids'] as $wid){
            try{db()->prepare("INSERT IGNORE INTO user_woningen (user_id,woning_id) VALUES (?,?)")->execute([$id,(int)$wid]);}catch(Exception $e){}
        }
    }
    respond(['success'=>true,'id'=>$id,'magic_link'=>BASE_URL.'/?m='.$token]);
}

function updateUser():void {
    reqAdmin(); $d=input();
    if(empty($d['id'])) respond(['error'=>'ID vereist'],400);
    $st=db()->prepare("UPDATE users SET name=?,email=?,phone=?,roles=?,active=? WHERE id=?");
    $st->execute([$d['name']??'',$d['email']??'',$d['phone']??'',$d['roles']??'monteur',isset($d['active'])?(int)$d['active']:1,(int)$d['id']]);
    respond(['success'=>true]);
}

function linkUserWoning():void {
    reqAdmin(); $d=input();
    if(empty($d['user_id'])||empty($d['woning_id'])) respond(['error'=>'IDs vereist'],400);
    db()->prepare("INSERT IGNORE INTO user_woningen (user_id,woning_id) VALUES (?,?)")->execute([$d['user_id'],$d['woning_id']]);
    respond(['success'=>true]);
}

// ============================================================
// APP STATUS (versie check voor admin)
// ============================================================
function appStatus():void {
    requireAdmin();
    $dbVersion = null; $lastDeploy = null;
    try {
        $st=db()->query("SELECT key_name,value FROM app_meta WHERE key_name IN ('db_version','last_deploy')");
        foreach($st->fetchAll() as $r) {
            if($r['key_name']==='db_version') $dbVersion=$r['value'];
            if($r['key_name']==='last_deploy') $lastDeploy=$r['value'];
        }
    } catch(Throwable $e) {}
    respond([
        'file_version' => APP_VERSION,
        'db_version'   => $dbVersion,
        'last_deploy'  => $lastDeploy,
        'match'        => $dbVersion === APP_VERSION,
    ]);
}

// ============================================================
// SAVE AI CORRECTION (training data)
// ============================================================
function saveCorrection():void {
    $d=input();
    try {
        db()->prepare("
            CREATE TABLE IF NOT EXISTS ai_corrections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                visit_id INT, photo_url VARCHAR(255),
                ai_ph DECIMAL(4,2), ai_cl DECIMAL(5,2), ai_alk INT, ai_stab DECIMAL(5,1),
                human_ph DECIMAL(4,2), human_cl DECIMAL(5,2), human_alk INT, human_stab DECIMAL(5,1),
                ai_reasoning TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )")->execute([]);
        $st=db()->prepare("INSERT INTO ai_corrections (visit_id,photo_url,ai_ph,ai_cl,ai_alk,ai_stab,human_ph,human_cl,human_alk,human_stab,ai_reasoning) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $d['visit_id']??null, $d['photo_url']??'',
            $d['ai_ph']??null,$d['ai_cl']??null,$d['ai_alk']??null,$d['ai_stab']??null,
            $d['human_ph']??null,$d['human_cl']??null,$d['human_alk']??null,$d['human_stab']??null,
            json_encode($d['reasoning']??null),
        ]);
        respond(['success'=>true]);
    } catch(Throwable $e) { respond(['error'=>$e->getMessage()],500); }
}

// ============================================================
// SMTP
// ============================================================
function smtpRead($s):string{$o='';while($l=fgets($s,515)){$o.=$l;if(strlen($l)>=4&&$l[3]===' ')break;}return $o;}
function smtpCmd($s,string $c):string{fputs($s,$c."\r\n");return smtpRead($s);}
function smtpSend(string $to,string $toN,string $sub,string $html):bool{
    try{
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]]);
        $s=stream_socket_client('ssl://'.MAIL_HOST.':'.MAIL_PORT,$en,$es,15,STREAM_CLIENT_CONNECT,$ctx);
        if(!$s) throw new Exception("Verbinding mislukt: $es");
        smtpRead($s); smtpCmd($s,'EHLO '.gethostname()); smtpCmd($s,'AUTH LOGIN');
        smtpCmd($s,base64_encode(MAIL_USER));
        $r=smtpCmd($s,base64_encode(MAIL_PASS));
        if(strpos($r,'235')===false) throw new Exception("Auth mislukt: $r");
        smtpCmd($s,'MAIL FROM:<'.MAIL_FROM.'>'); smtpCmd($s,"RCPT TO:<$to>"); smtpCmd($s,'DATA');
        $n=str_replace(['"',"\r","\n"],'',$toN);
        $msg="From: ".MAIL_NAME." <".MAIL_FROM.">\r\nTo: \"$n\" <$to>\r\nSubject: =?UTF-8?B?".base64_encode($sub)."?=\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html))."\r\n.\r\n";
        fputs($s,$msg); $sr=smtpRead($s); smtpCmd($s,'QUIT'); fclose($s);
        return strpos($sr,'250')!==false;
    }catch(Throwable $e){error_log("SMTP $to: ".$e->getMessage());return false;}
}
