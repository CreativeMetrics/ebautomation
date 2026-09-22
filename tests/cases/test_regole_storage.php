<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

check(load_regole() === [], 'nessuna regola su installazione fresca', $failures);

save_regola_rule('R1', 'EVT1', [
    'descrizione' => 'Promo Uno', 'tipo_sconto' => 'percentuale', 'percentuale' => '30.00',
    'importo_fisso' => 0, 'codice_prefix' => 'GIFT', 'target_ids' => ['T1', 'T2'],
    'quantita' => 2, 'giorni_scadenza' => 10, 'qty_minima' => 1, 'attiva' => true,
]);
save_regola_rule('R2', 'EVT2', [
    'descrizione' => 'Promo Due', 'tipo_sconto' => 'importo', 'percentuale' => '100.00',
    'importo_fisso' => 5.5, 'codice_prefix' => 'SPECIAL', 'target_ids' => ['T3'],
    'quantita' => 1, 'giorni_scadenza' => 0, 'qty_minima' => 3, 'attiva' => true,
]);
$regole = load_regole();
check(count($regole) === 2, 'due regole salvate', $failures);
check($regole['R1']['target_ids'] === ['T1', 'T2'], 'target_ids multipli preservati nell\'ordine corretto', $failures);
check($regole['R2']['importo_fisso'] === 5.5, 'importo_fisso preservato come float', $failures);
check($regole['R1']['trigger_id'] === 'EVT1' && $regole['R2']['trigger_id'] === 'EVT2', 'trigger_id riportato in ogni regola caricata', $failures);

// Più regole sullo stesso evento trigger, con target/sconto indipendenti
save_regola_rule('R3', 'EVT1', [
    'descrizione' => 'Promo Tre (stesso trigger di R1)', 'tipo_sconto' => 'percentuale', 'percentuale' => '10.00',
    'importo_fisso' => 0.01, 'codice_prefix' => 'ALT', 'target_ids' => ['T4'],
    'quantita' => 1, 'giorni_scadenza' => 0, 'qty_minima' => 1, 'attiva' => true,
]);
$regole = load_regole();
check(count($regole) === 3, 'una seconda regola sullo stesso trigger_id si aggiunge, non sovrascrive', $failures);
check($regole['R1']['trigger_id'] === 'EVT1' && $regole['R3']['trigger_id'] === 'EVT1', 'due regole indipendenti condividono lo stesso trigger_id', $failures);
check($regole['R1']['target_ids'] === ['T1', 'T2'] && $regole['R3']['target_ids'] === ['T4'], 'target_ids restano indipendenti tra le due regole', $failures);

// Toggle attiva (per id di regola, non più per trigger_id)
$regole['R1']['attiva'] = false;
save_regola_rule('R1', $regole['R1']['trigger_id'], $regole['R1']);
check(load_regole()['R1']['attiva'] === false, 'toggle attiva=false persistito', $failures);
check(load_regole()['R3']['attiva'] === true, 'toggle su R1 non ha toccato l\'altra regola con lo stesso trigger_id', $failures);

// Delete (per id di regola)
delete_regola_rule('R2');
$after_delete = load_regole();
check(count($after_delete) === 2 && !isset($after_delete['R2']), 'delete_regola_rule rimuove solo la regola indicata', $failures);

// replace_all_regole: sostituzione totale (usata da import JSON/CSV)
replace_all_regole([
    'NEW1' => ['trigger_id' => 'EVTX', 'descrizione' => 'Nuova', 'target_ids' => ['X'], 'codice_prefix' => 'NEW', 'tipo_sconto' => 'percentuale', 'percentuale' => '50.00', 'importo_fisso' => 0, 'quantita' => 1, 'giorni_scadenza' => 0, 'qty_minima' => 1, 'attiva' => true],
    // 'trigger_id' assente: compatibilità con un vecchio export dove la
    // chiave dell'array era il trigger_id stesso.
    'EVTY' => ['descrizione' => 'Vecchio formato', 'target_ids' => ['Y'], 'codice_prefix' => 'OLD', 'tipo_sconto' => 'percentuale', 'percentuale' => '20.00', 'importo_fisso' => 0, 'quantita' => 1, 'giorni_scadenza' => 0, 'qty_minima' => 1, 'attiva' => true],
]);
$after_replace = load_regole();
check(count($after_replace) === 2 && isset($after_replace['NEW1']) && !isset($after_replace['R1']), 'replace_all_regole sostituisce completamente il set di regole', $failures);
check($after_replace['NEW1']['trigger_id'] === 'EVTX', 'replace_all_regole rispetta trigger_id esplicito', $failures);
check($after_replace['EVTY']['trigger_id'] === 'EVTY', 'replace_all_regole usa la chiave come trigger_id quando assente (retro-compat vecchio export)', $failures);

// Backup creato ad ogni scrittura
$backups = glob(__DIR__ . '/backups/regole_*.json') ?: [];
check(count($backups) >= 1, 'make_regole_backup ha creato almeno un backup', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
