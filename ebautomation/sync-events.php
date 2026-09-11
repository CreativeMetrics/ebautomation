<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/functions.php';

send_security_headers();

if ($issue = environment_issue()) {
    render_environment_error($issue);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['authenticated'])) {
    http_response_code(403);
    die('Accesso non autorizzato. Effettua il login dalla <a href="dashboard.php">dashboard</a>.');
}

$conf = load_config();

if (empty($conf['api_token']) || empty($conf['org_id'])) {
    die('Configura api_token e org_id nella dashboard prima di usare questo script.');
}

// Filtra per organizer_id (passabili via GET o impostabili qui)
$id_attivatore = $_GET['attivatore'] ?? '';
$id_regalo     = $_GET['regalo']     ?? '';

$trigger_ids = [];
$target_ids  = [];
$page        = 1;
$has_more    = true;

while ($has_more && $page <= 20) { // max 20 pagine = 1000 eventi
    $ch = curl_init("https://www.eventbriteapi.com/v3/organizations/{$conf['org_id']}/events/?status=all&page={$page}");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!isset($res['events'])) {
        die('Errore nel recupero degli eventi alla pagina ' . $page . '. Verifica il token API.');
    }

    foreach ($res['events'] as $event) {
        $id           = $event['id'];
        $organizer_id = $event['organizer_id'];

        if ($id_attivatore && $organizer_id === $id_attivatore) {
            $trigger_ids[] = $id;
        }
        if ($id_regalo && $organizer_id === $id_regalo) {
            $target_ids[] = $id;
        }
    }

    $has_more = $res['pagination']['has_more_items'] ?? false;
    $page++;
}

file_put_contents(__DIR__ . '/trigger_events.txt', implode("\n", array_unique($trigger_ids)));
file_put_contents(__DIR__ . '/target_events.txt',  implode("\n", array_unique($target_ids)));

echo "Sincronizzazione completata.<br>";
echo "Pagine recuperate: " . ($page - 1) . "<br>";
echo "Eventi Attivatori trovati: " . count($trigger_ids) . "<br>";
echo "Eventi Regalo trovati: " . count($target_ids) . "<br>";
echo "<br>Usa i parametri GET <code>?attivatore=ID&regalo=ID</code> per filtrare per organizer_id.";
