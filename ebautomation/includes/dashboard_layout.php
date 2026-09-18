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
        :root {
            --primary: #D64545;
            --primary-dark: #b83737;
            --primary-50: #fdf1f1;
            --primary-100: #fbe1e1;
            --sidebar: #161c2c;
            --sidebar-hover: rgba(255,255,255,.06);
            --sidebar-active: rgba(214,69,69,.18);
            --sidebar-text: #97a1bd;
            --bg: #f4f5f8;
            --surface: #ffffff;
            --border: #e7e9f0;
            --border-strong: #d6d9e4;
            --text: #1c2131;
            --text-muted: #626b85;
            --text-faint: #9aa1b8;
            --success: #10b981;
            --success-50: #ecfdf5;
            --warning: #f59e0b;
            --warning-50: #fffbeb;
            --danger: #ef4444;
            --danger-50: #fef2f2;
            --info: #0ea5e9;
            --info-50: #f0f9ff;
            --radius-sm: 8px;
            --radius: 14px;
            --radius-lg: 18px;
            --shadow-sm: 0 1px 2px rgba(20,24,42,.05);
            --shadow: 0 1px 2px rgba(20,24,42,.04), 0 6px 16px -4px rgba(20,24,42,.08);
            --shadow-md: 0 10px 28px -6px rgba(20,24,42,.14);
            --font: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        * { box-sizing: border-box; }
        a { color: var(--primary); }
        a:hover { color: var(--primary-dark); }
        body { font-family: var(--font); background: var(--bg); color: var(--text); margin: 0; display: flex; min-height: 100vh; -webkit-font-smoothing: antialiased; }
        ::selection { background: var(--primary-100); color: var(--primary-dark); }

        /* ── Sidebar ─────────────────────────────────────────────────── */
        aside { width: 272px; background: var(--sidebar); color: white; padding: 28px 18px; flex-shrink: 0; display: flex; flex-direction: column; position: relative; z-index: 20; }
        .logo-box { max-width: 100%; margin-bottom: 22px; text-align: center; padding: 0 8px; }
        .logo-box img { max-width: 150px; max-height: 64px; height: auto; object-fit: contain; }
        .logo-box .brand-fallback { font-weight: 800; font-size: 19px; color: white; letter-spacing: -.01em; }
        .pause-banner { background: linear-gradient(135deg, #f59e0b, #d97706); color: #1c1917; padding: 9px 12px; border-radius: var(--radius-sm); margin-bottom: 14px; font-size: 12px; font-weight: 700; text-align: center; box-shadow: var(--shadow-sm); }
        nav { display: flex; flex-direction: column; flex-grow: 1; }
        nav a { display: flex; align-items: center; gap: 10px; color: var(--sidebar-text); text-decoration: none; padding: 11px 14px; border-radius: 10px; margin-bottom: 3px; font-weight: 600; font-size: 14px; transition: background .15s, color .15s; position: relative; }
        nav a:hover { background: var(--sidebar-hover); color: #e2e6f0; }
        nav a.active { background: var(--sidebar-active); color: white; }
        nav a.active::before { content: ''; position: absolute; left: -18px; top: 8px; bottom: 8px; width: 4px; background: var(--primary); border-radius: 0 4px 4px 0; }
        nav a.logout { margin-top: 24px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,.08); color: #7d869f; font-size: 13px; font-weight: 600; }
        nav a.logout:hover { color: #e2e6f0; background: none; }
        .badge-nav { background: var(--danger); color: white; border-radius: 10px; padding: 1px 7px; font-size: 10px; margin-left: auto; font-weight: 700; }
        .mobile-nav-toggle { display: none; }
        .mobile-nav-backdrop { display: none; }

        /* ── Layout ──────────────────────────────────────────────────── */
        main { flex-grow: 1; padding: 36px 40px 60px; overflow-y: auto; max-width: 1240px; }
        .page-head { margin-bottom: 22px; }
        .page-head h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -.01em; }
        .page-head p { margin: 0; color: var(--text-muted); font-size: 14px; }

        /* ── Cards ───────────────────────────────────────────────────── */
        .card { background: var(--surface); padding: 28px 30px; border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border); margin-bottom: 24px; }
        h2 { margin: 0 0 6px; font-size: 17px; font-weight: 700; display: flex; align-items: center; flex-wrap: wrap; gap: 9px; letter-spacing: -.01em; }
        .card-subtitle { margin: 0 0 22px; color: var(--text-muted); font-size: 13.5px; line-height: 1.5; padding-bottom: 18px; border-bottom: 1px solid var(--border); }
        h3 { color: var(--text); font-size: 12.5px; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; margin: 30px 0 4px; border: none; padding: 0; display: flex; align-items: center; gap: 6px; }
        h3:first-of-type { margin-top: 4px; }
        h3::before { content: ''; width: 3px; height: 12px; background: var(--primary); border-radius: 2px; display: inline-block; }

        /* ── Grid & forms ────────────────────────────────────────────── */
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; margin-bottom: 18px; }
        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 25px; }
        .stat-card { background: var(--surface); padding: 22px 18px; border-radius: var(--radius); box-shadow: inset 0 3px 0 0 var(--success), var(--shadow); border: 1px solid var(--border); text-align: center; }
        .stat-card.err { box-shadow: inset 0 3px 0 0 var(--danger), var(--shadow); }
        .stat-val { font-size: 32px; font-weight: 800; color: var(--text); line-height: 1; letter-spacing: -.02em; }
        .stat-lbl { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: .04em; margin-top: 8px; }
        .input-group { display: flex; flex-direction: column; gap: 7px; min-width: 0; }
        label { font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .03em; }
        input[type=text], input[type=email], input[type=password], input[type=number], input[type=file], input[type=color], select, textarea {
            padding: 11px 13px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; width: 100%;
            box-sizing: border-box; font-family: inherit; color: var(--text); background: var(--surface); transition: border-color .15s, box-shadow .15s;
        }
        input:hover, select:hover, textarea:hover { border-color: var(--border-strong); }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-100); }
        input::placeholder, textarea::placeholder { color: var(--text-faint); }
        select { cursor: pointer; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%23626b85' stroke-width='2'%3e%3cpath d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; padding-right: 36px; }
        textarea { resize: vertical; min-height: 80px; line-height: 1.5; }
        input[type=color] { height: 46px; padding: 4px 8px; cursor: pointer; }
        input[type=file] { padding: 9px 12px; cursor: pointer; background: var(--bg); }
        .tip { font-size: 12px; color: var(--text-faint); margin-top: 2px; line-height: 1.45; }

        /* ── Buttons ─────────────────────────────────────────────────── */
        button, .btn { background: var(--primary); color: white; border: none; padding: 11px 20px; border-radius: var(--radius-sm); cursor: pointer; font-weight: 700; font-size: 13.5px; font-family: inherit; transition: transform .12s, box-shadow .12s, background .15s, opacity .15s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(20,24,42,.08); white-space: nowrap; flex-shrink: 0; }
        button:hover, .btn:hover { background: var(--primary-dark); box-shadow: 0 4px 10px -2px rgba(214,69,69,.4); transform: translateY(-1px); }
        button:active, .btn:active { transform: translateY(0); box-shadow: 0 1px 2px rgba(20,24,42,.08); }
        button:focus-visible, .btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
        .btn-secondary { background: #626b85; }
        .btn-secondary:hover { background: #4d5670; box-shadow: 0 4px 10px -2px rgba(98,107,133,.4); }
        .btn-info { background: var(--info); }
        .btn-info:hover { background: #0284c7; box-shadow: 0 4px 10px -2px rgba(14,165,233,.4); }
        .btn-warning { background: var(--warning); }
        .btn-warning:hover { background: #d97706; box-shadow: 0 4px 10px -2px rgba(245,158,11,.4); }
        .btn-success { background: var(--success); }
        .btn-success:hover { background: #059669; box-shadow: 0 4px 10px -2px rgba(16,185,129,.4); }

        /* ── Tables ──────────────────────────────────────────────────── */
        table { width: 100%; border-collapse: separate; border-spacing: 0; border: 1.5px solid var(--border); border-radius: var(--radius-sm); }
        th { text-align: left; font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; font-weight: 800; padding: 12px 14px; background: var(--bg); border-bottom: 1.5px solid var(--border-strong); white-space: nowrap; }
        th:first-child { border-top-left-radius: calc(var(--radius-sm) - 1.5px); }
        th:last-child { border-top-right-radius: calc(var(--radius-sm) - 1.5px); }
        td { padding: 14px; border-bottom: 1px solid var(--border); font-size: 13.5px; vertical-align: middle; }
        tbody tr:hover td { background: var(--bg); }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:last-child td:first-child { border-bottom-left-radius: calc(var(--radius-sm) - 1.5px); }
        tbody tr:last-child td:last-child { border-bottom-right-radius: calc(var(--radius-sm) - 1.5px); }

        /* ── Badges, alerts, misc ────────────────────────────────────── */
        .badge { background: var(--bg); border: 1px solid var(--border); padding: 4px 10px; border-radius: 6px; font-family: 'SFMono-Regular', Consolas, monospace; color: var(--primary); font-size: 11.5px; font-weight: 600; cursor: pointer; }
        .alert-ok  { background: var(--success-50); color: #166534; padding: 14px 16px; border-radius: var(--radius-sm); margin-bottom: 24px; border: 1px solid #bbf7d0; font-size: 13.5px; font-weight: 600; }
        .alert-err { background: var(--danger-50); color: #991b1b; padding: 14px 16px; border-radius: var(--radius-sm); margin-bottom: 24px; border: 1px solid #fecaca; font-size: 13.5px; font-weight: 600; }
        .log-box { background: #0e1420; color: #9aa5c3; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 12px; padding: 18px 20px; border-radius: var(--radius-sm); overflow-y: auto; max-height: 460px; white-space: pre-wrap; word-break: break-all; line-height: 1.6; }
        .log-box .log-err  { color: #f87171; }
        .log-box .log-info { color: #6ee7b7; }
        code { background: var(--bg); border: 1px solid var(--border); padding: 10px 14px; border-radius: var(--radius-sm); display: block; font-size: 13px; word-break: break-all; font-family: 'SFMono-Regular', Consolas, monospace; }
        .empty-state { text-align: center; padding: 36px 20px; color: var(--text-faint); font-size: 13.5px; }

        /* ── Azioni a icona (righe di tabella) ──────────────────────────── */
        .row-actions { display: flex; align-items: center; gap: 2px; }
        .icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; flex-shrink: 0; border-radius: 8px; color: var(--text-faint); background: transparent; text-decoration: none; transition: background .15s, color .15s; box-shadow: none; border: none; cursor: pointer; padding: 0; }
        .icon-btn:hover { background: var(--bg); color: var(--text); transform: none; box-shadow: none; }
        .icon-btn svg { width: 17px; height: 17px; }
        .icon-btn.icon-info:hover { background: var(--info-50); color: var(--info); }
        .icon-btn.icon-danger:hover { background: var(--danger-50); color: var(--danger); }
        .icon-btn:disabled, .icon-btn.disabled { opacity: .3; cursor: default; pointer-events: none; }

        /* ── Badge a pillola (stato) ─────────────────────────────────────── */
        .pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
        .pill-success { background: var(--success-50); color: #166534; }
        .pill-warning { background: var(--warning-50); color: #92400e; }
        .pill-muted { background: var(--bg); color: var(--text-faint); font-weight: 600; }

        .edit-highlight { background: var(--warning-50); border: 1.5px solid #fcd34d; }
        .edit-highlight-row td { background: var(--warning-50); }
        .edit-highlight-box { background: var(--warning-50); border: 1.5px solid #fcd34d; border-radius: var(--radius); padding: 20px; margin-top: 10px; }
        .edit-highlight-banner { background: #fde68a; color: #92400e; padding: 10px 14px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 700; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .edit-highlight-banner a { color: #92400e; text-decoration: underline; font-weight: 700; white-space: nowrap; }

        /* ── Template editor ─────────────────────────────────────────── */
        .editor-mode-toggle { display: flex; gap: 6px; margin-bottom: 16px; background: var(--bg); padding: 4px; border-radius: 10px; width: fit-content; }
        .editor-mode-toggle button { background: transparent; color: var(--text-muted); border: none; padding: 8px 16px; border-radius: 7px; cursor: pointer; font-weight: 700; font-size: 13px; box-shadow: none; }
        .editor-mode-toggle button:hover { background: none; box-shadow: none; transform: none; color: var(--text); }
        .editor-mode-toggle button.active { background: var(--surface); color: var(--primary); box-shadow: var(--shadow-sm); }
        .block-editor { display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start; }
        .block-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
        .block-card { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px; }
        .block-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .block-card-title { font-size: 11.5px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: .03em; }
        .block-card-actions button { background: none; border: none; cursor: pointer; color: var(--text-faint); font-size: 15px; padding: 2px 6px; box-shadow: none; }
        .block-card-actions button:hover:not(:disabled) { color: var(--text); background: none; box-shadow: none; transform: none; }
        .block-card-actions button:disabled { opacity: .3; cursor: default; }
        .block-card-actions button.del:hover:not(:disabled) { color: var(--danger); }
        .field-row { display: flex; gap: 10px; margin-bottom: 8px; }
        .field-row > * { flex: 1; min-width: 0; }
        .block-add-menu { display: flex; flex-wrap: wrap; gap: 8px; }
        .block-add-menu button { background: var(--surface); border: 1.5px dashed var(--border-strong); color: var(--text-muted); font-weight: 600; font-size: 12px; padding: 8px 12px; border-radius: var(--radius-sm); cursor: pointer; box-shadow: none; }
        .block-add-menu button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); background: var(--primary-50); box-shadow: none; transform: none; }
        .block-add-menu button:disabled { opacity: .35; cursor: default; }
        .block-preview-panel { position: sticky; top: 20px; }
        .block-preview-frame { width: 100%; height: 520px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); }

        /* ── Responsive ──────────────────────────────────────────────── */
        @media (max-width: 900px) {
            .block-editor { grid-template-columns: 1fr; }
            .block-preview-panel { position: static; }
            .block-preview-frame { height: 400px; }
        }
        @media (max-width: 860px) {
            body { display: block; }
            aside { position: fixed; top: 0; left: 0; bottom: 0; width: 260px; transform: translateX(-100%); transition: transform .2s ease; overflow-y: auto; }
            aside.open { transform: translateX(0); }
            main { padding: 20px 16px 50px; max-width: 100%; }
            .mobile-nav-toggle { display: flex; align-items: center; gap: 8px; background: var(--surface); border: 1px solid var(--border); color: var(--text); font-weight: 700; font-size: 13px; padding: 10px 14px; border-radius: var(--radius-sm); box-shadow: var(--shadow-sm); margin-bottom: 18px; cursor: pointer; }
            .mobile-nav-backdrop { display: none; position: fixed; inset: 0; background: rgba(15,18,30,.5); z-index: 15; }
            .mobile-nav-backdrop.open { display: block; }
            .card { padding: 22px 18px; }
            .grid { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: 1fr; }
            table { display: block; overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; }
            table thead, table tbody, table tr { display: table; width: 100%; table-layout: auto; }
        }
    </style>
</head>
<body>
<div class="mobile-nav-backdrop" id="mobile-nav-backdrop"></div>
<aside id="sidebar">
    <div class="logo-box">
        <?php if (file_exists(APP_DIR . '/logo.png')): ?>
            <img src="logo.png?v=<?= filemtime(APP_DIR . '/logo.png') ?>" alt="Logo">
        <?php else: ?>
            <div class="brand-fallback"><?= h($brand_name) ?></div>
        <?php endif; ?>
    </div>
    <?php if ($conf['paused']): ?><div class="pause-banner">⏸ AUTOMAZIONI IN PAUSA</div><?php endif; ?>
    <?php if (!$is_admin): ?><div class="pause-banner" style="background:#4d5670;">👁 SOLA LETTURA</div><?php endif; ?>
    <nav>
        <a href="?tab=sconti"       class="<?= $active_tab==='sconti'      ?'active':'' ?>">🎁 Regole Sconti</a>
        <a href="?tab=connessioni"  class="<?= $active_tab==='connessioni' ?'active':'' ?>">🔌 Connessioni</a>
        <a href="?tab=template"     class="<?= $active_tab==='template'    ?'active':'' ?>">✉️ Template Email</a>
        <a href="?tab=utenti"       class="<?= $active_tab==='utenti'      ?'active':'' ?>">👥 Utenti & Accesso</a>
        <a href="?tab=log"          class="<?= $active_tab==='log'         ?'active':'' ?>">📋 Log Webhook<?php if ($today_errors > 0): ?><span class="badge-nav"><?= $today_errors ?></span><?php endif; ?></a>
        <a href="?tab=strumenti"    class="<?= $active_tab==='strumenti'   ?'active':'' ?>">🛠️ Strumenti</a>
        <a href="?tab=guida"        class="<?= $active_tab==='guida'       ?'active':'' ?>">📖 Guida & Help</a>
        <a href="?logout=1" class="logout">🚪 Esci</a>
    </nav>
</aside>

<main>
    <button type="button" class="mobile-nav-toggle" id="mobile-nav-toggle">☰ Menu</button>
    <?php if (isset($_GET['msg'])): ?><div class="alert-ok">✅ <?= $_GET['msg']==='setup_ok' ? 'Setup completato. Benvenuto!' : 'Salvato correttamente.' ?></div><?php endif; ?>
    <?php if ($flash_ok):  ?><div class="alert-ok">✅ <?= h($flash_ok)  ?></div><?php endif; ?>
    <?php if ($flash_error): ?><div class="alert-err">⚠️ <?= h($flash_error) ?></div><?php endif; ?>


    <?php
    if ($active_tab === 'sconti') {
        require __DIR__ . '/dashboard_view_sconti.php';
    } elseif ($active_tab === 'connessioni') {
        require __DIR__ . '/dashboard_view_connessioni.php';
    } elseif ($active_tab === 'template') {
        require __DIR__ . '/dashboard_view_template.php';
    } elseif ($active_tab === 'utenti') {
        require __DIR__ . '/dashboard_view_utenti.php';
    } elseif ($active_tab === 'log') {
        require __DIR__ . '/dashboard_view_log.php';
    } elseif ($active_tab === 'strumenti') {
        require __DIR__ . '/dashboard_view_strumenti.php';
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
(function() {
    const sidebar  = document.getElementById('sidebar');
    const toggle   = document.getElementById('mobile-nav-toggle');
    const backdrop = document.getElementById('mobile-nav-backdrop');
    if (!sidebar || !toggle || !backdrop) return;
    function closeNav() { sidebar.classList.remove('open'); backdrop.classList.remove('open'); }
    toggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        backdrop.classList.toggle('open');
    });
    backdrop.addEventListener('click', closeNav);
})();
</script>
</body>
</html>
