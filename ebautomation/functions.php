<?php
define('LOG_FILE',        __DIR__ . '/webhook_log.txt');
define('SECRET_KEY_FILE', __DIR__ . '/secret.php');
define('AUDIT_LOG_FILE',  __DIR__ . '/audit_log.txt');
define('DB_FILE',         __DIR__ . '/database.sqlite');

/**
 * Chiave di cifratura per i segreti salvati in config (api_token,
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
        return $value; // valore in chiaro (dato legacy non ancora migrato) o vuoto
    }
    $raw = base64_decode(substr($value, 7));
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct     = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain  = sodium_crypto_secretbox_open($ct, $nonce, get_secret_key());
    return $plain !== false ? $plain : '';
}

// ── DATABASE ────────────────────────────────────────────────────────────────
// Storage applicativo (config, regole, ordini processati, utenti, coda
// falliti, throttle, stato alert) su SQLite invece di singoli file JSON:
// scritture/letture per-riga realmente atomiche (niente più "riscrivi tutto
// il file ad ogni modifica"), transazioni vere per le sezioni critiche
// (idempotenza ordini), un solo file da includere nei backup.
// I log (webhook_log.txt, audit_log.txt) restano file di testo append-only:
// sono già efficienti così e non hanno bisogno di query relazionali.

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');   // permette letture concorrenti durante una scrittura
    $pdo->exec('PRAGMA busy_timeout = 5000');  // attende invece di fallire subito se il DB è momentaneamente locked
    $pdo->exec('PRAGMA foreign_keys = ON');
    ensure_schema($pdo);
    migrate_legacy_json_if_needed($pdo);
    return $pdo;
}

function ensure_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS regole (trigger_id TEXT PRIMARY KEY, data TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS processed_orders (
        order_id TEXT PRIMARY KEY, ts INTEGER, status TEXT, discounts TEXT, email_sent_targets TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_processed_orders_ts ON processed_orders(ts)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS failed_orders (order_id TEXT PRIMARY KEY, ts INTEGER, api_url TEXT, reason TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, password_hash TEXT, created_at INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS throttle (key TEXT PRIMARY KEY, count INTEGER, first INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS alert_state (id INTEGER PRIMARY KEY, last_ts INTEGER, last_count INTEGER)');
}

/**
 * Esegue $fn dentro una transazione con lock di scrittura acquisito subito
 * (BEGIN IMMEDIATE, non il BEGIN differito di default di PDO): è
 * l'equivalente SQLite del flock() usato in precedenza su atomic_json_update
 * — evita che due richieste concorrenti leggano entrambe lo stesso stato
 * "non ancora completo" prima che una delle due scriva (es. due consegne
 * quasi simultanee dello stesso webhook Eventbrite).
 */
function db_atomic(callable $fn) {
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn($pdo);
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Importa una tantum i dati da una precedente installazione basata su file
 * JSON (config.json, regole_sconti.json, processed_orders.json, users.json,
 * failed_orders.json), se il DB è ancora vuoto e quei file esistono. I file
 * originali vengono rinominati in *.migrated al termine, così restano
 * consultabili ma non vengono più letti dall'app.
 */
function migrate_legacy_json_if_needed(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $has_config = (int)$pdo->query('SELECT COUNT(*) FROM config')->fetchColumn();
    if ($has_config > 0) return; // DB già popolato, niente da migrare

    $legacy_config = __DIR__ . '/config.json';
    $legacy_regole = __DIR__ . '/regole_sconti.json';
    $legacy_proc   = __DIR__ . '/processed_orders.json';
    $legacy_users  = __DIR__ . '/users.json';
    $legacy_failed = __DIR__ . '/failed_orders.json';
    if (!file_exists($legacy_config) && !file_exists($legacy_users)) return; // installazione nuova, nessun file legacy

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $conf_data = [];
        if (file_exists($legacy_config)) {
            $conf_data = json_decode(file_get_contents($legacy_config), true) ?: [];
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO config (key, value) VALUES (?, ?)');
            foreach ($conf_data as $k => $v) {
                if ($k === 'dashboard_password') continue; // migrata sotto in users, non serve più in config
                $stmt->execute([$k, is_bool($v) ? ($v ? '1' : '0') : (string)$v]);
            }
        }

        if (file_exists($legacy_regole)) {
            $regole = json_decode(file_get_contents($legacy_regole), true) ?: [];
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO regole (trigger_id, data) VALUES (?, ?)');
            foreach ($regole as $tid => $rule) $stmt->execute([(string)$tid, json_encode($rule, JSON_UNESCAPED_UNICODE)]);
        }

        if (file_exists($legacy_proc)) {
            $proc = json_decode(file_get_contents($legacy_proc), true) ?: [];
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO processed_orders (order_id, ts, status, discounts, email_sent_targets) VALUES (?,?,?,?,?)');
            foreach ($proc as $oid => $v) {
                if (is_array($v)) {
                    $ts     = (int)($v['ts'] ?? 0);
                    if (isset($v['status'], $v['discounts'])) {
                        // formato già "nuovo" (con retry/coda) scritto da una versione precedente su file
                        $status    = $v['status'];
                        $discounts = $v['discounts'];
                        $emailed   = $v['email_sent_targets'] ?? [];
                    } else {
                        // formato molto vecchio: solo un elenco piatto di discount_ids, senza mappa per target
                        $status    = 'complete';
                        $discounts = [];
                        $emailed   = [];
                        $i = 0;
                        foreach (($v['discount_ids'] ?? []) as $did) $discounts['legacy_' . ($i++)] = $did;
                    }
                } else {
                    $ts = (int)$v; $status = 'complete'; $discounts = []; $emailed = [];
                }
                $stmt->execute([(string)$oid, $ts, $status, json_encode($discounts), json_encode($emailed)]);
            }
        }

        if (file_exists($legacy_users)) {
            $users = json_decode(file_get_contents($legacy_users), true) ?: [];
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO users (username, password_hash, created_at) VALUES (?,?,?)');
            foreach ($users as $uname => $u) {
                $stmt->execute([(string)$uname, $u['password_hash'] ?? '', (int)($u['created_at'] ?? time())]);
            }
        } elseif (!empty($conf_data['dashboard_password'])) {
            // installazione ancora più vecchia: password singola senza mai essere passata da users.json
            $pdo->prepare('INSERT OR REPLACE INTO users (username, password_hash, created_at) VALUES (?,?,?)')
                ->execute(['admin', $conf_data['dashboard_password'], time()]);
        }

        if (file_exists($legacy_failed)) {
            $failed = json_decode(file_get_contents($legacy_failed), true) ?: [];
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO failed_orders (order_id, ts, api_url, reason) VALUES (?,?,?,?)');
            foreach ($failed as $oid => $f) {
                $stmt->execute([(string)$oid, (int)($f['ts'] ?? 0), $f['api_url'] ?? '', $f['reason'] ?? '']);
            }
        }

        $pdo->exec('COMMIT');

        foreach ([$legacy_config, $legacy_regole, $legacy_proc, $legacy_users, $legacy_failed] as $f) {
            if (file_exists($f)) @rename($f, $f . '.migrated');
        }
        write_log('Migrazione dati da file JSON a SQLite completata.');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        write_log('ERRORE migrazione JSON->SQLite: ' . $e->getMessage());
    }
}

// ── CONFIGURAZIONE ────────────────────────────────────────────────────────────

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
        'webhook_token'      => '',
        'paused'             => false,
        'email_subject'      => 'I tuoi regali da {{business_name}}',
        'email_intro'        => 'Grazie per i tuoi acquisti. Ecco i regali che abbiamo riservato per te:',
        'email_greeting'     => 'Ciao {{nome}}!',
        'email_color'        => '#D64545',
        'alert_email'        => '',
        'alert_threshold'    => '3',
    ];
    $rows = db()->query('SELECT key, value FROM config')->fetchAll(PDO::FETCH_KEY_PAIR);
    $conf = array_merge($defaults, $rows);
    $conf['paused'] = filter_var($conf['paused'], FILTER_VALIDATE_BOOLEAN);
    // api_token e smtp_pass sono cifrati a riposo (vedi save_config);
    // decrypt_secret restituisce il valore invariato se non è cifrato,
    // quindi un dato legacy in chiaro continua a funzionare e viene
    // ricifrato automaticamente al primo save_config().
    $conf['api_token'] = decrypt_secret((string)$conf['api_token']);
    $conf['smtp_pass'] = decrypt_secret((string)$conf['smtp_pass']);
    return $conf;
}

function save_config(array $config): void {
    make_config_backup(); // backup della versione precedente prima di sovrascrivere
    if (isset($config['api_token'])) $config['api_token'] = encrypt_secret((string)$config['api_token']);
    if (isset($config['smtp_pass'])) $config['smtp_pass'] = encrypt_secret((string)$config['smtp_pass']);
    db_atomic(function (PDO $pdo) use ($config) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO config (key, value) VALUES (?, ?)');
        foreach ($config as $k => $v) {
            if (is_bool($v)) $v = $v ? '1' : '0';
            $stmt->execute([$k, (string)$v]);
        }
    });
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
 * Esporta un array associativo in backups/<prefix>_YYYYMMDD_HHMMSS.json,
 * tenendo solo gli ultimi $keep. Usata per config e regole: il backup resta
 * un file JSON leggibile anche se lo storage applicativo è SQLite.
 */
function make_backup(array $data, string $prefix, int $keep = 10): void {
    if (empty($data)) return;
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    file_put_contents($dir . '/' . $prefix . '_' . date('Ymd_His') . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $files = glob($dir . '/' . $prefix . '_*.json') ?: [];
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $old) {
        unlink($old);
    }
}

function make_regole_backup(): void {
    make_backup(load_regole(), 'regole');
}

function make_config_backup(): void {
    make_backup(db()->query('SELECT key, value FROM config')->fetchAll(PDO::FETCH_KEY_PAIR), 'config');
}

// ── REGOLE SCONTI ─────────────────────────────────────────────────────────────

function load_regole(): array {
    $rows = db()->query('SELECT trigger_id, data FROM regole')->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach ($rows as $tid => $json) $out[$tid] = json_decode($json, true) ?: [];
    return $out;
}

function save_regola_rule(string $trigger_id, array $rule): void {
    make_regole_backup();
    db()->prepare('INSERT OR REPLACE INTO regole (trigger_id, data) VALUES (?, ?)')
        ->execute([$trigger_id, json_encode($rule, JSON_UNESCAPED_UNICODE)]);
}

function delete_regola_rule(string $trigger_id): void {
    make_regole_backup();
    db()->prepare('DELETE FROM regole WHERE trigger_id = ?')->execute([$trigger_id]);
}

function replace_all_regole(array $regole): void {
    make_regole_backup();
    db_atomic(function (PDO $pdo) use ($regole) {
        $pdo->exec('DELETE FROM regole');
        $stmt = $pdo->prepare('INSERT INTO regole (trigger_id, data) VALUES (?, ?)');
        foreach ($regole as $tid => $rule) $stmt->execute([(string)$tid, json_encode($rule, JSON_UNESCAPED_UNICODE)]);
    });
}

// ── LOG ───────────────────────────────────────────────────────────────────────

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

/**
 * Verifica che l'ambiente PHP abbia il necessario per far funzionare l'app
 * (estensioni pdo_sqlite e sodium, cartella scrivibile) PRIMA di toccare
 * config/utenti tramite db(). Senza questo controllo, un hosting con
 * un'estensione mancante o permessi sbagliati mostrerebbe un errore PHP
 * grezzo — con tanto di percorsi del server nello stack trace se
 * display_errors è attivo — invece di un messaggio comprensibile.
 * Particolarmente critico al primissimo avvio: prima ancora che esista un
 * utente, è già la prima cosa che il visitatore vede.
 * Ritorna null se tutto ok, altrimenti un messaggio d'errore comprensibile.
 */
function environment_issue(): ?string {
    if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return 'L\'estensione PHP "pdo_sqlite" non è disponibile su questo hosting. Chiedi al tuo provider di abilitarla (è quasi sempre già installata, a volte va solo attivata dal pannello di controllo).';
    }
    if (!extension_loaded('sodium')) {
        return 'L\'estensione PHP "sodium" non è disponibile su questo hosting. È necessaria per cifrare le credenziali salvate (token API, password SMTP); chiedi al tuo provider di abilitarla.';
    }
    if (!is_writable(__DIR__)) {
        return 'La cartella dell\'applicazione non è scrivibile dal webserver, quindi non può creare il database. Correggi i permessi della cartella (es. 755) e riprova.';
    }
    return null;
}

/** Pagina d'errore per un ambiente non pronto (vedi environment_issue()). */
function render_environment_error(string $msg): void {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Errore di configurazione del server</title>'
        . '<style>body{font-family:sans-serif;background:#f8f9fa;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;padding:20px;box-sizing:border-box;}'
        . '.card{background:white;padding:40px;border-radius:16px;box-shadow:0 20px 40px rgba(0,0,0,.1);max-width:480px;}'
        . 'h2{margin-top:0;color:#991b1b;font-size:20px;} p{color:#334155;line-height:1.6;font-size:14px;}</style></head><body>'
        . '<div class="card"><h2>⚠️ Errore di configurazione del server</h2><p>' . h($msg) . '</p></div></body></html>';
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

// ── RATE LIMITING PER IP ────────────────────────────────────────────────────────
// Complemento al throttling basato su sessione: quest'ultimo da solo è
// aggirabile semplicemente non inviando il cookie di sessione.

function throttle_allowed(string $key, int $max, int $window): bool {
    $stmt = db()->prepare('SELECT count, first FROM throttle WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (time() - (int)$row['first']) > $window) return true;
    return (int)$row['count'] < $max;
}

function throttle_hit(string $key, int $window): void {
    db_atomic(function (PDO $pdo) use ($key, $window) {
        $pdo->prepare('DELETE FROM throttle WHERE first < ?')->execute([time() - $window]);
        $stmt = $pdo->prepare('SELECT count, first FROM throttle WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (time() - (int)$row['first']) > $window) {
            $pdo->prepare('INSERT OR REPLACE INTO throttle (key, count, first) VALUES (?, 1, ?)')->execute([$key, time()]);
        } else {
            $pdo->prepare('UPDATE throttle SET count = count + 1 WHERE key = ?')->execute([$key]);
        }
    });
}

function throttle_reset(string $key): void {
    db()->prepare('DELETE FROM throttle WHERE key = ?')->execute([$key]);
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

// ── ORDINI PROCESSATI (idempotenza + retry) ─────────────────────────────────────

/**
 * Apre (o riapre) la "sezione di lavoro" per un ordine in una transazione
 * atomica: se l'ordine è già completo lo segnala e non tocca nulla; altrimenti
 * crea/mantiene la riga a status 'partial' e restituisce gli sconti/email già
 * noti da un tentativo precedente, così chi chiama può riprendere da lì senza
 * ricreare sconti né rimandare email già inviate. Pulisce anche gli ordini più
 * vecchi di 30 giorni.
 */
function claim_processed_order(string $order_id): array {
    return db_atomic(function (PDO $pdo) use ($order_id) {
        $cutoff = time() - 30 * 86400;
        $pdo->prepare('DELETE FROM processed_orders WHERE ts < ? AND order_id != ?')->execute([$cutoff, $order_id]);

        $stmt = $pdo->prepare('SELECT status, discounts, email_sent_targets FROM processed_orders WHERE order_id = ?');
        $stmt->execute([$order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['status'] === 'complete') {
            return ['already_complete' => true, 'discounts' => [], 'email_sent_targets' => []];
        }

        $discounts = $row ? (json_decode($row['discounts'], true) ?: []) : [];
        $emailed   = $row ? (json_decode($row['email_sent_targets'], true) ?: []) : [];
        $pdo->prepare('INSERT OR REPLACE INTO processed_orders (order_id, ts, status, discounts, email_sent_targets) VALUES (?,?,?,?,?)')
            ->execute([$order_id, time(), 'partial', json_encode($discounts), json_encode($emailed)]);

        return ['already_complete' => false, 'discounts' => $discounts, 'email_sent_targets' => $emailed];
    });
}

function finalize_processed_order(string $order_id, array $discounts, array $email_sent_targets, bool $is_complete): void {
    db()->prepare('INSERT OR REPLACE INTO processed_orders (order_id, ts, status, discounts, email_sent_targets) VALUES (?,?,?,?,?)')
        ->execute([$order_id, time(), $is_complete ? 'complete' : 'partial', json_encode($discounts), json_encode($email_sent_targets)]);
}

/** Voce grezza per il flusso rimborsi (legge gli sconti tracciati per un ordine, se esiste). */
function get_processed_order(string $order_id): ?array {
    $stmt = db()->prepare('SELECT ts, status, discounts, email_sent_targets FROM processed_orders WHERE order_id = ?');
    $stmt->execute([$order_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'ts'                 => (int)$row['ts'],
        'status'             => $row['status'],
        'discounts'          => json_decode($row['discounts'], true) ?: [],
        'email_sent_targets' => json_decode($row['email_sent_targets'], true) ?: [],
    ];
}

function count_processed_orders(): int {
    return (int)db()->query('SELECT COUNT(*) FROM processed_orders')->fetchColumn();
}

/** Ultimi $limit ordini processati, più recenti prima. */
function list_recent_processed_orders(int $limit = 25): array {
    $stmt = db()->prepare('SELECT order_id, ts, status, discounts, email_sent_targets FROM processed_orders ORDER BY ts DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['order_id']] = [
            'ts'                 => (int)$r['ts'],
            'status'             => $r['status'],
            'discounts'          => json_decode($r['discounts'], true) ?: [],
            'email_sent_targets' => json_decode($r['email_sent_targets'], true) ?: [],
        ];
    }
    return $out;
}

// ── CODA ORDINI FALLITI ──────────────────────────────────────────────────────

/**
 * Coda degli ordini che, dopo i retry immediati, restano con almeno uno
 * sconto o l'email ancora da completare. Consultata dalla dashboard
 * (azione "Riprova ordini falliti") per ritentare più tardi senza dover
 * aspettare un nuovo webhook da Eventbrite.
 */
function queue_failed_order(string $order_id, string $api_url, string $reason): void {
    db()->prepare('INSERT OR REPLACE INTO failed_orders (order_id, ts, api_url, reason) VALUES (?,?,?,?)')
        ->execute([$order_id, time(), $api_url, $reason]);
}

function unqueue_failed_order(string $order_id): void {
    db()->prepare('DELETE FROM failed_orders WHERE order_id = ?')->execute([$order_id]);
}

function load_failed_orders(): array {
    $rows = db()->query('SELECT order_id, ts, api_url, reason FROM failed_orders ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['order_id']] = ['ts' => (int)$r['ts'], 'api_url' => $r['api_url'], 'reason' => $r['reason']];
    }
    return $out;
}

// ── UTENTI ────────────────────────────────────────────────────────────────────

function load_users(): array {
    $rows = db()->query('SELECT username, password_hash, created_at FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[$r['username']] = ['password_hash' => $r['password_hash'], 'created_at' => (int)$r['created_at']];
    return $out;
}

/** Crea un nuovo utente (o lo sovrascrive se il nome esiste già). */
function add_user_row(string $username, string $password_hash): void {
    db()->prepare('INSERT OR REPLACE INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')
        ->execute([$username, $password_hash, time()]);
}

/**
 * Reimposta la password di un utente esistente (preservando created_at) o
 * lo crea se non esiste ancora. Ritorna true se l'utente esisteva già.
 */
function set_user_password(string $username, string $password_hash): bool {
    return db_atomic(function (PDO $pdo) use ($username, $password_hash) {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $existing = (bool)$stmt->fetchColumn();
        if ($existing) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?')->execute([$password_hash, $username]);
        } else {
            $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)')->execute([$username, $password_hash, time()]);
        }
        return $existing;
    });
}

function delete_user_row(string $username): void {
    db()->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
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

// ── ALERT ERRORI ──────────────────────────────────────────────────────────────

function get_alert_state(): array {
    $row = db()->query('SELECT last_ts, last_count FROM alert_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    return $row ? ['last_ts' => (int)$row['last_ts'], 'last_count' => (int)$row['last_count']] : ['last_ts' => 0, 'last_count' => 0];
}

function set_alert_state(int $ts, int $count): void {
    db()->prepare('INSERT OR REPLACE INTO alert_state (id, last_ts, last_count) VALUES (1, ?, ?)')->execute([$ts, $count]);
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

    $state = get_alert_state();
    if ((time() - $state['last_ts']) < $window && $today_errors <= $state['last_count']) return;

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
        set_alert_state(time(), $today_errors);
        write_log("Alert email inviato a $alert_email ($today_errors errori oggi).");
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        write_log('ERRORE invio alert admin: ' . $mail->ErrorInfo);
    }
}
