<?php
declare(strict_types=1);

if (!extension_loaded('mongodb')) {
    http_response_code(500);
    echo '<h1>MongoDB PHP extension not installed</h1>';
    echo '<p>Install with: <code>pecl install mongodb</code> and enable in php.ini.</p>';
    exit;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => !empty($_SERVER['HTTPS']),
    'samesite' => 'Lax',
]);
session_name('MONGOPHPSESS');
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; connect-src 'self'");

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/Mongo.php';

function require_login(): void {
    if (empty($_SESSION['conn'])) {
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string {
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    $t = $_POST['_csrf'] ?? $_GET['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        exit('CSRF check failed.');
    }
}
