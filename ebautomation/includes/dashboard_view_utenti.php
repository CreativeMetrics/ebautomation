<?php if (!defined('EBAUTO_APP')) { http_response_code(403); exit; } ?>

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
    <?php endif; ?>
