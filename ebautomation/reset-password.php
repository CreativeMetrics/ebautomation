<?php
/**
 * Reset password della dashboard.
 * Verifica l'identità richiedendo il Private Token API di Eventbrite,
 * che solo l'amministratore conosce e che non è mai esposto in URL pubblici.
 */
require __DIR__ . '/functions.php';

send_security_headers();

if ($issue = environment_issue()) {
    render_environment_error($issue);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure',   $is_https ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

$conf    = load_config();
$error   = '';
$success = false;

// Se la config non ha api_token, il reset non è possibile via questo script
if (empty($conf['api_token'])) {
    die('<p style="font-family:sans-serif;padding:40px;">Impossibile procedere: nessun token API configurato. Modifica il database direttamente via FTP/SSH oppure completa prima la configurazione dalla dashboard.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting: max 3 tentativi in 15 minuti, per sessione E per IP
    // (il solo limite di sessione è aggirabile non inviando il cookie).
    $ip_key       = 'reset:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $attempts     = (int)($_SESSION['rp_attempts']     ?? 0);
    $last_attempt = (int)($_SESSION['rp_last_attempt'] ?? 0);
    if (time() - $last_attempt > 900) $attempts = 0;

    if ($attempts >= 3 || !throttle_allowed($ip_key, 3, 900)) {
        $error = 'Troppi tentativi falliti. Riprova tra 15 minuti.';
    } else {
        $api_token = trim($_POST['api_token']        ?? '');
        $username  = trim($_POST['username']         ?? '') ?: 'admin';
        $new_pwd   = trim($_POST['new_password']     ?? '');
        $confirm   = trim($_POST['confirm_password'] ?? '');

        if (!hash_equals($conf['api_token'], $api_token)) {
            $_SESSION['rp_attempts']     = $attempts + 1;
            $_SESSION['rp_last_attempt'] = time();
            throttle_hit($ip_key, 900);
            $error = 'Private Token API non corretto. Trovi il token nel pannello Eventbrite → API Keys.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            $error = 'Nome utente non valido.';
        } elseif ($issue = password_issue($new_pwd)) {
            $error = $issue;
        } elseif ($new_pwd !== $confirm) {
            $error = 'Le password non coincidono.';
        } else {
            // Il token API verificato sopra è la prova d'identità: possiamo
            // reimpostare la password di un utente esistente, o crearlo se
            // non esiste ancora (utile se non si ricorda più quali utenti
            // sono stati configurati).
            $was_existing = set_user_password($username, password_hash($new_pwd, PASSWORD_DEFAULT));
            unset($_SESSION['rp_attempts'], $_SESSION['rp_last_attempt']);
            throttle_reset($ip_key);
            $_SESSION['username'] = $username; // per l'audit_log qui sotto
            audit_log($was_existing ? 'Password reimpostata via token API' : 'Utente creato via reset password (token API)', $username);
            unset($_SESSION['username']); // non autentica automaticamente: bisogna comunque fare login
            $success = true;
        }
    }
}

$brand = htmlspecialchars($conf['business_name'] ?: 'Dashboard', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Reset Password | <?= $brand ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8f9fa; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); max-width: 460px; width: 100%; }
        h2 { margin: 0 0 8px; color: #2d3142; font-size: 22px; }
        .sub { color: #64748b; font-size: 14px; margin: 0 0 28px; line-height: 1.5; }
        label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 6px; letter-spacing: .04em; }
        input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; box-sizing: border-box; margin-bottom: 18px; font-family: inherit; }
        input:focus { outline: none; border-color: #94a3b8; }
        button { width: 100%; background: #D64545; color: white; border: none; padding: 14px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 15px; transition: opacity 0.2s; }
        button:hover { opacity: 0.88; }
        .alert-err { background: #fee2e2; color: #991b1b; padding: 14px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border: 1px solid #fecaca; }
        .alert-ok  { background: #dcfce7; color: #166534; padding: 14px 16px; border-radius: 8px; font-size: 14px; border: 1px solid #bbf7d0; }
        .hint { background: #f8f9fa; border-left: 4px solid #e2e8f0; padding: 12px 16px; border-radius: 4px; font-size: 13px; color: #64748b; margin-bottom: 24px; line-height: 1.5; }
        a { color: #D64545; }
    </style>
</head>
<body>
<div class="card">
    <h2>🔑 Reset Password</h2>
    <p class="sub">Per verificare la tua identità inserisci il <strong>Private Token API</strong> di Eventbrite configurato nella dashboard.</p>

    <?php if ($success): ?>
        <div class="alert-ok">
            ✅ Password aggiornata correttamente.<br>
            <a href="dashboard.php" style="font-weight:700;">→ Accedi alla dashboard</a>
        </div>
    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert-err">⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="hint">
            Trovi il Private Token su <strong>eventbrite.com → Account → Impostazioni → Chiavi API</strong>.<br>
            È lo stesso token inserito nella tab Connessioni della dashboard.
        </div>

        <form method="POST" autocomplete="off">
            <label>Private Token API Eventbrite</label>
            <input type="password" name="api_token" placeholder="Incolla il token" required autocomplete="new-password">

            <label>Nome utente</label>
            <input type="text" name="username" value="admin" placeholder="admin" required>

            <label>Nuova Password Dashboard</label>
            <input type="password" name="new_password" placeholder="Minimo 10 caratteri" required autocomplete="new-password">

            <label>Conferma Nuova Password</label>
            <input type="password" name="confirm_password" placeholder="Ripeti la password" required autocomplete="new-password">

            <button type="submit">Reimposta Password</button>
        </form>

    <?php endif; ?>
</div>
</body>
</html>
