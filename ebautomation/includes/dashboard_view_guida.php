<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

    <div class="page-head">
        <h1>📖 Guida & Help</h1>
        <p>Primi passi per il setup e una mappa rapida di tutta la dashboard.</p>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:20px;">
        <a href="riepilogo.php" target="_blank" class="btn btn-secondary" style="font-size:13px;padding:10px 18px;">📄 Riepilogo PDF</a>
        <a href="?action=health" target="_blank" class="btn btn-secondary" style="font-size:13px;padding:10px 18px;">🔍 Health Check</a>
    </div>

    <div class="card">
        <h2>🚀 Primi Passi</h2>
        <p class="card-subtitle">Nell'ordine, per portare l'automazione da zero a funzionante.</p>
        <ol style="margin:0 0 24px;padding-left:20px;line-height:2.1;">
            <li>Configura Token API e SMTP nella tab <a href="?tab=connessioni">Connessioni</a>.</li>
            <li>Copia il tuo Organization ID dalla lista qui sotto.</li>
            <li>Incolla l'URL Webhook (qui sotto) su Eventbrite → Account → Webhook.</li>
            <li>Crea le regole nella tab <a href="?tab=sconti">Regole Sconti</a>.</li>
            <li>Personalizza l'email nella tab <a href="?tab=template">Template Email</a> (opzionale — funziona già con quello predefinito).</li>
        </ol>
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
        <?php if (empty($conf['webhook_token'])): ?><p style="color:var(--warning);font-size:13px;margin-top:10px;font-weight:600;">⚠️ Token non generato. Salva la configurazione.</p><?php endif; ?>
    </div>

    <div class="card">
        <h2>🧭 Dove Trovare...</h2>
        <p class="card-subtitle">Una mappa rapida delle altre sezioni della dashboard.</p>
        <div class="grid">
            <div class="input-group">
                <label>🎁 Regole Sconti</label>
                <span class="tip">Crea/modifica le automazioni sconto e sfoglia gli eventi Eventbrite disponibili. <a href="?tab=sconti">Vai →</a></span>
            </div>
            <div class="input-group">
                <label>✉️ Template Email</label>
                <span class="tip">Editor visivo a blocchi per l'email con il codice sconto, in una o più lingue. <a href="?tab=template">Vai →</a></span>
            </div>
            <div class="input-group">
                <label>👥 Utenti & Accesso</label>
                <span class="tip">Password personale e account con accesso alla dashboard. <a href="?tab=utenti">Vai →</a></span>
            </div>
            <div class="input-group">
                <label>📋 Log Webhook</label>
                <span class="tip">Statistiche giornaliere, log dettagliato, ordini processati e audit log. <a href="?tab=log">Vai →</a></span>
            </div>
            <div class="input-group">
                <label>🛠️ Strumenti</label>
                <span class="tip">Simulazione webhook, import/export di regole e template, backup. <a href="?tab=strumenti">Vai →</a></span>
            </div>
        </div>
    </div>
