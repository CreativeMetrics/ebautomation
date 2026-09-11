<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

// Round-trip cifratura
$plain = 'super-secret-api-token-12345';
$enc   = encrypt_secret($plain);
check(str_starts_with($enc, 'enc:v1:'), 'il valore cifrato ha il prefisso enc:v1:', $failures);
check(decrypt_secret($enc) === $plain, 'decrypt_secret(encrypt_secret(x)) === x', $failures);

// Retrocompatibilità: un valore in chiaro (config legacy) passa invariato
check(decrypt_secret('valore-in-chiaro') === 'valore-in-chiaro', 'decrypt_secret passa invariato un valore non cifrato', $failures);

// Stringa vuota
check(encrypt_secret('') === '', 'encrypt_secret("") === ""', $failures);
check(decrypt_secret('') === '', 'decrypt_secret("") === ""', $failures);

// Chiave rigenerata identica tra chiamate (persistita su disco)
$k1 = get_secret_key();
$k2 = get_secret_key();
check($k1 === $k2, 'la chiave di cifratura è stabile tra chiamate', $failures);
check(strlen($k1) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'la chiave ha la lunghezza attesa', $failures);

// save_config/load_config: round trip completo, incluso il bool "paused"
$conf = load_config();
check($conf['paused'] === false, 'paused di default è false (bool)', $failures);
check($conf['business_name'] === '', 'business_name di default è vuoto', $failures);

// Un primo save_config popola la tabella (make_config_backup su tabella
// ancora vuota non crea nulla: non c'è uno stato precedente da salvare).
save_config($conf);

$conf['business_name'] = 'Azienda Test';
$conf['paused']        = true;
$conf['api_token']     = 'TOKEN-ABC-123';
$conf['smtp_pass']     = 'PASSWORD-SMTP-XYZ';
save_config($conf);

$reloaded = load_config();
check($reloaded['business_name'] === 'Azienda Test', 'business_name persistito correttamente', $failures);
check($reloaded['paused'] === true, 'paused persistito come bool true', $failures);
check($reloaded['api_token'] === 'TOKEN-ABC-123', 'api_token decifrato correttamente al rilancio', $failures);
check($reloaded['smtp_pass'] === 'PASSWORD-SMTP-XYZ', 'smtp_pass decifrato correttamente al rilancio', $failures);

// Il valore su disco non deve MAI contenere il token in chiaro
$raw_on_disk = db()->query("SELECT value FROM config WHERE key='api_token'")->fetchColumn();
check(!str_contains($raw_on_disk, 'TOKEN-ABC-123'), 'api_token su disco non è in chiaro', $failures);
check(str_starts_with($raw_on_disk, 'enc:v1:'), 'api_token su disco è nel formato cifrato atteso', $failures);

// Backup di config creato prima della sovrascrittura
$backups = glob(__DIR__ . '/backups/config_*.json') ?: [];
check(count($backups) >= 1, 'make_config_backup ha creato almeno un backup', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
