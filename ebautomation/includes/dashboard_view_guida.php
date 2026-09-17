<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:20px;">
        <a href="riepilogo.php" target="_blank" class="btn btn-secondary" style="font-size:13px;padding:10px 18px;">📄 Riepilogo PDF</a>
        <a href="?action=health" target="_blank" class="btn btn-secondary" style="font-size:13px;padding:10px 18px;">🔍 Health Check</a>
    </div>

    <div class="card">
        <h2>📖 Setup & Help</h2>
        <p><strong>1.</strong> Configura Token API e SMTP nella tab "Connessioni".<br>
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
