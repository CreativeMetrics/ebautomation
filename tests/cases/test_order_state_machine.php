<?php
require __DIR__ . '/functions.php';

$failures = [];
function check(bool $cond, string $msg, array &$failures): void {
    if (!$cond) $failures[] = "✗ $msg";
}

$order_id = 'ORD-1';

// Passaggio 1: 2 target attesi, solo 1 riesce -> stato partial
$claim1 = claim_processed_order($order_id);
check($claim1['already_complete'] === false, 'primo claim su ordine nuovo: already_complete=false', $failures);
check($claim1['discounts'] === [], 'primo claim: nessuno sconto pregresso', $failures);

$discounts = $claim1['discounts'];
$discounts['target-A'] = 'disc-A';
finalize_processed_order($order_id, $discounts, ['target-A'], false);
$o = get_processed_order($order_id);
check($o['status'] === 'partial', 'dopo un completamento parziale lo stato è "partial"', $failures);

// Passaggio 2 (retry): riparte da target-A, non lo ricrea, non lo re-invia via email
$claim2 = claim_processed_order($order_id);
check($claim2['already_complete'] === false, 'retry su ordine partial: already_complete=false', $failures);
check($claim2['discounts'] === ['target-A' => 'disc-A'], 'retry riparte dallo sconto già ottenuto', $failures);
check($claim2['email_sent_targets'] === ['target-A'], 'retry sa che target-A è già stato comunicato via email', $failures);

$discounts2 = $claim2['discounts'];
$discounts2['target-B'] = 'disc-B';
$targets_to_email = array_diff(array_keys($discounts2), $claim2['email_sent_targets']);
check($targets_to_email === ['target-B' => 'target-B'] || array_values($targets_to_email) === ['target-B'], 'solo target-B va comunicato via email al retry (no duplicati)', $failures);
finalize_processed_order($order_id, $discounts2, array_keys($discounts2), true);

$o2 = get_processed_order($order_id);
check($o2['status'] === 'complete', 'dopo il completamento lo stato è "complete"', $failures);
check($o2['discounts']['target-A'] === 'disc-A' && $o2['discounts']['target-B'] === 'disc-B', 'entrambi gli sconti sono tracciati correttamente', $failures);

// Passaggio 3: un webhook duplicato (order.placed) su un ordine completo va saltato
$claim3 = claim_processed_order($order_id);
check($claim3['already_complete'] === true, 'un duplicato order.placed su ordine completo viene saltato', $failures);

// Passaggio 4: order.updated (allow_reopen) riapre l'ordine completo senza perdere nulla
$claim4 = claim_processed_order($order_id, true);
check($claim4['already_complete'] === false, 'order.updated riapre un ordine completo per rivalutazione', $failures);
check($claim4['discounts']['target-A'] === 'disc-A' && $claim4['discounts']['target-B'] === 'disc-B', 'order.updated non perde gli sconti già emessi', $failures);

$discounts4 = $claim4['discounts'];
$discounts4['target-C'] = 'disc-C'; // un nuovo target ora qualifica (es. quantità aumentata)
finalize_processed_order($order_id, $discounts4, array_keys($discounts4), true);
$o4 = get_processed_order($order_id);
check(count($o4['discounts']) === 3, 'order.updated può aggiungere un nuovo sconto senza toccare gli altri', $failures);
check($o4['status'] === 'complete', 'l\'ordine torna "complete" dopo la rivalutazione', $failures);

// Coda ordini falliti: popolata su fallimento parziale, svuotata al completamento
$order_id2 = 'ORD-2';
claim_processed_order($order_id2);
finalize_processed_order($order_id2, ['target-X' => 'disc-X'], [], false);
queue_failed_order($order_id2, 'https://www.eventbriteapi.com/v3/orders/2/', 'email non inviata');
check(isset(load_failed_orders()[$order_id2]), 'un ordine parziale finisce nella coda dei falliti', $failures);
unqueue_failed_order($order_id2);
check(!isset(load_failed_orders()[$order_id2]), 'unqueue_failed_order rimuove correttamente dalla coda', $failures);

// Claim ripetuti senza finalize in mezzo non devono corrompere lo stato
// (simula due consegne quasi simultanee dello stesso webhook prima che la
// prima abbia fatto in tempo a chiamare finalize_processed_order).
$order_id3 = 'ORD-3';
$c1 = claim_processed_order($order_id3);
$c2 = claim_processed_order($order_id3);
check($c1['already_complete'] === false && $c2['already_complete'] === false, 'due claim ravvicinati sullo stesso ordine nuovo procedono entrambi (dedup reale è a valle, su discount code deterministico)', $failures);
$o3 = get_processed_order($order_id3);
check($o3['status'] === 'partial', 'lo stato resta "partial" finché nessuno chiama finalize', $failures);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
exit(0);
