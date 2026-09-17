<?php
use PHPMailer\PHPMailer\PHPMailer as Mailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/functions.php';

send_security_headers();

if ($issue = environment_issue()) {
    render_environment_error($issue);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure',   $is_https ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}
rotate_logs();

$conf       = load_config();
$csrf       = csrf_token();
$brand_name = $conf['business_name'] ?: 'Automazione Sconti';

$users = load_users();

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
    $preview_logo = file_exists(__DIR__ . '/logo.png');
    // In un browser "cid:" (usato per l'invio reale, dove il logo è un
    // allegato incorporato) non risolve a nulla: qui serve un URL vero.
    $preview_logo_src = $preview_logo ? 'logo.png?v=' . filemtime(__DIR__ . '/logo.png') : '';
    $preview = render_email_template($preview_template, $conf['business_name'] ?: 'La nostra Azienda', 'Mario', sample_regali_finali(), $preview_logo, $preview_logo_src);
    echo $preview['html'];
    exit;
}

// ── AZIONI POST ───────────────────────────────────────────────────────────────
$flash_error = $_SESSION['flash_error'] ?? '';
$flash_ok    = $_SESSION['flash_ok']    ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { http_response_code(403); die('Token CSRF non valido.'); }

    // Un utente "viewer" può guardare dashboard/log/statistiche ma non
    // eseguire nessuna azione che modifica stato: blocco unico e globale
    // invece di controlli sparsi per singola azione, per non rischiare di
    // dimenticarne una. Eccezione: cambiare la propria password non è
    // un'azione amministrativa.
    if (!$is_admin && ($_POST['action'] ?? '') !== 'change_own_password') {
        http_response_code(403);
        die('Il tuo utente ha accesso in sola lettura: questa azione richiede un account amministratore.');
    }

    switch ($_POST['action'] ?? '') {

        case 'save_config':
            $enc     = in_array($_POST['smtp_encryption'] ?? '', ['smtps','tls']) ? $_POST['smtp_encryption'] : $conf['smtp_encryption'];
            $updated = array_merge($conf, [
                'business_name'   => trim($_POST['business_name']   ?? $conf['business_name']),
                'api_token'       => trim($_POST['api_token']       ?? $conf['api_token']),
                'org_id'          => trim($_POST['org_id']          ?? $conf['org_id']),
                'smtp_host'       => trim($_POST['smtp_host']       ?? $conf['smtp_host']),
                'smtp_user'       => trim($_POST['smtp_user']       ?? $conf['smtp_user']),
                'smtp_pass'       => trim($_POST['smtp_pass']       ?? $conf['smtp_pass']),
                'smtp_port'       => trim($_POST['smtp_port']       ?? $conf['smtp_port']),
                'smtp_encryption' => $enc,
                'currency'        => strtoupper(substr(preg_replace('/[^A-Z]/i', '', $_POST['currency'] ?? $conf['currency']), 0, 3)),
                'alert_email'     => trim($_POST['alert_email']     ?? $conf['alert_email']),
                'alert_threshold' => max(1, (int)($_POST['alert_threshold'] ?? $conf['alert_threshold'])),
            ]);
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $ext      = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                $img_info = @getimagesize($_FILES['logo']['tmp_name']);
                if (in_array($ext, ['png','jpg','jpeg'], true)
                    && $img_info && in_array($img_info['mime'], ['image/png','image/jpeg'], true)
                    && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                    move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/logo.png');
                }
            }
            save_config($updated);
            audit_log('Configurazione salvata');
            header('Location: dashboard.php?tab=config&msg=ok');
            exit;

        case 'change_own_password':
            // Consentito anche agli utenti "sola lettura": non tocca nulla
            // oltre alle proprie credenziali, non è un'azione amministrativa.
            $new_pwd = $_POST['new_password'] ?? '';
            if ($new_pwd !== '') {
                if ($issue = password_issue($new_pwd)) {
                    $_SESSION['flash_error'] = $issue;
                } else {
                    set_user_password($current_username, password_hash($new_pwd, PASSWORD_DEFAULT));
                    audit_log('Password personale cambiata');
                    $_SESSION['flash_ok'] = 'Password aggiornata.';
                }
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'add_user':
            $new_uname = trim($_POST['new_username'] ?? '');
            $new_upwd  = $_POST['new_user_password'] ?? '';
            $new_role  = ($_POST['new_user_role'] ?? '') === 'viewer' ? 'viewer' : 'admin';
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $new_uname)) {
                $_SESSION['flash_error'] = 'Nome utente non valido: usa 3-32 caratteri (lettere, numeri, . _ -).';
            } elseif (isset($users[$new_uname])) {
                $_SESSION['flash_error'] = 'Esiste già un utente con questo nome.';
            } elseif ($issue = password_issue($new_upwd)) {
                $_SESSION['flash_error'] = $issue;
            } else {
                add_user_row($new_uname, password_hash($new_upwd, PASSWORD_DEFAULT), $new_role);
                audit_log('Utente creato', "$new_uname ($new_role)");
                $_SESSION['flash_ok'] = "Utente \"$new_uname\" creato.";
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'delete_user':
            $del_uname = trim($_POST['username'] ?? '');
            $n_admins  = count(array_filter($users, fn($u) => ($u['role'] ?? 'admin') === 'admin'));
            if (count($users) <= 1) {
                $_SESSION['flash_error'] = 'Non puoi eliminare l\'unico utente rimasto.';
            } elseif ($del_uname === $current_username) {
                $_SESSION['flash_error'] = 'Non puoi eliminare l\'utente con cui hai effettuato l\'accesso.';
            } elseif (isset($users[$del_uname]) && ($users[$del_uname]['role'] ?? 'admin') === 'admin' && $n_admins <= 1) {
                $_SESSION['flash_error'] = 'Non puoi eliminare l\'unico amministratore rimasto.';
            } elseif (isset($users[$del_uname])) {
                delete_user_row($del_uname);
                audit_log('Utente eliminato', $del_uname);
                $_SESSION['flash_ok'] = "Utente \"$del_uname\" eliminato.";
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'toggle_user_role':
            $t_uname  = trim($_POST['username'] ?? '');
            $n_admins = count(array_filter($users, fn($u) => ($u['role'] ?? 'admin') === 'admin'));
            if (!isset($users[$t_uname])) {
                break;
            }
            $cur_role = $users[$t_uname]['role'] ?? 'admin';
            if ($cur_role === 'admin' && $n_admins <= 1) {
                $_SESSION['flash_error'] = 'Non puoi togliere i permessi di amministratore all\'unico admin rimasto.';
            } else {
                $new_role = $cur_role === 'admin' ? 'viewer' : 'admin';
                db()->prepare('UPDATE users SET role = ? WHERE username = ?')->execute([$new_role, $t_uname]);
                audit_log('Ruolo utente cambiato', "$t_uname → $new_role");
                $_SESSION['flash_ok'] = "Ruolo di \"$t_uname\" cambiato in $new_role.";
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'toggle_pause':
            $conf['paused'] = !$conf['paused'];
            save_config($conf);
            audit_log($conf['paused'] ? 'Automazioni messe in pausa' : 'Automazioni riattivate');
            $_SESSION['flash_ok'] = $conf['paused'] ? 'Automazioni messe in pausa.' : 'Automazioni riattivate.';
            header('Location: dashboard.php?tab=config');
            exit;

        case 'test_smtp':
            // Verifica pura della connessione SMTP: messaggio minimo fisso,
            // indipendente dai template (che si testano singolarmente nella
            // sezione "Template Email", con dati e visual reali).
            $to = trim($_POST['test_email'] ?? $conf['smtp_user']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = 'Indirizzo email non valido.';
                header('Location: dashboard.php?tab=config');
                exit;
            }
            require_once __DIR__ . '/PHPMailer/Exception.php';
            require_once __DIR__ . '/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/PHPMailer/SMTP.php';
            $mail = new Mailer(true);
            try {
                $bname_t = $conf['business_name'] ?: 'La nostra Azienda';
                $mail->isSMTP();
                $mail->Host       = $conf['smtp_host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $conf['smtp_user'];
                $mail->Password   = $conf['smtp_pass'];
                $mail->SMTPSecure = ($conf['smtp_encryption'] === 'tls') ? Mailer::ENCRYPTION_STARTTLS : Mailer::ENCRYPTION_SMTPS;
                $mail->Port       = (int)$conf['smtp_port'];
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom($conf['smtp_user'], $bname_t);
                $mail->addAddress($to);
                $mail->Subject = "[TEST] Connessione SMTP — $bname_t";
                $mail->isHTML(false);
                $mail->Body = "Questa è un'email di test per verificare che le credenziali SMTP configurate funzionino.\n\nSe la ricevi, la connessione è corretta.";
                $mail->send();
                $_SESSION['flash_ok'] = "Email di test inviata a $to.";
            } catch (MailException $e) {
                $_SESSION['flash_error'] = 'Errore SMTP: ' . $mail->ErrorInfo;
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'save_email_template':
            $lingua = trim($_POST['lingua'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]{1,10}$/', $lingua)) {
                $_SESSION['flash_error'] = 'Codice lingua non valido: usa 1-10 caratteri (lettere, numeri, _ -), es. "it", "en".';
                header('Location: dashboard.php?tab=config');
                exit;
            }
            $colore      = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['colore'] ?? '') ? $_POST['colore'] : '#D64545';
            $editor_mode = ($_POST['editor_mode'] ?? 'visual') === 'code' ? 'code' : 'visual';

            if ($editor_mode === 'visual') {
                $raw_blocks = json_decode((string)($_POST['blocks_json'] ?? '[]'), true);
                $blocks     = sanitize_email_blocks(is_array($raw_blocks) ? $raw_blocks : []);
                $rendered   = render_blocks_to_html($blocks, $colore);
                $body_html  = $rendered['body_html'];
                $item_html  = $rendered['item_html'];
                $logo_width = $rendered['logo_width'];
            } else {
                // Modalità codice: HTML scritto a mano, il template non è
                // più ricostruibile nell'editor a blocchi (blocks = null).
                $blocks     = null;
                $body_html  = (string)($_POST['body_html'] ?? '');
                $item_html  = (string)($_POST['item_html'] ?? '');
                $logo_width = 150;
            }

            save_email_template($lingua, [
                'nome'       => trim($_POST['nome'] ?? '') ?: strtoupper($lingua),
                'subject'    => trim($_POST['subject'] ?? '') ?: 'I tuoi regali da {{business_name}}',
                'colore'     => $colore,
                'body_html'  => $body_html,
                'item_html'  => $item_html,
                'is_default' => !empty($_POST['is_default']),
                'blocks'     => $blocks,
                'logo_width' => $logo_width,
            ]);
            audit_log('Template email salvato', $lingua);
            $_SESSION['flash_ok'] = "Template \"$lingua\" salvato.";
            header('Location: dashboard.php?tab=config');
            exit;

        case 'preview_blocks':
            // Anteprima dal vivo mentre si modifica nell'editor a blocchi,
            // prima di salvare: stesso motore di rendering usato per email
            // reali/anteprime salvate, applicato però a blocchi non ancora
            // persistiti (arrivano interamente dal form via AJAX).
            header('Content-Type: text/html; charset=utf-8');
            $pb_raw_blocks = json_decode((string)($_POST['blocks_json'] ?? '[]'), true);
            $pb_blocks     = sanitize_email_blocks(is_array($pb_raw_blocks) ? $pb_raw_blocks : []);
            $pb_colore     = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['colore'] ?? '') ? $_POST['colore'] : '#D64545';
            $pb_rendered   = render_blocks_to_html($pb_blocks, $pb_colore);
            $pb_template   = ['colore' => $pb_colore, 'body_html' => $pb_rendered['body_html'], 'item_html' => $pb_rendered['item_html'], 'logo_width' => $pb_rendered['logo_width']];
            $pb_has_logo   = file_exists(__DIR__ . '/logo.png');
            $pb_logo_src   = $pb_has_logo ? 'logo.png?v=' . filemtime(__DIR__ . '/logo.png') : '';
            $pb_preview    = render_email_template($pb_template, $conf['business_name'] ?: 'La nostra Azienda', 'Mario', sample_regali_finali(), $pb_has_logo, $pb_logo_src);
            echo $pb_preview['html'];
            exit;

        case 'delete_email_template':
            $lingua = trim($_POST['lingua'] ?? '');
            $all_templates = load_email_templates();
            if (count($all_templates) <= 1) {
                $_SESSION['flash_error'] = 'Non puoi eliminare l\'unico template rimasto: serve sempre almeno un template per inviare le email.';
            } elseif (isset($all_templates[$lingua])) {
                $was_default = $all_templates[$lingua]['is_default'];
                delete_email_template($lingua);
                if ($was_default) {
                    // Deve sempre restarne uno predefinito: promuove il primo rimasto.
                    $remaining = load_email_templates();
                    $first_key = array_key_first($remaining);
                    if ($first_key !== null) {
                        save_email_template($first_key, array_merge($remaining[$first_key], ['is_default' => true]));
                    }
                }
                audit_log('Template email eliminato', $lingua);
                $_SESSION['flash_ok'] = "Template \"$lingua\" eliminato.";
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'test_email_template':
            $lingua = trim($_POST['lingua'] ?? '');
            $to     = trim($_POST['test_email'] ?? $conf['smtp_user']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = 'Indirizzo email non valido.';
                header('Location: dashboard.php?tab=config');
                exit;
            }
            $template = get_email_template($lingua ?: null);
            if (!$template) {
                $_SESSION['flash_error'] = 'Nessun template disponibile da testare.';
                header('Location: dashboard.php?tab=config');
                exit;
            }
            require_once __DIR__ . '/PHPMailer/Exception.php';
            require_once __DIR__ . '/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/PHPMailer/SMTP.php';
            $bname_t  = $conf['business_name'] ?: 'La nostra Azienda';
            $logo_path = __DIR__ . '/logo.png';
            $has_logo  = file_exists($logo_path);
            $rendered  = render_email_template($template, $bname_t, 'Cliente', sample_regali_finali(), $has_logo);
            $mail = new Mailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $conf['smtp_host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $conf['smtp_user'];
                $mail->Password   = $conf['smtp_pass'];
                $mail->SMTPSecure = ($conf['smtp_encryption'] === 'tls') ? Mailer::ENCRYPTION_STARTTLS : Mailer::ENCRYPTION_SMTPS;
                $mail->Port       = (int)$conf['smtp_port'];
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom($conf['smtp_user'], $bname_t);
                $mail->addAddress($to);
                $mail->isHTML(true);
                $mail->Subject = '[TEST] ' . $rendered['subject'];
                if ($has_logo) $mail->addEmbeddedImage($logo_path, 'logo_cid');
                $mail->Body    = $rendered['html'];
                $mail->AltBody = $rendered['text'];
                $mail->send();
                $_SESSION['flash_ok'] = "Email di test inviata a $to con il template \"{$template['nome']}\".";
            } catch (MailException $e) {
                $_SESSION['flash_error'] = 'Errore SMTP: ' . $mail->ErrorInfo;
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'regenerate_token':
            $conf['webhook_token'] = bin2hex(random_bytes(16));
            save_config($conf);
            audit_log('Token webhook rigenerato');
            $_SESSION['flash_ok'] = 'Nuovo token generato. Aggiorna subito l\'URL su Eventbrite.';
            header('Location: dashboard.php?tab=guida');
            exit;

        case 'save_regola':
            $regole = load_regole();
            $tid    = trim($_POST['trigger_id'] ?? '');
            if ($tid) {
                $targets = array_values(array_unique(array_filter(array_map('trim', explode(',', $_POST['target_id'] ?? '')))));
                $tipo    = ($_POST['tipo_sconto'] ?? '') === 'importo' ? 'importo' : 'percentuale';

                // Validazione event ID via API Eventbrite
                $ev_names = [];
                if (!empty($conf['api_token'])) {
                    $invalid = [];
                    foreach (array_unique(array_merge([$tid], $targets)) as $eid) {
                        $ch = curl_init("https://www.eventbriteapi.com/v3/events/{$eid}/");
                        curl_setopt_array($ch, [
                            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token']],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 5,
                        ]);
                        $res  = json_decode(curl_exec($ch), true);
                        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        if ($code !== 200 || empty($res['id'])) {
                            $invalid[] = $eid;
                        } else {
                            $ev_names[$eid] = $res['name']['text'] ?? $eid;
                        }
                    }
                    if (!empty($invalid)) {
                        $_SESSION['flash_error'] = 'ID non trovati su Eventbrite: ' . implode(', ', $invalid) . '. Verifica che siano corretti e che il token API abbia i permessi necessari.';
                        header('Location: dashboard.php?tab=sconti');
                        exit;
                    }
                }

                $regole[$tid] = [
                    'descrizione'     => trim($_POST['descrizione']    ?? ''),
                    'tipo_sconto'     => $tipo,
                    'percentuale'     => number_format(max(1.0, min(100.0, (float)($_POST['percentuale'] ?? 100))), 2, '.', ''),
                    'importo_fisso'   => max(0.0, (float)($_POST['importo_fisso'] ?? 0)),
                    'codice_prefix'   => substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_POST['codice_prefix'] ?? 'GIFT'))), 0, 10) ?: 'GIFT',
                    'target_ids'      => $targets,
                    'quantita'        => max(1, (int)($_POST['quantita']        ?? 1)),
                    'giorni_scadenza' => max(0, (int)($_POST['giorni_scadenza'] ?? 0)),
                    'qty_minima'      => max(1, (int)($_POST['qty_minima']      ?? 1)),
                    // Modificare una regola dal form non la riattiva/disattiva:
                    // preserva lo stato attuale (true se è una regola nuova).
                    'attiva'          => $regole[$tid]['attiva'] ?? true,
                    // Lingua del template email da usare per questa regola;
                    // vuoto = usa il template predefinito (vedi get_email_template).
                    'lingua'          => trim($_POST['lingua'] ?? ''),
                ];
                save_regola_rule($tid, $regole[$tid]);
                audit_log('Regola salvata', $tid);

                if (!empty($ev_names)) {
                    $t_name = $ev_names[$tid] ?? $tid;
                    $tg_names = implode('», «', array_map(fn($id) => $ev_names[$id] ?? $id, $targets));
                    $_SESSION['flash_ok'] = "Regola salvata. Trigger: «{$t_name}» → Target: «{$tg_names}»";
                    header('Location: dashboard.php?tab=sconti');
                } else {
                    header('Location: dashboard.php?tab=sconti&msg=ok');
                }
                exit;
            }
            break;

        case 'delete_regola':
            $tid = trim($_POST['trigger_id'] ?? '');
            if ($tid) {
                delete_regola_rule($tid);
                audit_log('Regola eliminata', $tid);
                header('Location: dashboard.php?tab=sconti&msg=ok');
                exit;
            }
            break;

        case 'toggle_regola':
            $tid    = trim($_POST['trigger_id'] ?? '');
            $regole = load_regole();
            if ($tid && isset($regole[$tid])) {
                $regole[$tid]['attiva'] = !($regole[$tid]['attiva'] ?? true);
                save_regola_rule($tid, $regole[$tid]);
                audit_log($regole[$tid]['attiva'] ? 'Regola riattivata' : 'Regola disattivata', $tid);
                header('Location: dashboard.php?tab=sconti');
                exit;
            }
            break;

        case 'import_regole':
            if (isset($_FILES['regole_file']) && $_FILES['regole_file']['error'] === UPLOAD_ERR_OK) {
                $imported = json_decode(file_get_contents($_FILES['regole_file']['tmp_name']), true);
                if (!is_array($imported)) {
                    $_SESSION['flash_error'] = 'File JSON non valido.';
                } else {
                    $valid = true;
                    foreach ($imported as $r) {
                        if (!isset($r['descrizione'], $r['codice_prefix'], $r['target_ids'])) { $valid = false; break; }
                    }
                    if ($valid) {
                        replace_all_regole($imported);
                        audit_log('Regole importate', count($imported) . ' regole');
                        $_SESSION['flash_ok'] = 'Importate ' . count($imported) . ' regole con successo.';
                    } else {
                        $_SESSION['flash_error'] = 'Struttura JSON non valida. Usa un file esportato da questa dashboard.';
                    }
                }
            } else {
                $_SESSION['flash_error'] = 'Nessun file selezionato.';
            }
            header('Location: dashboard.php?tab=guida');
            exit;

        case 'import_regole_csv':
            if (!isset($_FILES['regole_csv']) || $_FILES['regole_csv']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = 'Nessun file selezionato.';
                header('Location: dashboard.php?tab=guida');
                exit;
            }
            $fh = fopen($_FILES['regole_csv']['tmp_name'], 'r');
            $header = $fh ? fgetcsv($fh) : null;
            $expected_header      = ['trigger_id', 'descrizione', 'tipo_sconto', 'percentuale', 'importo_fisso', 'codice_prefix', 'target_ids', 'quantita', 'giorni_scadenza', 'qty_minima', 'attiva', 'lingua'];
            $expected_header_old  = ['trigger_id', 'descrizione', 'tipo_sconto', 'percentuale', 'importo_fisso', 'codice_prefix', 'target_ids', 'quantita', 'giorni_scadenza', 'qty_minima', 'attiva']; // esportato prima dell'introduzione della colonna lingua
            $header_norm = $header ? array_map('trim', $header) : [];
            if ($header_norm !== $expected_header && $header_norm !== $expected_header_old) {
                $_SESSION['flash_error'] = 'Intestazione CSV non valida. Usa un file esportato da questa dashboard (' . implode(',', $expected_header) . ').';
                header('Location: dashboard.php?tab=guida');
                exit;
            }
            $has_lingua_col = $header_norm === $expected_header;
            $imported_csv = [];
            $csv_error = null;
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < count($expected_header_old)) continue; // riga vuota/incompleta, ignorata
                $lingua = $has_lingua_col ? ($row[11] ?? '') : '';
                [$tid, $descr, $tipo, $perc, $imp, $prefix, $targets_raw, $qta, $giorni, $qtymin, $attiva] = $row;
                $tid = trim($tid);
                $targets = array_values(array_filter(array_map('trim', explode('|', $targets_raw))));
                if ($tid === '' || empty($targets)) {
                    $csv_error = "Riga non valida (trigger_id o target_ids mancanti): " . implode(',', $row);
                    break;
                }
                $imported_csv[$tid] = [
                    'descrizione'     => trim($descr),
                    'tipo_sconto'     => $tipo === 'importo' ? 'importo' : 'percentuale',
                    'percentuale'     => number_format(max(1.0, min(100.0, (float)($perc ?: 100))), 2, '.', ''),
                    'importo_fisso'   => max(0.0, (float)$imp),
                    'codice_prefix'   => substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($prefix ?: 'GIFT'))), 0, 10) ?: 'GIFT',
                    'target_ids'      => $targets,
                    'quantita'        => max(1, (int)($qta ?: 1)),
                    'giorni_scadenza' => max(0, (int)($giorni ?: 0)),
                    'qty_minima'      => max(1, (int)($qtymin ?: 1)),
                    'attiva'          => trim((string)$attiva) !== '0',
                    'lingua'          => trim($lingua),
                ];
            }
            fclose($fh);
            if ($csv_error) {
                $_SESSION['flash_error'] = $csv_error;
            } elseif (empty($imported_csv)) {
                $_SESSION['flash_error'] = 'Nessuna riga valida trovata nel CSV.';
            } else {
                replace_all_regole($imported_csv);
                audit_log('Regole importate da CSV', count($imported_csv) . ' regole');
                $_SESSION['flash_ok'] = 'Importate ' . count($imported_csv) . ' regole da CSV con successo.';
            }
            header('Location: dashboard.php?tab=guida');
            exit;

        case 'simulate_webhook':
            $order_id_sim = preg_replace('/[^0-9]/', '', $_POST['sim_order_id'] ?? '');
            if (!$order_id_sim) {
                $_SESSION['flash_error'] = 'Inserisci un Order ID numerico valido.';
                header('Location: dashboard.php?tab=guida');
                exit;
            }
            $sim_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $sim_base   = $sim_scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/';
            $sim_url    = $sim_base . 'eventbrite-webhook.php' . ($conf['webhook_token'] ? '?token=' . $conf['webhook_token'] : '');
            $ch = curl_init($sim_url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'api_url' => "https://www.eventbriteapi.com/v3/orders/{$order_id_sim}/",
                    'config'  => ['action' => 'order.placed'],
                ]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);
            curl_exec($ch);
            $sim_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($sim_status === 200) {
                $_SESSION['flash_ok'] = "Simulazione inviata (HTTP $sim_status). Controlla il tab Log per i dettagli.";
            } else {
                $_SESSION['flash_error'] = "Simulazione fallita (HTTP $sim_status). Controlla token webhook e configurazione.";
            }
            header('Location: dashboard.php?tab=guida');
            exit;

        case 'retry_failed_orders':
            $to_retry = load_failed_orders();
            if (empty($to_retry)) {
                $_SESSION['flash_ok'] = 'Nessun ordine in coda da ritentare.';
                header('Location: dashboard.php?tab=log');
                exit;
            }
            $rf_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $rf_base   = $rf_scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/';
            $rf_url    = $rf_base . 'eventbrite-webhook.php' . ($conf['webhook_token'] ? '?token=' . $conf['webhook_token'] : '');
            $rf_ok = 0; $rf_ko = 0;
            foreach ($to_retry as $rf_order_id => $rf_entry) {
                $ch = curl_init($rf_url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'api_url' => $rf_entry['api_url'] ?? '',
                        'config'  => ['action' => 'order.placed'],
                    ]),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                ]);
                curl_exec($ch);
                $rf_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($rf_status === 200) $rf_ok++; else $rf_ko++;
            }
            // Chi è tornato "complete" si è già auto-rimosso dalla coda dentro al webhook;
            // ricontiamo cosa resta per un messaggio accurato.
            $still_pending = count(load_failed_orders());
            audit_log('Riprova ordini falliti', count($to_retry) . ' tentati, ' . $still_pending . ' ancora in coda');
            $_SESSION['flash_ok'] = "Ritentati " . count($to_retry) . " ordini. Ancora in coda: $still_pending.";
            header('Location: dashboard.php?tab=log');
            exit;
    }
}

// ── DATI ──────────────────────────────────────────────────────────────────────
$conf       = load_config();
$brand_name = $conf['business_name'] ?: 'Automazione Sconti';
$active_tab = in_array($_GET['tab'] ?? '', ['sconti','config','log','guida']) ? $_GET['tab'] : 'sconti';
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

// ── HTML ──────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?= h($brand_name) ?> | Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #D64545; --sidebar: #1e293b; --bg: #f8f9fa; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: #334155; margin: 0; display: flex; min-height: 100vh; }
        aside { width: 280px; background: var(--sidebar); color: white; padding: 30px 20px; flex-shrink: 0; }
        .logo-box { max-width: 100%; margin-bottom: 20px; text-align: center; }
        .logo-box img { max-width: 150px; height: auto; }
        .pause-banner { background: #f59e0b; color: #1c1917; padding: 8px 12px; border-radius: 8px; margin-bottom: 16px; font-size: 12px; font-weight: 700; text-align: center; }
        nav a { display: block; color: #94a3b8; text-decoration: none; padding: 12px; border-radius: 8px; margin-bottom: 8px; font-weight: 500; transition: 0.2s; }
        nav a:hover, nav a.active { background: rgba(255,255,255,0.1); color: white; }
        nav a.logout { margin-top: 40px; color: #64748b; font-size: 13px; }
        .badge-nav { background: #ef4444; color: white; border-radius: 10px; padding: 1px 7px; font-size: 10px; margin-left: 5px; font-weight: 700; }
        main { flex-grow: 1; padding: 40px; overflow-y: auto; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 30px; }
        h2 { margin-top: 0; font-size: 19px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 25px; }
        h3 { color: #475569; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; margin: 28px 0 14px; border: none; padding: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; border-top: 4px solid #10b981; }
        .stat-card.err { border-top-color: #ef4444; }
        .stat-val { font-size: 34px; font-weight: 700; color: #334155; line-height: 1; }
        .stat-lbl { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 600; margin-top: 6px; }
        .input-group { display: flex; flex-direction: column; gap: 8px; }
        label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; }
        input[type=text], input[type=email], input[type=password], input[type=number], input[type=file], input[type=color], select, textarea { padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; width: 100%; box-sizing: border-box; font-family: inherit; }
        select { background: white; cursor: pointer; }
        textarea { resize: vertical; min-height: 80px; }
        input[type=color] { height: 46px; padding: 4px 8px; cursor: pointer; }
        button, .btn { background: var(--primary); color: white; border: none; padding: 12px 22px; border-radius: 8px; cursor: pointer; font-weight: 700; transition: 0.2s; text-decoration: none; display: inline-block; font-size: 14px; }
        button:hover, .btn:hover { opacity: 0.88; transform: translateY(-1px); }
        .btn-secondary { background: #64748b; }
        .btn-info { background: #0ea5e9; }
        .btn-warning { background: #f59e0b; }
        .btn-success { background: #10b981; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 11px; color: #94a3b8; text-transform: uppercase; padding: 12px; border-bottom: 2px solid #f1f5f9; }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
        .badge { background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-family: monospace; color: var(--primary); font-size: 12px; cursor: pointer; }
        .alert-ok  { background: #dcfce7; color: #166534; padding: 15px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #bbf7d0; }
        .alert-err { background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #fecaca; }
        .log-box { background: #0f172a; color: #94a3b8; font-family: monospace; font-size: 12px; padding: 20px; border-radius: 8px; overflow-y: auto; max-height: 460px; white-space: pre-wrap; word-break: break-all; }
        .log-box .log-err  { color: #f87171; }
        .log-box .log-info { color: #86efac; }
        .del-btn { background: none; border: none; color: #ef4444; cursor: pointer; font-weight: bold; font-size: 18px; padding: 0 4px; line-height: 1; }
        code { background: #f1f5f9; padding: 10px 14px; border-radius: 6px; display: block; font-size: 13px; word-break: break-all; }
        .edit-highlight { background: #fffbeb; border: 2px solid #fcd34d; }
        .tip { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .editor-mode-toggle { display: flex; gap: 8px; margin-bottom: 16px; }
        .editor-mode-toggle button { background: #f1f5f9; color: #64748b; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; }
        .editor-mode-toggle button.active { background: var(--primary); color: white; }
        .block-editor { display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start; }
        .block-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
        .block-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; }
        .block-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .block-card-title { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .03em; }
        .block-card-actions button { background: none; border: none; cursor: pointer; color: #94a3b8; font-size: 15px; padding: 2px 6px; }
        .block-card-actions button:hover:not(:disabled) { color: #334155; }
        .block-card-actions button:disabled { opacity: .3; cursor: default; }
        .block-card-actions button.del:hover:not(:disabled) { color: #ef4444; }
        .field-row { display: flex; gap: 10px; margin-bottom: 8px; }
        .field-row > * { flex: 1; min-width: 0; }
        .block-add-menu { display: flex; flex-wrap: wrap; gap: 8px; }
        .block-add-menu button { background: white; border: 1px dashed #cbd5e1; color: #475569; font-weight: 600; font-size: 12px; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
        .block-add-menu button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .block-add-menu button:disabled { opacity: .35; cursor: default; }
        .block-preview-panel { position: sticky; top: 20px; }
        .block-preview-frame { width: 100%; height: 520px; border: 1px solid #e2e8f0; border-radius: 10px; background: white; }
        @media (max-width: 900px) {
            .block-editor { grid-template-columns: 1fr; }
            .block-preview-panel { position: static; }
            .block-preview-frame { height: 400px; }
        }
    </style>
</head>
<body>
<aside>
    <div class="logo-box">
        <?php if (file_exists(__DIR__ . '/logo.png')): ?>
            <img src="logo.png?v=<?= filemtime(__DIR__ . '/logo.png') ?>" alt="Logo">
        <?php else: ?>
            <div style="font-weight:bold;font-size:20px;"><?= h($brand_name) ?></div>
        <?php endif; ?>
    </div>
    <?php if ($conf['paused']): ?><div class="pause-banner">⏸ AUTOMAZIONI IN PAUSA</div><?php endif; ?>
    <?php if (!$is_admin): ?><div class="pause-banner" style="background:#64748b;color:white;">👁 SOLA LETTURA</div><?php endif; ?>
    <nav>
        <a href="?tab=sconti" class="<?= $active_tab==='sconti'?'active':'' ?>">🎁 Regole Sconti</a>
        <a href="?tab=config" class="<?= $active_tab==='config'?'active':'' ?>">⚙️ Configurazione</a>
        <a href="?tab=log"    class="<?= $active_tab==='log'   ?'active':'' ?>">📋 Log Webhook<?php if ($today_errors > 0): ?><span class="badge-nav"><?= $today_errors ?></span><?php endif; ?></a>
        <a href="?tab=guida"  class="<?= $active_tab==='guida' ?'active':'' ?>">📖 Guida & Help</a>
        <a href="?logout=1" class="logout">🚪 Esci</a>
    </nav>
</aside>

<main>
    <?php if (isset($_GET['msg'])): ?><div class="alert-ok">✅ <?= $_GET['msg']==='setup_ok' ? 'Setup completato. Benvenuto!' : 'Salvato correttamente.' ?></div><?php endif; ?>
    <?php if ($flash_ok):  ?><div class="alert-ok">✅ <?= h($flash_ok)  ?></div><?php endif; ?>
    <?php if ($flash_error): ?><div class="alert-err">⚠️ <?= h($flash_error) ?></div><?php endif; ?>

    <?php /* ══════════════ SCONTI ══════════════ */ if ($active_tab==='sconti'): ?>

    <div class="card">
        <h2>Automazioni Attive <a href="?action=export_regole" class="btn btn-secondary" style="float:right;font-size:12px;padding:8px 14px;">⬇ Esporta JSON</a></h2>
        <table>
            <thead><tr><th>Trigger</th><th>Descrizione</th><th>Sconto</th><th>Qtà</th><th>Min. trigger</th><th>Scade</th><th>Target</th><th>Stato</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($regole as $tid => $r):
                $is_imp = ($r['tipo_sconto'] ?? 'percentuale') === 'importo';
                $sconto_label = $is_imp ? h((string)($r['importo_fisso'] ?? 0)) . ' ' . h($conf['currency'] ?: 'EUR') : h($r['percentuale']) . '%';
                $r_attiva = $r['attiva'] ?? true;
            ?>
            <tr<?= $r_attiva ? '' : ' style="opacity:.55;"' ?>>
                <td><span class="badge"><?= h($tid) ?></span></td>
                <td><strong><?= h($r['descrizione']) ?></strong></td>
                <td><?= $sconto_label ?></td>
                <td><?= h((string)($r['quantita'] ?? 1)) ?></td>
                <td><?= ($r['qty_minima'] ?? 1) > 1 ? h((string)$r['qty_minima']) . ' biglietti' : '—' ?></td>
                <td><?= ($r['giorni_scadenza'] ?? 0) > 0 ? h((string)$r['giorni_scadenza']) . ' gg' : '—' ?></td>
                <td><?php foreach ($r['target_ids'] as $t) echo '<span class="badge">'.h($t).'</span> '; ?></td>
                <td>
                    <?php if ($is_admin): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action"     value="toggle_regola">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="trigger_id" value="<?= h($tid) ?>">
                        <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:6px 10px;<?= $r_attiva ? '' : 'background:#f59e0b;' ?>" title="<?= $r_attiva ? 'Disattiva questa regola' : 'Riattiva questa regola' ?>">
                            <?= $r_attiva ? '✓ attiva' : '⏸ disattiva' ?>
                        </button>
                    </form>
                    <?php else: ?>
                        <span class="badge" style="cursor:default;"><?= $r_attiva ? '✓ attiva' : '⏸ disattiva' ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($is_admin): ?>
                    <a href="?tab=sconti&edit=<?= urlencode($tid) ?>" style="color:#0ea5e9;text-decoration:none;font-weight:700;margin-right:8px;">✏</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminare questa regola?')">
                        <input type="hidden" name="action"     value="delete_regola">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="trigger_id" value="<?= h($tid) ?>">
                        <button type="submit" class="del-btn">&times;</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($regole)): ?><tr><td colspan="9" style="color:#94a3b8;text-align:center;padding:30px;">Nessuna regola. Creane una qui sotto.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($is_admin): ?>
    <div class="card <?= $edit_rule ? 'edit-highlight' : '' ?>">
        <h2><?= $edit_rule ? '✏️ Modifica Regola' : 'Nuova Regola' ?></h2>
        <?php if ($edit_rule): ?><p style="color:#92400e;font-size:13px;margin-top:-10px;">Trigger: <strong><?= h($edit_id) ?></strong></p><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action"     value="save_regola">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>ID Evento Trigger</label>
                    <input type="text" name="trigger_id" id="f_t" value="<?= h($edit_id ?? '') ?>" <?= $edit_rule ? 'readonly style="background:#f1f5f9;"' : '' ?> required>
                </div>
                <div class="input-group">
                    <label>ID Target (separati da ,)</label>
                    <input type="text" name="target_id" id="f_r" value="<?= h(implode(', ', $edit_rule['target_ids'] ?? [])) ?>" required>
                </div>
                <div class="input-group">
                    <label>Nome Promozione</label>
                    <input type="text" name="descrizione" value="<?= h($edit_rule['descrizione'] ?? '') ?>">
                </div>
            </div>
            <div class="grid">
                <div class="input-group">
                    <label>Tipo Sconto</label>
                    <select name="tipo_sconto" id="tipo_sconto" onchange="toggleTipo(this.value)">
                        <option value="percentuale" <?= ($edit_rule['tipo_sconto'] ?? 'percentuale') === 'percentuale' ? 'selected' : '' ?>>Percentuale (%)</option>
                        <option value="importo"     <?= ($edit_rule['tipo_sconto'] ?? '')              === 'importo'     ? 'selected' : '' ?>>Importo Fisso</option>
                    </select>
                </div>
                <div class="input-group" id="grp-perc">
                    <label>% Sconto</label>
                    <input type="number" name="percentuale" value="<?= h((string)($edit_rule['percentuale'] ?? 100)) ?>" min="1" max="100">
                </div>
                <div class="input-group" id="grp-imp" style="display:none;">
                    <label>Importo Fisso (<?= h($conf['currency'] ?: 'EUR') ?>)</label>
                    <input type="number" name="importo_fisso" step="0.01" min="0" value="<?= h((string)($edit_rule['importo_fisso'] ?? 0)) ?>">
                </div>
                <div class="input-group">
                    <label>Prefisso Codice</label>
                    <input type="text" name="codice_prefix" value="<?= h($edit_rule['codice_prefix'] ?? 'GIFT') ?>" maxlength="10">
                </div>
            </div>
            <div class="grid">
                <div class="input-group">
                    <label>Quantità utilizzi</label>
                    <input type="number" name="quantita" value="<?= h((string)($edit_rule['quantita'] ?? 1)) ?>" min="1" max="9999">
                </div>
                <div class="input-group">
                    <label>Scadenza (giorni, 0 = mai)</label>
                    <input type="number" name="giorni_scadenza" value="<?= h((string)($edit_rule['giorni_scadenza'] ?? 0)) ?>" min="0" max="3650">
                </div>
                <div class="input-group">
                    <label>Quantità minima trigger</label>
                    <input type="number" name="qty_minima" value="<?= h((string)($edit_rule['qty_minima'] ?? 1)) ?>" min="1" max="9999">
                    <span class="tip">Biglietti dell'evento trigger richiesti nello stesso ordine perché la regola si attivi. 1 = sempre (default).</span>
                </div>
                <div class="input-group">
                    <label>Lingua Email</label>
                    <select name="lingua">
                        <option value="">— Usa il template predefinito —</option>
                        <?php foreach ($email_templates as $lingua => $tpl): ?>
                            <option value="<?= h($lingua) ?>" <?= ($edit_rule['lingua'] ?? '') === $lingua ? 'selected' : '' ?>><?= h($tpl['nome']) ?><?= $tpl['is_default'] ? ' (predefinito)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="tip">Determina il template email usato per comunicare gli sconti di questa regola. Gestisci i template nella sezione "Template Email" del tab Configurazione.</span>
                </div>
            </div>
            <div class="grid">
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;grid-column:1/-1;">
                    <div>
                        <button type="submit"><?= $edit_rule ? 'Aggiorna' : 'Crea Regola' ?></button>
                        <?php if ($edit_rule): ?><a href="?tab=sconti" class="btn btn-secondary" style="margin-left:8px;">Annulla</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Eventi Disponibili</h2>
        <?php if (empty($conf['api_token'])): ?>
            <p style="color:#94a3b8;">Configura il Token API per vedere gli eventi.</p>
        <?php elseif (empty($events)): ?>
            <p style="color:#94a3b8;">Nessun evento trovato. Verifica l'Organization ID.</p>
        <?php else: ?>
        <p class="tip" style="margin-top:-14px;margin-bottom:16px;">Eventi di tutte le organizzazioni accessibili al tuo token — trigger e target possono appartenere a organizzazioni diverse.</p>
        <table>
            <thead><tr><th>Evento</th><th>Organizzazione</th><th>Status</th><th>ID</th></tr></thead>
            <tbody>
            <?php foreach ($events as $e): ?>
            <tr>
                <td><strong><?= h($e['name']['text'] ?? '') ?></strong></td>
                <td style="color:#64748b;"><?= h((string)($e['_org_name'] ?? '')) ?></td>
                <td><?= h($e['status'] ?? '') ?></td>
                <td><span class="badge" onclick="cp('<?= h($e['id']) ?>')" title="Clicca per copiare negli input"><?= h($e['id']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php /* ══════════════ CONFIG ══════════════ */ elseif ($active_tab==='config'): ?>

    <div class="card" style="border-top:4px solid <?= $conf['paused'] ? '#f59e0b' : '#10b981' ?>;">
        <h2><?= $conf['paused'] ? '⏸ Automazioni in Pausa' : '▶ Automazioni Attive' ?></h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;"><?= $conf['paused'] ? 'I webhook vengono ricevuti ma ignorati. Nessuno sconto verrà creato.' : 'Tutto funziona normalmente. Metti in pausa per bloccare temporaneamente le automazioni.' ?></p>
        <?php if ($is_admin): ?>
        <form method="POST">
            <input type="hidden" name="action"     value="toggle_pause">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button type="submit" class="<?= $conf['paused'] ? 'btn-success' : 'btn-warning' ?>">
                <?= $conf['paused'] ? '▶ Riattiva Automazioni' : '⏸ Metti in Pausa' ?>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($is_admin): ?>
    <div class="card">
        <h2>⚙️ Impostazioni</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action"     value="save_config">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <h3>Azienda</h3>
            <div class="grid">
                <div class="input-group"><label>Nome Brand</label><input type="text" name="business_name" value="<?= h($conf['business_name']) ?>"></div>
                <div class="input-group"><label>Logo (PNG/JPG, max 2 MB)</label><input type="file" name="logo" accept="image/png,image/jpeg"></div>
            </div>

            <h3>Eventbrite</h3>
            <div class="grid">
                <div class="input-group"><label>Private Token</label><input type="password" name="api_token" value="<?= h($conf['api_token']) ?>"></div>
                <div class="input-group"><label>Organization ID</label><input type="text" name="org_id" value="<?= h($conf['org_id']) ?>"></div>
                <div class="input-group"><label>Valuta sconti fissi</label><input type="text" name="currency" value="<?= h($conf['currency']) ?>" maxlength="3" placeholder="EUR"></div>
            </div>

            <h3>SMTP</h3>
            <div class="grid">
                <div class="input-group"><label>Host</label><input type="text" name="smtp_host" value="<?= h($conf['smtp_host']) ?>"></div>
                <div class="input-group">
                    <label>Cifratura</label>
                    <select name="smtp_encryption" onchange="syncPort(this.value)">
                        <option value="smtps" <?= $conf['smtp_encryption']==='smtps'?'selected':'' ?>>SSL/TLS (porta 465)</option>
                        <option value="tls"   <?= $conf['smtp_encryption']==='tls'  ?'selected':'' ?>>STARTTLS (porta 587)</option>
                    </select>
                </div>
                <div class="input-group"><label>Porta</label><input type="text" name="smtp_port" id="smtp_port" value="<?= h($conf['smtp_port']) ?>"></div>
                <div class="input-group"><label>Email</label><input type="email" name="smtp_user" value="<?= h($conf['smtp_user']) ?>"></div>
                <div class="input-group"><label>Password SMTP</label><input type="password" name="smtp_pass" value="<?= h($conf['smtp_pass']) ?>"></div>
            </div>

            <h3>Notifiche Admin</h3>
            <div class="grid">
                <div class="input-group">
                    <label>Email di alert</label>
                    <input type="email" name="alert_email" value="<?= h($conf['alert_email']) ?>" placeholder="<?= h($conf['smtp_user'] ?: 'usa Email Mittente') ?>">
                    <span class="tip">Se vuota, gli alert vanno all'Email Mittente SMTP.</span>
                </div>
                <div class="input-group">
                    <label>Soglia errori/giorno</label>
                    <input type="number" name="alert_threshold" value="<?= h((string)$conf['alert_threshold']) ?>" min="1" max="999">
                    <span class="tip">Sopra questa soglia parte un'email di alert (max 1/ora).</span>
                </div>
            </div>

            <button type="submit">Salva Configurazione</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>✉️ Template Email</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Uno o più template HTML completi, uno per lingua/variante visiva. Ogni regola sceglie quale usare (campo "Lingua Email"); chi non specifica nulla usa il predefinito.</p>
        <table>
            <thead><tr><th>Codice</th><th>Nome</th><th>Oggetto</th><th>Predefinito</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($email_templates as $lingua => $tpl): ?>
                <tr>
                    <td><span class="badge"><?= h($lingua) ?></span></td>
                    <td><strong><?= h($tpl['nome']) ?></strong></td>
                    <td style="color:#64748b;"><?= h($tpl['subject']) ?></td>
                    <td><?= $tpl['is_default'] ? '<span style="color:#10b981;">✓ predefinito</span>' : '—' ?></td>
                    <td style="white-space:nowrap;">
                        <a href="?tab=config&action=preview_email_template&lingua=<?= urlencode($lingua) ?>" target="_blank" style="color:#0ea5e9;text-decoration:none;font-weight:700;margin-right:8px;" title="Anteprima in una nuova scheda">👁</a>
                        <?php if ($is_admin): ?>
                        <a href="?tab=config&edit_template=<?= urlencode($lingua) ?>#template-form" style="color:#0ea5e9;text-decoration:none;font-weight:700;margin-right:8px;">✏</a>
                        <?php if (count($email_templates) > 1): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminare il template <?= h(addslashes($tpl['nome'])) ?>?')">
                            <input type="hidden" name="action" value="delete_email_template">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="lingua" value="<?= h($lingua) ?>">
                            <button type="submit" class="del-btn">&times;</button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($is_admin): ?>
        <h3 id="template-form"><?= $edit_template ? '✏️ Modifica Template «' . h($edit_template_lingua) . '»' : '+ Nuovo Template' ?></h3>
        <form method="POST">
            <input type="hidden" name="action"     value="save_email_template">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Codice lingua</label>
                    <input type="text" name="lingua" value="<?= h($edit_template_lingua ?? '') ?>" placeholder="es. it, en, es" maxlength="10" <?= $edit_template ? 'readonly style="background:#f1f5f9;"' : '' ?> required>
                    <span class="tip">Identificativo libero, non deve necessariamente essere un codice ISO. Non modificabile dopo la creazione.</span>
                </div>
                <div class="input-group">
                    <label>Nome (etichetta)</label>
                    <input type="text" name="nome" value="<?= h($edit_template['nome'] ?? '') ?>" placeholder="es. Italiano">
                </div>
                <div class="input-group">
                    <label>Colore Principale</label>
                    <input type="color" name="colore" id="tpl_colore" value="<?= h($edit_template['colore'] ?? '#D64545') ?>">
                </div>
            </div>
            <div class="grid">
                <div class="input-group" style="grid-column:1/-1;">
                    <label>Oggetto</label>
                    <input type="text" name="subject" value="<?= h($edit_template['subject'] ?? 'I tuoi regali da {{business_name}}') ?>">
                    <span class="tip">Segnaposto disponibile: <code style="display:inline;padding:1px 5px;">{{business_name}}</code></span>
                </div>
            </div>

            <div class="input-group" style="margin-bottom:20px;">
                <label>Struttura Email</label>
                <div class="editor-mode-toggle">
                    <button type="button" id="mode-btn-visual">🧱 Editor Visivo</button>
                    <button type="button" id="mode-btn-code">&lt;/&gt; Codice HTML</button>
                </div>

                <input type="hidden" name="editor_mode" id="editor_mode_input" value="<?= h($edit_template_initial_mode) ?>">
                <input type="hidden" name="blocks_json" id="blocks_json_input" value="">

                <div id="visual-editor-box"<?= $edit_template_initial_mode !== 'visual' ? ' hidden' : '' ?>>
                    <div class="block-editor">
                        <div>
                            <div class="block-list" id="blocks-list"></div>
                            <div class="block-add-menu">
                                <button type="button" data-add="logo">+ 🖼 Logo</button>
                                <button type="button" data-add="heading">+ 🔤 Titolo</button>
                                <button type="button" data-add="text">+ 📝 Testo</button>
                                <button type="button" data-add="gift_box">+ 🎁 Box Sconto</button>
                                <button type="button" data-add="divider">+ ➖ Divisore</button>
                                <button type="button" data-add="spacer">+ ↕ Spazio</button>
                                <button type="button" data-add="footer">+ 📄 Piè di pagina</button>
                            </div>
                            <p class="tip" style="margin-top:12px;">Segnaposto utilizzabili nei testi: <code style="display:inline;padding:1px 5px;">{{nome}}</code> <code style="display:inline;padding:1px 5px;">{{anno}}</code> <code style="display:inline;padding:1px 5px;">{{business_name}}</code></p>
                        </div>
                        <div class="block-preview-panel">
                            <label style="display:block;margin-bottom:8px;">Anteprima Live</label>
                            <iframe id="block-preview-frame" class="block-preview-frame" sandbox="allow-same-origin" title="Anteprima email"></iframe>
                        </div>
                    </div>
                </div>

                <div id="code-editor-box"<?= $edit_template_initial_mode !== 'code' ? ' hidden' : '' ?>>
                    <div class="input-group" style="margin-bottom:16px;">
                        <label>Corpo Email (HTML)</label>
                        <textarea name="body_html" style="min-height:220px;font-family:monospace;font-size:12px;"><?= h($edit_template['body_html'] ?? '') ?></textarea>
                        <span class="tip">Segnaposto disponibili: <code style="display:inline;padding:1px 5px;">{{business_name}}</code> <code style="display:inline;padding:1px 5px;">{{nome}}</code> <code style="display:inline;padding:1px 5px;">{{items}}</code> <code style="display:inline;padding:1px 5px;">{{logo}}</code> <code style="display:inline;padding:1px 5px;">{{anno}}</code> <code style="display:inline;padding:1px 5px;">{{colore}}</code> — <code style="display:inline;padding:1px 5px;">{{items}}</code> viene sostituito con i blocchi sconto (vedi sotto), uno per ogni codice regalo.</span>
                    </div>
                    <div class="input-group">
                        <label>Blocco Singolo Sconto (HTML, ripetuto per ogni codice)</label>
                        <textarea name="item_html" style="min-height:120px;font-family:monospace;font-size:12px;"><?= h($edit_template['item_html'] ?? '') ?></textarea>
                        <span class="tip">Segnaposto disponibili: <code style="display:inline;padding:1px 5px;">{{desc}}</code> <code style="display:inline;padding:1px 5px;">{{code}}</code> <code style="display:inline;padding:1px 5px;">{{url}}</code> <code style="display:inline;padding:1px 5px;">{{label}}</code> <code style="display:inline;padding:1px 5px;">{{colore}}</code></span>
                    </div>
                </div>
            </div>

            <div class="grid">
                <div class="input-group">
                    <label style="text-transform:none;font-size:13px;display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_default" value="1" style="width:auto;" <?= ($edit_template['is_default'] ?? false) ? 'checked' : '' ?>>
                        Usa come predefinito
                    </label>
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <div>
                        <button type="submit"><?= $edit_template ? 'Aggiorna Template' : 'Crea Template' ?></button>
                        <?php if ($edit_template): ?><a href="?tab=config#template-form" class="btn btn-secondary" style="margin-left:8px;">Annulla</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </form>

        <script>
        (function() {
            const CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const DEFAULT_BLOCKS = <?= json_encode(default_email_blocks(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const BLOCK_LABELS = {
                logo: '🖼 Logo / Nome Azienda', heading: '🔤 Titolo', text: '📝 Testo',
                gift_box: '🎁 Box Sconto', divider: '➖ Divisore', spacer: '↕ Spazio', footer: '📄 Piè di pagina',
            };

            let blocks = <?= json_encode($edit_template_blocks_for_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            let editorMode = <?= json_encode($edit_template_initial_mode) ?>;

            const listEl = document.getElementById('blocks-list');
            if (!listEl) return; // form non presente (utente non admin)

            function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
            function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

            function alignSelect(b, i) {
                const cur = b.align || 'center';
                const labels = { left: 'Sinistra', center: 'Centro', right: 'Destra' };
                return '<select data-f="align" data-i="' + i + '">' + ['left','center','right'].map(v =>
                    '<option value="' + v + '"' + (cur === v ? ' selected' : '') + '>' + labels[v] + '</option>'
                ).join('') + '</select>';
            }

            function newBlock(type) {
                switch (type) {
                    case 'logo':     return { type: 'logo', align: 'center', width: 150 };
                    case 'heading':  return { type: 'heading', text: 'Ciao {{nome}}!', align: 'center', color: '#2d3142' };
                    case 'text':     return { type: 'text', text: 'Scrivi qui il tuo testo…', align: 'center', color: '#4f5d75' };
                    case 'gift_box': return { type: 'gift_box', label: "Per l'evento:", button_text: 'Usa Sconto' };
                    case 'divider':  return { type: 'divider' };
                    case 'spacer':   return { type: 'spacer', height: 20 };
                    case 'footer':   return { type: 'footer', text: '© {{anno}} {{business_name}}' };
                }
            }

            function blockFields(b, i) {
                switch (b.type) {
                    case 'logo':
                        return '<div class="field-row">' + alignSelect(b, i)
                            + '<input type="number" data-f="width" data-i="' + i + '" value="' + (b.width || 150) + '" min="40" max="400" step="10" style="max-width:120px;flex:none;" title="Larghezza in pixel"></div>'
                            + '<span class="tip">Larghezza in pixel (40–400). L\'altezza si adatta da sola per mantenere le proporzioni originali dell\'immagine.</span>';
                    case 'heading':
                        return '<div class="field-row"><input type="text" data-f="text" data-i="' + i + '" value="' + escAttr(b.text || '') + '" placeholder="Ciao {{nome}}!" maxlength="200"></div>'
                            + '<div class="field-row">' + alignSelect(b, i) + '<input type="color" data-f="color" data-i="' + i + '" value="' + (b.color || '#2d3142') + '" title="Colore testo"></div>';
                    case 'text':
                        return '<div class="field-row"><textarea data-f="text" data-i="' + i + '" maxlength="1000" style="min-height:60px;">' + escHtml(b.text || '') + '</textarea></div>'
                            + '<div class="field-row">' + alignSelect(b, i) + '<input type="color" data-f="color" data-i="' + i + '" value="' + (b.color || '#4f5d75') + '" title="Colore testo"></div>';
                    case 'gift_box':
                        return '<div class="field-row">'
                            + '<input type="text" data-f="label" data-i="' + i + '" value="' + escAttr(b.label || '') + '" placeholder="Per l\'evento:" maxlength="100">'
                            + '<input type="text" data-f="button_text" data-i="' + i + '" value="' + escAttr(b.button_text || '') + '" placeholder="Usa Sconto" maxlength="60">'
                            + '</div><span class="tip">Colore ripreso dal "Colore Principale" qui sopra. Va sempre al posto del codice sconto, uno per ogni regalo.</span>';
                    case 'divider':
                        return '<span class="tip">Una riga sottile per separare le sezioni, nessuna impostazione.</span>';
                    case 'spacer':
                        return '<div class="field-row"><input type="number" data-f="height" data-i="' + i + '" value="' + (b.height || 20) + '" min="4" max="120" style="max-width:120px;flex:none;"><span class="tip" style="align-self:center;">altezza in pixel</span></div>';
                    case 'footer':
                        return '<div class="field-row"><textarea data-f="text" data-i="' + i + '" maxlength="300" style="min-height:50px;">' + escHtml(b.text || '') + '</textarea></div><span class="tip">Segnaposto disponibili: {{anno}} {{business_name}}</span>';
                    default:
                        return '';
                }
            }

            function renderBlockCard(b, i) {
                return '<div class="block-card">'
                    + '<div class="block-card-head">'
                    + '<span class="block-card-title">' + (BLOCK_LABELS[b.type] || b.type) + '</span>'
                    + '<span class="block-card-actions">'
                    + '<button type="button" data-act="up" data-i="' + i + '"' + (i === 0 ? ' disabled' : '') + ' title="Sposta su">↑</button>'
                    + '<button type="button" data-act="down" data-i="' + i + '"' + (i === blocks.length - 1 ? ' disabled' : '') + ' title="Sposta giù">↓</button>'
                    + '<button type="button" class="del" data-act="del" data-i="' + i + '" title="Elimina">🗑</button>'
                    + '</span></div>'
                    + blockFields(b, i)
                    + '</div>';
            }

            function updateAddMenu() {
                const hasLogo = blocks.some(b => b.type === 'logo');
                const hasGift = blocks.some(b => b.type === 'gift_box');
                document.querySelectorAll('.block-add-menu button[data-add]').forEach(btn => {
                    const t = btn.getAttribute('data-add');
                    btn.disabled = (t === 'logo' && hasLogo) || (t === 'gift_box' && hasGift);
                });
            }

            function syncHidden() {
                document.getElementById('blocks_json_input').value = JSON.stringify(blocks);
            }

            function renderList() {
                listEl.innerHTML = blocks.map(renderBlockCard).join('') || '<p class="tip">Nessun blocco: aggiungine uno qui sotto.</p>';
                updateAddMenu();
                syncHidden();
            }

            let previewTimer = null;
            function schedulePreview() {
                clearTimeout(previewTimer);
                previewTimer = setTimeout(runPreview, 400);
            }
            async function runPreview() {
                const frame = document.getElementById('block-preview-frame');
                if (!frame || editorMode !== 'visual') return;
                const form = new FormData();
                form.append('action', 'preview_blocks');
                form.append('csrf_token', CSRF);
                form.append('blocks_json', JSON.stringify(blocks));
                form.append('colore', document.getElementById('tpl_colore').value);
                try {
                    const res = await fetch('dashboard.php', { method: 'POST', body: form });
                    frame.srcdoc = await res.text();
                } catch (e) { /* anteprima non disponibile, non blocca la modifica */ }
            }

            listEl.addEventListener('input', function(e) {
                const f = e.target.getAttribute('data-f'), i = e.target.getAttribute('data-i');
                if (f === null || i === null) return;
                blocks[+i][f] = (e.target.type === 'number') ? (parseInt(e.target.value, 10) || 0) : e.target.value;
                syncHidden();
                schedulePreview();
            });
            listEl.addEventListener('click', function(e) {
                const btn = e.target.closest('button[data-act]');
                if (!btn) return;
                const act = btn.getAttribute('data-act'), i = +btn.getAttribute('data-i');
                if (act === 'del') blocks.splice(i, 1);
                else if (act === 'up' && i > 0) [blocks[i - 1], blocks[i]] = [blocks[i], blocks[i - 1]];
                else if (act === 'down' && i < blocks.length - 1) [blocks[i + 1], blocks[i]] = [blocks[i], blocks[i + 1]];
                renderList();
                schedulePreview();
            });
            document.querySelectorAll('.block-add-menu button[data-add]').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (btn.disabled) return;
                    blocks.push(newBlock(btn.getAttribute('data-add')));
                    renderList();
                    schedulePreview();
                });
            });
            document.getElementById('tpl_colore').addEventListener('input', schedulePreview);

            function setMode(mode) {
                if (mode === 'visual' && editorMode === 'code') {
                    if (!confirm('Passando all\'Editor Visivo, al salvataggio l\'HTML scritto a mano verrà sostituito da un template generato dai blocchi. Continuare?')) return;
                    if (!blocks || !blocks.length) blocks = JSON.parse(JSON.stringify(DEFAULT_BLOCKS));
                }
                editorMode = mode;
                document.getElementById('editor_mode_input').value = mode;
                document.getElementById('mode-btn-visual').classList.toggle('active', mode === 'visual');
                document.getElementById('mode-btn-code').classList.toggle('active', mode === 'code');
                document.getElementById('visual-editor-box').hidden = mode !== 'visual';
                document.getElementById('code-editor-box').hidden = mode !== 'code';
                if (mode === 'visual') { renderList(); runPreview(); }
            }
            document.getElementById('mode-btn-visual').addEventListener('click', () => setMode('visual'));
            document.getElementById('mode-btn-code').addEventListener('click', () => setMode('code'));
            document.getElementById('mode-btn-visual').classList.toggle('active', editorMode === 'visual');
            document.getElementById('mode-btn-code').classList.toggle('active', editorMode === 'code');

            renderList();
            if (editorMode === 'visual') runPreview();
        })();
        </script>

        <h3>Invia Email di Test</h3>
        <form method="POST">
            <input type="hidden" name="action"     value="test_email_template">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Template</label>
                    <select name="lingua">
                        <?php foreach ($email_templates as $lingua => $tpl): ?>
                            <option value="<?= h($lingua) ?>"><?= h($tpl['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Indirizzo destinatario</label>
                    <input type="email" name="test_email" value="<?= h($conf['smtp_user']) ?>" required>
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <button type="submit" class="btn-info">📧 Invia con dati di esempio</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>🔑 La tua password (<?= h($current_username) ?>)</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Chiunque può cambiare la propria password, indipendentemente dal ruolo.</p>
        <form method="POST">
            <input type="hidden" name="action"     value="change_own_password">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Nuova Password (lascia vuoto per non cambiare)</label>
                    <input type="password" name="new_password" placeholder="Minimo 10 caratteri" autocomplete="new-password">
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <button type="submit" class="btn-secondary">Cambia Password</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($is_admin): ?>
    <div class="card">
        <h2>👥 Utenti Dashboard</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Ogni utente ha le proprie credenziali; le azioni compiute vengono registrate nel log di audit (tab Log) con nome utente e IP. Un utente "sola lettura" può vedere tutto ma non modificare nulla.</p>
        <table>
            <thead><tr><th>Utente</th><th>Ruolo</th><th>Creato il</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $uname => $u):
                $u_role = $u['role'] ?? 'admin';
            ?>
                <tr>
                    <td><strong><?= h($uname) ?></strong><?= $uname === $current_username ? ' <span class="badge" style="cursor:default;">tu</span>' : '' ?></td>
                    <td><span class="badge" style="cursor:default;<?= $u_role === 'viewer' ? 'color:#64748b;' : '' ?>"><?= $u_role === 'admin' ? '⚙ admin' : '👁 sola lettura' ?></span></td>
                    <td style="color:#64748b;"><?= !empty($u['created_at']) ? date('d/m/Y H:i', $u['created_at']) : '—' ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($uname !== $current_username): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action"   value="toggle_user_role">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="username"  value="<?= h($uname) ?>">
                            <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:6px 10px;margin-right:6px;"><?= $u_role === 'admin' ? '→ rendi sola lettura' : '→ rendi admin' ?></button>
                        </form>
                        <?php endif; ?>
                        <?php if ($uname !== $current_username && count($users) > 1): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminare l\'utente <?= h(addslashes($uname)) ?>?')">
                            <input type="hidden" name="action"   value="delete_user">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="username"  value="<?= h($uname) ?>">
                            <button type="submit" class="del-btn">&times;</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h3>Nuovo Utente</h3>
        <form method="POST">
            <input type="hidden" name="action"     value="add_user">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Nome utente</label>
                    <input type="text" name="new_username" placeholder="es. marco" pattern="[a-zA-Z0-9_.\-]{3,32}" required>
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="new_user_password" placeholder="Minimo 10 caratteri" autocomplete="new-password" required>
                </div>
                <div class="input-group">
                    <label>Ruolo</label>
                    <select name="new_user_role">
                        <option value="admin">Amministratore</option>
                        <option value="viewer">Sola lettura</option>
                    </select>
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <button type="submit" class="btn-secondary">+ Crea Utente</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>📧 Test Email</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Verifica che le impostazioni SMTP siano corrette inviando un'email di prova.</p>
        <form method="POST">
            <input type="hidden" name="action"     value="test_smtp">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Indirizzo destinatario</label>
                    <input type="email" name="test_email" value="<?= h($conf['smtp_user']) ?>" required>
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <button type="submit" class="btn-info">📧 Invia Email di Test</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php /* ══════════════ LOG ══════════════ */ elseif ($active_tab==='log'): ?>

    <?php
    // Archivi disponibili
    $archive_files = glob(dirname(LOG_FILE) . '/webhook_log_*.txt') ?: [];
    rsort($archive_files);

    // File selezionato (sanitizzato: solo YYYY_MM)
    $sel_archive = preg_match('/^\d{4}_\d{2}$/', $_GET['logfile'] ?? '') ? $_GET['logfile'] : '';
    $log_target  = $sel_archive
        ? dirname(LOG_FILE) . '/webhook_log_' . $sel_archive . '.txt'
        : LOG_FILE;

    $log_lines = [];
    $stats = ['sconti' => 0, 'email' => 0, 'errori' => 0];
    $daily_stats = []; // 'YYYY-MM-DD' => ['sconti'=>,'email'=>,'errori'=>]
    if (file_exists($log_target) && is_readable($log_target)) {
        $all_lines = file($log_target, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($all_lines as $line) {
            $day = null;
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2})/', $line, $m)) $day = $m[1];
            if ($day !== null && !isset($daily_stats[$day])) $daily_stats[$day] = ['sconti' => 0, 'email' => 0, 'errori' => 0];

            if (stripos($line, 'Sconto creato') !== false) {
                $stats['sconti']++;
                if ($day !== null) $daily_stats[$day]['sconti']++;
            } elseif (stripos($line, 'Email inviata') !== false) {
                $stats['email']++;
                if ($day !== null) $daily_stats[$day]['email']++;
            } elseif (stripos($line, 'ERRORE') !== false) {
                $stats['errori']++;
                if ($day !== null) $daily_stats[$day]['errori']++;
            }
        }
        krsort($daily_stats); // più recente in cima
        $log_lines = array_reverse(array_slice($all_lines, -500));
    }

    $log_period_label = $sel_archive
        ? (DateTimeImmutable::createFromFormat('Y_m', $sel_archive)?->format('F Y') ?? $sel_archive)
        : 'Corrente';
    ?>

    <div style="margin-bottom:20px;display:flex;align-items:center;gap:12px;">
        <label style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;white-space:nowrap;">Periodo</label>
        <form method="GET" style="display:contents;">
            <input type="hidden" name="tab" value="log">
            <select name="logfile" onchange="this.form.submit()" style="padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;background:white;cursor:pointer;">
                <option value="">Log corrente</option>
                <?php foreach ($archive_files as $af):
                    preg_match('/webhook_log_(\d{4}_\d{2})\.txt$/', $af, $m);
                    if (empty($m[1])) continue;
                    $key   = $m[1];
                    $label = DateTimeImmutable::createFromFormat('Y_m', $key)?->format('F Y') ?? $key;
                ?>
                <option value="<?= h($key) ?>" <?= $sel_archive === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if (empty($archive_files)): ?>
            <span style="font-size:12px;color:#94a3b8;">Gli archivi dei mesi precedenti appariranno qui automaticamente.</span>
        <?php endif; ?>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-val"><?= $stats['sconti'] ?></div><div class="stat-lbl">Sconti Creati</div></div>
        <div class="stat-card"><div class="stat-val"><?= $stats['email']  ?></div><div class="stat-lbl">Email Inviate</div></div>
        <div class="stat-card err"><div class="stat-val"><?= $stats['errori'] ?></div><div class="stat-lbl">Errori</div></div>
    </div>

    <?php if (!empty($daily_stats)): ?>
    <div class="card">
        <h2>📊 Statistiche giornaliere <?= h($log_period_label) ?></h2>
        <table>
            <thead><tr><th>Giorno</th><th>Sconti Creati</th><th>Email Inviate</th><th>Errori</th></tr></thead>
            <tbody>
            <?php foreach ($daily_stats as $day => $ds): ?>
                <tr>
                    <td><?= h(DateTimeImmutable::createFromFormat('Y-m-d', $day)?->format('d/m/Y') ?? $day) ?></td>
                    <td><?= $ds['sconti'] ?></td>
                    <td><?= $ds['email'] ?></td>
                    <td style="<?= $ds['errori'] > 0 ? 'color:#ef4444;font-weight:700;' : '' ?>"><?= $ds['errori'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>📋 Log <?= h($log_period_label) ?> <span style="font-size:13px;font-weight:400;color:#94a3b8;">(ultimi 500, più recenti in cima)</span></h2>
        <?php if (empty($log_lines)): ?>
            <p style="color:#94a3b8;">Nessun log disponibile per questo periodo.</p>
        <?php else: ?>
            <div class="log-box"><?php
                foreach ($log_lines as $line) {
                    $cls = stripos($line, 'ERRORE') !== false ? 'log-err' : 'log-info';
                    echo '<span class="'.$cls.'">'.h($line)."</span>\n";
                }
            ?></div>
        <?php endif; ?>
    </div>

    <?php
    $recent_orders = list_recent_processed_orders(25);
    $failed_orders = load_failed_orders();
    ?>
    <?php if (!empty($failed_orders)): ?>
    <div class="card" style="border-top:4px solid #ef4444;">
        <h2>⚠️ Ordini in coda da ritentare (<?= count($failed_orders) ?>)</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Ordini per cui almeno uno sconto o l'invio email non sono ancora andati a buon fine dopo i tentativi automatici. Il ritentativo riprende solo la parte mancante: non ricrea sconti già ottenuti né duplica email già inviate.</p>
        <table>
            <thead><tr><th>Order ID</th><th>Da quando</th><th>Motivo</th></tr></thead>
            <tbody>
            <?php foreach ($failed_orders as $foid => $fv): ?>
                <tr>
                    <td><span class="badge"><?= h($foid) ?></span></td>
                    <td style="color:#64748b;"><?= date('d/m/Y H:i:s', $fv['ts'] ?? 0) ?></td>
                    <td style="color:#991b1b;"><?= h($fv['reason'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($is_admin): ?>
        <form method="POST" style="margin-top:16px;">
            <input type="hidden" name="action"     value="retry_failed_orders">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button type="submit" class="btn-warning">🔄 Riprova ordini falliti</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($recent_orders)): ?>
    <div class="card">
        <h2>📦 Ordini Processati di Recente <a href="?action=export_orders_csv" class="btn btn-secondary" style="float:right;font-size:12px;padding:8px 14px;">⬇ Esporta CSV</a></h2>
        <table>
            <thead><tr><th>Order ID</th><th>Data / Ora</th><th>Stato</th><th>Sconti tracciati</th></tr></thead>
            <tbody>
            <?php foreach ($recent_orders as $oid => $v): ?>
                <tr>
                    <td><span class="badge"><?= h($oid) ?></span></td>
                    <td style="color:#64748b;"><?= date('d/m/Y H:i:s', $v['ts']) ?></td>
                    <td><?= $v['status'] === 'partial' ? '<span style="color:#f59e0b;font-weight:700;">⚠ parziale</span>' : ($v['status'] === 'complete' ? '<span style="color:#10b981;">✓ completo</span>' : '—') ?></td>
                    <td style="color:#64748b;"><?= count($v['discounts']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php
    $audit_lines = [];
    if (file_exists(AUDIT_LOG_FILE) && is_readable(AUDIT_LOG_FILE)) {
        $audit_lines = array_reverse(array_slice(file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -200));
    }
    ?>
    <?php if (!empty($audit_lines)): ?>
    <div class="card">
        <h2>🕵️ Audit Log <span style="font-size:13px;font-weight:400;color:#94a3b8;">(ultime 200 azioni, chi ha fatto cosa)</span></h2>
        <div class="log-box"><?php foreach ($audit_lines as $line) echo h($line) . "\n"; ?></div>
    </div>
    <?php endif; ?>

    <?php /* ══════════════ GUIDA ══════════════ */ elseif ($active_tab==='guida'): ?>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:20px;">
        <a href="riepilogo.php" target="_blank" class="btn btn-secondary" style="font-size:13px;padding:10px 18px;">📄 Riepilogo PDF</a>
        <a href="?action=health" target="_blank" class="btn btn-secondary" style="font-size:13px;padding:10px 18px;">🔍 Health Check</a>
    </div>

    <div class="card">
        <h2>📖 Setup & Help</h2>
        <p><strong>1.</strong> Configura Token API e SMTP in "Configurazione".<br>
           <strong>2.</strong> Copia il tuo Organization ID dalla lista qui sotto.<br>
           <strong>3.</strong> Incolla l'URL Webhook su Eventbrite.<br>
           <strong>4.</strong> Crea le regole nella tab "Regole Sconti".</p>
        <?php if (!empty($organizations)): ?>
        <h3>Organizzazioni</h3>
        <ul style="list-style:none;padding:0;">
            <?php foreach ($organizations as $o): ?>
                <li style="margin-bottom:10px;"><?= h($o['name'] ?? '') ?>: <span class="badge"><?= h($o['id'] ?? '') ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <h3>URL Webhook</h3>
        <code><?= h($webhook_url) ?></code>
        <?php if (empty($conf['webhook_token'])): ?><p style="color:#f59e0b;font-size:13px;margin-top:10px;">⚠️ Token non generato. Salva la configurazione.</p><?php endif; ?>
    </div>

    <?php if ($is_admin): ?>
    <div class="card">
        <h2>🧪 Simulazione Webhook</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Testa il flusso completo (API → sconto → email) con un ordine Eventbrite reale senza aspettare un acquisto.</p>
        <form method="POST">
            <input type="hidden" name="action"     value="simulate_webhook">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Order ID Eventbrite</label>
                    <input type="text" name="sim_order_id" placeholder="Es. 1234567890" required>
                    <span class="tip">Trovi l'ID ordine nell'URL della pagina ordine su Eventbrite.</span>
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <button type="submit" class="btn-info" onclick="return confirm('Questo creerà sconti reali su Eventbrite e invierà email reali. Continuare?')">▶ Simula Ordine</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>🔄 Rigenera Token Webhook</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Il vecchio URL webhook diventerà <strong>invalido</strong>: aggiornalo subito su Eventbrite dopo la rigenerazione.</p>
        <form method="POST" onsubmit="return confirm('Il vecchio URL diventerà invalido. Continuare?')">
            <input type="hidden" name="action"     value="regenerate_token">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button type="submit" class="btn-secondary">🔄 Rigenera Token</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>📥 Import / Export Regole</h2>
        <a href="?action=export_regole" class="btn btn-secondary" style="margin-bottom:25px;">⬇ Esporta regole JSON</a>
        <a href="?action=export_regole_csv" class="btn btn-secondary" style="margin-bottom:25px;margin-left:10px;">⬇ Esporta regole CSV</a>

        <?php if ($is_admin): ?>
        <h3>Importa Regole (JSON)</h3>
        <p style="color:#f59e0b;font-size:13px;">⚠️ L'importazione sovrascrive tutte le regole esistenti (un backup viene creato automaticamente).</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action"     value="import_regole">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group"><label>File JSON regole</label><input type="file" name="regole_file" accept="application/json,.json" required></div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;"><button type="submit">⬆ Importa</button></div>
            </div>
        </form>

        <h3>Importa Regole (CSV)</h3>
        <p style="color:#f59e0b;font-size:13px;">⚠️ Sovrascrive tutte le regole esistenti. Usa lo stesso formato dell'esportazione CSV (colonna <code style="display:inline;padding:1px 5px;">target_ids</code> con più ID separati da <code style="display:inline;padding:1px 5px;">|</code>).</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action"     value="import_regole_csv">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group"><label>File CSV regole</label><input type="file" name="regole_csv" accept="text/csv,.csv" required></div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;"><button type="submit">⬆ Importa CSV</button></div>
            </div>
        </form>
        <?php endif; ?>

        <?php
        $backup_dir     = __DIR__ . '/backups';
        $regole_backups = is_dir($backup_dir) ? (glob($backup_dir . '/regole_*.json') ?: []) : [];
        $config_backups = is_dir($backup_dir) ? (glob($backup_dir . '/config_*.json') ?: []) : [];
        $db_backups     = is_dir($backup_dir) ? (glob($backup_dir . '/database_*.sqlite') ?: []) : [];
        rsort($regole_backups);
        rsort($config_backups);
        rsort($db_backups);
        ?>
        <?php if (!empty($db_backups)): ?>
        <h3>Backup Database Completo</h3>
        <p class="tip" style="margin-top:-8px;margin-bottom:10px;">Copia integrale del database (config, regole, ordini, utenti), creata al massimo una volta al giorno.</p>
        <ul style="list-style:none;padding:0;margin:0;">
            <?php foreach (array_slice($db_backups, 0, 7) as $bk): ?>
                <li style="font-size:13px;padding:6px 0;border-bottom:1px solid #f1f5f9;color:#475569;">
                    🗄️ <?= h(basename($bk)) ?>
                    <span style="color:#94a3b8;margin-left:8px;"><?= date('d/m/Y H:i', filemtime($bk)) ?></span>
                    <span style="color:#94a3b8;margin-left:8px;"><?= round(filesize($bk) / 1024) ?> KB</span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($regole_backups)): ?>
        <h3>Backup Regole Sconti</h3>
        <ul style="list-style:none;padding:0;margin:0;">
            <?php foreach (array_slice($regole_backups, 0, 10) as $bk): ?>
                <li style="font-size:13px;padding:6px 0;border-bottom:1px solid #f1f5f9;color:#475569;">
                    📄 <?= h(basename($bk)) ?>
                    <span style="color:#94a3b8;margin-left:8px;"><?= date('d/m/Y H:i', filemtime($bk)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($config_backups)): ?>
        <h3>Backup Configurazione</h3>
        <p class="tip" style="margin-top:-8px;margin-bottom:10px;">Creato automaticamente ad ogni salvataggio della configurazione (contiene i segreti cifrati).</p>
        <ul style="list-style:none;padding:0;margin:0;">
            <?php foreach (array_slice($config_backups, 0, 10) as $bk): ?>
                <li style="font-size:13px;padding:6px 0;border-bottom:1px solid #f1f5f9;color:#475569;">
                    📄 <?= h(basename($bk)) ?>
                    <span style="color:#94a3b8;margin-left:8px;"><?= date('d/m/Y H:i', filemtime($bk)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</main>

<script>
function cp(id) {
    let t = document.getElementById('f_t'), r = document.getElementById('f_r');
    if (t && !t.value) t.value = id;
    else if (r) r.value = r.value ? r.value + ', ' + id : id;
}
function toggleTipo(val) {
    document.getElementById('grp-perc').style.display = val === 'importo' ? 'none' : '';
    document.getElementById('grp-imp').style.display  = val === 'importo' ? '' : 'none';
}
function syncPort(enc) {
    const p = document.getElementById('smtp_port');
    if (p && (p.value === '465' || p.value === '587')) p.value = enc === 'tls' ? '587' : '465';
}
(function() {
    const ts = document.getElementById('tipo_sconto');
    if (ts) toggleTipo(ts.value);
})();
</script>
</body>
</html>

<?php
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
    $free     = disk_free_space(__DIR__);
    $total    = disk_total_space(__DIR__);
    $free_gb  = round($free / 1073741824, 2);
    $pct_used = $total > 0 ? round(($total - $free) / $total * 100) : 0;
    $disk_ok  = $free > 50 * 1048576;
    $checks[] = [
        'label'  => 'Spazio su Disco',
        'ok'     => $disk_ok,
        'detail' => "{$free_gb} GB liberi — {$pct_used}% utilizzato" . (!$disk_ok ? ' ⚠️ Spazio insufficiente.' : ''),
    ];

    // 5. Permessi file system
    $dir_ok = is_writable(__DIR__);
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
