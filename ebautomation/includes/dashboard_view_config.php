<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

    <div class="card" style="border-top:4px solid <?= $conf['paused'] ? '#f59e0b' : '#10b981' ?>;">
        <h2><?= $conf['paused'] ? '⏸ Automazioni in Pausa' : '▶ Automazioni Attive' ?></h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;"><?= $conf['paused'] ? 'I webhook vengono ricevuti ma ignorati. Nessuno sconto verrà creato.' : 'Tutto funziona normalmente. Metti in pausa per bloccare temporaneamente le automazioni.' ?></p>
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

    <div class="card">
        <h2>✉️ Template Email</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Uno o più template HTML completi, uno per lingua/variante visiva. Ogni regola sceglie quale usare (campo "Lingua Email"); chi non specifica nulla usa il predefinito.</p>
        <table>
            <thead><tr><th>Codice</th><th>Nome</th><th>Oggetto</th><th>Predefinito</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($email_templates as $lingua => $tpl):
                $is_being_edited = $edit_template_lingua === $lingua;
            ?>
                <tr<?= $is_being_edited ? ' class="edit-highlight-row"' : '' ?>>
                    <td><span class="badge"><?= h($lingua) ?></span></td>
                    <td><strong><?= h($tpl['nome']) ?></strong><?= $is_being_edited ? ' <span class="badge" style="background:#fef3c7;color:#92400e;cursor:default;">✏ in modifica</span>' : '' ?></td>
                    <td style="color:#64748b;"><?= h($tpl['subject']) ?></td>
                    <td><?= $tpl['is_default'] ? '<span style="color:#10b981;">✓ predefinito</span>' : '—' ?></td>
                    <td style="white-space:nowrap;">
                        <a href="?tab=config&action=preview_email_template&lingua=<?= urlencode($lingua) ?>" target="_blank" style="color:#0ea5e9;text-decoration:none;font-weight:700;margin-right:8px;" title="Anteprima in una nuova scheda">👁</a>
                        <?php if ($is_admin): ?>
                        <a href="?tab=config&edit_template=<?= urlencode($lingua) ?>#template-form" style="color:#0ea5e9;text-decoration:none;font-weight:700;margin-right:8px;">✏</a>
                        <?php if (count($email_templates) > 1): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminare il template <?= h(addslashes($tpl['nome'])) ?>?')">
                            <input type="hidden" name="action" value="delete_email_template">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="lingua" value="<?= h($lingua) ?>">
                            <button type="submit" class="del-btn">&times;</button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($is_admin): ?>
        <div id="template-form" class="<?= $edit_template ? 'edit-highlight-box' : '' ?>">
            <?php if ($edit_template): ?>
            <div class="edit-highlight-banner">
                <span>✏️ Stai modificando il template <strong>«<?= h($edit_template['nome']) ?>»</strong> (codice <code style="display:inline;padding:1px 6px;background:rgba(0,0,0,0.06);"><?= h($edit_template_lingua) ?></code>)<?= $edit_template['is_default'] ? ' — è il template <strong>predefinito</strong>' : '' ?></span>
                <a href="?tab=config#template-form">Annulla, crea un nuovo template</a>
            </div>
            <?php endif; ?>
        <h3><?= $edit_template ? 'Modifica Template' : '+ Nuovo Template' ?></h3>
        <form method="POST">
            <input type="hidden" name="action"     value="save_email_template">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Codice lingua</label>
                    <input type="text" name="lingua" value="<?= h($edit_template_lingua ?? '') ?>" placeholder="es. it, en, es" maxlength="10" <?= $edit_template ? 'readonly style="background:#f1f5f9;"' : '' ?> required>
                    <span class="tip">Identificativo libero, non deve necessariamente essere un codice ISO. Non modificabile dopo la creazione.</span>
                </div>
                <div class="input-group">
                    <label>Nome (etichetta)</label>
                    <input type="text" name="nome" value="<?= h($edit_template['nome'] ?? '') ?>" placeholder="es. Italiano">
                </div>
                <div class="input-group">
                    <label>Colore Principale</label>
                    <input type="color" name="colore" id="tpl_colore" value="<?= h($edit_template['colore'] ?? '#D64545') ?>">
                </div>
            </div>
            <div class="grid">
                <div class="input-group" style="grid-column:1/-1;">
                    <label>Oggetto</label>
                    <input type="text" name="subject" value="<?= h($edit_template['subject'] ?? 'I tuoi regali da {{business_name}}') ?>">
                    <span class="tip">Segnaposto disponibile: <code style="display:inline;padding:1px 5px;">{{business_name}}</code></span>
                </div>
            </div>

            <div class="input-group" style="margin-bottom:20px;">
                <label>Struttura Email</label>
                <div class="editor-mode-toggle">
                    <button type="button" id="mode-btn-visual">🧱 Editor Visivo</button>
                    <button type="button" id="mode-btn-code">&lt;/&gt; Codice HTML</button>
                </div>

                <input type="hidden" name="editor_mode" id="editor_mode_input" value="<?= h($edit_template_initial_mode) ?>">
                <input type="hidden" name="blocks_json" id="blocks_json_input" value="">

                <div id="visual-editor-box"<?= $edit_template_initial_mode !== 'visual' ? ' hidden' : '' ?>>
                    <div class="block-editor">
                        <div>
                            <div class="block-list" id="blocks-list"></div>
                            <div class="block-add-menu">
                                <button type="button" data-add="logo">+ 🖼 Logo</button>
                                <button type="button" data-add="heading">+ 🔤 Titolo</button>
                                <button type="button" data-add="text">+ 📝 Testo</button>
                                <button type="button" data-add="gift_box">+ 🎁 Box Sconto</button>
                                <button type="button" data-add="divider">+ ➖ Divisore</button>
                                <button type="button" data-add="spacer">+ ↕ Spazio</button>
                                <button type="button" data-add="footer">+ 📄 Piè di pagina</button>
                            </div>
                            <p class="tip" style="margin-top:12px;">Segnaposto utilizzabili nei testi: <code style="display:inline;padding:1px 5px;">{{nome}}</code> <code style="display:inline;padding:1px 5px;">{{anno}}</code> <code style="display:inline;padding:1px 5px;">{{business_name}}</code></p>
                        </div>
                        <div class="block-preview-panel">
                            <label style="display:block;margin-bottom:8px;">Anteprima Live</label>
                            <iframe id="block-preview-frame" class="block-preview-frame" sandbox="allow-same-origin" title="Anteprima email"></iframe>
                        </div>
                    </div>
                </div>

                <div id="code-editor-box"<?= $edit_template_initial_mode !== 'code' ? ' hidden' : '' ?>>
                    <div class="input-group" style="margin-bottom:16px;">
                        <label>Corpo Email (HTML)</label>
                        <textarea name="body_html" style="min-height:220px;font-family:monospace;font-size:12px;"><?= h($edit_template['body_html'] ?? '') ?></textarea>
                        <span class="tip">Segnaposto disponibili: <code style="display:inline;padding:1px 5px;">{{business_name}}</code> <code style="display:inline;padding:1px 5px;">{{nome}}</code> <code style="display:inline;padding:1px 5px;">{{items}}</code> <code style="display:inline;padding:1px 5px;">{{logo}}</code> <code style="display:inline;padding:1px 5px;">{{anno}}</code> <code style="display:inline;padding:1px 5px;">{{colore}}</code> — <code style="display:inline;padding:1px 5px;">{{items}}</code> viene sostituito con i blocchi sconto (vedi sotto), uno per ogni codice regalo.</span>
                    </div>
                    <div class="input-group">
                        <label>Blocco Singolo Sconto (HTML, ripetuto per ogni codice)</label>
                        <textarea name="item_html" style="min-height:120px;font-family:monospace;font-size:12px;"><?= h($edit_template['item_html'] ?? '') ?></textarea>
                        <span class="tip">Segnaposto disponibili: <code style="display:inline;padding:1px 5px;">{{desc}}</code> <code style="display:inline;padding:1px 5px;">{{code}}</code> <code style="display:inline;padding:1px 5px;">{{url}}</code> <code style="display:inline;padding:1px 5px;">{{label}}</code> <code style="display:inline;padding:1px 5px;">{{colore}}</code></span>
                    </div>
                </div>
            </div>

            <div class="grid">
                <div class="input-group">
                    <label style="text-transform:none;font-size:13px;display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_default" value="1" style="width:auto;" <?= ($edit_template['is_default'] ?? false) ? 'checked' : '' ?>>
                        Usa come predefinito
                    </label>
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <div>
                        <button type="submit"><?= $edit_template ? 'Aggiorna Template' : 'Crea Template' ?></button>
                        <?php if ($edit_template): ?><a href="?tab=config#template-form" class="btn btn-secondary" style="margin-left:8px;">Annulla</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
        </div>

        <script>
        (function() {
            const CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const DEFAULT_BLOCKS = <?= json_encode(default_email_blocks(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const BLOCK_LABELS = {
                logo: '🖼 Logo / Nome Azienda', heading: '🔤 Titolo', text: '📝 Testo',
                gift_box: '🎁 Box Sconto', divider: '➖ Divisore', spacer: '↕ Spazio', footer: '📄 Piè di pagina',
            };

            let blocks = <?= json_encode($edit_template_blocks_for_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            let editorMode = <?= json_encode($edit_template_initial_mode) ?>;

            const listEl = document.getElementById('blocks-list');
            if (!listEl) return; // form non presente (utente non admin)

            function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
            function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

            function alignSelect(b, i) {
                const cur = b.align || 'center';
                const labels = { left: 'Sinistra', center: 'Centro', right: 'Destra' };
                return '<select data-f="align" data-i="' + i + '">' + ['left','center','right'].map(v =>
                    '<option value="' + v + '"' + (cur === v ? ' selected' : '') + '>' + labels[v] + '</option>'
                ).join('') + '</select>';
            }

            function newBlock(type) {
                switch (type) {
                    case 'logo':     return { type: 'logo', align: 'center', width: 150 };
                    case 'heading':  return { type: 'heading', text: 'Ciao {{nome}}!', align: 'center', color: '#2d3142' };
                    case 'text':     return { type: 'text', text: 'Scrivi qui il tuo testo…', align: 'center', color: '#4f5d75' };
                    case 'gift_box': return { type: 'gift_box', label: "Per l'evento:", button_text: 'Usa Sconto' };
                    case 'divider':  return { type: 'divider' };
                    case 'spacer':   return { type: 'spacer', height: 20 };
                    case 'footer':   return { type: 'footer', text: '© {{anno}} {{business_name}}' };
                }
            }

            function blockFields(b, i) {
                switch (b.type) {
                    case 'logo':
                        return '<div class="field-row">' + alignSelect(b, i)
                            + '<input type="number" data-f="width" data-i="' + i + '" value="' + (b.width || 150) + '" min="40" max="400" step="10" style="max-width:120px;flex:none;" title="Larghezza in pixel"></div>'
                            + '<span class="tip">Larghezza in pixel (40–400). L\'altezza si adatta da sola per mantenere le proporzioni originali dell\'immagine.</span>';
                    case 'heading':
                        return '<div class="field-row"><input type="text" data-f="text" data-i="' + i + '" value="' + escAttr(b.text || '') + '" placeholder="Ciao {{nome}}!" maxlength="200"></div>'
                            + '<div class="field-row">' + alignSelect(b, i) + '<input type="color" data-f="color" data-i="' + i + '" value="' + (b.color || '#2d3142') + '" title="Colore testo"></div>';
                    case 'text':
                        return '<div class="field-row"><textarea data-f="text" data-i="' + i + '" maxlength="1000" style="min-height:60px;">' + escHtml(b.text || '') + '</textarea></div>'
                            + '<div class="field-row">' + alignSelect(b, i) + '<input type="color" data-f="color" data-i="' + i + '" value="' + (b.color || '#4f5d75') + '" title="Colore testo"></div>';
                    case 'gift_box':
                        return '<div class="field-row">'
                            + '<input type="text" data-f="label" data-i="' + i + '" value="' + escAttr(b.label || '') + '" placeholder="Per l\'evento:" maxlength="100">'
                            + '<input type="text" data-f="button_text" data-i="' + i + '" value="' + escAttr(b.button_text || '') + '" placeholder="Usa Sconto" maxlength="60">'
                            + '</div><span class="tip">Colore ripreso dal "Colore Principale" qui sopra. Va sempre al posto del codice sconto, uno per ogni regalo.</span>';
                    case 'divider':
                        return '<span class="tip">Una riga sottile per separare le sezioni, nessuna impostazione.</span>';
                    case 'spacer':
                        return '<div class="field-row"><input type="number" data-f="height" data-i="' + i + '" value="' + (b.height || 20) + '" min="4" max="120" style="max-width:120px;flex:none;"><span class="tip" style="align-self:center;">altezza in pixel</span></div>';
                    case 'footer':
                        return '<div class="field-row"><textarea data-f="text" data-i="' + i + '" maxlength="300" style="min-height:50px;">' + escHtml(b.text || '') + '</textarea></div><span class="tip">Segnaposto disponibili: {{anno}} {{business_name}}</span>';
                    default:
                        return '';
                }
            }

            function renderBlockCard(b, i) {
                return '<div class="block-card">'
                    + '<div class="block-card-head">'
                    + '<span class="block-card-title">' + (BLOCK_LABELS[b.type] || b.type) + '</span>'
                    + '<span class="block-card-actions">'
                    + '<button type="button" data-act="up" data-i="' + i + '"' + (i === 0 ? ' disabled' : '') + ' title="Sposta su">↑</button>'
                    + '<button type="button" data-act="down" data-i="' + i + '"' + (i === blocks.length - 1 ? ' disabled' : '') + ' title="Sposta giù">↓</button>'
                    + '<button type="button" class="del" data-act="del" data-i="' + i + '" title="Elimina">🗑</button>'
                    + '</span></div>'
                    + blockFields(b, i)
                    + '</div>';
            }

            function updateAddMenu() {
                const hasLogo = blocks.some(b => b.type === 'logo');
                const hasGift = blocks.some(b => b.type === 'gift_box');
                document.querySelectorAll('.block-add-menu button[data-add]').forEach(btn => {
                    const t = btn.getAttribute('data-add');
                    btn.disabled = (t === 'logo' && hasLogo) || (t === 'gift_box' && hasGift);
                });
            }

            function syncHidden() {
                document.getElementById('blocks_json_input').value = JSON.stringify(blocks);
            }

            function renderList() {
                listEl.innerHTML = blocks.map(renderBlockCard).join('') || '<p class="tip">Nessun blocco: aggiungine uno qui sotto.</p>';
                updateAddMenu();
                syncHidden();
            }

            let previewTimer = null;
            function schedulePreview() {
                clearTimeout(previewTimer);
                previewTimer = setTimeout(runPreview, 400);
            }
            async function runPreview() {
                const frame = document.getElementById('block-preview-frame');
                if (!frame || editorMode !== 'visual') return;
                const form = new FormData();
                form.append('action', 'preview_blocks');
                form.append('csrf_token', CSRF);
                form.append('blocks_json', JSON.stringify(blocks));
                form.append('colore', document.getElementById('tpl_colore').value);
                try {
                    const res = await fetch('dashboard.php', { method: 'POST', body: form });
                    frame.srcdoc = await res.text();
                } catch (e) { /* anteprima non disponibile, non blocca la modifica */ }
            }

            listEl.addEventListener('input', function(e) {
                const f = e.target.getAttribute('data-f'), i = e.target.getAttribute('data-i');
                if (f === null || i === null) return;
                blocks[+i][f] = (e.target.type === 'number') ? (parseInt(e.target.value, 10) || 0) : e.target.value;
                syncHidden();
                schedulePreview();
            });
            listEl.addEventListener('click', function(e) {
                const btn = e.target.closest('button[data-act]');
                if (!btn) return;
                const act = btn.getAttribute('data-act'), i = +btn.getAttribute('data-i');
                if (act === 'del') blocks.splice(i, 1);
                else if (act === 'up' && i > 0) [blocks[i - 1], blocks[i]] = [blocks[i], blocks[i - 1]];
                else if (act === 'down' && i < blocks.length - 1) [blocks[i + 1], blocks[i]] = [blocks[i], blocks[i + 1]];
                renderList();
                schedulePreview();
            });
            document.querySelectorAll('.block-add-menu button[data-add]').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (btn.disabled) return;
                    blocks.push(newBlock(btn.getAttribute('data-add')));
                    renderList();
                    schedulePreview();
                });
            });
            document.getElementById('tpl_colore').addEventListener('input', schedulePreview);

            function setMode(mode) {
                if (mode === 'visual' && editorMode === 'code') {
                    if (!confirm('Passando all\'Editor Visivo, al salvataggio l\'HTML scritto a mano verrà sostituito da un template generato dai blocchi. Continuare?')) return;
                    if (!blocks || !blocks.length) blocks = JSON.parse(JSON.stringify(DEFAULT_BLOCKS));
                }
                editorMode = mode;
                document.getElementById('editor_mode_input').value = mode;
                document.getElementById('mode-btn-visual').classList.toggle('active', mode === 'visual');
                document.getElementById('mode-btn-code').classList.toggle('active', mode === 'code');
                document.getElementById('visual-editor-box').hidden = mode !== 'visual';
                document.getElementById('code-editor-box').hidden = mode !== 'code';
                if (mode === 'visual') { renderList(); runPreview(); }
            }
            document.getElementById('mode-btn-visual').addEventListener('click', () => setMode('visual'));
            document.getElementById('mode-btn-code').addEventListener('click', () => setMode('code'));
            document.getElementById('mode-btn-visual').classList.toggle('active', editorMode === 'visual');
            document.getElementById('mode-btn-code').classList.toggle('active', editorMode === 'code');

            renderList();
            if (editorMode === 'visual') runPreview();
        })();
        </script>

        <h3>Invia Email di Test</h3>
        <form method="POST">
            <input type="hidden" name="action"     value="test_email_template">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Template</label>
                    <select name="lingua">
                        <?php foreach ($email_templates as $lingua => $tpl): ?>
                            <option value="<?= h($lingua) ?>"><?= h($tpl['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Indirizzo destinatario</label>
                    <input type="email" name="test_email" value="<?= h($conf['smtp_user']) ?>" required>
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <button type="submit" class="btn-info">📧 Invia con dati di esempio</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>🔑 La tua password (<?= h($current_username) ?>)</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Chiunque può cambiare la propria password, indipendentemente dal ruolo.</p>
        <form method="POST">
            <input type="hidden" name="action"     value="change_own_password">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>Nuova Password (lascia vuoto per non cambiare)</label>
                    <input type="password" name="new_password" placeholder="Minimo 10 caratteri" autocomplete="new-password">
                </div>
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;">
                    <button type="submit" class="btn-secondary">Cambia Password</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($is_admin): ?>
    <div class="card">
        <h2>👥 Utenti Dashboard</h2>
        <p style="color:#64748b;font-size:14px;margin-top:0;">Ogni utente ha le proprie credenziali; le azioni compiute vengono registrate nel log di audit (tab Log) con nome utente e IP. Un utente "sola lettura" può vedere tutto ma non modificare nulla.</p>
        <table>
            <thead><tr><th>Utente</th><th>Ruolo</th><th>Creato il</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $uname => $u):
                $u_role = $u['role'] ?? 'admin';
            ?>
                <tr>
                    <td><strong><?= h($uname) ?></strong><?= $uname === $current_username ? ' <span class="badge" style="cursor:default;">tu</span>' : '' ?></td>
                    <td><span class="badge" style="cursor:default;<?= $u_role === 'viewer' ? 'color:#64748b;' : '' ?>"><?= $u_role === 'admin' ? '⚙ admin' : '👁 sola lettura' ?></span></td>
                    <td style="color:#64748b;"><?= !empty($u['created_at']) ? date('d/m/Y H:i', $u['created_at']) : '—' ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($uname !== $current_username): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action"   value="toggle_user_role">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="username"  value="<?= h($uname) ?>">
                            <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:6px 10px;margin-right:6px;"><?= $u_role === 'admin' ? '→ rendi sola lettura' : '→ rendi admin' ?></button>
                        </form>
                        <?php endif; ?>
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
                <div class="input-group">
                    <label>Ruolo</label>
                    <select name="new_user_role">
                        <option value="admin">Amministratore</option>
                        <option value="viewer">Sola lettura</option>
                    </select>
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
    <?php endif; ?>

