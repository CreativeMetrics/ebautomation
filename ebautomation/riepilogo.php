<?php
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['authenticated'])) {
    header('Location: dashboard.php');
    exit;
}

$conf   = load_config();
$regole = load_regole();

// Statistiche log
$stats = ['sconti' => 0, 'email' => 0, 'errori' => 0];
if (file_exists(LOG_FILE)) {
    foreach (file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (stripos($line, 'Sconto creato')  !== false) $stats['sconti']++;
        elseif (stripos($line, 'Email inviata') !== false) $stats['email']++;
        elseif (stripos($line, 'ERRORE')        !== false) $stats['errori']++;
    }
}

// Ordini processati
$proc_data  = file_exists(__DIR__ . '/processed_orders.json')
    ? (json_decode(file_get_contents(__DIR__ . '/processed_orders.json'), true) ?: [])
    : [];
$proc_count = count($proc_data);

// URL webhook
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url    = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/';
$webhook_url = $base_url . 'eventbrite-webhook.php' . ($conf['webhook_token'] ? '?token=' . $conf['webhook_token'] : '');

// Mascheramento valori sensibili
function mask(string $val, int $show = 4): string {
    if (strlen($val) <= $show * 2) return str_repeat('●', max(6, strlen($val)));
    return substr($val, 0, $show) . str_repeat('●', 6) . substr($val, -$show);
}

$brand   = $conf['business_name'] ?: 'Automazione Sconti';
$gen_at  = date('d/m/Y \a\l\l\e H:i:s');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Riepilogo Configurazione — <?= h($brand) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Schermo ── */
        :root { --primary: #D64545; --dark: #1e293b; --border: #e2e8f0; --muted: #64748b; --bg: #f8f9fa; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: #334155; margin: 0; padding: 32px 20px; font-size: 14px; line-height: 1.6; }
        .page { max-width: 860px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); overflow: hidden; }
        .toolbar { background: var(--dark); color: white; padding: 14px 32px; display: flex; justify-content: space-between; align-items: center; }
        .toolbar a { color: #94a3b8; font-size: 13px; text-decoration: none; }
        .toolbar a:hover { color: white; }
        .btn-print { background: var(--primary); color: white; border: none; padding: 10px 22px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .btn-print:hover { opacity: 0.88; }

        /* ── Documento ── */
        .doc { padding: 48px; }

        /* Cover */
        .cover { display: flex; align-items: center; gap: 24px; margin-bottom: 40px; padding-bottom: 32px; border-bottom: 3px solid var(--primary); }
        .cover-logo { flex-shrink: 0; }
        .cover-logo img { max-height: 72px; max-width: 180px; object-fit: contain; }
        .cover-logo .name-box { background: var(--dark); color: white; padding: 12px 20px; border-radius: 8px; font-weight: 700; font-size: 20px; }
        .cover-info h1 { margin: 0 0 4px; font-size: 22px; color: var(--dark); }
        .cover-info .sub { color: var(--muted); font-size: 13px; margin: 0; }
        .cover-badges { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .badge-ok  { background: #dcfce7; color: #166534; }
        .badge-warn { background: #fef3c7; color: #92400e; }
        .badge-err  { background: #fee2e2; color: #991b1b; }

        /* Sezioni */
        .section { margin-bottom: 36px; }
        .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--primary); margin: 0 0 16px; padding-bottom: 8px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; }
        .section-title span { font-size: 15px; }

        /* Griglia info */
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .info-item { background: #f8f9fa; border-radius: 8px; padding: 14px 16px; border: 1px solid var(--border); }
        .info-item .lbl { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; }
        .info-item .val { font-weight: 600; color: var(--dark); word-break: break-all; }
        .info-item .val.mono { font-family: monospace; font-size: 13px; }
        .info-item.full { grid-column: 1 / -1; }
        .info-item.status-ok  { border-left: 4px solid #10b981; }
        .info-item.status-warn { border-left: 4px solid #f59e0b; }
        .info-item.status-err  { border-left: 4px solid #ef4444; }

        /* Tabella regole */
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead { background: var(--dark); color: white; }
        th { padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
        td { padding: 11px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        tr:nth-child(even) td { background: #f8f9fa; }
        .code { font-family: monospace; background: #f1f5f9; padding: 2px 7px; border-radius: 4px; color: var(--primary); font-size: 12px; white-space: nowrap; }
        .no-rules { color: var(--muted); font-style: italic; text-align: center; padding: 24px; }

        /* Statistiche */
        .stat-row { display: flex; gap: 16px; }
        .stat-box { flex: 1; text-align: center; background: #f8f9fa; border: 1px solid var(--border); border-radius: 8px; padding: 16px; }
        .stat-box .n { font-size: 32px; font-weight: 700; color: var(--dark); line-height: 1; }
        .stat-box .l { font-size: 11px; color: var(--muted); text-transform: uppercase; font-weight: 600; margin-top: 6px; }
        .stat-box.err-box .n { color: #ef4444; }

        /* Setup wizard steps */
        .steps { counter-reset: step; }
        .step { display: flex; gap: 16px; margin-bottom: 16px; align-items: flex-start; }
        .step-num { background: var(--primary); color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; flex-shrink: 0; }
        .step-body { flex: 1; padding-top: 4px; }
        .step-body strong { display: block; margin-bottom: 2px; }
        .step-body p { margin: 0; color: var(--muted); font-size: 13px; }
        .url-box { background: #0f172a; color: #86efac; font-family: monospace; font-size: 12px; padding: 12px 16px; border-radius: 8px; margin-top: 8px; word-break: break-all; }

        /* Footer */
        .footer { border-top: 1px solid var(--border); margin-top: 40px; padding-top: 20px; display: flex; justify-content: space-between; align-items: center; color: var(--muted); font-size: 12px; }

        /* ── Stampa ── */
        @media print {
            body { background: white; padding: 0; font-size: 12px; }
            .page { max-width: 100%; border-radius: 0; box-shadow: none; }
            .toolbar { display: none !important; }
            .doc { padding: 24px 32px; }
            .cover { margin-bottom: 28px; padding-bottom: 20px; }
            .section { margin-bottom: 24px; page-break-inside: avoid; }
            table { page-break-inside: avoid; }
            tr { page-break-inside: avoid; }
            .stat-row { gap: 10px; }
            .info-grid { gap: 8px; }
            a { color: inherit !important; text-decoration: none !important; }
            .url-box { border: 1px solid #ccc; background: #f8f8f8; color: #333; }
            @page { margin: 1.5cm; size: A4; }
        }
    </style>
</head>
<body>
<div class="page">

    <!-- Toolbar (solo schermo) -->
    <div class="toolbar">
        <a href="dashboard.php?tab=guida">← Torna alla Dashboard</a>
        <button class="btn-print" onclick="window.print()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Stampa / Salva PDF
        </button>
    </div>

    <div class="doc">

        <!-- ── COVER ── -->
        <div class="cover">
            <div class="cover-logo">
                <?php if (file_exists(__DIR__ . '/logo.png')): ?>
                    <img src="logo.png?v=<?= filemtime(__DIR__ . '/logo.png') ?>" alt="Logo <?= h($brand) ?>">
                <?php else: ?>
                    <div class="name-box"><?= h($brand) ?></div>
                <?php endif; ?>
            </div>
            <div class="cover-info">
                <h1>Riepilogo Configurazione</h1>
                <p class="sub">Sistema di Automazione Sconti Eventbrite</p>
                <div class="cover-badges">
                    <?php if ($conf['paused']): ?>
                        <span class="badge badge-warn">⏸ Automazioni in pausa</span>
                    <?php else: ?>
                        <span class="badge badge-ok">▶ Automazioni attive</span>
                    <?php endif; ?>
                    <span class="badge badge-ok"><?= count($regole) ?> regor<?= count($regole) === 1 ? 'a' : 'e' ?> configurar<?= count($regole) === 1 ? 'a' : 'e' ?></span>
                    <span class="badge <?= $stats['errori'] > 0 ? 'badge-err' : 'badge-ok' ?>"><?= $stats['errori'] > 0 ? $stats['errori'] . ' errori nel log' : 'Nessun errore nel log' ?></span>
                </div>
            </div>
        </div>
        <p style="font-size:12px;color:var(--muted);margin:-24px 0 36px;text-align:right;">Generato il <?= $gen_at ?></p>

        <!-- ── 1. SETUP WIZARD ── -->
        <div class="section">
            <div class="section-title"><span>🔧</span> Procedura di Setup</div>
            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <strong>Configura Token API ed SMTP</strong>
                        <p>Nella tab Configurazione: inserisci il Private Token Eventbrite, l'Organization ID e le credenziali SMTP per l'invio email.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <strong>Registra il Webhook su Eventbrite</strong>
                        <p>Vai su Eventbrite → Account → Webhook → Aggiungi endpoint. Incolla l'URL qui sotto e seleziona l'evento <em>order.placed</em> (e opzionalmente <em>order.refunded</em>).</p>
                        <div class="url-box"><?= h($webhook_url ?: '— Token webhook non ancora generato —') ?></div>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-body">
                        <strong>Crea le Regole Sconti</strong>
                        <p>Nella tab Regole Sconti: per ogni evento trigger (A) indica l'evento target (B) a cui va generato il codice sconto, con percentuale o importo fisso, quantità utilizzi e scadenza opzionale.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">4</div>
                    <div class="step-body">
                        <strong>Verifica con la Simulazione</strong>
                        <p>Nella tab Guida & Help → Simulazione Webhook: inserisci un Order ID reale di Eventbrite per testare l'intero flusso (fetch ordine → creazione sconto → invio email) senza aspettare un acquisto.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">5</div>
                    <div class="step-body">
                        <strong>Monitora con Health Check e Log</strong>
                        <p>Usa il pulsante Health Check per verificare la connettività API ed SMTP, e la tab Log per controllare l'esecuzione delle automazioni in tempo reale.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 2. CONFIGURAZIONE EVENTBRITE ── -->
        <div class="section">
            <div class="section-title"><span>🎟</span> Configurazione Eventbrite</div>
            <div class="info-grid">
                <div class="info-item <?= !empty($conf['api_token']) ? 'status-ok' : 'status-err' ?>">
                    <div class="lbl">Private Token API</div>
                    <div class="val mono"><?= !empty($conf['api_token']) ? mask($conf['api_token']) : '— Non configurato —' ?></div>
                </div>
                <div class="info-item <?= !empty($conf['org_id']) ? 'status-ok' : 'status-err' ?>">
                    <div class="lbl">Organization ID</div>
                    <div class="val mono"><?= !empty($conf['org_id']) ? h($conf['org_id']) : '— Non configurato —' ?></div>
                </div>
                <div class="info-item <?= !empty($conf['webhook_token']) ? 'status-ok' : 'status-warn' ?>">
                    <div class="lbl">Token Webhook</div>
                    <div class="val mono"><?= !empty($conf['webhook_token']) ? h($conf['webhook_token']) : '— Non generato —' ?></div>
                </div>
                <div class="info-item <?= !$conf['paused'] ? 'status-ok' : 'status-warn' ?>">
                    <div class="lbl">Stato Automazioni</div>
                    <div class="val"><?= $conf['paused'] ? '⏸ In pausa' : '▶ Attive' ?></div>
                </div>
                <div class="info-item full">
                    <div class="lbl">URL Webhook (da registrare su Eventbrite)</div>
                    <div class="val mono" style="font-size:12px;color:#0369a1;"><?= h($webhook_url ?: '— Token non ancora generato —') ?></div>
                </div>
            </div>
        </div>

        <!-- ── 3. CONFIGURAZIONE SMTP ── -->
        <div class="section">
            <div class="section-title"><span>📧</span> Configurazione Email (SMTP)</div>
            <div class="info-grid">
                <div class="info-item <?= !empty($conf['smtp_host']) ? 'status-ok' : 'status-err' ?>">
                    <div class="lbl">Host SMTP</div>
                    <div class="val mono"><?= !empty($conf['smtp_host']) ? h($conf['smtp_host']) : '— Non configurato —' ?></div>
                </div>
                <div class="info-item">
                    <div class="lbl">Porta</div>
                    <div class="val mono"><?= h($conf['smtp_port']) ?></div>
                </div>
                <div class="info-item">
                    <div class="lbl">Cifratura</div>
                    <div class="val"><?= $conf['smtp_encryption'] === 'tls' ? 'STARTTLS (TLS)' : 'SSL/TLS (SMTPS)' ?></div>
                </div>
                <div class="info-item <?= !empty($conf['smtp_user']) ? 'status-ok' : 'status-err' ?>">
                    <div class="lbl">Email Mittente</div>
                    <div class="val"><?= !empty($conf['smtp_user']) ? h($conf['smtp_user']) : '— Non configurato —' ?></div>
                </div>
                <div class="info-item">
                    <div class="lbl">Password SMTP</div>
                    <div class="val"><?= !empty($conf['smtp_pass']) ? '●●●●●●●●' : '— Non configurata —' ?></div>
                </div>
                <div class="info-item">
                    <div class="lbl">Valuta Sconti Fissi</div>
                    <div class="val"><?= h($conf['currency'] ?: 'EUR') ?></div>
                </div>
            </div>
        </div>

        <!-- ── 4. TEMPLATE EMAIL ── -->
        <div class="section">
            <div class="section-title"><span>✉️</span> Template Email Cliente</div>
            <div class="info-grid">
                <div class="info-item full">
                    <div class="lbl">Oggetto</div>
                    <div class="val"><?= h($conf['email_subject']) ?></div>
                </div>
                <div class="info-item full">
                    <div class="lbl">Saluto</div>
                    <div class="val"><?= h($conf['email_greeting']) ?> <span style="color:var(--muted);font-size:12px;">→ es: "<?= h(str_replace('{{nome}}', 'Mario', $conf['email_greeting'])) ?>"</span></div>
                </div>
                <div class="info-item full">
                    <div class="lbl">Testo Introduttivo</div>
                    <div class="val"><?= h($conf['email_intro']) ?></div>
                </div>
                <div class="info-item">
                    <div class="lbl">Colore Principale</div>
                    <div class="val" style="display:flex;align-items:center;gap:10px;">
                        <span style="display:inline-block;width:20px;height:20px;border-radius:4px;background:<?= h($conf['email_color']) ?>;border:1px solid #ddd;flex-shrink:0;"></span>
                        <?= h($conf['email_color']) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 5. REGOLE SCONTI ── -->
        <div class="section">
            <div class="section-title"><span>🎁</span> Regole Sconti Configurate (<?= count($regole) ?>)</div>
            <?php if (empty($regole)): ?>
                <p class="no-rules">Nessuna regola configurata. Creane una dalla tab "Regole Sconti" nella dashboard.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Trigger (ID)</th>
                        <th>Descrizione</th>
                        <th>Sconto</th>
                        <th>Prefisso</th>
                        <th>Qtà</th>
                        <th>Scade</th>
                        <th>Target (ID)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($regole as $tid => $r):
                    $is_imp = ($r['tipo_sconto'] ?? 'percentuale') === 'importo';
                    $sconto = $is_imp
                        ? number_format((float)($r['importo_fisso'] ?? 0), 2, ',', '.') . ' ' . h($conf['currency'] ?: 'EUR')
                        : h($r['percentuale'] ?? '100.00') . '%';
                    $scade  = ($r['giorni_scadenza'] ?? 0) > 0 ? h((string)$r['giorni_scadenza']) . ' gg' : 'Mai';
                ?>
                <tr>
                    <td><span class="code"><?= h($tid) ?></span></td>
                    <td><?= h($r['descrizione'] ?? '') ?></td>
                    <td style="font-weight:700;"><?= $sconto ?></td>
                    <td><span class="code"><?= h($r['codice_prefix'] ?? 'GIFT') ?></span></td>
                    <td style="text-align:center;"><?= h((string)($r['quantita'] ?? 1)) ?></td>
                    <td><?= $scade ?></td>
                    <td><?php foreach ($r['target_ids'] as $t) echo '<span class="code" style="margin-right:4px;">'.h($t).'</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:12px;color:var(--muted);margin-top:10px;">Esempio codice generato: <span class="code"><?= h(($regole[array_key_first($regole)]['codice_prefix'] ?? 'GIFT')) ?>-A1B2C3D4</span> (prefisso + hash ordine, univoco per cliente)</p>
            <?php endif; ?>
        </div>

        <!-- ── 6. STATISTICHE ── -->
        <div class="section">
            <div class="section-title"><span>📊</span> Statistiche (log corrente)</div>
            <div class="stat-row">
                <div class="stat-box">
                    <div class="n"><?= $stats['sconti'] ?></div>
                    <div class="l">Sconti Creati</div>
                </div>
                <div class="stat-box">
                    <div class="n"><?= $stats['email'] ?></div>
                    <div class="l">Email Inviate</div>
                </div>
                <div class="stat-box err-box">
                    <div class="n"><?= $stats['errori'] ?></div>
                    <div class="l">Errori</div>
                </div>
                <div class="stat-box">
                    <div class="n"><?= $proc_count ?></div>
                    <div class="l">Ordini Tracciati</div>
                </div>
            </div>
        </div>

        <!-- ── 7. SICUREZZA E MANUTENZIONE ── -->
        <div class="section">
            <div class="section-title"><span>🔒</span> Sicurezza e Manutenzione</div>
            <div class="info-grid">
                <div class="info-item <?= !empty($conf['dashboard_password']) ? 'status-ok' : 'status-err' ?>">
                    <div class="lbl">Password Dashboard</div>
                    <div class="val"><?= !empty($conf['dashboard_password']) ? '●●●●●●●● (impostata)' : '— Non impostata —' ?></div>
                </div>
                <div class="info-item">
                    <div class="lbl">Sessione</div>
                    <div class="val">Cookie HttpOnly, SameSite=Strict<?= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? ', Secure' : '' ?></div>
                </div>
                <div class="info-item full">
                    <div class="lbl">Reset Password (emergenza)</div>
                    <div class="val mono" style="font-size:12px;"><?= h($base_url) ?>reset-password.php &nbsp;→ richiede il Private Token API come verifica identità</div>
                </div>
                <div class="info-item full">
                    <div class="lbl">Backup Regole</div>
                    <?php
                    $backup_dir = __DIR__ . '/backups';
                    $backups    = is_dir($backup_dir) ? (glob($backup_dir . '/regole_*.json') ?: []) : [];
                    rsort($backups);
                    ?>
                    <div class="val">
                        <?php if (empty($backups)): ?>
                            Nessun backup ancora creato. Vengono generati automaticamente ad ogni modifica delle regole.
                        <?php else: ?>
                            <?= count($backups) ?> backup disponibil<?= count($backups) === 1 ? 'e' : 'i' ?>.
                            Ultimo: <?= date('d/m/Y H:i', filemtime($backups[0])) ?> — <span class="code"><?= h(basename($backups[0])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── FOOTER ── -->
        <div class="footer">
            <span><?= h($brand) ?> — Sistema Automazione Sconti Eventbrite</span>
            <span>Generato il <?= $gen_at ?></span>
        </div>

    </div><!-- /doc -->
</div><!-- /page -->
</body>
</html>
