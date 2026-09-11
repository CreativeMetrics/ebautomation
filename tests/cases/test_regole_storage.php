<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

check(load_regole() === [], 'nessuna regola su installazione fresca', $failures);

save_regola_rule('EVT1', [
    'descrizione' => 'Promo Uno', 'tipo_sconto' => 'percentuale', 'percentuale' => '30.00',
    'importo_fisso' => 0, 'codice_prefix' => 'GIFT', 'target_ids' => ['T1', 'T2'],
    'quantita' => 2, 'giorni_scadenza' => 10, 'qty_minima' => 1, 'attiva' => true,
]);
save_regola_rule('EVT2', [
    'descrizione' => 'Promo Due', 'tipo_sconto' => 'importo', 'percentuale' => '100.00',
    'importo_fisso' => 5.5, 'codice_prefix' => 'SPECIAL', 'target_ids' => ['T3'],
    'quantita' => 1, 'giorni_scadenza' => 0, 'qty_minima' => 3, 'attiva' => true,
]);
$regole = load_regole();
check(count($regole) === 2, 'due regole salvate', $failures);
check($regole['EVT1']['target_ids'] === ['T1', 'T2'], 'target_ids multipli preservati nell\'ordine corretto', $failures);
check($regole['EVT2']['importo_fisso'] === 5.5, 'importo_fisso preservato come float', $failures);

// Toggle attiva
$regole['EVT1']['attiva'] = false;
save_regola_rule('EVT1', $regole['EVT1']);
check(load_regole()['EVT1']['attiva'] === false, 'toggle attiva=false persistito', $failures);

// Delete
delete_regola_rule('EVT2');
check(count(load_regole()) === 1 && !isset(load_regole()['EVT2']), 'delete_regola_rule rimuove solo la regola indicata', $failures);

// replace_all_regole: sostituzione totale (usata da import JSON/CSV)
replace_all_regole([
    'NEW1' => ['descrizione' => 'Nuova', 'target_ids' => ['X'], 'codice_prefix' => 'NEW', 'tipo_sconto' => 'percentuale', 'percentuale' => '50.00', 'importo_fisso' => 0, 'quantita' => 1, 'giorni_scadenza' => 0, 'qty_minima' => 1, 'attiva' => true],
]);
$after_replace = load_regole();
check(count($after_replace) === 1 && isset($after_replace['NEW1']) && !isset($after_replace['EVT1']), 'replace_all_regole sostituisce completamente il set di regole', $failures);

// Backup creato ad ogni scrittura
$backups = glob(__DIR__ . '/backups/regole_*.json') ?: [];
check(count($backups) >= 1, 'make_regole_backup ha creato almeno un backup', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
