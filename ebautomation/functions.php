<?php
// Cartella radice dell'app: functions.php non viene mai spostato da qui,
// quindi il suo __DIR__ è l'unico affidabile per costruire percorsi
// assoluti (logo.png, PHPMailer/, backups/, ecc.) — i file sotto
// includes/ hanno un __DIR__ diverso (la propria sottocartella) e devono
// usare questa costante invece di __DIR__ per riferirsi alla radice.
define('APP_DIR', __DIR__);

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
    migrate_regole_schema_if_needed($pdo);
    migrate_legacy_json_if_needed($pdo);
    migrate_email_templates_if_needed($pdo);
    return $pdo;
}

/**
 * Evolve lo schema della tabella regole da quello precedente (trigger_id
 * come chiave primaria, quindi una sola regola per evento trigger) a quello
 * attuale (id autogenerato come chiave, trigger_id solo indicizzato): serve
 * per permettere più regole con lo stesso evento trigger, ciascuna con i
 * propri target e sconto. SQLite non supporta di modificare una chiave
 * primaria con ALTER TABLE, quindi la tabella va ricreata; per le regole
 * già esistenti id = trigger_id, così restano raggiungibili con lo stesso
 * link "Modifica" di prima e il comportamento non cambia finché non si
 * aggiunge volontariamente una seconda regola sullo stesso trigger.
 */
function migrate_regole_schema_if_needed(PDO $pdo): void {
    $cols = $pdo->query('PRAGMA table_info(regole)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (in_array('id', $cols, true)) return; // già nel nuovo schema
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec('ALTER TABLE regole RENAME TO regole_old_schema');
        $pdo->exec('CREATE TABLE regole (id TEXT PRIMARY KEY, trigger_id TEXT NOT NULL, data TEXT)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_regole_trigger_id ON regole(trigger_id)');
        $pdo->exec('INSERT INTO regole (id, trigger_id, data) SELECT trigger_id, trigger_id, data FROM regole_old_schema');
        $pdo->exec('DROP TABLE regole_old_schema');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function ensure_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT)');
    // id è la chiave della singola regola (autogenerato); trigger_id NON è
    // più univoco, perché più regole possono condividere lo stesso evento
    // trigger con target e sconti diversi (vedi migrate_regole_schema_if_needed
    // per l'evoluzione dallo schema precedente, dove trigger_id era la PK).
    $pdo->exec('CREATE TABLE IF NOT EXISTS regole (id TEXT PRIMARY KEY, trigger_id TEXT NOT NULL, data TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_regole_trigger_id ON regole(trigger_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS processed_orders (
        order_id TEXT PRIMARY KEY, ts INTEGER, status TEXT, discounts TEXT, email_sent_targets TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_processed_orders_ts ON processed_orders(ts)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS failed_orders (order_id TEXT PRIMARY KEY, ts INTEGER, api_url TEXT, reason TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, password_hash TEXT, created_at INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS throttle (key TEXT PRIMARY KEY, count INTEGER, first INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS alert_state (id INTEGER PRIMARY KEY, last_ts INTEGER, last_count INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_templates (
        lingua TEXT PRIMARY KEY, nome TEXT, subject TEXT, colore TEXT, body_html TEXT, item_html TEXT, is_default INTEGER DEFAULT 0
    )');

    // Migrazioni additive su tabelle già esistenti (SQLite non supporta
    // "ADD COLUMN IF NOT EXISTS", quindi controlliamo prima via PRAGMA).
    add_column_if_missing($pdo, 'users', 'role', "TEXT NOT NULL DEFAULT 'admin'");
    add_column_if_missing($pdo, 'email_templates', 'blocks_json', 'TEXT');
    add_column_if_missing($pdo, 'email_templates', 'logo_width', 'INTEGER DEFAULT 150');
}

// NB: $table/$column/$definition vanno interpolati direttamente nell'SQL
// (PDO non supporta il binding di nomi di tabella/colonna in PRAGMA/ALTER):
// sicuro solo perché questa funzione va chiamata esclusivamente con
// stringhe letterali scritte da noi in ensure_schema(), mai con input
// dell'utente.
function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    $cols = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($column, $cols, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
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
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO regole (id, trigger_id, data) VALUES (?, ?, ?)');
            foreach ($regole as $tid => $rule) $stmt->execute([(string)$tid, (string)$tid, json_encode($rule, JSON_UNESCAPED_UNICODE)]);
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

/**
 * Se non esiste ancora nessun template email, ne crea uno "Italiano"
 * predefinito ereditando i vecchi campi a singolo valore
 * (email_subject/email_intro/email_greeting/email_color) con la stessa
 * struttura HTML usata finora: chi aveva già personalizzato oggetto/saluto/
 * intro/colore li ritrova identici nel nuovo sistema multi-template, non
 * deve ricrearli da zero. I vecchi campi restano nella tabella config come
 * dato inerte (non più letti altrove) per non introdurre altre migrazioni.
 */
function migrate_email_templates_if_needed(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $count = (int)$pdo->query('SELECT COUNT(*) FROM email_templates')->fetchColumn();
    if ($count > 0) return;

    $rows = $pdo->query("SELECT key, value FROM config WHERE key IN ('email_subject','email_intro','email_greeting','email_color')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    $subject  = ($rows['email_subject']  ?? '') ?: 'I tuoi regali da {{business_name}}';
    $greeting = ($rows['email_greeting'] ?? '') ?: 'Ciao {{nome}}!'; // contiene già il segnaposto {{nome}}
    $intro    = ($rows['email_intro']    ?? '') ?: 'Grazie per i tuoi acquisti. Ecco i regali che abbiamo riservato per te:';
    $colore   = ($rows['email_color']    ?? '') ?: '#D64545';

    // Stessi blocchi usati da default_email_blocks(), ma con i testi
    // ereditati dai vecchi campi: il template migrato resta modificabile
    // nell'editor visivo, non solo in modalità codice.
    $blocks = [
        ['type' => 'logo', 'align' => 'center', 'width' => 150],
        ['type' => 'heading', 'text' => $greeting, 'align' => 'center'],
        ['type' => 'text', 'text' => $intro, 'align' => 'center'],
        ['type' => 'gift_box', 'label' => "Per l'evento:", 'button_text' => 'Usa Sconto'],
        ['type' => 'footer', 'text' => '© {{anno}} {{business_name}}'],
    ];
    $rendered = render_blocks_to_html($blocks, $colore);

    $pdo->prepare('INSERT INTO email_templates (lingua, nome, subject, colore, body_html, item_html, is_default, blocks_json, logo_width) VALUES (?,?,?,?,?,?,1,?,?)')
        ->execute(['it', 'Italiano', $subject, $colore, $rendered['body_html'], $rendered['item_html'], json_encode($blocks, JSON_UNESCAPED_UNICODE), $rendered['logo_width']]);

    write_log('Creato template email "Italiano" (migrazione automatica dai campi precedenti).');
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

function make_email_templates_backup(): void {
    make_backup(load_email_templates(), 'template_email');
}

/**
 * Backup completo del database (non solo config/regole come make_backup):
 * copre anche ordini processati, utenti, coda falliti. Al massimo una volta
 * al giorno (controllo lazy sul file più recente, stesso pattern di
 * rotate_logs — nessun vero cron necessario), ultimi 7 conservati.
 * VACUUM INTO produce una copia consistente anche con WAL attivo, senza
 * dover fermare le scritture.
 */
function maybe_backup_database(): void {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    $existing = glob($dir . '/database_*.sqlite') ?: [];
    sort($existing);
    $last = end($existing);
    if ($last !== false && (time() - filemtime($last)) < 86400) return; // già un backup nelle ultime 24h

    $dest = $dir . '/database_' . date('Ymd_His') . '.sqlite';
    if (file_exists($dest)) {
        // Collisione nello stesso secondo (es. due chiamate ravvicinate):
        // VACUUM INTO fallisce se il file di destinazione esiste già, a
        // differenza di copy() usata per gli altri backup.
        $dest = $dir . '/database_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.sqlite';
    }
    try {
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    } catch (Throwable $e) {
        write_log('ERRORE backup database: ' . $e->getMessage());
        return;
    }
    $files = glob($dir . '/database_*.sqlite') ?: [];
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - 7)) as $old) unlink($old);
    write_log('Backup completo del database creato: ' . basename($dest));
}

// ── REGOLE SCONTI ─────────────────────────────────────────────────────────────

/**
 * Restituisce tutte le regole indicizzate per id (non più per trigger_id:
 * più regole possono condividere lo stesso evento trigger). Ogni regola
 * riporta comunque il proprio 'trigger_id', così il chiamante non deve mai
 * consultare la tabella per saperlo.
 */
function load_regole(): array {
    $rows = db()->query('SELECT id, trigger_id, data FROM regole')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $rule = json_decode($row['data'], true) ?: [];
        $rule['trigger_id'] = $row['trigger_id'];
        $out[$row['id']] = $rule;
    }
    return $out;
}

/** $rule_id vuoto o non ancora esistente = crea una nuova regola. */
function save_regola_rule(string $rule_id, string $trigger_id, array $rule): void {
    make_regole_backup();
    unset($rule['trigger_id']); // ridondante: sta nella colonna dedicata, non nel JSON
    db()->prepare('INSERT OR REPLACE INTO regole (id, trigger_id, data) VALUES (?, ?, ?)')
        ->execute([$rule_id, $trigger_id, json_encode($rule, JSON_UNESCAPED_UNICODE)]);
}

function delete_regola_rule(string $rule_id): void {
    make_regole_backup();
    db()->prepare('DELETE FROM regole WHERE id = ?')->execute([$rule_id]);
}

/**
 * Sostituzione totale (import JSON/CSV). Ogni regola può indicare il proprio
 * 'trigger_id'; se assente (export nel vecchio formato, da prima che una
 * regola potesse avere un id diverso dal trigger) si assume che la chiave
 * dell'array sia sia l'id sia il trigger_id, per compatibilità con i file
 * esportati in precedenza.
 */
function replace_all_regole(array $regole): void {
    make_regole_backup();
    db_atomic(function (PDO $pdo) use ($regole) {
        $pdo->exec('DELETE FROM regole');
        $stmt = $pdo->prepare('INSERT INTO regole (id, trigger_id, data) VALUES (?, ?, ?)');
        foreach ($regole as $rid => $rule) {
            $tid = (string)($rule['trigger_id'] ?? $rid);
            unset($rule['trigger_id']);
            $stmt->execute([(string)$rid, $tid, json_encode($rule, JSON_UNESCAPED_UNICODE)]);
        }
    });
}

// ── TEMPLATE EMAIL ────────────────────────────────────────────────────────────
// Più lingue/varianti visive possibili: ogni regola sceglie quale usare
// (campo "lingua" nella regola), con una predefinita per chi non specifica
// nulla. Ogni template è HTML libero con segnaposto, non solo testo fisso.

function load_email_templates(): array {
    $rows = db()->query('SELECT lingua, nome, subject, colore, body_html, item_html, is_default, blocks_json, logo_width FROM email_templates ORDER BY lingua')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $blocks = $r['blocks_json'] ? json_decode($r['blocks_json'], true) : null;
        $out[$r['lingua']] = [
            'nome'       => $r['nome'],
            'subject'    => $r['subject'],
            'colore'     => $r['colore'],
            'body_html'  => $r['body_html'],
            'item_html'  => $r['item_html'],
            'is_default' => (bool)$r['is_default'],
            // null = template "a codice" (HTML scritto/modificato a mano,
            // non più ricostruibile nell'editor a blocchi).
            'blocks'     => is_array($blocks) ? $blocks : null,
            'logo_width' => (int)($r['logo_width'] ?: 150),
        ];
    }
    return $out;
}

/**
 * Salva un template email (crea o sovrascrive). Se marcato come
 * predefinito, toglie il flag a tutti gli altri: ce n'è sempre al più uno.
 * $tpl['blocks'] è opzionale: se presente (array), il template resta
 * modificabile nell'editor visivo a blocchi; se assente/null, il template
 * è considerato "a codice" (body_html/item_html scritti a mano).
 */
function save_email_template(string $lingua, array $tpl): void {
    make_email_templates_backup();
    db_atomic(function (PDO $pdo) use ($lingua, $tpl) {
        if (!empty($tpl['is_default'])) {
            $pdo->exec('UPDATE email_templates SET is_default = 0');
        }
        $blocks_json = isset($tpl['blocks']) && is_array($tpl['blocks']) ? json_encode($tpl['blocks'], JSON_UNESCAPED_UNICODE) : null;
        $logo_width  = max(40, min(400, (int)($tpl['logo_width'] ?? 150)));
        $pdo->prepare('INSERT OR REPLACE INTO email_templates (lingua, nome, subject, colore, body_html, item_html, is_default, blocks_json, logo_width) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$lingua, $tpl['nome'], $tpl['subject'], $tpl['colore'], $tpl['body_html'], $tpl['item_html'], !empty($tpl['is_default']) ? 1 : 0, $blocks_json, $logo_width]);
    });
}

function delete_email_template(string $lingua): void {
    make_email_templates_backup();
    db()->prepare('DELETE FROM email_templates WHERE lingua = ?')->execute([$lingua]);
}

/**
 * Sostituisce tutti i template email con quelli forniti (import JSON dalla
 * dashboard). Se nessuno dei template importati è marcato come predefinito,
 * il primo lo diventa: l'app non deve mai restare senza un template
 * predefinito da usare come fallback (vedi get_email_template).
 */
function replace_all_email_templates(array $templates): void {
    make_email_templates_backup();
    db_atomic(function (PDO $pdo) use ($templates) {
        $pdo->exec('DELETE FROM email_templates');
        $has_default = false;
        foreach ($templates as $tpl) {
            if (!empty($tpl['is_default'])) { $has_default = true; break; }
        }
        $stmt  = $pdo->prepare('INSERT INTO email_templates (lingua, nome, subject, colore, body_html, item_html, is_default, blocks_json, logo_width) VALUES (?,?,?,?,?,?,?,?,?)');
        $first = true;
        foreach ($templates as $lingua => $tpl) {
            $is_default  = !empty($tpl['is_default']) || (!$has_default && $first);
            $blocks_json = isset($tpl['blocks']) && is_array($tpl['blocks']) ? json_encode($tpl['blocks'], JSON_UNESCAPED_UNICODE) : null;
            $logo_width  = max(40, min(400, (int)($tpl['logo_width'] ?? 150)));
            $stmt->execute([
                (string)$lingua, $tpl['nome'] ?? strtoupper((string)$lingua), $tpl['subject'] ?? '',
                $tpl['colore'] ?? '#D64545', $tpl['body_html'] ?? '', $tpl['item_html'] ?? '',
                $is_default ? 1 : 0, $blocks_json, $logo_width,
            ]);
            $first = false;
        }
    });
}

/**
 * Il template da usare: quello con la lingua indicata se esiste, altrimenti
 * il predefinito, altrimenti il primo disponibile — non lascia mai un
 * ordine senza email per una configurazione incompleta (lingua di una
 * regola cancellata, nessun predefinito impostato, ecc.).
 */
function get_email_template(?string $lingua): ?array {
    $templates = load_email_templates();
    if ($lingua && isset($templates[$lingua])) return $templates[$lingua];
    foreach ($templates as $t) {
        if ($t['is_default']) return $t;
    }
    return $templates ? reset($templates) : null;
}

/** Blocchi di partenza per un nuovo template creato nell'editor visivo. */
function default_email_blocks(): array {
    return [
        ['type' => 'logo', 'align' => 'center', 'width' => 150],
        ['type' => 'heading', 'text' => 'Ciao {{nome}}!', 'align' => 'center'],
        ['type' => 'text', 'text' => 'Grazie per i tuoi acquisti. Ecco i regali che abbiamo riservato per te:', 'align' => 'center'],
        ['type' => 'gift_box', 'label' => "Per l'evento:", 'button_text' => 'Usa Sconto'],
        ['type' => 'footer', 'text' => '© {{anno}} {{business_name}}'],
    ];
}

const EMAIL_BLOCK_TYPES = ['logo', 'heading', 'text', 'gift_box', 'divider', 'spacer', 'footer'];

/**
 * Valida/normalizza un array di blocchi arrivato dal client (editor
 * visivo): scarta blocchi con tipo sconosciuto, applica limiti di
 * lunghezza/valori sensati, tiene solo il primo blocco "gift_box" (un solo
 * blocco sconto ha senso: è lì che va il ciclo dei codici regalo).
 */
function sanitize_email_blocks(array $raw_blocks): array {
    $blocks = [];
    $has_gift_box = false;
    foreach (array_slice($raw_blocks, 0, 40) as $b) {
        if (!is_array($b)) continue;
        $type = $b['type'] ?? '';
        if (!in_array($type, EMAIL_BLOCK_TYPES, true)) continue;
        if ($type === 'gift_box') {
            if ($has_gift_box) continue; // un solo blocco sconto per template
            $has_gift_box = true;
        }
        $align_raw = $b['align'] ?? 'center';
        $align = in_array($align_raw, ['left', 'center', 'right'], true) ? $align_raw : 'center';
        $color_raw = $b['color'] ?? '';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color_raw) ? $color_raw : '';
        switch ($type) {
            case 'logo':
                $blocks[] = ['type' => 'logo', 'align' => $align, 'width' => max(40, min(400, (int)($b['width'] ?? 150)))];
                break;
            case 'heading':
                $blocks[] = ['type' => 'heading', 'text' => mb_substr((string)($b['text'] ?? ''), 0, 200), 'align' => $align, 'color' => $color];
                break;
            case 'text':
                $blocks[] = ['type' => 'text', 'text' => mb_substr((string)($b['text'] ?? ''), 0, 1000), 'align' => $align, 'color' => $color];
                break;
            case 'gift_box':
                $blocks[] = [
                    'type'        => 'gift_box',
                    'label'       => mb_substr((string)($b['label'] ?? "Per l'evento:"), 0, 100),
                    'button_text' => mb_substr((string)($b['button_text'] ?? 'Usa Sconto'), 0, 60),
                ];
                break;
            case 'divider':
                $blocks[] = ['type' => 'divider'];
                break;
            case 'spacer':
                $blocks[] = ['type' => 'spacer', 'height' => max(4, min(120, (int)($b['height'] ?? 20)))];
                break;
            case 'footer':
                $blocks[] = ['type' => 'footer', 'text' => mb_substr((string)($b['text'] ?? ''), 0, 300)];
                break;
        }
    }
    return $blocks;
}

/**
 * Converte i blocchi dell'editor visivo in body_html/item_html, lo stesso
 * formato consumato da render_email_template(): niente doppio motore di
 * rendering, i blocchi sono solo un modo più semplice di scrivere lo
 * stesso HTML. Se non c'è un blocco "gift_box", ne viene aggiunto uno di
 * default in coda: un template non può mai restare senza il punto in cui
 * mostrare i codici sconto.
 */
function render_blocks_to_html(array $blocks, string $colore): array {
    $colore = preg_match('/^#[0-9a-fA-F]{6}$/', $colore) ? $colore : '#D64545';
    $parts = [];
    $item_html = null;
    $logo_width = 150;

    foreach ($blocks as $b) {
        switch ($b['type'] ?? '') {
            case 'logo':
                $logo_width = max(40, min(400, (int)($b['width'] ?? 150)));
                $parts[] = '<div style="text-align:' . h($b['align'] ?? 'center') . ';margin-bottom:20px;">{{logo}}</div>';
                break;
            case 'heading':
                $color = ($b['color'] ?? '') ?: '#2d3142';
                $parts[] = '<h2 style="color:' . h($color) . ';text-align:' . h($b['align'] ?? 'center') . ';margin:0 0 14px;">' . h((string)($b['text'] ?? '')) . '</h2>';
                break;
            case 'text':
                $color = ($b['color'] ?? '') ?: '#4f5d75';
                $parts[] = '<p style="color:' . h($color) . ';text-align:' . h($b['align'] ?? 'center') . ';margin:0 0 18px;">' . nl2br(h((string)($b['text'] ?? ''))) . '</p>';
                break;
            case 'gift_box':
                if ($item_html !== null) break; // solo il primo conta
                $parts[] = '{{items}}';
                $label = h((string)($b['label'] ?? "Per l'evento:"));
                $btn   = h((string)($b['button_text'] ?? 'Usa Sconto'));
                $item_html = '<div style="background:#f3f3f3;border:1px solid #ddd;padding:15px;margin-bottom:15px;border-radius:8px;text-align:center;">'
                    . '<p style="color:#666;font-size:13px;margin:0;">' . $label . ' <b>{{desc}}</b></p>'
                    . '<p style="color:{{colore}};font-size:24px;font-weight:bold;margin:10px 0;">{{code}}</p>'
                    . '<a href="{{url}}" style="display:inline-block;background:{{colore}};color:white;padding:10px 20px;text-decoration:none;border-radius:5px;">' . $btn . ' {{label}}</a>'
                    . '</div>';
                break;
            case 'divider':
                $parts[] = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">';
                break;
            case 'spacer':
                $height = max(0, (int)($b['height'] ?? 20));
                $parts[] = '<div style="height:' . $height . 'px;line-height:' . $height . 'px;font-size:1px;">&nbsp;</div>';
                break;
            case 'footer':
                $parts[] = '<p style="font-size:11px;color:#aaa;text-align:center;margin-top:30px;">' . h((string)($b['text'] ?? '')) . '</p>';
                break;
        }
    }

    if ($item_html === null) {
        // Nessun blocco "gift_box" nell'editor: non deve mai risultare un
        // template senza spazio per i codici sconto, quindi lo aggiungiamo.
        $parts[] = '{{items}}';
        $item_html = '<div style="background:#f3f3f3;border:1px solid #ddd;padding:15px;margin-bottom:15px;border-radius:8px;text-align:center;">'
            . '<p style="color:#666;font-size:13px;margin:0;">Per l\'evento: <b>{{desc}}</b></p>'
            . '<p style="color:{{colore}};font-size:24px;font-weight:bold;margin:10px 0;">{{code}}</p>'
            . '<a href="{{url}}" style="display:inline-block;background:{{colore}};color:white;padding:10px 20px;text-decoration:none;border-radius:5px;">Usa Sconto {{label}}</a>'
            . '</div>';
    }

    $body_html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #eee;">'
        . implode('', $parts)
        . '</div>';

    return ['body_html' => $body_html, 'item_html' => $item_html, 'logo_width' => $logo_width];
}

/**
 * Prepara il logo da incorporare in un'email, ridimensionato esattamente
 * alla larghezza a cui viene poi mostrato (1:1, niente margine per schermi
 * retina: alcuni client — Gmail in primis — mostrano comunque un'icona di
 * zoom su ogni immagine incorporata, indipendentemente dalla sua
 * risoluzione, quindi non ha senso appesantire l'email per uno scarto di
 * nitidezza che non risolve comunque quell'icona). Senza questo passaggio
 * il file originale — spesso molto più grande della larghezza configurata
 * nel template — finiva incorporato per intero, appesantendo ogni email.
 *
 * La versione ridimensionata viene cachata su disco (rigenerata solo se il
 * logo originale cambia o la larghezza richiesta cambia) per non rifare il
 * resize ad ogni invio. Se GD non è disponibile, il file non è
 * un'immagine valida, o è già alla risoluzione target o più piccolo,
 * ritorna semplicemente il percorso originale.
 */
function get_logo_path_for_email(string $original_path, int $display_width): string {
    if (!extension_loaded('gd') || !file_exists($original_path)) return $original_path;

    $info = @getimagesize($original_path);
    if (!$info) return $original_path;
    [$orig_w, $orig_h, $type] = $info;

    $target_w = max(1, $display_width);
    if ($orig_w <= $target_w) return $original_path;

    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    $cache_path = $dir . '/logo_' . $target_w . '_' . filemtime($original_path) . '.png';
    if (file_exists($cache_path)) return $cache_path;

    $src = match ($type) {
        IMAGETYPE_PNG  => @imagecreatefrompng($original_path),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($original_path),
        IMAGETYPE_GIF  => @imagecreatefromgif($original_path),
        default        => null,
    };
    if (!$src) return $original_path;

    $target_h = max(1, (int)round($orig_h * ($target_w / $orig_w)));
    $dst = imagecreatetruecolor($target_w, $target_h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $target_w, $target_h, $orig_w, $orig_h);
    imagedestroy($src);

    $ok = imagepng($dst, $cache_path, 6);
    imagedestroy($dst);

    // Rimuove solo le versioni cachate di un logo ORMAI SOSTITUITO (mtime
    // precedente): template diversi possono avere una logo_width diversa,
    // quindi più file cachati per lo stesso logo corrente sono legittimi e
    // vanno lasciati (altrimenti ogni invio ricalcolerebbe da capo quello
    // dell'altro template).
    foreach (glob($dir . '/logo_*.png') ?: [] as $f) {
        if ($f !== $cache_path && !str_ends_with($f, '_' . filemtime($original_path) . '.png')) {
            @unlink($f);
        }
    }

    return $ok ? $cache_path : $original_path;
}

/**
 * Sostituisce i segnaposto di un template con i dati reali dell'ordine,
 * producendo oggetto/HTML/testo semplice pronti per PHPMailer. Condivisa
 * tra invio reale (webhook) e anteprima/test dalla dashboard, così
 * l'anteprima mostra SEMPRE esattamente quello che verrebbe inviato.
 *
 * Segnaposto nel corpo: {{business_name}} {{nome}} {{items}} {{anno}} {{logo}} {{colore}}
 * Segnaposto per singolo sconto (item_html, concatenati in {{items}}):
 * {{desc}} {{code}} {{url}} {{label}} {{colore}}
 *
 * $logo_src distingue invio reale da anteprima: nell'email vera il logo va
 * incorporato come allegato e richiamato con "cid:logo_cid" (i client email
 * bloccano di default le immagini remote), ma un browser non sa risolvere
 * un cid — per le anteprime lato dashboard va quindi passato un URL reale
 * (es. "logo.png?v=..."), altrimenti l'immagine risulta rotta.
 */
function render_email_template(array $template, string $business_name, string $nome_cliente, array $regali_finali, bool $has_logo, string $logo_src = 'cid:logo_cid'): array {
    $colore     = $template['colore'] ?: '#D64545';
    $logo_width = max(40, min(400, (int)($template['logo_width'] ?? 150)));

    $items_html = '';
    foreach ($regali_finali as $reg) {
        $items_html .= strtr((string)($template['item_html'] ?? ''), [
            '{{desc}}'   => h($reg['desc']),
            '{{code}}'   => h($reg['code']),
            '{{url}}'    => h($reg['url']),
            '{{label}}'  => h($reg['label']),
            '{{colore}}' => h($colore),
        ]);
    }

    // Dimensione del logo fissata in pixel, mai in percentuale: alcuni
    // client email non contengono l'immagine dentro un blocco di
    // larghezza nota quanto il browser (Outlook desktop in particolare
    // ignora anche il "max-width:600px" del corpo email), quindi un
    // "width:100%" nello style rischia di farla scalare alla larghezza
    // dell'intera finestra di lettura — molto peggio della dimensione
    // originale dell'immagine. Attributo HTML "width" (letto da Outlook,
    // che ignora il CSS sulle immagini) + "width"/"max-width" in px nello
    // style (letti da tutti gli altri client) devono sempre concordare
    // sullo stesso valore esatto. Nessun attributo "height": lasciarlo
    // assente fa scalare l'immagine proporzionalmente ovunque.
    $logo_html = $has_logo
        ? '<img src="' . h($logo_src) . '" width="' . $logo_width . '" style="width:' . $logo_width . 'px;max-width:' . $logo_width . 'px;height:auto;margin-bottom:20px;">'
        : '<h1 style="color:#2d3142;">' . h($business_name) . '</h1>';

    $html = strtr((string)($template['body_html'] ?? ''), [
        '{{business_name}}' => h($business_name),
        '{{nome}}'           => h($nome_cliente),
        '{{items}}'          => $items_html,
        '{{anno}}'            => date('Y'),
        '{{logo}}'            => $logo_html,
        '{{colore}}'          => h($colore),
    ]);

    // L'oggetto va in un header email, non in HTML: nessun h() qui.
    $subject = strtr((string)($template['subject'] ?? ''), ['{{business_name}}' => $business_name]);
    $text    = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/** Sconti di esempio per anteprima/test di un template, senza un ordine reale. */
function sample_regali_finali(): array {
    return [['desc' => 'Evento di Esempio', 'code' => 'GIFT-PREVIEW', 'url' => '#', 'label' => '100%']];
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
    // 'unsafe-inline' su script/style è necessario perché l'app usa
    // attributi onclick="" e <style>/<script> inline senza un sistema di
    // build che generi nonce — non protegge da uno script iniettato
    // inline, ma blocca comunque il caso più comune di exfiltrazione via
    // XSS: caricare risorse (script, immagini, richieste fetch) da un
    // dominio esterno diverso da quelli esplicitamente permessi qui sotto.
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-src 'self'; "
        . "form-action 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'");
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
function claim_processed_order(string $order_id, bool $allow_reopen = false): array {
    return db_atomic(function (PDO $pdo) use ($order_id, $allow_reopen) {
        $cutoff = time() - 30 * 86400;
        $pdo->prepare('DELETE FROM processed_orders WHERE ts < ? AND order_id != ?')->execute([$cutoff, $order_id]);

        $stmt = $pdo->prepare('SELECT status, discounts, email_sent_targets FROM processed_orders WHERE order_id = ?');
        $stmt->execute([$order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // $allow_reopen (usato per order.updated): riapre per rivalutazione
        // anche un ordine già completo, es. se l'ordine è stato modificato
        // dopo l'invio iniziale (quantità aumentata sopra una soglia
        // qty_minima, nuovo evento aggiunto all'ordine). Non revoca mai
        // sconti già creati: la pipeline a valle crea solo quelli mancanti.
        if ($row && $row['status'] === 'complete' && !$allow_reopen) {
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
    $rows = db()->query('SELECT username, password_hash, created_at, role FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['username']] = ['password_hash' => $r['password_hash'], 'created_at' => (int)$r['created_at'], 'role' => $r['role'] ?: 'admin'];
    }
    return $out;
}

/**
 * Crea un nuovo utente (o lo sovrascrive se il nome esiste già).
 * $role: 'admin' (accesso completo) o 'viewer' (sola lettura: può vedere
 * dashboard/log/statistiche ma nessuna azione che modifica stato).
 */
function add_user_row(string $username, string $password_hash, string $role = 'admin'): void {
    if (!in_array($role, ['admin', 'viewer'], true)) $role = 'admin';
    db()->prepare('INSERT OR REPLACE INTO users (username, password_hash, created_at, role) VALUES (?, ?, ?, ?)')
        ->execute([$username, $password_hash, time(), $role]);
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

// ── ELABORAZIONE ORDINE (webhook + strumenti dashboard) ────────────────────────

/**
 * Elabora un evento "ordine" di Eventbrite: crea gli sconti configurati e
 * invia l'email al cliente (o, per un rimborso, elimina gli sconti già
 * creati). Contiene tutta la logica condivisa tra l'endpoint pubblico
 * (eventbrite-webhook.php, che valida token webhook e stato di pausa prima
 * di chiamare questa funzione) e gli strumenti della dashboard che devono
 * rieseguire la stessa pipeline — "Simula Ordine" e "Riprova ordini
 * falliti" — SENZA passare da una richiesta HTTP verso se stessi: quel giro
 * è sempre stato inutile (siamo già nello stesso processo PHP) e su alcuni
 * hosting con un firewall/antibot aggressivo viene bloccato con 403 prima
 * ancora di raggiungere l'applicazione, mentre le chiamate reali di
 * Eventbrite (da IP esterno) passano regolarmente.
 *
 * Ritorna ['http_code' => int, 'message' => string]: http_code è quello
 * che l'endpoint HTTP deve restituire a Eventbrite (200 = non ritentare,
 * 400 = richiesta non valida); message è una descrizione leggibile
 * dell'esito, usata sia nel log sia nei messaggi della dashboard.
 */
function process_eventbrite_order(array $conf, string $api_url, string $action): array {
    if ($conf['paused']) {
        write_log('Webhook ricevuto ma automazioni in pausa. Skip.');
        return ['http_code' => 200, 'message' => 'Automazioni in pausa: nessuna azione eseguita.'];
    }

    // Protezione SSRF: accetta solo l'esatto endpoint "ordine" di Eventbrite
    // (non un generico prefisso di dominio), così questa funzione non può
    // essere usata come oracolo per interrogare, col nostro token,
    // qualunque altro endpoint dell'API Eventbrite.
    if (!preg_match('#^https://www\.eventbriteapi\.com/v3/orders/\d+/$#', $api_url)) {
        write_log('api_url non autorizzata: ' . $api_url);
        return ['http_code' => 400, 'message' => 'api_url non autorizzata.'];
    }

    $order_res = api_call_with_retry($api_url . '?expand=attendees', [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token']],
    ]);
    $order     = $order_res['body'];
    $http_code = $order_res['status'];

    if (!isset($order['id'])) {
        write_log('Ordine non recuperato. HTTP: ' . $http_code);
        return ['http_code' => 200, 'message' => "Impossibile recuperare l'ordine da Eventbrite (HTTP $http_code)."];
    }

    $order_id = (string)$order['id'];
    if ($order_id === '') {
        write_log('Order ID mancante nella risposta API.');
        return ['http_code' => 200, 'message' => 'Order ID mancante nella risposta API.'];
    }

    // ── RIMBORSO ─────────────────────────────────────────────────────────────
    if ($action === 'order.refunded') {
        $entry    = get_processed_order($order_id);
        $disc_ids = $entry ? array_values($entry['discounts']) : [];

        if (empty($disc_ids)) {
            write_log("Rimborso ordine $order_id: nessun codice sconto tracciato, niente da eliminare.");
            return ['http_code' => 200, 'message' => "Rimborso ordine $order_id: nessun codice sconto tracciato."];
        }

        $eliminati = 0;
        foreach ($disc_ids as $disc_id) {
            $res = api_call_with_retry("https://www.eventbriteapi.com/v3/discounts/{$disc_id}/", [
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token']],
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ], 2);

            if ($res['status'] === 200 || $res['status'] === 204) {
                write_log("Sconto $disc_id eliminato per rimborso ordine $order_id.");
                $eliminati++;
            } else {
                write_log("ERRORE eliminazione sconto $disc_id per ordine $order_id. HTTP: {$res['status']}");
            }
        }
        return ['http_code' => 200, 'message' => "Rimborso ordine $order_id: $eliminati/" . count($disc_ids) . ' sconto/i eliminato/i.'];
    }

    // ── ACQUISTO / MODIFICA ORDINE ──────────────────────────────────────────────
    // order.updated arriva quando un ordine già esistente cambia (es. quantità di
    // biglietti aumentata, partecipanti aggiunti): lo trattiamo con la stessa
    // pipeline di order.placed, ma "riaprendo" anche un ordine già marcato
    // completo per rivalutarlo alla luce dei dati aggiornati — es. una quantità
    // che ora supera una soglia qty_minima prima non raggiunta. Non revoca mai
    // sconti già creati (vedi commento su claim_processed_order): la pipeline
    // crea solo i target ancora mancanti rispetto allo stato attuale dell'ordine.
    if (!in_array($action, ['order.placed', 'order.updated'], true)) {
        return ['http_code' => 200, 'message' => "Azione \"$action\" ignorata: non riguarda un ordine."];
    }

    // Verifica e (ri)apre una sezione di lavoro atomica per l'ordine (transazione
    // SQLite BEGIN IMMEDIATE, vedi claim_processed_order). Un ordine già COMPLETO
    // (tutti gli sconti creati ed email inviata con successo) viene saltato — è
    // la vera idempotenza — a meno che non sia un order.updated (vedi sopra).
    $claim = claim_processed_order($order_id, $action === 'order.updated');
    if ($claim['already_complete']) {
        write_log("Ordine $order_id già completato. Skip.");
        return ['http_code' => 200, 'message' => "Ordine $order_id già completato in precedenza."];
    }
    $existing_discounts = $claim['discounts'];
    $existing_emailed   = $claim['email_sent_targets'];

    if (!isset($order['attendees'])) {
        write_log("Ordine $order_id senza attendees. HTTP: $http_code");
        return ['http_code' => 200, 'message' => "Ordine $order_id: risposta Eventbrite senza elenco partecipanti."];
    }

    $regole        = load_regole();
    $business_name = $conf['business_name'] ?: 'La nostra Azienda';

    // Biglietti acquistati per evento (per la condizione "quantità minima")
    $qty_per_evento = [];
    foreach ($order['attendees'] as $att) {
        $eid = $att['event_id'] ?? null;
        if ($eid) $qty_per_evento[$eid] = ($qty_per_evento[$eid] ?? 0) + 1;
    }
    $discounts         = $existing_discounts; // target_id => discount_id (riparte da eventuali successi precedenti)
    $attempted_targets = [];                  // target_id di tutte le regole che dovrebbero attivarsi su questo ordine

    // Creazione sconti (i target già presenti in $discounts non vengono ricreati).
    // Iteriamo su TUTTE le regole (non solo sugli eventi acquistati) perché più
    // regole possono condividere lo stesso evento trigger con target/sconti
    // diversi: non c'è più un'unica regola per trigger da guardare direttamente.
    foreach ($regole as $r) {
        $e_id = $r['trigger_id'] ?? '';
        if ($e_id === '' || !isset($qty_per_evento[$e_id])) continue; // trigger non acquistato in quest'ordine

        if (($r['attiva'] ?? true) === false) continue; // regola disattivata dalla dashboard

        $qty_minima = max(1, (int)($r['qty_minima'] ?? 1));
        if (($qty_per_evento[$e_id] ?? 0) < $qty_minima) {
            write_log("Regola per evento $e_id non attivata per ordine $order_id: acquistati {$qty_per_evento[$e_id]} biglietti, ne servono almeno $qty_minima.");
            continue;
        }

        foreach ($r['target_ids'] as $t_id) {
            $attempted_targets[$t_id] = true;
            if (isset($discounts[$t_id])) continue; // già creato in un tentativo precedente

            $promo_code = ($r['codice_prefix'] ?? 'GIFT') . '-' . strtoupper(substr(md5($order_id . $t_id), 0, 8));

            $discount = [
                'type'               => 'coded',
                'code'               => $promo_code,
                'event_id'           => $t_id,
                'quantity_available' => max(1, (int)($r['quantita'] ?? 1)),
            ];

            if (($r['tipo_sconto'] ?? 'percentuale') === 'importo') {
                // L'API Eventbrite vuole un numero semplice nella valuta
                // dell'evento (es. "10.00"), non un oggetto {currency,value}
                // (quel formato è usato altrove nell'API, es. per il prezzo dei
                // biglietti, ma non per lo sconto — inviarlo qui viene
                // rifiutato con "discount.amount_off - Not a valid string").
                $discount['amount_off'] = number_format((float)($r['importo_fisso'] ?? 0), 2, '.', '');
            } else {
                $discount['percent_off'] = $r['percentuale'];
            }

            $giorni = (int)($r['giorni_scadenza'] ?? 0);
            if ($giorni > 0) {
                $discount['end_date'] = date('Y-m-d\TH:i:s\Z', time() + $giorni * 86400);
            }

            // Multi-organizzazione: l'endpoint discounts è scoped per org, quindi
            // risolviamo dinamicamente l'org proprietaria dell'evento target
            // invece di assumere un org_id fisso in configurazione. Questo
            // permette a un unico token di gestire regole su più organizzazioni.
            $target_org_id = resolve_event_org_id($t_id, $conf['api_token']) ?: $conf['org_id'];
            if (!$target_org_id) {
                write_log("ERRORE: impossibile determinare l'organizzazione dell'evento target $t_id (ordine $order_id). Sconto non creato.");
                continue;
            }

            $res = api_call_with_retry("https://www.eventbriteapi.com/v3/organizations/{$target_org_id}/discounts/", [
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['api_token'], 'Content-Type: application/json'],
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['discount' => $discount]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);

            if ($res['status'] === 200 || $res['status'] === 201) {
                $disc_id = (string)($res['body']['discount']['id'] ?? $res['body']['id'] ?? '');
                if ($disc_id) {
                    $discounts[$t_id] = $disc_id;
                    write_log("Sconto creato: $promo_code per ordine $order_id (org $target_org_id, tentativi: {$res['attempts']})");
                }
            } else {
                write_log("ERRORE creazione sconto $promo_code per ordine $order_id. HTTP: {$res['status']} dopo {$res['attempts']} tentativi. Risposta: " . $res['raw']);
            }
        }
    }

    $targets_missing    = array_diff(array_keys($attempted_targets), array_keys($discounts));
    $discounts_complete = empty($targets_missing);

    // Ricostruisce i dati necessari all'email per TUTTI gli sconti noti finora
    // (non solo quelli creati in questo passaggio), così un retry che recupera
    // uno sconto mancante può reinviare un'email completa. Determina anche la
    // lingua/template da usare: quella della prima regola incontrata che ha
    // contribuito allo sconto (se un ordine matcha regole con lingue diverse,
    // l'intera email consolidata usa questa — non la spezziamo in più email per
    // non vanificare la logica "un cliente riceve un'unica email con tutto").
    $regali_finali = [];
    $chosen_lingua = null;
    foreach ($regole as $r) {
        foreach (($r['target_ids'] ?? []) as $t_id) {
            if (!isset($discounts[$t_id]) || isset($regali_finali[$t_id])) continue;
            if ($chosen_lingua === null) $chosen_lingua = ($r['lingua'] ?? '') ?: null;
            $is_imp = ($r['tipo_sconto'] ?? 'percentuale') === 'importo';
            $regali_finali[$t_id] = [
                'desc'  => $r['descrizione'] ?? '',
                'code'  => ($r['codice_prefix'] ?? 'GIFT') . '-' . strtoupper(substr(md5($order_id . $t_id), 0, 8)),
                'url'   => 'https://www.eventbrite.it/e/' . $t_id,
                'label' => $is_imp ? ($r['importo_fisso'] ?? '?') . ' ' . ($conf['currency'] ?: 'EUR') : $r['percentuale'] . '%',
            ];
        }
    }
    $regali_finali = array_values($regali_finali);

    // Invio email — solo se c'è qualcosa di nuovo da comunicare rispetto
    // all'ultimo invio riuscito (evita di reinviare la stessa email identica
    // ad ogni retry quando non c'è nulla di cambiato).
    $targets_to_email   = array_diff(array_keys($discounts), $existing_emailed);
    $email_sent_targets = $existing_emailed;
    // Niente da inviare = ok, sia perché è già stato inviato tutto in un
    // tentativo precedente sia perché l'ordine non ha semplicemente sconti
    // da comunicare (es. nessuna regola configurata per gli eventi
    // acquistati — caso comune, dato che Eventbrite invia order.placed per
    // ogni ordine dell'account, non solo per quelli con una regola). Prima
    // richiedeva anche !empty($existing_emailed), il che marcava per errore
    // come "non completato" (e rimetteva in coda per sempre) ogni ordine
    // senza alcuna regola corrispondente.
    $email_ok           = empty($targets_to_email);

    if (!empty($regali_finali) && !empty($targets_to_email)) {
        $recipient = filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$recipient) {
            write_log("Email non valida per ordine $order_id: " . ($order['email'] ?? 'N/A'));
        } else {
            $nome_cliente = $order['first_name'] ?? 'Cliente';
            $template     = get_email_template($chosen_lingua);
            $logo_path    = APP_DIR . '/logo.png';
            $has_logo     = file_exists($logo_path);

            if (!$template) {
                write_log("ERRORE: nessun template email configurato, impossibile inviare email per ordine $order_id.");
            } else {
                $rendered = render_email_template($template, $business_name, $nome_cliente, $regali_finali, $has_logo);

                require_once APP_DIR . '/PHPMailer/Exception.php';
                require_once APP_DIR . '/PHPMailer/PHPMailer.php';
                require_once APP_DIR . '/PHPMailer/SMTP.php';

                // Fino a 3 tentativi di invio SMTP: ricostruiamo il messaggio ad ogni
                // tentativo perché PHPMailer non garantisce di essere riutilizzabile
                // dopo un errore di connessione.
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = $conf['smtp_host'];
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $conf['smtp_user'];
                        $mail->Password   = $conf['smtp_pass'];
                        $mail->SMTPSecure = ($conf['smtp_encryption'] === 'tls') ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                        $mail->Port       = (int)$conf['smtp_port'];
                        $mail->CharSet    = 'UTF-8';
                        $mail->setFrom($conf['smtp_user'], $business_name);
                        $mail->addAddress($recipient);
                        $mail->isHTML(true);
                        $mail->Subject = $rendered['subject'];
                        if ($has_logo) $mail->addEmbeddedImage(get_logo_path_for_email($logo_path, $template['logo_width'] ?? 150), 'logo_cid');
                        $mail->Body    = $rendered['html'];
                        $mail->AltBody = $rendered['text'];
                        $mail->send();
                        write_log("Email inviata a $recipient per ordine $order_id (template: {$template['nome']}, tentativo $attempt)");
                        $email_ok = true;
                        $email_sent_targets = array_keys($discounts);
                        break;
                    } catch (\PHPMailer\PHPMailer\Exception $e) {
                        write_log("ERRORE SMTP (tentativo $attempt) per ordine $order_id: " . $mail->ErrorInfo);
                        if ($attempt < 3) usleep([300000, 900000][$attempt - 1]);
                    }
                }
            }
        }
    }

    // Persisti lo stato finale dell'ordine
    $is_complete = $discounts_complete && $email_ok;
    finalize_processed_order($order_id, $discounts, $email_sent_targets, $is_complete);

    if ($is_complete) {
        unqueue_failed_order($order_id);
    } else {
        $reason = !$discounts_complete
            ? 'Sconto non creato per: ' . implode(', ', $targets_missing)
            : 'Email non ancora inviata con successo';
        queue_failed_order($order_id, $api_url, $reason);
        write_log("Ordine $order_id non completato: $reason. Verrà ritentato (dashboard → Log → Riprova ordini falliti).");
    }

    maybe_send_error_alert($conf);
    maybe_backup_database(); // no-op se già fatto nelle ultime 24h

    if ($is_complete) {
        return ['http_code' => 200, 'message' => "Ordine $order_id completato: " . count($discounts) . ' sconto/i creato/i, email inviata.'];
    }
    $reason = !$discounts_complete
        ? 'sconto non creato per: ' . implode(', ', $targets_missing)
        : 'email non ancora inviata con successo';
    return ['http_code' => 200, 'message' => "Ordine $order_id non completato ($reason). Verrà ritentato."];
}
