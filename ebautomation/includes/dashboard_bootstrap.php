<?php
if (!defined('EBAUTO_APP')) { http_response_code(403); exit; }

// ── SETUP MODE ────────────────────────────────────────────────────────────────
// (nessun utente esiste ancora — prima installazione, o installazione
// legacy senza password che load_users() non è riuscita a migrare)
if (empty($users)) {
    $setup_error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup' && verify_csrf()) {
        $pwd   = $_POST['new_password'] ?? '';
        $bname = trim($_POST['business_name'] ?? '');
        if ($issue = password_issue($pwd)) {
            $setup_error = $issue;
        } else {
            $conf['business_name'] = $bname ?: ($conf['business_name'] ?: 'La nostra Azienda');
            if (empty($conf['webhook_token'])) $conf['webhook_token'] = bin2hex(random_bytes(16));
            save_config($conf);
            add_user_row('admin', password_hash($pwd, PASSWORD_DEFAULT), 'admin');
            $_SESSION['authenticated'] = true;
            $_SESSION['username']      = 'admin';
            $_SESSION['role']          = 'admin';
            audit_log('Setup iniziale completato');
            header('Location: dashboard.php?msg=setup_ok');
            exit;
        }
    }
    render_setup($conf, $csrf, $setup_error);
    exit;
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['authenticated']) || empty($_SESSION['username']) || !isset($users[$_SESSION['username']])) {
    $login_error = $login_blocked = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        // Throttling per IP oltre a quello di sessione: quest'ultimo da solo
        // è aggirabile non inviando il cookie di sessione ad ogni tentativo.
        $uname        = trim($_POST['username'] ?? '');
        $ip_key       = 'login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $attempts     = (int)($_SESSION['login_attempts']     ?? 0);
        $last_attempt = (int)($_SESSION['login_last_attempt'] ?? 0);
        if (time() - $last_attempt > 900) $attempts = 0;
        if ($attempts >= 5 || !throttle_allowed($ip_key, 5, 900)) {
            $login_blocked = $login_error = true;
        } elseif (!verify_csrf()) {
            $login_error = true;
        } elseif (isset($users[$uname]) && password_verify($_POST['password'] ?? '', $users[$uname]['password_hash'])) {
            unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);
            throttle_reset($ip_key);
            $_SESSION['authenticated'] = true;
            $_SESSION['username']      = $uname;
            $_SESSION['role']          = $users[$uname]['role'] ?? 'admin';
            session_regenerate_id(true);
            audit_log('Login effettuato');
            header('Location: dashboard.php');
            exit;
        } else {
            $_SESSION['login_attempts']     = $attempts + 1;
            $_SESSION['login_last_attempt'] = time();
            throttle_hit($ip_key, 900);
            $login_error = true;
        }
    }
    $session_expired = isset($_GET['msg']) && $_GET['msg'] === 'session_expired';
    render_login($csrf, $login_error, $brand_name, $login_blocked, $session_expired);
    exit;
}

// ── TIMEOUT PER INATTIVITÀ ───────────────────────────────────────────────────
// Utile ora che possono esistere più utenti con permessi diversi: una
// sessione dimenticata aperta su un computer condiviso non resta valida
// all'infinito.
$idle_timeout = 1800; // 30 minuti
if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idle_timeout) {
    audit_log('Sessione scaduta per inattività');
    session_unset();
    session_destroy();
    header('Location: dashboard.php?msg=session_expired');
    exit;
}
$_SESSION['last_activity'] = time();
$current_username = $_SESSION['username'];
$is_admin          = ($_SESSION['role'] ?? 'admin') === 'admin';
maybe_backup_database(); // no-op se già fatto nelle ultime 24h

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    if (!empty($_SESSION['authenticated'])) audit_log('Logout');
    session_destroy();
    header('Location: dashboard.php');
    exit;
}

// ── EXPORT REGOLE ─────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_regole') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="regole_sconti_' . date('Y-m-d') . '.json"');
    echo json_encode(load_regole(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── EXPORT REGOLE CSV ────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_regole_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="regole_sconti_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['trigger_id', 'descrizione', 'tipo_sconto', 'percentuale', 'importo_fisso', 'codice_prefix', 'target_ids', 'quantita', 'giorni_scadenza', 'qty_minima', 'attiva', 'lingua']);
    foreach (load_regole() as $tid => $r) {
        fputcsv($out, [
            $tid,
            $r['descrizione'] ?? '',
            $r['tipo_sconto'] ?? 'percentuale',
            $r['percentuale'] ?? '',
            $r['importo_fisso'] ?? 0,
            $r['codice_prefix'] ?? 'GIFT',
            implode('|', $r['target_ids'] ?? []),
            $r['quantita'] ?? 1,
            $r['giorni_scadenza'] ?? 0,
            $r['qty_minima'] ?? 1,
            ($r['attiva'] ?? true) ? '1' : '0',
            $r['lingua'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── EXPORT TEMPLATE EMAIL ────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_templates') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_email_' . date('Y-m-d') . '.json"');
    echo json_encode(load_email_templates(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── EXPORT ORDINI CSV ─────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_orders_csv') {
    $all_proc = list_recent_processed_orders(1000000); // già ordinati per ts DESC

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ordini_processati_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['order_id', 'data_ora', 'stato', 'sconti_creati', 'target_ids', 'email_inviata']);
    foreach ($all_proc as $oid => $v) {
        $emailed = !empty($v['email_sent_targets']);
        fputcsv($out, [$oid, date('Y-m-d H:i:s', $v['ts']), $v['status'] ?: '—', count($v['discounts']), implode('|', array_keys($v['discounts'])), $emailed ? 'si' : 'no']);
    }
    fclose($out);
    exit;
}

// ── HEALTH CHECK ──────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'health') {
    render_health_check($conf);
    exit;
}

// ── ANTEPRIMA TEMPLATE EMAIL ─────────────────────────────────────────────────
// Sola lettura (accessibile anche ai "viewer"): mostra esattamente l'HTML che
// verrebbe inviato, con dati di esempio, usando lo stesso motore di rendering
// dell'invio reale — nessuna ricostruzione lato JS da tenere sincronizzata.
if (($_GET['action'] ?? '') === 'preview_email_template') {
    $preview_template = get_email_template(trim($_GET['lingua'] ?? '') ?: null);
    header('Content-Type: text/html; charset=utf-8');
    if (!$preview_template) {
        echo '<p style="font-family:sans-serif;padding:40px;">Nessun template disponibile.</p>';
        exit;
    }
    $preview_logo = file_exists(APP_DIR . '/logo.png');
    // In un browser "cid:" (usato per l'invio reale, dove il logo è un
    // allegato incorporato) non risolve a nulla: qui serve un URL vero.
    $preview_logo_src = $preview_logo ? 'logo.png?v=' . filemtime(APP_DIR . '/logo.png') : '';
    $preview = render_email_template($preview_template, $conf['business_name'] ?: 'La nostra Azienda', 'Mario', sample_regali_finali(), $preview_logo, $preview_logo_src);
    echo $preview['html'];
    exit;
}

