<?php
define('CONFIG_FILE', __DIR__ . '/config.json');
define('REGOLE_FILE', __DIR__ . '/regole_sconti.json');
define('LOG_FILE',    __DIR__ . '/webhook_log.txt');

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
    ];
    if (!file_exists(CONFIG_FILE)) return $defaults;
    $data = json_decode(file_get_contents(CONFIG_FILE), true);
    return array_merge($defaults, (array)$data);
}

function save_config(array $config): void {
    file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function make_regole_backup(): void {
    if (!is_readable(REGOLE_FILE)) return;
    $dir = dirname(REGOLE_FILE) . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    copy(REGOLE_FILE, $dir . '/regole_' . date('Ymd_His') . '.json');
    $files = glob($dir . '/regole_*.json') ?: [];
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - 10)) as $old) {
        unlink($old);
    }
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
