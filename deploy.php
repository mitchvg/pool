<?php
/**
 * deploy.php — wordt automatisch aangeroepen door Plesk na elke git pull
 * Alleen uitvoerbaar via command line (niet via browser)
 */

// Blokkeer browser toegang
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Alleen via command line.');
}

echo "=== PoolCheck Deploy ===\n";
echo date('d-m-Y H:i:s') . "\n\n";

// DB verbinding — zelfde credentials als api.php
$pdo = new PDO(
    'mysql:host=localhost;dbname=pool;charset=utf8mb4',
    'pool', '5S!5VcwPbc%v7ofw',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Schema.sql uitvoeren
$schemaFile = __DIR__ . '/schema.sql';
if (!file_exists($schemaFile)) {
    echo "SKIP: schema.sql niet gevonden\n";
    exit(0);
}

$sql = file_get_contents($schemaFile);
$statements = preg_split('/;\s*[\r\n]+/', $sql, -1, PREG_SPLIT_NO_EMPTY);
$ok = 0; $skip = 0;

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if (!$stmt || str_starts_with(ltrim($stmt), '--')) continue;
    try {
        $pdo->exec($stmt);
        $ok++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Negeer "already exists" en "Duplicate entry" — die zijn normaal
        if (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate') !== false) {
            $skip++;
        } else {
            echo "SQL waarschuwing: $msg\n";
        }
    }
}

echo "Schema: $ok statements uitgevoerd, $skip overgeslagen (al aanwezig)\n";
echo "Deploy klaar!\n";
