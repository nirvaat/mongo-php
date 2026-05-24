<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();
require __DIR__ . '/lib/layout.php';

try {
    $mongo = new Mongo($_SESSION['conn']);
} catch (\Throwable $e) {
    unset($_SESSION['conn']);
    header('Location: login.php');
    exit;
}

$page = preg_replace('/[^a-z_]/', '', (string)($_GET['page'] ?? 'home'));
$db   = isset($_GET['db'])   ? (string)$_GET['db']   : null;
$coll = isset($_GET['coll']) ? (string)$_GET['coll'] : null;

$validPages = ['home','database','browse','structure','query','insert','edit','indexes','collstats','operations','status','import','restore'];
if (!in_array($page, $validPages, true)) $page = 'home';

$viewFile = __DIR__ . '/views/' . $page . '.php';
if (!file_exists($viewFile)) {
    $viewFile = __DIR__ . '/views/home.php';
    $page = 'home';
}

try {
    include $viewFile;
} catch (\Throwable $e) {
    if (!headers_sent()) {
        render_header(['title' => 'Error', 'mongo' => $mongo, 'db' => $db, 'coll' => $coll, 'page' => $page]);
    }
    echo '<div class="flash flash-error">' . h($e->getMessage()) . '</div>';
    echo '<pre class="error-trace">' . h($e->getTraceAsString()) . '</pre>';
    render_footer();
}
