<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

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
        $backup_dir     = APP_DIR . '/backups';
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
