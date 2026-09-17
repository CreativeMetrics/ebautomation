<?php
if (!defined('EBAUTO_APP')) { http_response_code(403); exit; }

function render_health_check(array $conf): void {
    $checks = [];

    // 1. Configurazione
    $missing = array_keys(array_filter([
        'api_token' => empty($conf['api_token']),
        'org_id'    => empty($conf['org_id']),
        'smtp_host' => empty($conf['smtp_host']),
        'smtp_user' => empty($conf['smtp_user']),
        'smtp_pass' => empty($conf['smtp_pass']),
    ]));
    $checks[] = [
        'label'  => 'Configurazione',
        'ok'     => empty($missing),
        'detail' => empty($missing) ? 'Tutti i campi obbligatori sono impostati.' : 'Campi mancanti: ' . implode(', ', $missing),
    ];

    // 2. Eventbrite API
    $api_ok = false; $api_detail = 'Token non configurato.'; $api_ms = 0;
    if (!empty($conf['api_token'])) {
        $ch = curl_init('https://www.eventbriteapi.com/v3/users/me/');
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $conf['api_token']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $t0     = microtime(true);
        $body   = curl_exec($ch);
        $api_ms = (int)round((microtime(true) - $t0) * 1000);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $user   = json_decode($body, true);
        $api_ok = $code === 200 && !empty($user['id']);
        $api_detail = $api_ok
            ? 'Connesso come ' . ($user['name'] ?? ($user['emails'][0]['email'] ?? 'utente')) . " — {$api_ms}ms"
            : "HTTP $code — token non valido o API non raggiungibile ({$api_ms}ms)";
    }
    $checks[] = ['label' => 'Eventbrite API', 'ok' => $api_ok, 'detail' => $api_detail];

    // 3. SMTP
    $smtp_ok = false; $smtp_detail = 'Host SMTP non configurato.';
    if (!empty($conf['smtp_host'])) {
        $sock = @fsockopen($conf['smtp_host'], (int)$conf['smtp_port'], $errno, $errstr, 5);
        $smtp_ok     = $sock !== false;
        $smtp_detail = $smtp_ok
            ? h($conf['smtp_host']) . ':' . h($conf['smtp_port']) . ' raggiungibile.'
            : "Connessione fallita: $errstr ($errno)";
        if ($sock) fclose($sock);
    }
    $checks[] = ['label' => 'SMTP Server', 'ok' => $smtp_ok, 'detail' => $smtp_detail];

    // 4. Spazio disco
    $free     = disk_free_space(APP_DIR);
    $total    = disk_total_space(APP_DIR);
    $free_gb  = round($free / 1073741824, 2);
    $pct_used = $total > 0 ? round(($total - $free) / $total * 100) : 0;
    $disk_ok  = $free > 50 * 1048576;
    $checks[] = [
        'label'  => 'Spazio su Disco',
        'ok'     => $disk_ok,
        'detail' => "{$free_gb} GB liberi — {$pct_used}% utilizzato" . (!$disk_ok ? ' ⚠️ Spazio insufficiente.' : ''),
    ];

    // 5. Permessi file system
    $dir_ok = is_writable(APP_DIR);
    $log_ok = !file_exists(LOG_FILE)         ? $dir_ok : is_writable(LOG_FILE);
    $db_ok  = !file_exists(DB_FILE)          ? $dir_ok : is_writable(DB_FILE);
    $fs_ok  = $dir_ok && $log_ok && $db_ok;
    $checks[] = [
        'label'  => 'File System',
        'ok'     => $fs_ok,
        'detail' => $fs_ok ? 'Cartella, log e database scrivibili.' : 'Problemi di permessi. Verifica i diritti sulla cartella.',
    ];

    // 5b. Database
    $db_query_ok = false; $db_detail = 'Database non raggiungibile.';
    try {
        db()->query('SELECT 1');
        $db_query_ok = true;
        $db_size_mb  = file_exists(DB_FILE) ? round(filesize(DB_FILE) / 1048576, 2) : 0;
        $db_detail   = "database.sqlite raggiungibile — {$db_size_mb} MB.";
    } catch (Throwable $e) {
        $db_detail = 'Errore database: ' . $e->getMessage();
    }
    $checks[] = ['label' => 'Database (SQLite)', 'ok' => $db_query_ok, 'detail' => $db_detail];

    // 6. Regole attive e pausa
    $regole   = load_regole();
    $n_regole = count($regole);
    $checks[] = [
        'label'  => 'Regole & Stato',
        'ok'     => $n_regole > 0 && !$conf['paused'],
        'detail' => $n_regole . ' regol' . ($n_regole === 1 ? 'a configurata' : 'e configurate')
            . ($conf['paused'] ? ' — ⚠️ AUTOMAZIONI IN PAUSA' : ' — automazioni attive'),
    ];

    // 7. Log recente
    $recent_errors = 0; $last_event = '(nessun evento registrato)';
    if (file_exists(LOG_FILE)) {
        $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            $last_event = substr(end($lines), 0, 80) . (strlen(end($lines)) > 80 ? '…' : '');
            $today = '[' . date('Y-m-d');
            foreach ($lines as $l) {
                if (str_starts_with($l, $today) && stripos($l, 'ERRORE') !== false) $recent_errors++;
            }
        }
    }
    $checks[] = [
        'label'  => 'Log (oggi)',
        'ok'     => $recent_errors === 0,
        'detail' => $recent_errors > 0 ? "$recent_errors errori oggi. Ultimo evento: $last_event" : "Nessun errore oggi. Ultimo evento: $last_event",
    ];

    $all_ok = array_reduce($checks, fn($c, $r) => $c && $r['ok'], true);
    $brand  = h($conf['business_name'] ?: 'Dashboard');

    // Formato machine-readable per monitoraggio esterno (uptime monitor,
    // cron aziendale): ?action=health&format=json, nessuna autenticazione
    // aggiuntiva richiesta oltre alla sessione già verificata dal chiamante
    // di questa funzione. Non espone segreti, solo lo stato dei controlli.
    if (($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($all_ok ? 200 : 503);
        echo json_encode([
            'ok'         => $all_ok,
            'checked_at' => date('c'),
            'checks'     => array_map(fn($c) => ['label' => $c['label'], 'ok' => $c['ok'], 'detail' => $c['detail']], $checks),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">';
    echo "<title>Health Check | $brand</title>";
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">';
    echo '<style>
        *{box-sizing:border-box;}
        body{font-family:"Inter",sans-serif;background:#f8f9fa;color:#334155;margin:0;padding:40px 20px;}
        .wrap{max-width:720px;margin:0 auto;}
        h1{font-size:22px;margin:0 0 6px;}
        .sub{color:#94a3b8;font-size:14px;margin:0 0 28px;}
        .back{color:#64748b;font-size:13px;text-decoration:none;display:inline-block;margin-bottom:20px;}
        .back:hover{color:#334155;}
        .card{background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:12px;overflow:hidden;}
        .row{display:flex;align-items:flex-start;padding:18px 24px;gap:16px;}
        .dot{width:13px;height:13px;border-radius:50%;flex-shrink:0;margin-top:3px;}
        .ok .dot{background:#10b981;} .err .dot{background:#ef4444;}
        .lbl{font-weight:700;font-size:14px;min-width:190px;flex-shrink:0;}
        .det{color:#64748b;font-size:13px;line-height:1.5;}
        .banner{padding:16px 24px;border-radius:12px;font-weight:700;font-size:15px;margin-bottom:24px;text-align:center;}
        .banner.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
        .banner.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
        .ts{color:#94a3b8;font-size:12px;text-align:right;margin-top:20px;}
        .actions{margin-bottom:24px;display:flex;gap:12px;}
        .btn{background:#64748b;color:white;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;text-decoration:none;display:inline-block;}
        .btn-refresh{background:#0ea5e9;}
    </style>';
    echo '</head><body><div class="wrap">';
    echo "<a href='dashboard.php?tab=guida' class='back'>← Torna alla Dashboard</a>";
    echo "<h1>🔍 Health Check — $brand</h1>";
    echo "<p class='sub'>Controllo eseguito il " . date('d/m/Y') . ' alle ' . date('H:i:s') . '</p>';
    echo '<div class="actions">';
    echo "<a href='?action=health' class='btn btn-refresh'>↺ Riesegui</a>";
    echo "<a href='dashboard.php?tab=guida' class='btn'>← Dashboard</a>";
    echo '</div>';
    echo '<div class="banner ' . ($all_ok ? 'ok">✅ Tutti i controlli superati.' : 'err">⚠️ Alcuni controlli richiedono attenzione.') . '</div>';
    foreach ($checks as $c) {
        $cls = $c['ok'] ? 'ok' : 'err';
        echo "<div class='card $cls'><div class='row'>";
        echo "<div class='dot'></div>";
        echo "<div class='lbl'>" . h($c['label']) . "</div>";
        echo "<div class='det'>" . h($c['detail']) . "</div>";
        echo '</div></div>';
    }
    echo "<p class='ts'>Generato il " . date('d/m/Y \a\l\l\e H:i:s') . '</p>';
    echo '</div></body></html>';
}

function render_setup(array $conf, string $csrf, string $error): void {
    $bname = h($conf['business_name']);
    $err   = $error ? '<div class="alert-err">⚠️ ' . h($error) . '</div>' : '';
    echo <<<HTML
    <!DOCTYPE html><html lang="it"><head>
    <meta charset="UTF-8"><title>Setup Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif;background:#f8f9fa;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;}.card{background:white;padding:40px;border-radius:16px;box-shadow:0 20px 25px rgba(0,0,0,0.1);max-width:480px;width:90%;}h2{margin-top:0;color:#2d3142;}label{font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:6px;}input{width:100%;padding:12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;margin-bottom:18px;}button{width:100%;background:#D64545;color:white;border:none;padding:14px;border-radius:8px;cursor:pointer;font-weight:700;}.alert-err{background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:16px;font-size:14px;}p{color:#64748b;font-size:14px;}</style>
    </head><body><div class="card">
    <h2>🔧 Configura la Dashboard</h2>
    <p>Imposta il nome dell'azienda e la password di accesso per iniziare.</p>
    {$err}
    <form method="POST">
        <input type="hidden" name="action" value="setup">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <label>Nome Azienda</label><input type="text" name="business_name" value="{$bname}" placeholder="Es. Mia Azienda Srl" required>
        <label>Password Dashboard (min. 10 caratteri)</label><input type="password" name="new_password" placeholder="Scegli una password sicura" required autocomplete="new-password">
        <button type="submit">Configura e Accedi</button>
    </form></div></body></html>
HTML;
}

function render_login(string $csrf, bool $error, string $brand, bool $blocked = false, bool $sessionExpired = false): void {
    $err = '';
    if ($blocked)         $err = '<div class="alert-err">Troppi tentativi. Riprova tra 15 minuti.</div>';
    elseif ($error)       $err = '<div class="alert-err">Password non corretta.</div>';
    elseif ($sessionExpired) $err = '<div class="alert-err">Sessione scaduta per inattività. Effettua di nuovo l\'accesso.</div>';
    $b = h($brand);
    echo <<<HTML
    <!DOCTYPE html><html lang="it"><head>
    <meta charset="UTF-8"><title>Accesso | {$b}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif;background:#f8f9fa;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;}.card{background:white;padding:40px;border-radius:16px;box-shadow:0 20px 25px rgba(0,0,0,0.1);max-width:360px;width:90%;}h2{margin-top:0;color:#2d3142;}label{font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:6px;}input{width:100%;padding:12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;margin-bottom:18px;}button{width:100%;background:#D64545;color:white;border:none;padding:14px;border-radius:8px;cursor:pointer;font-weight:700;}.alert-err{background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:16px;font-size:14px;}</style>
    </head><body><div class="card">
    <h2>🔒 {$b}</h2>{$err}
    <form method="POST">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <label>Utente</label><input type="text" name="username" value="admin" autofocus required autocomplete="username">
        <label>Password</label><input type="password" name="password" required autocomplete="current-password">
        <button type="submit">Accedi</button>
    </form></div></body></html>
HTML;
}
