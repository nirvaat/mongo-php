<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();
require __DIR__ . '/lib/Export.php';

$db = isset($_GET['db']) ? (string)$_GET['db'] : '';
if ($db === '' || preg_match('#[/\\\\. "$*<>:|?\x00]#', $db)) {
    http_response_code(400);
    exit('Invalid or missing db.');
}

try {
    $mongo = new Mongo($_SESSION['conn']);
    // Cheap reachability check before we start streaming a download.
    $mongo->dbStats($db);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Connection failed: ' . h($e->getMessage()));
}

while (ob_get_level() > 0) { ob_end_clean(); }

$filename = $db . '-' . gmdate('Ymd-His') . '.sql';
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

@set_time_limit(0);

$write = function (string $chunk): void {
    echo $chunk;
    if (function_exists('flush')) { @flush(); }
};

try {
    (new SqlExporter($mongo, $write))->exportDatabase($db);
} catch (\Throwable $e) {
    $write("\n-- EXPORT FAILED: " . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n");
}
