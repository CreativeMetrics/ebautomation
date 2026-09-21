<?php
require __DIR__ . '/functions.php';

// Ambiente non pronto (estensione mancante, permessi): nessuna pagina HTML da
// mostrare qui, è un endpoint macchina-a-macchina. Rispondiamo 500 così
// Eventbrite lo tratta come errore transitorio e ritenta più tardi.
if ($issue = environment_issue()) {
    error_log('ebautomation eventbrite-webhook.php: ambiente non pronto: ' . $issue);
    http_response_code(500);
    exit;
}

$conf = load_config();

// Verifica token webhook (confronto a tempo costante). Logga anche il
// rifiuto (senza esporre il token) perché altrimenti una richiesta
// bloccata qui non lascerebbe alcuna traccia nel log applicativo,
// rendendo impossibile distinguere "la richiesta non è mai arrivata"
// da "è arrivata ma è stata respinta silenziosamente".
if (!empty($conf['webhook_token']) && !hash_equals($conf['webhook_token'], (string)($_GET['token'] ?? ''))) {
    write_log('Richiesta webhook rifiutata: token mancante o non valido (IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ').');
    http_response_code(403);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['api_url'])) {
    write_log('Richiesta webhook con payload non valido o api_url mancante.');
    http_response_code(400);
    exit;
}

$action = $input['config']['action'] ?? 'order.placed';

// Tutta la logica di elaborazione (validazione api_url, recupero ordine,
// creazione sconti, invio email, persistenza stato) vive in
// process_eventbrite_order() — vedi functions.php — così è richiamabile
// anche in-process dagli strumenti della dashboard ("Simula Ordine",
// "Riprova ordini falliti") senza passare da una chiamata HTTP verso se
// stessi.
$result = process_eventbrite_order($conf, $input['api_url'], $action);
http_response_code($result['http_code']);
