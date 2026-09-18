<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

    <div class="page-head">
        <h1>🎁 Regole Sconti</h1>
        <p>Definisci quali acquisti generano automaticamente un codice sconto, e per quale evento.</p>
    </div>

    <div class="card">
        <h2>Automazioni Attive <a href="?action=export_regole" class="btn btn-secondary" style="margin-left:auto;font-size:12px;padding:8px 14px;">⬇ Esporta JSON</a></h2>
        <table>
            <thead><tr><th>Trigger</th><th>Descrizione</th><th>Sconto</th><th>Qtà</th><th>Min. trigger</th><th>Scade</th><th>Target</th><th>Stato</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($regole as $tid => $r):
                $is_imp = ($r['tipo_sconto'] ?? 'percentuale') === 'importo';
                $sconto_label = $is_imp ? h((string)($r['importo_fisso'] ?? 0)) . ' ' . h($conf['currency'] ?: 'EUR') : h($r['percentuale']) . '%';
                $r_attiva = $r['attiva'] ?? true;
            ?>
            <tr<?= $r_attiva ? '' : ' style="opacity:.55;"' ?>>
                <td><span class="badge"><?= h($tid) ?></span></td>
                <td><strong><?= h($r['descrizione']) ?></strong></td>
                <td><?= $sconto_label ?></td>
                <td><?= h((string)($r['quantita'] ?? 1)) ?></td>
                <td><?= ($r['qty_minima'] ?? 1) > 1 ? h((string)$r['qty_minima']) . ' biglietti' : '—' ?></td>
                <td><?= ($r['giorni_scadenza'] ?? 0) > 0 ? h((string)$r['giorni_scadenza']) . ' gg' : '—' ?></td>
                <td><?php foreach ($r['target_ids'] as $t) echo '<span class="badge">'.h($t).'</span> '; ?></td>
                <td>
                    <?php if ($is_admin): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action"     value="toggle_regola">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="trigger_id" value="<?= h($tid) ?>">
                        <button type="submit" class="btn btn-secondary" style="font-size:11px;padding:6px 10px;<?= $r_attiva ? '' : 'background:#f59e0b;' ?>" title="<?= $r_attiva ? 'Disattiva questa regola' : 'Riattiva questa regola' ?>">
                            <?= $r_attiva ? '✓ attiva' : '⏸ disattiva' ?>
                        </button>
                    </form>
                    <?php else: ?>
                        <span class="pill <?= $r_attiva ? 'pill-success' : 'pill-muted' ?>"><?= $r_attiva ? '✓ attiva' : '⏸ disattiva' ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($is_admin): ?>
                    <div class="row-actions">
                        <a href="?tab=sconti&edit=<?= urlencode($tid) ?>" class="icon-btn icon-info" title="Modifica regola"><?= icon_svg('pencil') ?></a>
                        <form method="POST" onsubmit="return confirm('Eliminare questa regola?')">
                            <input type="hidden" name="action"     value="delete_regola">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="trigger_id" value="<?= h($tid) ?>">
                            <button type="submit" class="icon-btn icon-danger" title="Elimina regola"><?= icon_svg('trash') ?></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($regole)): ?><tr><td colspan="9" class="empty-state">Nessuna regola ancora creata. Creane una qui sotto. 👇</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($is_admin): ?>
    <div class="card <?= $edit_rule ? 'edit-highlight' : '' ?>">
        <h2><?= $edit_rule ? '✏️ Modifica Regola' : 'Nuova Regola' ?></h2>
        <?php if ($edit_rule): ?><p style="color:#92400e;font-size:13px;margin-top:-10px;">Trigger: <strong><?= h($edit_id) ?></strong></p><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action"     value="save_regola">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="grid">
                <div class="input-group">
                    <label>ID Evento Trigger</label>
                    <input type="text" name="trigger_id" id="f_t" value="<?= h($edit_id ?? '') ?>" <?= $edit_rule ? 'readonly style="background:#f1f5f9;"' : '' ?> required>
                </div>
                <div class="input-group">
                    <label>ID Target (separati da ,)</label>
                    <input type="text" name="target_id" id="f_r" value="<?= h(implode(', ', $edit_rule['target_ids'] ?? [])) ?>" required>
                </div>
                <div class="input-group">
                    <label>Nome Promozione</label>
                    <input type="text" name="descrizione" value="<?= h($edit_rule['descrizione'] ?? '') ?>">
                </div>
            </div>
            <div class="grid">
                <div class="input-group">
                    <label>Tipo Sconto</label>
                    <select name="tipo_sconto" id="tipo_sconto" onchange="toggleTipo(this.value)">
                        <option value="percentuale" <?= ($edit_rule['tipo_sconto'] ?? 'percentuale') === 'percentuale' ? 'selected' : '' ?>>Percentuale (%)</option>
                        <option value="importo"     <?= ($edit_rule['tipo_sconto'] ?? '')              === 'importo'     ? 'selected' : '' ?>>Importo Fisso</option>
                    </select>
                </div>
                <div class="input-group" id="grp-perc">
                    <label>% Sconto</label>
                    <input type="number" name="percentuale" value="<?= h((string)($edit_rule['percentuale'] ?? 100)) ?>" min="1" max="100">
                </div>
                <div class="input-group" id="grp-imp" style="display:none;">
                    <label>Importo Fisso (<?= h($conf['currency'] ?: 'EUR') ?>)</label>
                    <input type="number" name="importo_fisso" step="0.01" min="0" value="<?= h((string)($edit_rule['importo_fisso'] ?? 0)) ?>">
                </div>
                <div class="input-group">
                    <label>Prefisso Codice</label>
                    <input type="text" name="codice_prefix" value="<?= h($edit_rule['codice_prefix'] ?? 'GIFT') ?>" maxlength="10">
                </div>
            </div>
            <div class="grid">
                <div class="input-group">
                    <label>Quantità utilizzi</label>
                    <input type="number" name="quantita" value="<?= h((string)($edit_rule['quantita'] ?? 1)) ?>" min="1" max="9999">
                </div>
                <div class="input-group">
                    <label>Scadenza (giorni, 0 = mai)</label>
                    <input type="number" name="giorni_scadenza" value="<?= h((string)($edit_rule['giorni_scadenza'] ?? 0)) ?>" min="0" max="3650">
                </div>
                <div class="input-group">
                    <label>Quantità minima trigger</label>
                    <input type="number" name="qty_minima" value="<?= h((string)($edit_rule['qty_minima'] ?? 1)) ?>" min="1" max="9999">
                    <span class="tip">Biglietti dell'evento trigger richiesti nello stesso ordine perché la regola si attivi. 1 = sempre (default).</span>
                </div>
                <div class="input-group">
                    <label>Lingua Email</label>
                    <select name="lingua">
                        <option value="">— Usa il template predefinito —</option>
                        <?php foreach ($email_templates as $lingua => $tpl): ?>
                            <option value="<?= h($lingua) ?>" <?= ($edit_rule['lingua'] ?? '') === $lingua ? 'selected' : '' ?>><?= h($tpl['nome']) ?><?= $tpl['is_default'] ? ' (predefinito)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="tip">Determina il template email usato per comunicare gli sconti di questa regola. Gestisci i template nella tab "Template Email".</span>
                </div>
            </div>
            <div class="grid">
                <div class="input-group" style="justify-content:flex-end;align-items:flex-end;grid-column:1/-1;">
                    <div>
                        <button type="submit"><?= $edit_rule ? 'Aggiorna' : 'Crea Regola' ?></button>
                        <?php if ($edit_rule): ?><a href="?tab=sconti" class="btn btn-secondary" style="margin-left:8px;">Annulla</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>🎪 Eventi Disponibili</h2>
        <?php if (empty($conf['api_token'])): ?>
            <p style="color:var(--text-faint);">Configura il Token API nella tab <a href="?tab=connessioni">Connessioni</a> per vedere gli eventi.</p>
        <?php elseif (empty($events)): ?>
            <p style="color:var(--text-faint);">Nessun evento trovato. Verifica l'Organization ID nella tab <a href="?tab=connessioni">Connessioni</a>.</p>
        <?php else: ?>
        <p class="tip" style="margin-top:-14px;margin-bottom:16px;">Eventi di tutte le organizzazioni accessibili al tuo token — trigger e target possono appartenere a organizzazioni diverse.</p>
        <table>
            <thead><tr><th>Evento</th><th>Organizzazione</th><th>Status</th><th>ID</th></tr></thead>
            <tbody>
            <?php foreach ($events as $e): ?>
            <tr>
                <td><strong><?= h($e['name']['text'] ?? '') ?></strong></td>
                <td style="color:#64748b;"><?= h((string)($e['_org_name'] ?? '')) ?></td>
                <td><?= h($e['status'] ?? '') ?></td>
                <td><span class="badge" onclick="cp('<?= h($e['id']) ?>')" title="Clicca per copiare negli input"><?= h($e['id']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

