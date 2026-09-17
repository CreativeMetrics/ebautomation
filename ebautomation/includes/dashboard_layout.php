<?php
if (!defined('EBAUTO_APP')) { http_response_code(403); exit; }
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
        .edit-highlight-row td { background: #fffbeb; }
        .edit-highlight-box { background: #fffbeb; border: 2px solid #fcd34d; border-radius: 10px; padding: 20px; margin-top: 10px; }
        .edit-highlight-banner { background: #fde68a; color: #92400e; padding: 10px 14px; border-radius: 8px; font-size: 13px; font-weight: 700; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .edit-highlight-banner a { color: #92400e; text-decoration: underline; font-weight: 700; white-space: nowrap; }
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
        <?php if (file_exists(APP_DIR . '/logo.png')): ?>
            <img src="logo.png?v=<?= filemtime(APP_DIR . '/logo.png') ?>" alt="Logo">
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


    <?php
    if ($active_tab === 'sconti') {
        require __DIR__ . '/dashboard_view_sconti.php';
    } elseif ($active_tab === 'config') {
        require __DIR__ . '/dashboard_view_config.php';
    } elseif ($active_tab === 'log') {
        require __DIR__ . '/dashboard_view_log.php';
    } elseif ($active_tab === 'guida') {
        require __DIR__ . '/dashboard_view_guida.php';
    }
    ?>
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
