<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

    <div class="page-head">
        <h1>🔌 Connessioni</h1>
        <p>Stato delle automazioni, credenziali Eventbrite e SMTP, webhook.</p>
    </div>

    <div class="card" style="box-shadow: inset 0 3px 0 0 <?= $conf['paused'] ? '#f59e0b' : '#10b981' ?>, var(--shadow);">
        <h2><?= $conf['paused'] ? '⏸ Automazioni in Pausa' : '▶ Automazioni Attive' ?></h2>
        <p class="card-subtitle"><?= $conf['paused'] ? 'I webhook vengono ricevuti ma ignorati. Nessuno sconto verrà creato.' : 'Tutto funziona normalmente. Metti in pausa per bloccare temporaneamente le automazioni.' ?></p>
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

    <?php if ($is_admin): ?>
    <div class="card">
        <h2>🔄 URL Webhook</h2>
        <p class="card-subtitle">Questo è l'URL attualmente valido: deve essere incollato <strong>esattamente così</strong> nella configurazione webhook di Eventbrite. Se non coincide, Eventbrite invierà un token vecchio/errato e ogni richiesta reale verrà rifiutata (visibile nel tab Log come "token mancante o non valido").</p>
        <code style="display:block;overflow-wrap:anywhere;margin-bottom:16px;"><?= h($webhook_url) ?></code>
        <?php if (empty($conf['webhook_token'])): ?><p style="color:var(--warning);font-size:13px;margin-bottom:16px;font-weight:600;">⚠️ Token non generato. Salva la configurazione.</p><?php endif; ?>
        <form method="POST" onsubmit="return confirm('Il vecchio URL diventerà invalido. Continuare?')">
            <input type="hidden" name="action"     value="regenerate_token">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button type="submit" class="btn-secondary">🔄 Rigenera Token</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($is_admin): ?>
    <div class="card">
        <h2>📧 Test Email</h2>
        <p class="card-subtitle">Verifica che le impostazioni SMTP siano corrette inviando un'email di prova.</p>
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
