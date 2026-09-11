<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/functions.php';
require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

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

// Protezione SSRF
if (!str_starts_with($input['api_url'], 'https://www.eventbriteapi.com/')) {
    write_log('api_url non autorizzata: ' . $input['api_url']);
    http_response_code(400);
    exit;
}

$action = $input['config']['action'] ?? 'order.placed';

// 2. Recupero ordine da Eventbrite
$ch = curl_init($input['api_url'] . '?expand=attendees');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token']],
]);
$order     = json_decode(curl_exec($ch), true);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

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

// 3. Carica processed_orders.json (compatibile con vecchio formato int e nuovo formato array)
$processed_file = __DIR__ . '/processed_orders.json';
$processed      = [];

if (file_exists($processed_file)) {
    $processed = json_decode(file_get_contents($processed_file), true) ?: [];
    $cutoff    = time() - 30 * 86400;
    $processed = array_filter($processed, function ($v) use ($cutoff) {
        return (is_array($v) ? ($v['ts'] ?? 0) : (int)$v) > $cutoff;
    });
}

// ── RIMBORSO ─────────────────────────────────────────────────────────────────
if ($action === 'order.refunded') {
    $entry    = $processed[$order_id] ?? null;
    $disc_ids = is_array($entry) ? ($entry['discount_ids'] ?? []) : [];

    if (empty($disc_ids)) {
        write_log("Rimborso ordine $order_id: nessun codice sconto tracciato, niente da eliminare.");
        exit;
    }

    foreach ($disc_ids as $disc_id) {
        $ch = curl_init("https://www.eventbriteapi.com/v3/discounts/{$disc_id}/");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token']],
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        $del_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($del_status === 200 || $del_status === 204) {
            write_log("Sconto $disc_id eliminato per rimborso ordine $order_id.");
        } else {
            write_log("ERRORE eliminazione sconto $disc_id per ordine $order_id. HTTP: $del_status");
        }
    }
    exit;
}

// ── ACQUISTO ─────────────────────────────────────────────────────────────────
if ($action !== 'order.placed') exit; // ignora altri tipi di evento

// Verifica e marca come "in elaborazione" in un'unica sezione atomica
// (protetta da flock) per evitare che due consegne concorrenti dello
// stesso webhook (Eventbrite può inviare retry) superino entrambe il
// controllo di idempotenza e creino sconti/email duplicati.
$already_processed = false;
$cutoff = time() - 30 * 86400;
atomic_json_update($processed_file, function (array $data) use ($order_id, $cutoff, &$already_processed) {
    $data = array_filter($data, function ($v) use ($cutoff) {
        return (is_array($v) ? ($v['ts'] ?? 0) : (int)$v) > $cutoff;
    });
    if (isset($data[$order_id])) {
        $already_processed = true;
        return $data;
    }
    $data[$order_id] = ['ts' => time(), 'discount_ids' => []];
    return $data;
});

if ($already_processed) {
    write_log("Ordine $order_id già processato. Skip.");
    exit;
}

if (!isset($order['attendees'])) {
    write_log("Ordine $order_id senza attendees. HTTP: $http_code");
    exit;
}

$regole            = load_regole();
$business_name     = $conf['business_name'] ?: 'La nostra Azienda';
$eventi_acquistati = array_unique(array_column($order['attendees'], 'event_id'));
$regali_finali     = [];
$created_disc_ids  = [];

// 4. Creazione sconti
foreach ($eventi_acquistati as $e_id) {
    if (!isset($regole[$e_id])) continue;
    $r = $regole[$e_id];

    foreach ($r['target_ids'] as $t_id) {
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
            $label = ($r['importo_fisso'] ?? '?') . ' ' . ($conf['currency'] ?: 'EUR');
        } else {
            $discount['percent_off'] = $r['percentuale'];
            $label = $r['percentuale'] . '%';
        }

        $giorni = (int)($r['giorni_scadenza'] ?? 0);
        if ($giorni > 0) {
            $discount['end_date'] = date('Y-m-d\TH:i:s\Z', time() + $giorni * 86400);
        }

        $ch = curl_init("https://www.eventbriteapi.com/v3/organizations/{$conf['org_id']}/discounts/");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token'], 'Content-Type: application/json'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['discount' => $discount]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res    = json_decode(curl_exec($ch), true);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200 || $status === 201) {
            $disc_id = (string)($res['discount']['id'] ?? $res['id'] ?? '');
            if ($disc_id) $created_disc_ids[] = $disc_id;

            $regali_finali[] = [
                'desc'  => $r['descrizione'],
                'code'  => $promo_code,
                'url'   => 'https://www.eventbrite.it/e/' . $t_id,
                'label' => $label,
            ];
            write_log("Sconto creato: $promo_code per ordine $order_id");
        } else {
            write_log("ERRORE creazione sconto $promo_code. HTTP: $status. Risposta: " . json_encode($res));
        }
    }
}

// Aggiorna i discount ID tracciati
atomic_json_update($processed_file, function (array $data) use ($order_id, $created_disc_ids) {
    if (isset($data[$order_id]) && is_array($data[$order_id])) {
        $data[$order_id]['discount_ids'] = $created_disc_ids;
    }
    return $data;
});

// 5. Invio email
if (empty($regali_finali)) exit;

$recipient = filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$recipient) {
    write_log("Email non valida per ordine $order_id: " . ($order['email'] ?? 'N/A'));
    exit;
}

$email_color    = $conf['email_color']    ?: '#D64545';
$email_subject  = str_replace('{{business_name}}', $business_name, $conf['email_subject'] ?: "I tuoi regali da $business_name");
$email_intro    = $conf['email_intro']    ?: 'Grazie per i tuoi acquisti. Ecco i regali che abbiamo riservato per te:';
$nome_cliente   = $order['first_name'] ?? 'Cliente';
$greeting_tpl   = $conf['email_greeting'] ?: 'Ciao {{nome}}!';
$greeting_html  = str_replace('{{nome}}', h($nome_cliente), h($greeting_tpl));

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
    $mail->Subject = $email_subject;

    $logo_path = __DIR__ . '/logo.png';
    if (file_exists($logo_path)) {
        $mail->addEmbeddedImage($logo_path, 'logo_cid');
        $logo_html = '<img src="cid:logo_cid" style="max-width:150px;margin-bottom:20px;">';
    } else {
        $logo_html = '<h1 style="color:#2d3142;">' . h($business_name) . '</h1>';
    }

    $items_html = '';
    foreach ($regali_finali as $reg) {
        $items_html .= '
        <div style="background:#f3f3f3;border:1px solid #ddd;padding:15px;margin-bottom:15px;border-radius:8px;text-align:center;">
            <p style="color:#666;font-size:13px;margin:0;">Per l\'evento: <b>' . h($reg['desc']) . '</b></p>
            <p style="color:' . h($email_color) . ';font-size:24px;font-weight:bold;margin:10px 0;">' . h($reg['code']) . '</p>
            <a href="' . h($reg['url']) . '" style="display:inline-block;background:' . h($email_color) . ';color:white;padding:10px 20px;text-decoration:none;border-radius:5px;">Usa Sconto ' . h($reg['label']) . '</a>
        </div>';
    }

    $mail->Body = '
    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #eee;">
        <div style="text-align:center;">' . $logo_html . '</div>
        <h2 style="color:#2d3142;text-align:center;">' . $greeting_html . '</h2>
        <p style="text-align:center;color:#4f5d75;">' . h($email_intro) . '</p>
        ' . $items_html . '
        <p style="font-size:11px;color:#aaa;text-align:center;margin-top:30px;">&copy; ' . date('Y') . ' ' . h($business_name) . '</p>
    </div>';

    $plain_greeting = str_replace('{{nome}}', $nome_cliente, $greeting_tpl);
    $mail->AltBody  = "$plain_greeting $email_intro Apri questa email in un client HTML per visualizzare i tuoi codici sconto.";
    $mail->send();
    write_log("Email inviata a $recipient per ordine $order_id");
} catch (Exception $e) {
    write_log('ERRORE SMTP: ' . $mail->ErrorInfo);
}
