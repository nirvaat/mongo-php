<?php
declare(strict_types=1);

/**
 * MongoDB → MySQL SQL dump generator.
 *
 * Two passes per collection: discover the union of top-level fields and
 * observed BSON types, then re-iterate to emit INSERT rows. Nested
 * objects/arrays land in JSON columns. MongoDB has no relational joins or
 * foreign keys, so none are emitted.
 */
class SqlExporter {
    private Mongo $mongo;
    /** @var callable */
    private $write;
    private int $batchSize;

    public function __construct(Mongo $mongo, callable $write, int $batchSize = 100) {
        $this->mongo = $mongo;
        $this->write = $write;
        $this->batchSize = max(1, $batchSize);
    }

    public function exportDatabase(string $db): void {
        $w = $this->write;
        $dbIdent = $this->ident($db);
        $w("-- mongo-php → MySQL export\n");
        $w("-- Source database: {$db}\n");
        $w("-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n");
        $w("--\n");
        $w("-- NOTE: MongoDB has no relational joins or foreign keys. Soft\n");
        $w("-- references between collections (e.g. *_id ObjectId fields) are\n");
        $w("-- application-defined and are not emitted as FOREIGN KEY clauses.\n");
        $w("-- Nested objects and arrays are stored as JSON columns.\n");
        $w("--\n\n");
        $w("SET NAMES utf8mb4;\n");
        $w("SET time_zone = '+00:00';\n");
        $w("SET FOREIGN_KEY_CHECKS = 0;\n");
        $w("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        $w("CREATE DATABASE IF NOT EXISTS {$dbIdent} /*!40100 DEFAULT CHARACTER SET utf8mb4 */;\n");
        $w("USE {$dbIdent};\n\n");

        $colls = $this->mongo->listCollections($db);
        usort($colls, fn($a, $b) => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

        foreach ($colls as $c) {
            if (($c['type'] ?? 'collection') !== 'collection') continue;
            $name = (string)($c['name'] ?? '');
            if ($name === '' || str_starts_with($name, 'system.')) continue;
            $this->exportCollection($db, $name);
        }

        $w("SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    private function exportCollection(string $db, string $coll): void {
        $w = $this->write;
        $schema = $this->discoverSchema($db, $coll);
        $cols = array_keys($schema);
        usort($cols, function ($a, $b) {
            if ($a === '_id') return -1;
            if ($b === '_id') return 1;
            return strcmp($a, $b);
        });

        $table = $this->ident($coll);
        $w("--\n-- Table structure for `{$coll}`\n--\n");
        $w("DROP TABLE IF EXISTS {$table};\n");
        $w("CREATE TABLE {$table} (\n");
        $defs = [];
        foreach ($cols as $col) {
            $sqlType = $this->mysqlType($schema[$col]);
            $nullable = ($col === '_id') ? 'NOT NULL' : 'NULL';
            $defs[] = '  ' . $this->ident($col) . " {$sqlType} {$nullable}";
        }
        if (in_array('_id', $cols, true) && $this->idIsPkSafe($schema['_id'])) {
            $defs[] = '  PRIMARY KEY (`_id`)';
        }
        $w(implode(",\n", $defs) . "\n");
        $w(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n");

        $indexes = $this->mongo->listIndexes($db, $coll);
        foreach ($indexes as $idx) {
            $iname = (string)($idx['name'] ?? '');
            if ($iname === '' || $iname === '_id_') continue;
            $key = $idx['key'] ?? [];
            if (!is_array($key) || !$key) continue;
            $idxCols = [];
            foreach ($key as $k => $dir) {
                // Skip dotted (sub-field) index keys — no MySQL equivalent column.
                if (!is_string($k) || strpos($k, '.') !== false) { $idxCols = []; break; }
                if (!array_key_exists($k, $schema)) continue;
                $idxCols[] = $this->ident($k) . ($dir == -1 ? ' DESC' : '');
            }
            if (!$idxCols) continue;
            $unique = !empty($idx['unique']) ? 'UNIQUE ' : '';
            $w("CREATE {$unique}INDEX " . $this->ident($iname) . " ON {$table} (" . implode(', ', $idxCols) . ");\n");
        }
        $w("\n");

        $w("--\n-- Data for `{$coll}`\n--\n");
        $cursor = $this->mongo->cursor($db, $coll);
        $colList = implode(', ', array_map([$this, 'ident'], $cols));
        $buffer = [];
        foreach ($cursor as $doc) {
            $vals = [];
            foreach ($cols as $col) {
                $vals[] = $this->sqlValue($doc[$col] ?? null, $schema[$col]);
            }
            $buffer[] = '(' . implode(', ', $vals) . ')';
            if (count($buffer) >= $this->batchSize) {
                $w("INSERT INTO {$table} ({$colList}) VALUES\n" . implode(",\n", $buffer) . ";\n");
                $buffer = [];
            }
        }
        if ($buffer) {
            $w("INSERT INTO {$table} ({$colList}) VALUES\n" . implode(",\n", $buffer) . ";\n");
        }
        $w("\n");
    }

    /**
     * Walk every document once, collecting field → set of observed BSON types.
     */
    private function discoverSchema(string $db, string $coll): array {
        $schema = [];
        $cursor = $this->mongo->cursor($db, $coll);
        foreach ($cursor as $doc) {
            if (!is_array($doc)) continue;
            foreach ($doc as $k => $v) {
                if (!is_string($k)) continue;
                $t = Mongo::bsonType($v);
                if (!isset($schema[$k])) $schema[$k] = [];
                $schema[$k][$t] = true;
            }
        }
        // Ensure _id is present even on empty collections.
        if (!$schema) $schema['_id'] = ['objectId' => true];
        return $schema;
    }

    private function mysqlType(array $types): string {
        // Anything nested or mixed → JSON.
        if (isset($types['object']) || isset($types['array'])) return 'JSON';
        $keys = array_keys($types);
        if (count($keys) === 1) {
            switch ($keys[0]) {
                case 'null':      return 'TEXT';
                case 'bool':      return 'TINYINT(1)';
                case 'int':       return 'BIGINT';
                case 'double':    return 'DOUBLE';
                case 'decimal':   return 'DECIMAL(38,10)';
                case 'objectId':  return 'CHAR(24)';
                case 'date':      return 'DATETIME(3)';
                case 'binData':   return 'LONGBLOB';
                case 'timestamp': return 'VARCHAR(64)';
                case 'regex':     return 'VARCHAR(255)';
                case 'string':    return 'TEXT';
            }
        }
        // Numeric union (int + double + decimal) → DOUBLE.
        $numeric = ['int' => 1, 'double' => 1, 'decimal' => 1, 'null' => 1];
        if (!array_diff_key($types, $numeric)) {
            if (isset($types['decimal'])) return 'DECIMAL(38,10)';
            if (isset($types['double']))  return 'DOUBLE';
            return 'BIGINT';
        }
        return 'TEXT';
    }

    private function idIsPkSafe(array $types): bool {
        unset($types['null']);
        if (isset($types['object']) || isset($types['array'])) return false;
        return (bool)$types;
    }

    private function sqlValue($v, array $colTypes): string {
        if ($v === null) return 'NULL';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_int($v)) return (string)$v;
        if (is_float($v)) {
            if (is_nan($v) || is_infinite($v)) return 'NULL';
            return rtrim(rtrim(sprintf('%.17g', $v), '0'), '.') ?: '0';
        }
        if ($v instanceof \MongoDB\BSON\ObjectId) return "'" . (string)$v . "'";
        if ($v instanceof \MongoDB\BSON\UTCDateTime) {
            try {
                $dt = $v->toDateTime();
                $dt->setTimezone(new \DateTimeZone('UTC'));
                return "'" . $dt->format('Y-m-d H:i:s.v') . "'";
            } catch (\Throwable $e) { return 'NULL'; }
        }
        if ($v instanceof \MongoDB\BSON\Decimal128) return "'" . (string)$v . "'";
        if ($v instanceof \MongoDB\BSON\Binary) {
            $hex = bin2hex($v->getData());
            return $hex === '' ? "''" : "UNHEX('{$hex}')";
        }
        if ($v instanceof \MongoDB\BSON\Regex) {
            return "'" . $this->esc('/' . $v->getPattern() . '/' . $v->getFlags()) . "'";
        }
        if ($v instanceof \MongoDB\BSON\Timestamp) {
            return "'" . $this->esc((string)$v) . "'";
        }
        if (is_string($v)) return "'" . $this->esc($v) . "'";
        if (is_array($v) || is_object($v)) {
            try {
                $json = doc_to_extjson($v, false);
            } catch (\Throwable $e) {
                $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
            }
            return "'" . $this->esc($json) . "'";
        }
        return "'" . $this->esc((string)$v) . "'";
    }

    private function esc(string $s): string {
        return strtr($s, [
            "\\"   => "\\\\",
            "'"    => "\\'",
            "\0"   => "\\0",
            "\n"   => "\\n",
            "\r"   => "\\r",
            "\x1a" => "\\Z",
        ]);
    }

    private function ident(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
