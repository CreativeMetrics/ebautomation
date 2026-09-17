<?php
use PHPMailer\PHPMailer\PHPMailer as Mailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/functions.php';

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
rotate_logs();

$conf       = load_config();
$csrf       = csrf_token();
$brand_name = $conf['business_name'] ?: 'Automazione Sconti';

$users = load_users();

define('EBAUTO_APP', true);
require_once __DIR__ . '/includes/dashboard_pages.php';

require __DIR__ . '/includes/dashboard_bootstrap.php';
require __DIR__ . '/includes/dashboard_actions.php';
require __DIR__ . '/includes/dashboard_data.php';
require __DIR__ . '/includes/dashboard_layout.php';
