<?php
if (!defined('EBAUTO_APP')) { http_response_code(403); exit; }

use PHPMailer\PHPMailer\PHPMailer as Mailer;
use PHPMailer\PHPMailer\Exception as MailException;

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
                    move_uploaded_file($_FILES['logo']['tmp_name'], APP_DIR . '/logo.png');
                    // Il logo è cambiato: le versioni ridimensionate cachate per le email
                    // appartengono al logo precedente, vanno scartate.
                    foreach (glob(APP_DIR . '/cache/logo_*.png') ?: [] as $stale) unlink($stale);
                }
            }
            save_config($updated);
            audit_log('Configurazione salvata');
            header('Location: dashboard.php?tab=connessioni&msg=ok');
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
            header('Location: dashboard.php?tab=utenti');
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
            header('Location: dashboard.php?tab=utenti');
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
            header('Location: dashboard.php?tab=utenti');
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
            header('Location: dashboard.php?tab=utenti');
            exit;

        case 'toggle_pause':
            $conf['paused'] = !$conf['paused'];
            save_config($conf);
            audit_log($conf['paused'] ? 'Automazioni messe in pausa' : 'Automazioni riattivate');
            $_SESSION['flash_ok'] = $conf['paused'] ? 'Automazioni messe in pausa.' : 'Automazioni riattivate.';
            header('Location: dashboard.php?tab=connessioni');
            exit;

        case 'test_smtp':
            // Verifica pura della connessione SMTP: messaggio minimo fisso,
            // indipendente dai template (che si testano singolarmente nella
            // sezione "Template Email", con dati e visual reali).
            $to = trim($_POST['test_email'] ?? $conf['smtp_user']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = 'Indirizzo email non valido.';
                header('Location: dashboard.php?tab=connessioni');
                exit;
            }
            require_once APP_DIR . '/PHPMailer/Exception.php';
            require_once APP_DIR . '/PHPMailer/PHPMailer.php';
            require_once APP_DIR . '/PHPMailer/SMTP.php';
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
            header('Location: dashboard.php?tab=connessioni');
            exit;

        case 'save_email_template':
            $lingua = trim($_POST['lingua'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]{1,10}$/', $lingua)) {
                $_SESSION['flash_error'] = 'Codice lingua non valido: usa 1-10 caratteri (lettere, numeri, _ -), es. "it", "en".';
                header('Location: dashboard.php?tab=template');
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
            header('Location: dashboard.php?tab=template');
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
            $pb_has_logo   = file_exists(APP_DIR . '/logo.png');
            $pb_logo_src   = $pb_has_logo ? 'logo.png?v=' . filemtime(APP_DIR . '/logo.png') : '';
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
            header('Location: dashboard.php?tab=template');
            exit;

        case 'test_email_template':
            $lingua = trim($_POST['lingua'] ?? '');
            $to     = trim($_POST['test_email'] ?? $conf['smtp_user']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error'] = 'Indirizzo email non valido.';
                header('Location: dashboard.php?tab=template');
                exit;
            }
            $template = get_email_template($lingua ?: null);
            if (!$template) {
                $_SESSION['flash_error'] = 'Nessun template disponibile da testare.';
                header('Location: dashboard.php?tab=template');
                exit;
            }
            require_once APP_DIR . '/PHPMailer/Exception.php';
            require_once APP_DIR . '/PHPMailer/PHPMailer.php';
            require_once APP_DIR . '/PHPMailer/SMTP.php';
            $bname_t  = $conf['business_name'] ?: 'La nostra Azienda';
            $logo_path = APP_DIR . '/logo.png';
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
                if ($has_logo) $mail->addEmbeddedImage(get_logo_path_for_email($logo_path, $template['logo_width'] ?? 150), 'logo_cid');
                $mail->Body    = $rendered['html'];
                $mail->AltBody = $rendered['text'];
                $mail->send();
                $_SESSION['flash_ok'] = "Email di test inviata a $to con il template \"{$template['nome']}\".";
            } catch (MailException $e) {
                $_SESSION['flash_error'] = 'Errore SMTP: ' . $mail->ErrorInfo;
            }
            header('Location: dashboard.php?tab=template');
            exit;

        case 'regenerate_token':
            $conf['webhook_token'] = bin2hex(random_bytes(16));
            save_config($conf);
            audit_log('Token webhook rigenerato');
            $_SESSION['flash_ok'] = 'Nuovo token generato. Aggiorna subito l\'URL su Eventbrite.';
            header('Location: dashboard.php?tab=connessioni');
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
            header('Location: dashboard.php?tab=strumenti');
            exit;

        case 'import_regole_csv':
            if (!isset($_FILES['regole_csv']) || $_FILES['regole_csv']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = 'Nessun file selezionato.';
                header('Location: dashboard.php?tab=strumenti');
                exit;
            }
            $fh = fopen($_FILES['regole_csv']['tmp_name'], 'r');
            $header = $fh ? fgetcsv($fh) : null;
            $expected_header      = ['trigger_id', 'descrizione', 'tipo_sconto', 'percentuale', 'importo_fisso', 'codice_prefix', 'target_ids', 'quantita', 'giorni_scadenza', 'qty_minima', 'attiva', 'lingua'];
            $expected_header_old  = ['trigger_id', 'descrizione', 'tipo_sconto', 'percentuale', 'importo_fisso', 'codice_prefix', 'target_ids', 'quantita', 'giorni_scadenza', 'qty_minima', 'attiva']; // esportato prima dell'introduzione della colonna lingua
            $header_norm = $header ? array_map('trim', $header) : [];
            if ($header_norm !== $expected_header && $header_norm !== $expected_header_old) {
                $_SESSION['flash_error'] = 'Intestazione CSV non valida. Usa un file esportato da questa dashboard (' . implode(',', $expected_header) . ').';
                header('Location: dashboard.php?tab=strumenti');
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
            header('Location: dashboard.php?tab=strumenti');
            exit;

        case 'import_templates':
            if (isset($_FILES['templates_file']) && $_FILES['templates_file']['error'] === UPLOAD_ERR_OK) {
                $imported_tpl = json_decode(file_get_contents($_FILES['templates_file']['tmp_name']), true);
                if (!is_array($imported_tpl) || empty($imported_tpl)) {
                    $_SESSION['flash_error'] = 'File JSON non valido o vuoto.';
                } else {
                    $valid_tpl = true;
                    foreach ($imported_tpl as $tpl_lingua => $t) {
                        if (!is_string($tpl_lingua) || !preg_match('/^[a-zA-Z0-9_-]{1,10}$/', $tpl_lingua) || !is_array($t) || !isset($t['body_html'], $t['item_html'])) {
                            $valid_tpl = false;
                            break;
                        }
                    }
                    if ($valid_tpl) {
                        replace_all_email_templates($imported_tpl);
                        audit_log('Template email importati', count($imported_tpl) . ' template');
                        $_SESSION['flash_ok'] = 'Importati ' . count($imported_tpl) . ' template con successo.';
                    } else {
                        $_SESSION['flash_error'] = 'Struttura JSON non valida. Usa un file esportato da questa dashboard.';
                    }
                }
            } else {
                $_SESSION['flash_error'] = 'Nessun file selezionato.';
            }
            header('Location: dashboard.php?tab=strumenti');
            exit;

        case 'simulate_webhook':
            $order_id_sim = preg_replace('/[^0-9]/', '', $_POST['sim_order_id'] ?? '');
            if (!$order_id_sim) {
                $_SESSION['flash_error'] = 'Inserisci un Order ID numerico valido.';
                header('Location: dashboard.php?tab=strumenti');
                exit;
            }
            // Elaborazione in-process (non una richiesta HTTP verso se stessi): un
            // giro HTTP qui sarebbe comunque superfluo (stesso processo PHP) e su
            // alcuni hosting con un firewall/antibot aggressivo viene bloccato con
            // 403 prima ancora di raggiungere l'applicazione, mentre le chiamate
            // reali di Eventbrite (da IP esterno) passano regolarmente.
            $sim_result = process_eventbrite_order($conf, "https://www.eventbriteapi.com/v3/orders/{$order_id_sim}/", 'order.placed');
            if ($sim_result['http_code'] === 200) {
                $_SESSION['flash_ok'] = $sim_result['message'];
            } else {
                $_SESSION['flash_error'] = $sim_result['message'];
            }
            header('Location: dashboard.php?tab=strumenti');
            exit;

        case 'retry_failed_orders':
            $to_retry = load_failed_orders();
            if (empty($to_retry)) {
                $_SESSION['flash_ok'] = 'Nessun ordine in coda da ritentare.';
                header('Location: dashboard.php?tab=log');
                exit;
            }
            foreach ($to_retry as $rf_order_id => $rf_entry) {
                process_eventbrite_order($conf, $rf_entry['api_url'] ?? '', 'order.placed');
            }
            // Chi è tornato "complete" si è già auto-rimosso dalla coda dentro a
            // process_eventbrite_order(); ricontiamo cosa resta per un messaggio accurato.
            $still_pending = count(load_failed_orders());
            audit_log('Riprova ordini falliti', count($to_retry) . ' tentati, ' . $still_pending . ' ancora in coda');
            $_SESSION['flash_ok'] = "Ritentati " . count($to_retry) . " ordini. Ancora in coda: $still_pending.";
            header('Location: dashboard.php?tab=log');
            exit;
    }
}

