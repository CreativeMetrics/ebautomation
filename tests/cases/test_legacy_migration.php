<?php
// NB: qui NON richiediamo functions.php subito, perché la migrazione va
// verificata a partire da file JSON legacy scritti su disco PRIMA che
// db() venga chiamato per la prima volta (che è quando scatta la
// migrazione one-shot).

file_put_contents(__DIR__ . '/config.json', json_encode([
    'business_name' => 'Vecchia Azienda', 'api_token' => 'PLAINTEXT-TOKEN', 'org_id' => 'ORG1',
    'smtp_host' => 'smtp.test.it', 'smtp_user' => 'a@b.it', 'smtp_pass' => 'smtppw',
    'smtp_port' => '465', 'smtp_encryption' => 'smtps', 'currency' => 'EUR',
    'webhook_token' => 'WHTOK123', 'paused' => false,
    'email_subject' => 'Ciao', 'email_intro' => 'Intro', 'email_greeting' => 'Ciao {{nome}}!', 'email_color' => '#D64545',
]));
file_put_contents(__DIR__ . '/regole_sconti.json', json_encode([
    'EVT-TRIGGER' => ['descrizione' => 'Promo Legacy', 'tipo_sconto' => 'percentuale', 'percentuale' => '100.00', 'codice_prefix' => 'GIFT', 'target_ids' => ['EVT-TARGET'], 'quantita' => 1, 'giorni_scadenza' => 0],
]));
// Formato vecchio (flat discount_ids) E formato intermedio (con status/discounts)
file_put_contents(__DIR__ . '/processed_orders.json', json_encode([
    '111' => ['ts' => 1778412889, 'discount_ids' => ['DISC-OLD-1']],
    '222' => ['ts' => 1778412999, 'status' => 'complete', 'discounts' => ['T1' => 'DISC-NEW-1'], 'email_sent_targets' => ['T1']],
]));
file_put_contents(__DIR__ . '/users.json', json_encode([
    'admin' => ['password_hash' => password_hash('vecchia-password-legacy-123', PASSWORD_DEFAULT), 'created_at' => 1700000000],
]));

require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

$conf = load_config();
check($conf['business_name'] === 'Vecchia Azienda', 'config: business_name migrato', $failures);
check($conf['api_token'] === 'PLAINTEXT-TOKEN', 'config: api_token migrato e leggibile', $failures);
check($conf['webhook_token'] === 'WHTOK123', 'config: webhook_token migrato', $failures);

$regole = load_regole();
check(isset($regole['EVT-TRIGGER']) && $regole['EVT-TRIGGER']['descrizione'] === 'Promo Legacy', 'regole: migrate correttamente', $failures);

$users = load_users();
check(isset($users['admin']), 'utenti: admin migrato', $failures);
check(password_verify('vecchia-password-legacy-123', $users['admin']['password_hash']), 'utenti: hash password preservato e verificabile', $failures);
check(($users['admin']['role'] ?? '') === 'admin', 'utenti: ruolo di default admin applicato dopo la migrazione', $failures);

$proc = list_recent_processed_orders(10);
check(isset($proc['111']), 'ordini: formato vecchissimo (discount_ids piatto) migrato', $failures);
check(isset($proc['222']) && $proc['222']['status'] === 'complete', 'ordini: formato intermedio migrato preservando lo stato', $failures);

foreach (['config.json', 'regole_sconti.json', 'processed_orders.json', 'users.json'] as $f) {
    check(!file_exists(__DIR__ . "/$f"), "$f non esiste più (rinominato dopo la migrazione)", $failures);
    check(file_exists(__DIR__ . "/$f.migrated"), "$f.migrated presente come riferimento", $failures);
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
