<?php
define('CONFIG_FILE',        __DIR__ . '/config.json');
define('REGOLE_FILE',        __DIR__ . '/regole_sconti.json');
define('LOG_FILE',           __DIR__ . '/webhook_log.txt');
define('THROTTLE_FILE',      __DIR__ . '/login_throttle.json');
define('SECRET_KEY_FILE',    __DIR__ . '/secret.php');
define('PROCESSED_FILE',     __DIR__ . '/processed_orders.json');
define('FAILED_ORDERS_FILE', __DIR__ . '/failed_orders.json');
define('ALERT_STATE_FILE',   __DIR__ . '/alert_state.json');
define('USERS_FILE',         __DIR__ . '/users.json');
define('AUDIT_LOG_FILE',     __DIR__ . '/audit_log.txt');

/**
 * Chiave di cifratura per i segreti salvati in config.json (api_token,
 * smtp_pass). Viene generata una sola volta e conservata in un file .php:
 * essendo eseguito dal webserver come codice (non produce output), non è
 * mai scaricabile come testo — a differenza di un .json/.txt, la cui
 * protezione dipende dal fatto che .htaccess sia effettivamente applicato
 * dal webserver in uso.
 */
function get_secret_key(): string {
    if (!file_exists(SECRET_KEY_FILE)) {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        file_put_contents(SECRET_KEY_FILE, "<?php\nreturn '" . $key . "';\n", LOCK_EX);
        @chmod(SECRET_KEY_FILE, 0600);
    }
    return base64_decode((string) require SECRET_KEY_FILE);
}

function encrypt_secret(string $plain): string {
    if ($plain === '') return '';
    $key   = get_secret_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct    = sodium_crypto_secretbox($plain, $nonce, $key);
    return 'enc:v1:' . base64_encode($nonce . $ct);
}

function decrypt_secret(string $value): string {
    if ($value === '' || !str_starts_with($value, 'enc:v1:')) {
        return $value; // valore in chiaro (config legacy non ancora migrata) o vuoto
    }
    $raw = base64_decode(substr($value, 7));
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct     = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain  = sodium_crypto_secretbox_open($ct, $nonce, get_secret_key());
    return $plain !== false ? $plain : '';
}

function load_config(): array {
    $defaults = [
        'business_name'      => '',
        'api_token'          => '',
        'org_id'             => '',
        'smtp_host'          => '',
        'smtp_user'          => '',
        'smtp_pass'          => '',
        'smtp_port'          => '465',
        'smtp_encryption'    => 'smtps',
        'currency'           => 'EUR',
        'dashboard_password' => '',
        'webhook_token'      => '',
        'paused'             => false,
        'email_subject'      => 'I tuoi regali da {{business_name}}',
        'email_intro'        => 'Grazie per i tuoi acquisti. Ecco i regali che abbiamo riservato per te:',
        'email_greeting'     => 'Ciao {{nome}}!',
        'email_color'        => '#D64545',
        'alert_email'        => '',
        'alert_threshold'    => '3',
    ];
    if (!file_exists(CONFIG_FILE)) return $defaults;
    $data = json_decode(file_get_contents(CONFIG_FILE), true);
    $conf = array_merge($defaults, (array)$data);
    // api_token e smtp_pass sono cifrati a riposo (vedi save_config);
    // decrypt_secret restituisce il valore invariato se non è cifrato,
    // quindi una config esistente in chiaro continua a funzionare e
    // viene migrata automaticamente al primo save_config().
    $conf['api_token'] = decrypt_secret((string)$conf['api_token']);
    $conf['smtp_pass'] = decrypt_secret((string)$conf['smtp_pass']);
    return $conf;
}

function save_config(array $config): void {
    make_config_backup(); // backup della versione precedente prima di sovrascrivere
    if (isset($config['api_token'])) $config['api_token'] = encrypt_secret((string)$config['api_token']);
    if (isset($config['smtp_pass'])) $config['smtp_pass'] = encrypt_secret((string)$config['smtp_pass']);
    file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Valida la robustezza di una nuova password, restituendo il messaggio
 * d'errore da mostrare oppure null se la password è accettabile.
 * Segue l'approccio NIST 800-63B: si privilegia la lunghezza rispetto a
 * regole di complessità arbitrarie (maiuscole/simboli obbligatori), che
 * spingono verso pattern prevedibili senza aumentare davvero la sicurezza;
 * si blocca invece un piccolo elenco di password banali/prevedibili.
 */
function password_issue(string $pwd): ?string {
    if (mb_strlen($pwd) < 10) {
        return 'La password deve essere di almeno 10 caratteri.';
    }
    static $common = [
        'password', 'password1', 'password123', '12345678', '123456789',
        '1234567890', 'qwertyuiop', 'letmein123', 'admin12345', 'welcome123',
        'iloveyou12', 'changeme123', 'dashboard1', 'abcdefghij', 'eventbrite',
    ];
    if (in_array(mb_strtolower($pwd), $common, true)) {
        return 'Questa password è troppo comune e facilmente indovinabile. Scegline una più originale.';
    }
    if (preg_match('/^\d+$/', $pwd)) {
        return 'La password non può contenere solo cifre.';
    }
    return null;
}

/**
 * Copia $file in backups/<prefix>_YYYYMMDD_HHMMSS.<ext>, tenendo solo gli
 * ultimi $keep. Usata sia per regole_sconti.json che per config.json.
 */
function make_backup(string $file, string $prefix, int $keep = 10): void {
    if (!is_readable($file)) return;
    $dir = dirname($file) . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    $ext = pathinfo($file, PATHINFO_EXTENSION) ?: 'json';
    copy($file, $dir . '/' . $prefix . '_' . date('Ymd_His') . '.' . $ext);
    $files = glob($dir . '/' . $prefix . '_*.' . $ext) ?: [];
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $old) {
        unlink($old);
    }
}

function make_regole_backup(): void {
    make_backup(REGOLE_FILE, 'regole');
}

function make_config_backup(): void {
    make_backup(CONFIG_FILE, 'config');
}

function load_regole(): array {
    if (!is_readable(REGOLE_FILE)) return [];
    return json_decode(file_get_contents(REGOLE_FILE), true) ?: [];
}

function rotate_logs(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (file_exists(LOG_FILE)) {
        $mtime = filemtime(LOG_FILE);
        if (date('m', $mtime) !== date('m')) {
            rename(LOG_FILE, dirname(LOG_FILE) . '/webhook_log_' . date('Y_m', $mtime) . '.txt');
        }
    }
    foreach (glob(dirname(LOG_FILE) . '/webhook_log_*.txt') ?: [] as $file) {
        if (filemtime($file) < time() - 180 * 86400) {
            unlink($file);
        }
    }
}

function write_log(string $msg): void {
    rotate_logs();
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $t = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

/**
 * Invia header di hardening di base. Da chiamare prima di qualsiasi output HTML.
 */
function send_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

/**
 * Legge, modifica e riscrive un file JSON in una singola sezione critica
 * protetta da flock, per evitare race condition read-modify-write tra
 * richieste concorrenti (es. webhook duplicati inviati da Eventbrite).
 * $mutator riceve l'array decodificato (o [] se il file è vuoto/assente)
 * e deve restituire l'array da salvare.
 */
function atomic_json_update(string $file, callable $mutator): array {
    $fp = fopen($file, 'c+');
    if (!$fp) return [];
    flock($fp, LOCK_EX);
    $size = filesize($file) ?: 0;
    $raw  = $size > 0 ? fread($fp, $size) : '';
    $data = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    $data = $mutator($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

/**
 * Rate limiting per IP (indipendente dalla sessione/cookie), a complemento
 * del throttling basato su sessione: quest'ultimo da solo è aggirabile
 * semplicemente non inviando il cookie di sessione.
 */
function throttle_allowed(string $key, int $max, int $window): bool {
    $data  = file_exists(THROTTLE_FILE) ? (json_decode(file_get_contents(THROTTLE_FILE), true) ?: []) : [];
    $entry = $data[$key] ?? null;
    if (!$entry || (time() - $entry['first']) > $window) return true;
    return $entry['count'] < $max;
}

function throttle_hit(string $key, int $window): void {
    atomic_json_update(THROTTLE_FILE, function (array $data) use ($key, $window) {
        foreach ($data as $k => $v) {
            if ((time() - $v['first']) > $window) unset($data[$k]);
        }
        $entry = $data[$key] ?? ['count' => 0, 'first' => time()];
        if ((time() - $entry['first']) > $window) $entry = ['count' => 0, 'first' => time()];
        $entry['count']++;
        $data[$key] = $entry;
        return $data;
    });
}

function throttle_reset(string $key): void {
    if (!file_exists(THROTTLE_FILE)) return;
    atomic_json_update(THROTTLE_FILE, function (array $data) use ($key) {
        unset($data[$key]);
        return $data;
    });
}

/**
 * Esegue una richiesta HTTP con retry automatico sui soli errori transitori
 * (timeout/errore di rete, HTTP 429, HTTP 5xx). Un 4xx (es. parametri
 * invalidi, codice sconto duplicato) non viene ritentato: non migliorerebbe
 * riprovando. Ritorna sempre l'esito dell'ULTIMO tentativo.
 */
function api_call_with_retry(string $url, array $curl_opts, int $max_attempts = 3): array {
    $delays_us = [300000, 900000, 2000000]; // 0.3s, 0.9s, 2s
    $result = ['status' => 0, 'body' => null, 'raw' => '', 'curl_errno' => -1, 'attempts' => 0];
    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, $curl_opts);
        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = ['status' => $status, 'body' => json_decode((string)$raw, true), 'raw' => (string)$raw, 'curl_errno' => $errno, 'attempts' => $attempt];
        $transient = $errno !== 0 || $status === 429 || $status >= 500;
        if (!$transient || $attempt === $max_attempts) break;
        usleep($delays_us[$attempt - 1] ?? 2000000);
    }
    return $result;
}

/**
 * Risolve dinamicamente l'organization_id proprietaria di un evento
 * Eventbrite, interrogando l'API invece di assumere un org_id fisso in
 * config. Permette a un singolo token di gestire regole sconto su più
 * organizzazioni. Risultato cachato in-request (un ordine può avere più
 * target sullo stesso evento).
 */
function resolve_event_org_id(string $event_id, string $api_token): ?string {
    static $cache = [];
    if (array_key_exists($event_id, $cache)) return $cache[$event_id];

    $res = api_call_with_retry("https://www.eventbriteapi.com/v3/events/{$event_id}/?fields=organization_id", [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $api_token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ], 2);
    $org_id = $res['status'] === 200 ? (string)($res['body']['organization_id'] ?? '') : '';
    return $cache[$event_id] = ($org_id !== '' ? $org_id : null);
}

/**
 * Coda degli ordini che, dopo i retry immediati, restano con almeno uno
 * sconto o l'email ancora da completare. Consultata dalla dashboard
 * (azione "Riprova ordini falliti") per ritentare più tardi senza dover
 * aspettare un nuovo webhook da Eventbrite.
 */
function queue_failed_order(string $order_id, string $api_url, string $reason): void {
    atomic_json_update(FAILED_ORDERS_FILE, function (array $data) use ($order_id, $api_url, $reason) {
        $data[$order_id] = ['ts' => time(), 'api_url' => $api_url, 'reason' => $reason];
        return $data;
    });
}

function unqueue_failed_order(string $order_id): void {
    if (!file_exists(FAILED_ORDERS_FILE)) return;
    atomic_json_update(FAILED_ORDERS_FILE, function (array $data) use ($order_id) {
        unset($data[$order_id]);
        return $data;
    });
}

function load_failed_orders(): array {
    if (!file_exists(FAILED_ORDERS_FILE)) return [];
    return json_decode(file_get_contents(FAILED_ORDERS_FILE), true) ?: [];
}

/**
 * Utenti della dashboard. Se users.json non esiste ancora ma è presente
 * una password singola "legacy" in config.json (installazioni create prima
 * dell'introduzione del multi-utente), viene migrata automaticamente in un
 * unico utente "admin" la prima volta che load_users() viene chiamata.
 */
function load_users(): array {
    if (file_exists(USERS_FILE)) {
        return json_decode(file_get_contents(USERS_FILE), true) ?: [];
    }
    $conf = load_config();
    if (!empty($conf['dashboard_password'])) {
        $users = ['admin' => ['password_hash' => $conf['dashboard_password'], 'created_at' => time()]];
        save_users($users);
        return $users;
    }
    return [];
}

function save_users(array $users): void {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Registra un'azione nel log di audit: chi (utente in sessione), da dove
 * (IP), quando, cosa. Va richiamata su ogni azione della dashboard che
 * modifica stato (config, regole, utenti, pausa, ecc.).
 */
function audit_log(string $action, string $detail = ''): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user = $_SESSION['username'] ?? 'sconosciuto';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '-';
    $line = '[' . date('Y-m-d H:i:s') . "] $user ($ip): $action" . ($detail !== '' ? " — $detail" : '') . "\n";
    file_put_contents(AUDIT_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Se gli errori odierni nel log superano la soglia configurata, invia
 * un'email di alert all'indirizzo admin — al massimo una volta per ora
 * (e non ripetuta se il conteggio errori non è salito da allora), per non
 * spammare la casella dell'amministratore ad ogni ordine fallito.
 */
function maybe_send_error_alert(array $conf): void {
    if (empty($conf['smtp_host']) || empty($conf['smtp_user'])) return;

    $threshold = max(1, (int)($conf['alert_threshold'] ?: 3));
    $window    = 3600; // al massimo 1 alert/ora

    $today_errors = 0;
    if (file_exists(LOG_FILE)) {
        $today_prefix = '[' . date('Y-m-d');
        foreach (file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, $today_prefix) && stripos($line, 'ERRORE') !== false) $today_errors++;
        }
    }
    if ($today_errors < $threshold) return;

    $state      = file_exists(ALERT_STATE_FILE) ? (json_decode(file_get_contents(ALERT_STATE_FILE), true) ?: []) : [];
    $last_ts    = (int)($state['last_ts'] ?? 0);
    $last_count = (int)($state['last_count'] ?? 0);
    if ((time() - $last_ts) < $window && $today_errors <= $last_count) return;

    $alert_email = $conf['alert_email'] ?: $conf['smtp_user'];
    if (!filter_var($alert_email, FILTER_VALIDATE_EMAIL)) return;

    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    $mail  = new \PHPMailer\PHPMailer\PHPMailer(true);
    $bname = $conf['business_name'] ?: 'Automazione Sconti';
    try {
        $mail->isSMTP();
        $mail->Host       = $conf['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $conf['smtp_user'];
        $mail->Password   = $conf['smtp_pass'];
        $mail->SMTPSecure = ($conf['smtp_encryption'] === 'tls') ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = (int)$conf['smtp_port'];
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($conf['smtp_user'], $bname);
        $mail->addAddress($alert_email);
        $mail->isHTML(false);
        $mail->Subject = "⚠️ $bname: $today_errors errori oggi nell'automazione sconti";
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $mail->Body = "Ci sono $today_errors errori registrati oggi nel log dell'automazione sconti Eventbrite."
            . ($host ? "\n\nControlla la dashboard: https://$host/dashboard.php?tab=log" : '')
            . "\n\nQuesto avviso viene inviato al massimo una volta ogni ora.";
        $mail->send();
        file_put_contents(ALERT_STATE_FILE, json_encode(['last_ts' => time(), 'last_count' => $today_errors]), LOCK_EX);
        write_log("Alert email inviato a $alert_email ($today_errors errori oggi).");
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        write_log('ERRORE invio alert admin: ' . $mail->ErrorInfo);
    }
}
