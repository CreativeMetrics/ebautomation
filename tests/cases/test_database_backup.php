<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

$conf = load_config();
$conf['business_name'] = 'Test Backup';
save_config($conf);

maybe_backup_database();
$backups = glob(__DIR__ . '/backups/database_*.sqlite') ?: [];
check(count($backups) === 1, 'primo backup del database creato', $failures);

if (!empty($backups)) {
    $pdo_backup = new PDO('sqlite:' . $backups[0]);
    $bn = $pdo_backup->query("SELECT value FROM config WHERE key='business_name'")->fetchColumn();
    check($bn === 'Test Backup', 'il backup contiene una copia consistente e leggibile dei dati', $failures);
}

// Una seconda chiamata entro 24h non deve creare un secondo file
maybe_backup_database();
$backups2 = glob(__DIR__ . '/backups/database_*.sqlite') ?: [];
check(count($backups2) === 1, 'nessun backup duplicato entro le 24h', $failures);

// Se l'ultimo backup è "vecchio" (mtime forzato indietro), ne viene creato uno nuovo
if (!empty($backups)) {
    touch($backups[0], time() - 90000); // > 24h fa
    clearstatcache(true, $backups[0]); // PHP mette in cache filemtime(): va invalidata dopo touch()
    maybe_backup_database();
    $backups3 = glob(__DIR__ . '/backups/database_*.sqlite') ?: [];
    check(count($backups3) === 2, 'un nuovo backup viene creato dopo 24h dall\'ultimo', $failures);
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
