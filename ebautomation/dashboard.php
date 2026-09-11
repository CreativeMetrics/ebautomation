<?php
use PHPMailer\PHPMailer\PHPMailer as Mailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/functions.php';

send_security_headers();

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
            add_user_row('admin', password_hash($pwd, PASSWORD_DEFAULT));
            $_SESSION['authenticated'] = true;
            $_SESSION['username']      = 'admin';
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
    render_login($csrf, $login_error, $brand_name, $login_blocked);
    exit;
}
$current_username = $_SESSION['username'];

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

// ── AZIONI POST ───────────────────────────────────────────────────────────────
$flash_error = $_SESSION['flash_error'] ?? '';
$flash_ok    = $_SESSION['flash_ok']    ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { http_response_code(403); die('Token CSRF non valido.'); }

    switch ($_POST['action'] ?? '') {

        case 'save_config':
            $new_pwd = $_POST['new_password'] ?? '';
            $enc     = in_array($_POST['smtp_encryption'] ?? '', ['smtps','tls']) ? $_POST['smtp_encryption'] : $conf['smtp_encryption'];
            $color   = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['email_color'] ?? '') ? $_POST['email_color'] : $conf['email_color'];
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
                'email_subject'   => trim($_POST['email_subject']   ?? $conf['email_subject']),
                'email_intro'     => trim($_POST['email_intro']     ?? $conf['email_intro']),
                'email_greeting'  => trim($_POST['email_greeting']  ?? $conf['email_greeting']),
                'email_color'     => $color,
                'alert_email'     => trim($_POST['alert_email']     ?? $conf['alert_email']),
                'alert_threshold' => max(1, (int)($_POST['alert_threshold'] ?? $conf['alert_threshold'])),
            ]);
            if ($new_pwd !== '') {
                // Cambia la password dell'utente attualmente loggato (la gestione
                // degli altri utenti è nella sezione "Utenti" più sotto).
                if ($issue = password_issue($new_pwd)) {
                    $_SESSION['flash_error'] = $issue;
                    header('Location: dashboard.php?tab=config');
                    exit;
                }
                set_user_password($current_username, password_hash($new_pwd, PASSWORD_DEFAULT));
                audit_log('Password personale cambiata');
            }
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

        case 'add_user':
            $new_uname = trim($_POST['new_username'] ?? '');
            $new_upwd  = $_POST['new_user_password'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $new_uname)) {
                $_SESSION['flash_error'] = 'Nome utente non valido: usa 3-32 caratteri (lettere, numeri, . _ -).';
            } elseif (isset($users[$new_uname])) {
                $_SESSION['flash_error'] = 'Esiste già un utente con questo nome.';
            } elseif ($issue = password_issue($new_upwd)) {
                $_SESSION['flash_error'] = $issue;
            } else {
                add_user_row($new_uname, password_hash($new_upwd, PASSWORD_DEFAULT));
                audit_log('Utente creato', $new_uname);
                $_SESSION['flash_ok'] = "Utente \"$new_uname\" creato.";
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'delete_user':
            $del_uname = trim($_POST['username'] ?? '');
            if (count($users) <= 1) {
                $_SESSION['flash_error'] = 'Non puoi eliminare l\'unico utente rimasto.';
            } elseif ($del_uname === $current_username) {
                $_SESSION['flash_error'] = 'Non puoi eliminare l\'utente con cui hai effettuato l\'accesso.';
            } elseif (isset($users[$del_uname])) {
                delete_user_row($del_uname);
                audit_log('Utente eliminato', $del_uname);
                $_SESSION['flash_ok'] = "Utente \"$del_uname\" eliminato.";
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
                $bname_t   = $conf['business_name'] ?: 'La nostra Azienda';
                $subject_t = str_replace('{{business_name}}', $bname_t, $conf['email_subject'] ?: "I tuoi regali da $bname_t");
                $greeting_t = str_replace('{{nome}}', 'Cliente', $conf['email_greeting'] ?: 'Ciao {{nome}}!');
                $intro_t   = $conf['email_intro']   ?: 'Grazie per i tuoi acquisti. Ecco i regali che abbiamo riservato per te:';
                $color_t   = $conf['email_color']   ?: '#D64545';

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
                $mail->Subject = '[TEST] ' . $subject_t;
                $mail->isHTML(true);

                $logo_path = __DIR__ . '/logo.png';
                $logo_html = file_exists($logo_path) ? '' : '<h1 style="color:#2d3142;">' . h($bname_t) . '</h1>';
                if (file_exists($logo_path)) {
                    $mail->addEmbeddedImage($logo_path, 'logo_cid');
                    $logo_html = '<img src="cid:logo_cid" style="max-width:150px;margin-bottom:20px;">';
                }

                $mail->Body = '
                <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #eee;">
                    <div style="text-align:center;">' . $logo_html . '</div>
                    <h2 style="color:#2d3142;text-align:center;">' . h($greeting_t) . '</h2>
                    <p style="text-align:center;color:#4f5d75;">' . h($intro_t) . '</p>
                    <div style="background:#f3f3f3;border:1px solid #ddd;padding:15px;margin-bottom:15px;border-radius:8px;text-align:center;">
                        <p style="color:#666;font-size:13px;margin:0;">Per l\'evento: <b>Evento di Esempio</b></p>
                        <p style="color:' . h($color_t) . ';font-size:24px;font-weight:bold;margin:10px 0;">GIFT-PREVIEW</p>
                        <a href="#" style="display:inline-block;background:' . h($color_t) . ';color:white;padding:10px 20px;text-decoration:none;border-radius:5px;">Usa Sconto 100%</a>
                    </div>
                    <p style="font-size:11px;color:#aaa;text-align:center;margin-top:30px;">&copy; ' . date('Y') . ' ' . h($bname_t) . '</p>
                </div>';
                $mail->AltBody = "$greeting_t $intro_t (Codice di esempio: GIFT-PREVIEW)";
                $mail->send();
                $_SESSION['flash_ok'] = "Email di test inviata a $to con il template attuale.";
            } catch (MailException $e) {
                $_SESSION['flash_error'] = 'Errore SMTP: ' . $mail->ErrorInfo;
            }
            header('Location: dashboard.php?tab=config');
            exit;

        case 'test_smtp_preview':
            $is_json  = ($_POST['_format'] ?? '') === 'json';
            $to       = trim($_POST['preview_test_email'] ?? $conf['smtp_user']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $err = 'Indirizzo email non valido.';
                if ($is_json) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'msg' => $err]); exit; }
                $_SESSION['flash_error'] = $err; header('Location: dashboard.php?tab=config'); exit;
            }
            $pb    = trim($_POST['business_name']  ?? $conf['business_name'])  ?: 'La nostra Azienda';
            $ps    = str_replace('{{business_name}}', $pb, trim($_POST['email_subject']  ?? $conf['email_subject'])  ?: "I tuoi regali da $pb");
            $pg    = str_replace('{{nome}}', 'Cliente', trim($_POST['email_greeting'] ?? $conf['email_greeting']) ?: 'Ciao {{nome}}!');
            $pi    = trim($_POST['email_intro']    ?? $conf['email_intro']);
            $pc    = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['email_color'] ?? '') ? $_POST['email_color'] : $conf['email_color'];
            require_once __DIR__ . '/PHPMailer/Exception.php';
            require_once __DIR__ . '/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/PHPMailer/SMTP.php';
            $mail = new Mailer(true);
            try {
                $mail->isSMTP(); $mail->Host = $conf['smtp_host']; $mail->SMTPAuth = true;
                $mail->Username = $conf['smtp_user']; $mail->Password = $conf['smtp_pass'];
                $mail->SMTPSecure = ($conf['smtp_encryption'] === 'tls') ? Mailer::ENCRYPTION_STARTTLS : Mailer::ENCRYPTION_SMTPS;
                $mail->Port = (int)$conf['smtp_port']; $mail->CharSet = 'UTF-8';
                $mail->setFrom($conf['smtp_user'], $pb);
                $mail->addAddress($to);
                $mail->Subject = '[ANTEPRIMA] ' . $ps;
                $mail->isHTML(true);
                $logo_path = __DIR__ . '/logo.png';
                $logo_html = file_exists($logo_path) ? '' : '<h1 style="color:#2d3142;">' . h($pb) . '</h1>';
                if (file_exists($logo_path)) { $mail->addEmbeddedImage($logo_path, 'logo_cid'); $logo_html = '<img src="cid:logo_cid" style="max-width:150px;margin-bottom:20px;">'; }
                $mail->Body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #eee;">
                    <div style="text-align:center;">' . $logo_html . '</div>
                    <h2 style="color:#2d3142;text-align:center;">' . h($pg) . '</h2>
                    <p style="text-align:center;color:#4f5d75;">' . h($pi) . '</p>
                    <div style="background:#f3f3f3;border:1px solid #ddd;padding:15px;border-radius:8px;text-align:center;margin-bottom:15px;">
                        <p style="color:#666;font-size:13px;margin:0;">Per l\'evento: <b>Evento di Esempio</b></p>
                        <p style="color:' . h($pc) . ';font-size:24px;font-weight:bold;margin:10px 0;">GIFT-PREVIEW</p>
                        <a href="#" style="display:inline-block;background:' . h($pc) . ';color:white;padding:10px 20px;text-decoration:none;border-radius:5px;">Usa Sconto 100%</a>
                    </div>
                    <p style="font-size:11px;color:#aaa;text-align:center;margin-top:30px;">&copy; ' . date('Y') . ' ' . h($pb) . '</p></div>';
                $mail->AltBody = "$pg $pi";
                $mail->send();
                $ok_msg = "Email di anteprima inviata a $to.";
                if ($is_json) { header('Content-Type: application/json'); echo json_encode(['ok' => true, 'msg' => $ok_msg]); exit; }
                $_SESSION['flash_ok'] = $ok_msg;
            } catch (MailException $e) {
                $err = 'Errore SMTP: ' . $mail->ErrorInfo;
                if ($is_json) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'msg' => $err]); exit; }
                $_SESSION['flash_error'] = $err;
            }
            header('Location: dashboard.php?tab=config'); exit;

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
$regole     = load_regole();
$events     = [];
$organizations = [];

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

$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url    = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/';
$webhook_url      = $base_url . 'eventbrite-webhook.php' . ($conf['webhook_token'] ? '?token=' . h($conf['webhook_token']) : '');
$logo_preview_url = file_exists(__DIR__ . '/logo.png') ? rtrim($base_url, '/') . '/logo.png' : null;

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
        .modal-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); z-index:1000; display:none; justify-content:center; align-items:center; }
        .modal-box { background:white; border-radius:12px; max-width:680px; width:95%; max-height:92vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,0.25); }
        .modal-head { display:flex; justify-content:space-between; align-items:flex-start; padding:18px 24px; border-bottom:1px solid #f1f5f9; flex-shrink:0; }
        .modal-close { background:none; border:none; font-size:28px; cursor:pointer; color:#94a3b8; padding:0; line-height:1; }
        .modal-close:hover { color:#334155; }
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
            <thead><tr><th>Trigger</th><th>Descrizione</th><th>Sconto</th><th>Qtà</th><th>Min. trigger</th><th>Scade</th><th>Target</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($regole as $tid => $r):
                $is_imp = ($r['tipo_sconto'] ?? 'percentuale') === 'importo';
                $sconto_label = $is_imp ? h((string)($r['importo_fisso'] ?? 0)) . ' ' . h($conf['currency'] ?: 'EUR') : h($r['percentuale']) . '%';
            ?>
            <tr>
                <td><span class="badge"><?= h($tid) ?></span></td>
                <td><strong><?= h($r['descrizione']) ?></strong></td>
                <td><?= $sconto_label ?></td>
                <td><?= h((string)($r['quantita'] ?? 1)) ?></td>
                <td><?= ($r['qty_minima'] ?? 1) > 1 ? h((string)$r['qty_minima']) . ' biglietti' : '—' ?></td>
                <td><?= ($r['giorni_scadenza'] ?? 0) > 0 ? h((string)$r['giorni_scadenza']) . ' gg' : '—' ?></td>
                <td><?php foreach ($r['target_ids'] as $t) echo '<span class="badge">'.h($t).'</span> '; ?></td>
                <td style="white-space:nowrap;">
                    <a href="?tab=sconti&edit=<?= urlencode($tid) ?>" style="color:#0ea5e9;text-decoration:none;font-weight:700;margin-right:8px;">✏</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminare questa regola?')">
                        <input type="hidden" name="action"     value="delete_regola">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="trigger_id" value="<?= h($tid) ?>">
                        <button type="submit" class="del-btn">&times;</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($regole)): ?><tr><td colspan="8" style="color:#94a3b8;text-align:center;padding:30px;">Nessuna regola. Creane una qui sotto.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

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
        <form method="POST">
            <input type="hidden" name="action"     value="toggle_pause">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button type="submit" class="<?= $conf['paused'] ? 'btn-success' : 'btn-warning' ?>">
                <?= $conf['paused'] ? '▶ Riattiva Automazioni' : '⏸ Metti in Pausa' ?>
            </button>
        </form>
    </div>

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

            <h3>Template Email</h3>
            <div class="grid">
                <div class="input-group" style="grid-column:1/-1;">
                    <label>Oggetto</label>
                    <input type="text" name="email_subject" value="<?= h($conf['email_subject']) ?>">
                    <span class="tip">Usa <code style="display:inline;padding:1px 5px;">{{business_name}}</code> per inserire il nome azienda.</span>
                </div>
            </div>
            <div class="grid">
                <div class="input-group" style="grid-column:1/-1;">
                    <label>Saluto</label>
                    <input type="text" name="email_greeting" value="<?= h($conf['email_greeting']) ?>">
                    <span class="tip">Usa <code style="display:inline;padding:1px 5px;">{{nome}}</code> per il nome del cliente recuperato da Eventbrite. Es: <em>Ciao {{nome}}!</em> · <em>Gentile {{nome}},</em></span>
                </div>
            </div>
            <div class="grid">
                <div class="input-group" style="grid-column:1/-1;">
                    <label>Testo Introduttivo</label>
                    <textarea name="email_intro"><?= h($conf['email_intro']) ?></textarea>
                </div>
            </div>
            <div class="grid">
                <div class="input-group">
                    <label>Colore Principale</label>
                    <input type="color" name="email_color" value="<?= h($conf['email_color']) ?>">
                </div>
            </div>

            <div style="margin:16px 0 4px;">
                <button type="button" onclick="showPreview()" class="btn-info">👁 Anteprima Email</button>
                <span class="tip" style="margin-left:12px;">Mostra come appare l'email al cliente con i valori attuali del form.</span>
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

            <h3>La tua password (<?= h($current_username) ?>)</h3>
            <div class="grid">
                <div class="input-group">
                    <label>Nuova Password (lascia vuoto per non cambiare)</label>
                    <input type="password" name="new_password" placeholder="Minimo 10 caratteri" autocomplete="new-password">
                </div>
            </div>
            <button type="submit">Salva Configurazione</button>
        </form>
    </div>

    <div class="card">
        <h2>👥 Utenti Dashboard</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Ogni utente ha le proprie credenziali; le azioni compiute vengono registrate nel log di audit (tab Log) con nome utente e IP.</p>
        <table>
            <thead><tr><th>Utente</th><th>Creato il</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $uname => $u): ?>
                <tr>
                    <td><strong><?= h($uname) ?></strong><?= $uname === $current_username ? ' <span class="badge" style="cursor:default;">tu</span>' : '' ?></td>
                    <td style="color:#64748b;"><?= !empty($u['created_at']) ? date('d/m/Y H:i', $u['created_at']) : '—' ?></td>
                    <td>
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
        <form method="POST" style="margin-top:16px;">
            <input type="hidden" name="action"     value="retry_failed_orders">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button type="submit" class="btn-warning">🔄 Riprova ordini falliti</button>
        </form>
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

    <div class="card">
        <h2>📥 Import / Export Regole</h2>
        <a href="?action=export_regole" class="btn btn-secondary" style="margin-bottom:25px;">⬇ Esporta regole JSON</a>

        <h3>Importa Regole</h3>
        <p style="color:#f59e0b;font-size:13px;">⚠️ L'importazione sovrascrive tutte le regole esistenti (un backup viene creato automaticamente).</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action"     value="import_regole">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group"><label>File JSON regole</label><input type="file" name="regole_file" accept="application/json,.json" required></div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;"><button type="submit">⬆ Importa</button></div>
            </div>
        </form>

        <?php
        $backup_dir     = __DIR__ . '/backups';
        $regole_backups = is_dir($backup_dir) ? (glob($backup_dir . '/regole_*.json') ?: []) : [];
        $config_backups = is_dir($backup_dir) ? (glob($backup_dir . '/config_*.json') ?: []) : [];
        rsort($regole_backups);
        rsort($config_backups);
        ?>
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

<input type="hidden" id="preview-csrf" value="<?= h($csrf) ?>">
<div id="preview-modal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <div style="font-size:11px;color:#64748b;text-transform:uppercase;font-weight:700;margin-bottom:5px;">Oggetto email</div>
                <div id="preview-subject" style="font-weight:600;font-size:15px;color:#334155;"></div>
            </div>
            <button class="modal-close" onclick="closePreview()" title="Chiudi">×</button>
        </div>
        <iframe id="preview-iframe" style="flex:1;border:none;width:100%;min-height:460px;" sandbox="allow-same-origin"></iframe>
        <div style="padding:14px 24px;border-top:1px solid #f1f5f9;background:#f8f9fa;display:flex;align-items:center;gap:10px;flex-shrink:0;">
            <input type="email" id="preview-test-email" placeholder="Email destinatario test" value="<?= h($conf['smtp_user']) ?>" style="padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;flex:1;min-width:0;">
            <button type="button" onclick="sendTestFromPreview()" style="background:#0ea5e9;color:white;border:none;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;white-space:nowrap;">📧 Invia Test</button>
            <span id="preview-test-status" style="font-size:13px;min-width:0;flex:1;"></span>
        </div>
    </div>
</div>

<script>
const PREVIEW_LOGO = <?= json_encode($logo_preview_url) ?>;
const PREVIEW_YEAR = <?= date('Y') ?>;

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
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,'&#39;');
}
function showPreview() {
    const sub      = document.querySelector('[name=email_subject]')?.value  || '';
    const intro    = document.querySelector('[name=email_intro]')?.value    || '';
    const greeting = document.querySelector('[name=email_greeting]')?.value || 'Ciao {{nome}}!';
    const raw      = document.querySelector('[name=email_color]')?.value    || '#D64545';
    const color    = /^#[0-9a-fA-F]{6}$/.test(raw) ? raw : '#D64545';
    const bname    = document.querySelector('[name=business_name]')?.value  || 'Azienda';
    const subj     = sub.replace('{{business_name}}', esc(bname));
    const greet    = esc(greeting.replace('{{nome}}', 'Cliente'));

    document.getElementById('preview-subject').textContent = subj || '(oggetto vuoto)';

    const logo = PREVIEW_LOGO
        ? `<img src="${PREVIEW_LOGO}" style="max-width:150px;margin-bottom:20px;">`
        : `<h1 style="color:#2d3142;margin:0 0 20px;">${esc(bname)}</h1>`;

    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:24px;background:#f8f9fa;font-family:Arial,sans-serif;">
<div style="max-width:560px;margin:0 auto;padding:24px;border:1px solid #eee;background:white;border-radius:6px;">
  <div style="text-align:center;margin-bottom:10px;">${logo}</div>
  <h2 style="color:#2d3142;text-align:center;margin:0 0 12px;">${greet}</h2>
  <p style="text-align:center;color:#4f5d75;margin:0 0 20px;">${esc(intro) || '&nbsp;'}</p>
  <div style="background:#f3f3f3;border:1px solid #ddd;padding:15px;border-radius:8px;text-align:center;margin-bottom:15px;">
    <p style="color:#666;font-size:13px;margin:0 0 6px;">Per l'evento: <b>Evento di Esempio</b></p>
    <p style="color:${esc(color)};font-size:26px;font-weight:bold;margin:10px 0;letter-spacing:2px;">GIFT-PREVIEW</p>
    <a href="#" style="display:inline-block;background:${esc(color)};color:white;padding:10px 22px;text-decoration:none;border-radius:5px;font-size:14px;">Usa Sconto 100%</a>
  </div>
  <p style="font-size:11px;color:#aaa;text-align:center;margin-top:24px;">&copy; ${PREVIEW_YEAR} ${esc(bname)}</p>
</div>
</body></html>`;

    document.getElementById('preview-iframe').srcdoc = html;
    document.getElementById('preview-test-status').textContent = '';
    document.getElementById('preview-modal').style.display = 'flex';
}
function closePreview() {
    document.getElementById('preview-modal').style.display = 'none';
}
async function sendTestFromPreview() {
    const to = document.getElementById('preview-test-email').value.trim();
    const status = document.getElementById('preview-test-status');
    if (!to) { status.textContent = '⚠️ Inserisci un indirizzo email.'; status.style.color = '#991b1b'; return; }
    status.textContent = '⏳ Invio in corso…'; status.style.color = '#64748b';
    const form = new FormData();
    form.append('action',              'test_smtp_preview');
    form.append('_format',             'json');
    form.append('csrf_token',          document.getElementById('preview-csrf')?.value || '');
    form.append('preview_test_email',  to);
    form.append('business_name',       document.querySelector('[name=business_name]')?.value  || '');
    form.append('email_subject',       document.querySelector('[name=email_subject]')?.value  || '');
    form.append('email_greeting',      document.querySelector('[name=email_greeting]')?.value || '');
    form.append('email_intro',         document.querySelector('[name=email_intro]')?.value    || '');
    form.append('email_color',         document.querySelector('[name=email_color]')?.value    || '#D64545');
    try {
        const res  = await fetch('dashboard.php', { method: 'POST', body: form });
        const data = await res.json();
        status.textContent = (data.ok ? '✅ ' : '⚠️ ') + data.msg;
        status.style.color = data.ok ? '#166534' : '#991b1b';
    } catch (e) {
        status.textContent = '⚠️ Errore di rete. Riprova.';
        status.style.color = '#991b1b';
    }
}
document.getElementById('preview-modal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});
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

function render_login(string $csrf, bool $error, string $brand, bool $blocked = false): void {
    $err = '';
    if ($blocked)   $err = '<div class="alert-err">Troppi tentativi. Riprova tra 15 minuti.</div>';
    elseif ($error) $err = '<div class="alert-err">Password non corretta.</div>';
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
