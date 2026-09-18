<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/functions.php';
require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

// Ambiente non pronto (estensione mancante, permessi): nessuna pagina HTML da
// mostrare qui, è un endpoint macchina-a-macchina. Rispondiamo 500 così
// Eventbrite lo tratta come errore transitorio e ritenta più tardi.
if ($issue = environment_issue()) {
    error_log('ebautomation eventbrite-webhook.php: ambiente non pronto: ' . $issue);
    http_response_code(500);
    exit;
}

$conf = load_config();

// 0. Modalità pausa
if ($conf['paused']) {
    write_log('Webhook ricevuto ma automazioni in pausa. Skip.');
    exit;
}

// 1. Verifica token webhook (confronto a tempo costante)
if (!empty($conf['webhook_token']) && !hash_equals($conf['webhook_token'], (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['api_url'])) {
    http_response_code(400);
    exit;
}

// Protezione SSRF: accetta solo l'esatto endpoint "ordine" di Eventbrite
// (non un generico prefisso di dominio), così il webhook non può essere
// usato come oracolo per interrogare, col nostro token, qualunque altro
// endpoint dell'API Eventbrite.
if (!preg_match('#^https://www\.eventbriteapi\.com/v3/orders/\d+/$#', $input['api_url'])) {
    write_log('api_url non autorizzata: ' . $input['api_url']);
    http_response_code(400);
    exit;
}

$action = $input['config']['action'] ?? 'order.placed';

// 2. Recupero ordine da Eventbrite (con retry sui soli errori transitori)
$order_res = api_call_with_retry($input['api_url'] . '?expand=attendees', [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token']],
]);
$order     = $order_res['body'];
$http_code = $order_res['status'];

if (!isset($order['id'])) {
    write_log('Ordine non recuperato. HTTP: ' . $http_code);
    http_response_code(200);
    exit;
}

$order_id = (string)$order['id'];
if ($order_id === '') {
    write_log('Order ID mancante nella risposta API.');
    exit;
}

// ── RIMBORSO ─────────────────────────────────────────────────────────────────
if ($action === 'order.refunded') {
    $entry    = get_processed_order($order_id);
    $disc_ids = $entry ? array_values($entry['discounts']) : [];

    if (empty($disc_ids)) {
        write_log("Rimborso ordine $order_id: nessun codice sconto tracciato, niente da eliminare.");
        exit;
    }

    foreach ($disc_ids as $disc_id) {
        $res = api_call_with_retry("https://www.eventbriteapi.com/v3/discounts/{$disc_id}/", [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token']],
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ], 2);

        if ($res['status'] === 200 || $res['status'] === 204) {
            write_log("Sconto $disc_id eliminato per rimborso ordine $order_id.");
        } else {
            write_log("ERRORE eliminazione sconto $disc_id per ordine $order_id. HTTP: {$res['status']}");
        }
    }
    exit;
}

// ── ACQUISTO / MODIFICA ORDINE ────────────────────────────────────────────────
// order.updated arriva quando un ordine già esistente cambia (es. quantità di
// biglietti aumentata, partecipanti aggiunti): lo trattiamo con la stessa
// pipeline di order.placed, ma "riaprendo" anche un ordine già marcato
// completo per rivalutarlo alla luce dei dati aggiornati — es. una quantità
// che ora supera una soglia qty_minima prima non raggiunta. Non revoca mai
// sconti già creati (vedi commento su claim_processed_order): la pipeline
// crea solo i target ancora mancanti rispetto allo stato attuale dell'ordine.
if (!in_array($action, ['order.placed', 'order.updated'], true)) exit; // ignora altri tipi di evento

// Verifica e (ri)apre una sezione di lavoro atomica per l'ordine (transazione
// SQLite BEGIN IMMEDIATE, vedi claim_processed_order). Un ordine già COMPLETO
// (tutti gli sconti creati ed email inviata con successo) viene saltato — è
// la vera idempotenza — a meno che non sia un order.updated (vedi sopra). Un
// ordine ancora "partial" (fallito parzialmente in un tentativo precedente, o
// in corso da un'altra consegna concorrente dello stesso webhook) viene
// ripreso da dove era rimasto: questo stesso meccanismo è anche ciò che
// permette alla dashboard di "ritentare" un ordine fallito semplicemente
// re-inviando lo stesso payload al webhook.
$claim = claim_processed_order($order_id, $action === 'order.updated');
if ($claim['already_complete']) {
    write_log("Ordine $order_id già completato. Skip.");
    exit;
}
$existing_discounts = $claim['discounts'];
$existing_emailed   = $claim['email_sent_targets'];

if (!isset($order['attendees'])) {
    write_log("Ordine $order_id senza attendees. HTTP: $http_code");
    exit;
}

$regole         = load_regole();
$business_name  = $conf['business_name'] ?: 'La nostra Azienda';

// Biglietti acquistati per evento (per la condizione "quantità minima")
$qty_per_evento = [];
foreach ($order['attendees'] as $att) {
    $eid = $att['event_id'] ?? null;
    if ($eid) $qty_per_evento[$eid] = ($qty_per_evento[$eid] ?? 0) + 1;
}
$eventi_acquistati = array_keys($qty_per_evento);

$discounts        = $existing_discounts; // target_id => discount_id (riparte da eventuali successi precedenti)
$attempted_targets = [];                 // target_id di tutte le regole che dovrebbero attivarsi su questo ordine

// 4. Creazione sconti (i target già presenti in $discounts non vengono ricreati)
foreach ($eventi_acquistati as $e_id) {
    if (!isset($regole[$e_id])) continue;
    $r = $regole[$e_id];

    if (($r['attiva'] ?? true) === false) continue; // regola disattivata dalla dashboard

    $qty_minima = max(1, (int)($r['qty_minima'] ?? 1));
    if (($qty_per_evento[$e_id] ?? 0) < $qty_minima) {
        write_log("Regola per evento $e_id non attivata per ordine $order_id: acquistati {$qty_per_evento[$e_id]} biglietti, ne servono almeno $qty_minima.");
        continue;
    }

    foreach ($r['target_ids'] as $t_id) {
        $attempted_targets[$t_id] = true;
        if (isset($discounts[$t_id])) continue; // già creato in un tentativo precedente

        $promo_code = ($r['codice_prefix'] ?? 'GIFT') . '-' . strtoupper(substr(md5($order_id . $t_id), 0, 8));

        $discount = [
            'type'               => 'coded',
            'code'               => $promo_code,
            'event_id'           => $t_id,
            'quantity_available' => max(1, (int)($r['quantita'] ?? 1)),
        ];

        if (($r['tipo_sconto'] ?? 'percentuale') === 'importo') {
            $discount['amount_off'] = [
                'currency' => $conf['currency'] ?: 'EUR',
                'value'    => (int)round((float)($r['importo_fisso'] ?? 0) * 100),
            ];
        } else {
            $discount['percent_off'] = $r['percentuale'];
        }

        $giorni = (int)($r['giorni_scadenza'] ?? 0);
        if ($giorni > 0) {
            $discount['end_date'] = date('Y-m-d\TH:i:s\Z', time() + $giorni * 86400);
        }

        // Multi-organizzazione: l'endpoint discounts è scoped per org, quindi
        // risolviamo dinamicamente l'org proprietaria dell'evento target
        // invece di assumere un org_id fisso in configurazione. Questo
        // permette a un unico token di gestire regole su più organizzazioni.
        $target_org_id = resolve_event_org_id($t_id, $conf['api_token']) ?: $conf['org_id'];
        if (!$target_org_id) {
            write_log("ERRORE: impossibile determinare l'organizzazione dell'evento target $t_id (ordine $order_id). Sconto non creato.");
            continue;
        }

        $res = api_call_with_retry("https://www.eventbriteapi.com/v3/organizations/{$target_org_id}/discounts/", [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token'], 'Content-Type: application/json'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['discount' => $discount]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        if ($res['status'] === 200 || $res['status'] === 201) {
            $disc_id = (string)($res['body']['discount']['id'] ?? $res['body']['id'] ?? '');
            if ($disc_id) {
                $discounts[$t_id] = $disc_id;
                write_log("Sconto creato: $promo_code per ordine $order_id (org $target_org_id, tentativi: {$res['attempts']})");
            }
        } else {
            write_log("ERRORE creazione sconto $promo_code per ordine $order_id. HTTP: {$res['status']} dopo {$res['attempts']} tentativi. Risposta: " . $res['raw']);
        }
    }
}

$targets_missing    = array_diff(array_keys($attempted_targets), array_keys($discounts));
$discounts_complete = empty($targets_missing);

// Ricostruisce i dati necessari all'email per TUTTI gli sconti noti finora
// (non solo quelli creati in questo passaggio), così un retry che recupera
// uno sconto mancante può reinviare un'email completa. Determina anche la
// lingua/template da usare: quella della prima regola incontrata che ha
// contribuito allo sconto (se un ordine matcha regole con lingue diverse,
// l'intera email consolidata usa questa — non la spezziamo in più email per
// non vanificare la logica "un cliente riceve un'unica email con tutto").
$regali_finali = [];
$chosen_lingua = null;
foreach ($regole as $e_id => $r) {
    foreach (($r['target_ids'] ?? []) as $t_id) {
        if (!isset($discounts[$t_id]) || isset($regali_finali[$t_id])) continue;
        if ($chosen_lingua === null) $chosen_lingua = ($r['lingua'] ?? '') ?: null;
        $is_imp = ($r['tipo_sconto'] ?? 'percentuale') === 'importo';
        $regali_finali[$t_id] = [
            'desc'  => $r['descrizione'] ?? '',
            'code'  => ($r['codice_prefix'] ?? 'GIFT') . '-' . strtoupper(substr(md5($order_id . $t_id), 0, 8)),
            'url'   => 'https://www.eventbrite.it/e/' . $t_id,
            'label' => $is_imp ? ($r['importo_fisso'] ?? '?') . ' ' . ($conf['currency'] ?: 'EUR') : $r['percentuale'] . '%',
        ];
    }
}
$regali_finali = array_values($regali_finali);

// 5. Invio email — solo se c'è qualcosa di nuovo da comunicare rispetto
// all'ultimo invio riuscito (evita di reinviare la stessa email identica
// ad ogni retry quando non c'è nulla di cambiato).
$targets_to_email = array_diff(array_keys($discounts), $existing_emailed);
$email_sent_targets = $existing_emailed;
$email_ok = empty($targets_to_email) && !empty($existing_emailed); // niente da inviare = ok

if (!empty($regali_finali) && !empty($targets_to_email)) {
    $recipient = filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$recipient) {
        write_log("Email non valida per ordine $order_id: " . ($order['email'] ?? 'N/A'));
    } else {
        $nome_cliente = $order['first_name'] ?? 'Cliente';
        $template     = get_email_template($chosen_lingua);
        $logo_path    = __DIR__ . '/logo.png';
        $has_logo     = file_exists($logo_path);

        if (!$template) {
            write_log("ERRORE: nessun template email configurato, impossibile inviare email per ordine $order_id.");
        } else {
            $rendered = render_email_template($template, $business_name, $nome_cliente, $regali_finali, $has_logo);

            // Fino a 3 tentativi di invio SMTP: ricostruiamo il messaggio ad ogni
            // tentativo perché PHPMailer non garantisce di essere riutilizzabile
            // dopo un errore di connessione.
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = $conf['smtp_host'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $conf['smtp_user'];
                    $mail->Password   = $conf['smtp_pass'];
                    $mail->SMTPSecure = ($conf['smtp_encryption'] === 'tls') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = (int)$conf['smtp_port'];
                    $mail->CharSet    = 'UTF-8';
                    $mail->setFrom($conf['smtp_user'], $business_name);
                    $mail->addAddress($recipient);
                    $mail->isHTML(true);
                    $mail->Subject = $rendered['subject'];
                    if ($has_logo) $mail->addEmbeddedImage(get_logo_path_for_email($logo_path, $template['logo_width'] ?? 150), 'logo_cid');
                    $mail->Body    = $rendered['html'];
                    $mail->AltBody = $rendered['text'];
                    $mail->send();
                    write_log("Email inviata a $recipient per ordine $order_id (template: {$template['nome']}, tentativo $attempt)");
                    $email_ok = true;
                    $email_sent_targets = array_keys($discounts);
                    break;
                } catch (Exception $e) {
                    write_log("ERRORE SMTP (tentativo $attempt) per ordine $order_id: " . $mail->ErrorInfo);
                    if ($attempt < 3) usleep([300000, 900000][$attempt - 1]);
                }
            }
        }
    }
}

// 6. Persisti lo stato finale dell'ordine
$is_complete = $discounts_complete && $email_ok;
finalize_processed_order($order_id, $discounts, $email_sent_targets, $is_complete);

if ($is_complete) {
    unqueue_failed_order($order_id);
} else {
    $reason = !$discounts_complete
        ? 'Sconto non creato per: ' . implode(', ', $targets_missing)
        : 'Email non ancora inviata con successo';
    queue_failed_order($order_id, $input['api_url'], $reason);
    write_log("Ordine $order_id non completato: $reason. Verrà ritentato (dashboard → Log → Riprova ordini falliti).");
}

maybe_send_error_alert($conf);
maybe_backup_database(); // no-op se già fatto nelle ultime 24h
