<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

check(load_users() === [], 'nessun utente su installazione fresca', $failures);

add_user_row('admin', password_hash('AdminPassword123', PASSWORD_DEFAULT), 'admin');
add_user_row('osservatore', password_hash('ViewerPassword123', PASSWORD_DEFAULT), 'viewer');
$users = load_users();
check(count($users) === 2, 'due utenti creati', $failures);
check(($users['admin']['role'] ?? '') === 'admin', 'ruolo admin salvato correttamente', $failures);
check(($users['osservatore']['role'] ?? '') === 'viewer', 'ruolo viewer salvato correttamente', $failures);
check(password_verify('AdminPassword123', $users['admin']['password_hash']), 'password admin verificabile', $failures);

// set_user_password: preserva created_at su utente esistente, riporta true
$created_before = $users['admin']['created_at'];
sleep(1);
$was_existing = set_user_password('admin', password_hash('NuovaPasswordAdmin1', PASSWORD_DEFAULT));
check($was_existing === true, 'set_user_password su utente esistente ritorna true', $failures);
$users2 = load_users();
check($users2['admin']['created_at'] === $created_before, 'set_user_password preserva created_at', $failures);
check(password_verify('NuovaPasswordAdmin1', $users2['admin']['password_hash']), 'la nuova password è verificabile', $failures);

// set_user_password su utente inesistente lo crea, ritorna false
$was_existing2 = set_user_password('nuovo_utente', password_hash('AltraPassword123', PASSWORD_DEFAULT));
check($was_existing2 === false, 'set_user_password su utente nuovo ritorna false', $failures);
check(isset(load_users()['nuovo_utente']), 'set_user_password ha creato il nuovo utente', $failures);

// Ruolo di default per un utente creato senza specificarlo
add_user_row('senza_ruolo', password_hash('PasswordQualsiasi1', PASSWORD_DEFAULT));
check((load_users()['senza_ruolo']['role'] ?? '') === 'admin', 'ruolo di default è "admin" se non specificato', $failures);

// Ruolo non valido ricade su admin (fail-safe)
add_user_row('ruolo_strano', password_hash('PasswordQualsiasi2', PASSWORD_DEFAULT), 'superuser');
check((load_users()['ruolo_strano']['role'] ?? '') === 'admin', 'un valore di ruolo non riconosciuto ricade su "admin"', $failures);

delete_user_row('nuovo_utente');
check(!isset(load_users()['nuovo_utente']), 'delete_user_row elimina correttamente', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
