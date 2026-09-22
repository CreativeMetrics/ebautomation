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

// Rispondiamo subito con 200 a Eventbrite, PRIMA di iniziare il lavoro
// pesante (recupero ordine, creazione sconti — una o più regole condivise
// sullo stesso trigger — invio email via SMTP), che nel complesso può
// richiedere diversi secondi. Eventbrite ha un timeout piuttosto stretto
// sulla risposta del webhook: se lo aspettiamo prima di rispondere, rischia
// di chiudere la connessione per timeout. Peggio ancora, senza
// ignore_user_abort(true) PHP interromperebbe lo script A METÀ non appena
// il client si disconnette — prima ancora di poter scrivere una riga di
// log o mettere l'ordine in coda "falliti", facendo sparire l'intera
// elaborazione senza lasciarne traccia (il bug osservato in produzione).
ignore_user_abort(true);
set_time_limit(120);
http_response_code(200);
if (function_exists('fastcgi_finish_request')) {
    // Chiude subito la connessione col client (Eventbrite): lo script PHP
    // continua comunque a girare normalmente fino alla fine. Non disponibile
    // fuori da PHP-FPM (es. server di sviluppo built-in): in quel caso si
    // procede comunque, solo senza il vantaggio della risposta anticipata.
    flush();
    fastcgi_finish_request();
}

// Tutta la logica di elaborazione (validazione api_url, recupero ordine,
// creazione sconti, invio email, persistenza stato) vive in
// process_eventbrite_order() — vedi functions.php — così è richiamabile
// anche in-process dagli strumenti della dashboard ("Simula Ordine",
// "Riprova ordini falliti") senza passare da una chiamata HTTP verso se
// stessi. Il suo http_code di ritorno non serve più a questo punto: la
// risposta a Eventbrite è già stata inviata sopra.
process_eventbrite_order($conf, $input['api_url'], $action);
