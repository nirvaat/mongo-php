<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$db = isset($_GET['db']) ? (string)$_GET['db'] : '';
if ($db === '' || preg_match('#[/\\\\. "$*<>:|?\x00]#', $db)) {
    http_response_code(400);
    exit('Invalid or missing db.');
}
$format = (($_GET['format'] ?? 'sql') === 'mongo') ? 'mongo' : 'sql';

try {
    $mongo = new Mongo($_SESSION['conn']);
    // Cheap reachability check before we start streaming a download.
    $mongo->dbStats($db);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Connection failed: ' . h($e->getMessage()));
}

while (ob_get_level() > 0) { ob_end_clean(); }

@set_time_limit(0);

$write = function (string $chunk): void {
    echo $chunk;
    if (function_exists('flush')) { @flush(); }
};

if ($format === 'mongo') {
    require __DIR__ . '/lib/MongoExport.php';
    $filename = $db . '-' . gmdate('Ymd-His') . '.mongo.json';
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    try {
        (new MongoArchiveExporter($mongo, $write))->exportDatabase($db);
    } catch (\Throwable $e) {
        $write('{"t":"error","message":' . json_encode(str_replace(["\r", "\n"], ' ', $e->getMessage())) . "}\n");
    }
    exit;
}

require __DIR__ . '/lib/Export.php';
$filename = $db . '-' . gmdate('Ymd-His') . '.sql';
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    (new SqlExporter($mongo, $write))->exportDatabase($db);
} catch (\Throwable $e) {
    $write("\n-- EXPORT FAILED: " . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n");
}
