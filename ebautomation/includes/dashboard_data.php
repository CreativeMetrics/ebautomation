<?php
if (!defined('EBAUTO_APP')) { http_response_code(403); exit; }

// ── DATI ──────────────────────────────────────────────────────────────────────
$conf       = load_config();
$brand_name = $conf['business_name'] ?: 'Automazione Sconti';
$active_tab = in_array($_GET['tab'] ?? '', ['sconti','connessioni','template','utenti','log','strumenti','guida']) ? $_GET['tab'] : 'sconti';
$regole          = load_regole();
$email_templates = load_email_templates();
$events          = [];
$organizations   = [];

if (!empty($conf['api_token']) && in_array($active_tab, ['sconti','guida'])) {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $conf['api_token']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    curl_setopt($ch, CURLOPT_URL, 'https://www.eventbriteapi.com/v3/users/me/organizations/');
    $organizations = json_decode(curl_exec($ch), true)['organizations'] ?? [];

    if ($active_tab === 'sconti') {
        // Multi-organizzazione: un unico token può avere accesso a più org
        // Eventbrite (elenco sopra); mostriamo gli eventi di TUTTE quelle
        // accessibili, non solo dell'org_id impostato in configurazione, così
        // le regole possono referenziare eventi trigger/target di qualunque
        // organizzazione gestita dallo stesso account (il webhook risolve poi
        // l'org corretta per ogni evento target dinamicamente, vedi
        // resolve_event_org_id in functions.php). Limitato a 8 org per non
        // allungare troppo il caricamento della pagina.
        $org_list = !empty($organizations) ? array_slice($organizations, 0, 8)
            : (!empty($conf['org_id']) ? [['id' => $conf['org_id'], 'name' => $conf['org_id']]] : []);
        foreach ($org_list as $org) {
            if (empty($org['id'])) continue;
            curl_setopt($ch, CURLOPT_URL, 'https://www.eventbriteapi.com/v3/organizations/' . $org['id'] . '/events/?status=all');
            $org_events = json_decode(curl_exec($ch), true)['events'] ?? [];
            foreach ($org_events as $ev) {
                $ev['_org_name'] = $org['name'] ?? $org['id'];
                $events[] = $ev;
            }
        }
    }
    curl_close($ch);
}

$today_errors = 0;
if (file_exists(LOG_FILE)) {
    $today_prefix = '[' . date('Y-m-d');
    foreach (file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, $today_prefix) && stripos($line, 'ERRORE') !== false) $today_errors++;
    }
}

$edit_id   = isset($_GET['edit']) ? trim($_GET['edit']) : null;
$edit_rule = ($edit_id && isset($regole[$edit_id])) ? $regole[$edit_id] : null;

$edit_template_lingua = isset($_GET['edit_template']) ? trim($_GET['edit_template']) : null;
$edit_template = ($edit_template_lingua && isset($email_templates[$edit_template_lingua])) ? $email_templates[$edit_template_lingua] : null;
// Un template nuovo, o già creato/modificato nell'editor a blocchi, si apre
// in modalità visuale; un template con HTML scritto a mano (blocks=null)
// si apre in modalità codice, coi blocchi di default pronti se l'utente
// sceglie comunque di passare all'editor visivo.
$edit_template_initial_mode  = ($edit_template === null || $edit_template['blocks'] !== null) ? 'visual' : 'code';
$edit_template_blocks_for_js = $edit_template['blocks'] ?? default_email_blocks();

$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url    = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/';
$webhook_url      = $base_url . 'eventbrite-webhook.php' . ($conf['webhook_token'] ? '?token=' . h($conf['webhook_token']) : '');

