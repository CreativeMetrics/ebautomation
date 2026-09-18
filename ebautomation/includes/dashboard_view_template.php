<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

    <div class="page-head">
        <h1>✉️ Template Email</h1>
        <p>Progetta l'email che i clienti ricevono con il codice sconto, con un editor visivo a blocchi.</p>
    </div>

    <div class="card">
        <h2>Template Configurati <a href="?action=export_templates" class="btn btn-secondary" style="margin-left:auto;font-size:12px;padding:8px 14px;">⬇ Esporta JSON</a></h2>
        <p class="card-subtitle">Uno o più template HTML completi, uno per lingua/variante visiva. Ogni regola sceglie quale usare (campo "Lingua Email"); chi non specifica nulla usa il predefinito.</p>
        <table>
            <thead><tr><th>Codice</th><th>Nome</th><th>Oggetto</th><th>Predefinito</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($email_templates as $lingua => $tpl):
                $is_being_edited = $edit_template_lingua === $lingua;
            ?>
                <tr<?= $is_being_edited ? ' class="edit-highlight-row"' : '' ?>>
                    <td><span class="badge"><?= h($lingua) ?></span></td>
                    <td><strong><?= h($tpl['nome']) ?></strong><?= $is_being_edited ? ' <span class="pill pill-warning">✏ in modifica</span>' : '' ?></td>
                    <td style="color:var(--text-muted);"><?= h($tpl['subject']) ?></td>
                    <td><?= $tpl['is_default'] ? '<span class="pill pill-success">✓ Predefinito</span>' : '<span class="pill pill-muted">—</span>' ?></td>
                    <td>
                        <div class="row-actions">
                            <a href="?tab=template&action=preview_email_template&lingua=<?= urlencode($lingua) ?>" target="_blank" class="icon-btn icon-info" title="Anteprima in una nuova scheda"><?= icon_svg('eye') ?></a>
                            <?php if ($is_admin): ?>
                            <a href="?tab=template&edit_template=<?= urlencode($lingua) ?>#template-form" class="icon-btn icon-info" title="Modifica template"><?= icon_svg('pencil') ?></a>
                            <?php if (count($email_templates) > 1): ?>
                            <form method="POST" onsubmit="return confirm('Eliminare il template <?= h(addslashes($tpl['nome'])) ?>?')">
                                <input type="hidden" name="action" value="delete_email_template">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="lingua" value="<?= h($lingua) ?>">
                                <button type="submit" class="icon-btn icon-danger" title="Elimina template"><?= icon_svg('trash') ?></button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
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
                <a href="?tab=template#template-form">Annulla, crea un nuovo template</a>
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
                        <?php if ($edit_template): ?><a href="?tab=template#template-form" class="btn btn-secondary" style="margin-left:8px;">Annulla</a><?php endif; ?>
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

