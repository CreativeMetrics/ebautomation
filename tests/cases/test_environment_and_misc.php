<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

// Ambiente sano (l'installazione di test ha pdo_sqlite/sodium e cartella scrivibile)
check(environment_issue() === null, 'environment_issue() non rileva falsi positivi in un ambiente sano', $failures);

// Rate limiting per IP
$key = 'test:1.2.3.4';
check(throttle_allowed($key, 3, 900) === true, 'nessun hit ancora: consentito', $failures);
throttle_hit($key, 900);
throttle_hit($key, 900);
throttle_hit($key, 900);
check(throttle_allowed($key, 3, 900) === false, 'dopo 3 hit su soglia 3: bloccato', $failures);
throttle_reset($key);
check(throttle_allowed($key, 3, 900) === true, 'throttle_reset sblocca correttamente', $failures);

// Stato alert: round trip
check(get_alert_state() === ['last_ts' => 0, 'last_count' => 0], 'stato alert di default vuoto', $failures);
set_alert_state(12345, 7);
check(get_alert_state() === ['last_ts' => 12345, 'last_count' => 7], 'stato alert persistito correttamente', $failures);

// maybe_send_error_alert: senza smtp_host configurato non deve fare nulla
// (non deve lanciare eccezioni né richiedere una connessione di rete)
$conf = load_config(); // smtp_host vuoto di default
$threw = false;
try {
    maybe_send_error_alert($conf);
} catch (Throwable $e) {
    $threw = true;
}
check($threw === false, 'maybe_send_error_alert senza SMTP configurato non lancia eccezioni', $failures);

// add_column_if_missing è idempotente (richiamare ensure_schema due volte non deve fallire)
$threw2 = false;
try {
    ensure_schema(db());
    ensure_schema(db());
} catch (Throwable $e) {
    $threw2 = true;
}
check($threw2 === false, 'ensure_schema è idempotente e richiamabile più volte senza errori', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
