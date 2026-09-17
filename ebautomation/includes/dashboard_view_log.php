<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

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
