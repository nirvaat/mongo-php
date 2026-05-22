<?php
declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_bytes(int $bytes, int $precision = 2): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / (1024 ** $i), $precision) . ' ' . $units[$i];
}

function format_number($n): string {
    if (!is_numeric($n)) return (string)$n;
    return number_format((float)$n);
}

/**
 * Convert a PHP value coming from the Mongo driver into Extended JSON v2.
 */
function doc_to_extjson($doc, bool $pretty = true): string {
    if (class_exists('\MongoDB\BSON\Document')) {
        $bson = \MongoDB\BSON\Document::fromPHP($doc)->toBSON();
        $json = \MongoDB\BSON\Document::fromBSON($bson)->toCanonicalExtendedJSON();
    } else {
        $bson = \MongoDB\BSON\fromPHP($doc);
        $json = \MongoDB\BSON\toCanonicalExtendedJSON($bson);
    }
    if (!$pretty) return $json;
    $decoded = json_decode($json);
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Parse Extended JSON v2 input into a PHP document suitable for the driver.
 * Throws on invalid input.
 */
function extjson_to_doc(string $json) {
    $json = trim($json);
    if ($json === '') throw new InvalidArgumentException('Empty document');
    if (class_exists('\MongoDB\BSON\Document')) {
        return \MongoDB\BSON\Document::fromJSON($json)->toPHP([
            'root'     => 'array',
            'document' => 'array',
            'array'    => 'array',
        ]);
    }
    $bson = \MongoDB\BSON\fromJSON($json);
    return \MongoDB\BSON\toPHP($bson, [
        'root'     => 'array',
        'document' => 'array',
        'array'    => 'array',
    ]);
}

/**
 * Render a single value (any depth) as a compact one-line string suitable for table cells.
 */
function render_cell($val, int $maxLen = 80): string {
    if ($val === null) {
        return '<span class="null">null</span>';
    }
    if (is_bool($val)) {
        return '<span class="bool">' . ($val ? 'true' : 'false') . '</span>';
    }
    if ($val instanceof \MongoDB\BSON\ObjectId) {
        return '<span class="oid">ObjectId("' . h((string)$val) . '")</span>';
    }
    if ($val instanceof \MongoDB\BSON\UTCDateTime) {
        try {
            $dt = $val->toDateTime();
            return '<span class="date">' . h($dt->format('Y-m-d H:i:s.v')) . 'Z</span>';
        } catch (\Throwable $e) {
            return '<span class="date">(date)</span>';
        }
    }
    if ($val instanceof \MongoDB\BSON\Binary) {
        return '<span class="bin">Binary(' . strlen($val->getData()) . 'B,t' . $val->getType() . ')</span>';
    }
    if ($val instanceof \MongoDB\BSON\Regex) {
        return '<span class="regex">/' . h($val->getPattern()) . '/' . h($val->getFlags()) . '</span>';
    }
    if ($val instanceof \MongoDB\BSON\Decimal128) {
        return '<span class="num">' . h((string)$val) . '</span>';
    }
    if ($val instanceof \MongoDB\BSON\Timestamp) {
        return '<span class="ts">Timestamp(' . h((string)$val) . ')</span>';
    }
    if (is_scalar($val)) {
        $s = (string)$val;
        if (strlen($s) > $maxLen) {
            return '<span class="str" title="' . h($s) . '">' . h(mb_substr($s, 0, $maxLen)) . '…</span>';
        }
        return '<span class="str">' . h($s) . '</span>';
    }
    // arrays/objects: compact JSON
    try {
        $json = doc_to_extjson($val, false);
    } catch (\Throwable $e) {
        $json = '(object)';
    }
    if (strlen($json) > $maxLen) {
        return '<span class="obj" title="' . h($json) . '">' . h(mb_substr($json, 0, $maxLen)) . '…</span>';
    }
    return '<span class="obj">' . h($json) . '</span>';
}

function get_field($doc, string $name) {
    if (is_array($doc)) {
        return $doc[$name] ?? null;
    }
    if (is_object($doc)) {
        return $doc->{$name} ?? null;
    }
    return null;
}

function url(array $params, ?string $base = 'index.php'): string {
    return $base . '?' . http_build_query($params);
}

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_pop(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function is_system_db(string $db): bool {
    return in_array($db, ['admin', 'local', 'config'], true);
}

/**
 * Decode an ObjectId or scalar coming from query string into a value for use
 * as a Mongo filter on _id.
 */
function parse_id(string $raw) {
    // Try ObjectId
    if (preg_match('/^[a-f0-9]{24}$/i', $raw)) {
        try { return new \MongoDB\BSON\ObjectId($raw); } catch (\Throwable $e) {}
    }
    // Try JSON (e.g. for non-OID _id values)
    if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '"' || $raw[0] === '[' || is_numeric($raw))) {
        try {
            return extjson_to_doc('{"v":' . $raw . '}')['v'];
        } catch (\Throwable $e) {}
    }
    return $raw;
}
