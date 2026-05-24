<?php
declare(strict_types=1);

/**
 * MySQL SQL dump → MongoDB importer (the inverse of lib/Export.php).
 *
 * Parses a mysqldump-style .sql file with zero dependencies and reproduces the
 * schema in MongoDB as faithfully as the document model allows:
 *
 *   - Each CREATE TABLE becomes a collection.
 *   - Each INSERT row becomes a document; MySQL column types are coerced to the
 *     closest BSON type (INT→int, DECIMAL→Decimal128, DATETIME→UTCDateTime,
 *     JSON→nested object/array, BLOB→Binary, TINYINT(1)→bool, …).
 *   - A single-column PRIMARY KEY is folded into `_id` (so foreign-key values
 *     line up with the referenced documents' `_id`). Composite / missing PKs
 *     fall back to a generated ObjectId and a unique index on the PK columns.
 *   - UNIQUE KEY / KEY / INDEX definitions become MongoDB indexes.
 *   - MongoDB has no joins, so FOREIGN KEYs are NOT enforced; instead an index
 *     is created on each foreign-key column so application-side `$lookup`s stay
 *     fast (the "keep refs + index them" strategy).
 *
 * The whole file is read into memory once (web uploads are bounded by PHP's
 * upload limits), then split into statements and processed sequentially.
 */
class SqlImporter
{
    private Mongo $mongo;
    private string $db;
    private int $batchSize;

    /** @var array<string,array> table name => parsed schema */
    private array $tables = [];

    private array $report;

    public function __construct(Mongo $mongo, string $db, array $opts = [])
    {
        $this->mongo = $mongo;
        $this->db = $db;
        $this->batchSize = max(1, (int)($opts['batchSize'] ?? 500));
        $this->report = [
            'tables'           => [],
            'warnings'         => [],
            'skipped'          => [],
            'totalRows'        => 0,
            'totalCollections' => 0,
            'totalIndexes'     => 0,
        ];
    }

    public function importFile(string $path): array
    {
        $sql = @file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Could not read uploaded file.');
        }
        return $this->importString($sql);
    }

    public function importString(string $sql): array
    {
        foreach ($this->splitStatements($sql) as $stmt) {
            $this->handleStatement($stmt);
        }
        $this->finalize();
        return $this->report;
    }

    // ---------------------------------------------------------------------
    // Statement dispatch
    // ---------------------------------------------------------------------

    private function handleStatement(string $stmt): void
    {
        $stmt = trim($stmt);
        if ($stmt === '') return;

        // Leading keyword(s), case-insensitive.
        if (preg_match('/^CREATE\s+TABLE\b/i', $stmt)) {
            $this->parseCreateTable($stmt);
            return;
        }
        if (preg_match('/^(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\b/i', $stmt)) {
            $this->parseInsert($stmt);
            return;
        }

        // Things we deliberately ignore vs. things worth flagging.
        if (preg_match('/^(SET|LOCK|UNLOCK|USE|DROP|ALTER|START|COMMIT|BEGIN|CREATE\s+DATABASE|\/\*)/i', $stmt)) {
            return;
        }
        if (preg_match('/^CREATE\s+(?:DEFINER=\S+\s+)?(TRIGGER|PROCEDURE|FUNCTION|VIEW|EVENT)/i', $stmt, $m)) {
            $kind = strtolower($m[1]);
            $this->report['skipped'][$kind] = ($this->report['skipped'][$kind] ?? 0) + 1;
            return;
        }
        // Unknown — count it so nothing is silently dropped.
        $kw = strtolower(strtok($stmt, " \t\n("));
        $this->report['skipped'][$kw] = ($this->report['skipped'][$kw] ?? 0) + 1;
    }

    // ---------------------------------------------------------------------
    // CREATE TABLE
    // ---------------------------------------------------------------------

    private function parseCreateTable(string $stmt): void
    {
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`[^`]+`|[A-Za-z0-9_$]+)/i', $stmt, $m)) {
            return;
        }
        $table = $this->unquoteIdent($m[1]);

        $body = $this->extractParenBody($stmt);
        if ($body === null) return;

        $schema = [
            'columns' => [],   // ordered: name => kind
            'pk'      => [],    // primary key column names
            'uniques' => [],    // [ ['name'=>,'cols'=>[]] ]
            'keys'    => [],    // [ ['name'=>,'cols'=>[]] ]
            'fks'     => [],    // [ ['cols'=>[]] ]
        ];

        foreach ($this->splitTopLevel($body) as $def) {
            $def = trim($def);
            if ($def === '') continue;

            if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)/i', $def, $mm)) {
                $schema['pk'] = $this->parseKeyCols($mm[1]);
                continue;
            }
            if (preg_match('/^(?:UNIQUE)\s+(?:KEY|INDEX)?\s*(`[^`]+`|[A-Za-z0-9_$]+)?\s*\((.+)\)/i', $def, $mm)) {
                $schema['uniques'][] = ['name' => $this->unquoteIdent($mm[1] ?? ''), 'cols' => $this->parseKeyCols($mm[2])];
                continue;
            }
            if (preg_match('/^(?:CONSTRAINT\s+(?:`[^`]+`|[A-Za-z0-9_$]+)?\s+)?FOREIGN\s+KEY\s*\((.+?)\)/i', $def, $mm)) {
                $schema['fks'][] = ['cols' => $this->parseKeyCols($mm[1])];
                continue;
            }
            if (preg_match('/^(?:KEY|INDEX)\s+(`[^`]+`|[A-Za-z0-9_$]+)?\s*\((.+)\)/i', $def, $mm)) {
                $schema['keys'][] = ['name' => $this->unquoteIdent($mm[1] ?? ''), 'cols' => $this->parseKeyCols($mm[2])];
                continue;
            }
            if (preg_match('/^(FULLTEXT|SPATIAL)\s+(?:KEY|INDEX)?\s*(`[^`]+`|[A-Za-z0-9_$]+)?\s*\((.+)\)/i', $def, $mm)) {
                // No direct MongoDB equivalent; record a plain index on the columns.
                $schema['keys'][] = ['name' => $this->unquoteIdent($mm[2] ?? ''), 'cols' => $this->parseKeyCols($mm[3])];
                continue;
            }
            if (preg_match('/^(CONSTRAINT|CHECK)\b/i', $def)) {
                continue; // CHECK constraints have no schema-level Mongo equivalent.
            }

            // Otherwise it's a column definition: `name` type ...
            if (preg_match('/^(`[^`]+`|[A-Za-z0-9_$]+)\s+([A-Za-z]+)(\s*\([^)]*\))?/', $def, $mm)) {
                $col = $this->unquoteIdent($mm[1]);
                $kind = $this->typeKind(strtolower($mm[2]), $mm[3] ?? '');
                $schema['columns'][$col] = $kind;
            }
        }

        if (!$schema['columns']) return;
        $this->tables[$table] = $schema;

        if (!isset($this->report['tables'][$table])) {
            $this->report['tables'][$table] = [
                'collection' => $table,
                'rows'       => 0,
                'indexes'    => 0,
                'fkIndexes'  => 0,
                'pk'         => count($schema['pk']) === 1 ? $schema['pk'][0] : null,
                'warnings'   => [],
            ];
        }
    }

    /** Map a MySQL base type to a coercion kind. */
    private function typeKind(string $base, string $args): string
    {
        $args = trim($args, " ()\t");
        switch ($base) {
            case 'tinyint':
                return $args === '1' ? 'bool' : 'int';
            case 'bool':
            case 'boolean':
                return 'bool';
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'bigint':
            case 'bit':
            case 'year':
                return 'int';
            case 'decimal':
            case 'numeric':
            case 'dec':
            case 'fixed':
                return 'decimal';
            case 'float':
            case 'double':
            case 'real':
                return 'double';
            case 'date':
            case 'datetime':
            case 'timestamp':
                return 'date';
            case 'json':
                return 'json';
            case 'binary':
            case 'varbinary':
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
                return 'binary';
            default:
                return 'string';
        }
    }

    // ---------------------------------------------------------------------
    // INSERT
    // ---------------------------------------------------------------------

    private function parseInsert(string $stmt): void
    {
        if (!preg_match('/^(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+(`[^`]+`|[A-Za-z0-9_$]+)\s*(\(([^)]*)\))?\s+VALUES\s*(.*)$/is', $stmt, $m)) {
            return;
        }
        $table = $this->unquoteIdent($m[1]);
        $colList = trim($m[3] ?? '');
        $valuesPart = $m[4];

        $schema = $this->tables[$table] ?? null;

        if ($colList !== '') {
            $cols = array_map([$this, 'unquoteIdent'], $this->splitTopLevel($colList));
        } elseif ($schema) {
            $cols = array_keys($schema['columns']);
        } else {
            $cols = null; // unknown — fall back to positional field names
        }

        $rows = $this->parseValueTuples($valuesPart);
        if (!$rows) return;

        $pkCol = ($schema && count($schema['pk']) === 1) ? $schema['pk'][0] : null;

        $batch = [];
        $inserted = 0;
        foreach ($rows as $vals) {
            $names = $cols ?? array_map(fn($i) => 'f' . ($i + 1), array_keys($vals));
            $doc = [];
            foreach ($vals as $i => $token) {
                $name = $names[$i] ?? ('f' . ($i + 1));
                $kind = $schema['columns'][$name] ?? 'auto';
                $doc[$name] = $this->convertValue($token, $kind);
            }
            // Fold a single-column primary key into _id.
            if ($pkCol !== null && array_key_exists($pkCol, $doc)) {
                $idVal = $doc[$pkCol];
                unset($doc[$pkCol]);
                if ($idVal !== null) {
                    $doc = array_merge(['_id' => $idVal], $doc);
                }
            }
            $batch[] = $doc;
            if (count($batch) >= $this->batchSize) {
                $inserted += $this->flush($table, $batch);
                $batch = [];
            }
        }
        if ($batch) $inserted += $this->flush($table, $batch);

        if (!isset($this->report['tables'][$table])) {
            // INSERT for a table we never saw a CREATE for.
            $this->report['tables'][$table] = [
                'collection' => $table, 'rows' => 0, 'indexes' => 0,
                'fkIndexes' => 0, 'pk' => null, 'warnings' => [],
            ];
        }
        $this->report['tables'][$table]['rows'] += $inserted;
        $this->report['totalRows'] += $inserted;
    }

    private function flush(string $table, array $docs): int
    {
        try {
            $r = $this->mongo->insertMany($this->db, $table, $docs);
            return $r->getInsertedCount();
        } catch (\Throwable $e) {
            $this->report['tables'][$table]['warnings'][] = 'Insert error: ' . $e->getMessage();
            return 0;
        }
    }

    /** Coerce one raw value token to a BSON-friendly PHP value by column kind. */
    private function convertValue(array $tok, string $kind)
    {
        if ($tok['null']) return null;

        // Binary literals (0x.. / X'..') regardless of declared kind.
        if ($tok['hex'] !== null) {
            if ($kind === 'binary') {
                return new \MongoDB\BSON\Binary($tok['hex'], \MongoDB\BSON\Binary::TYPE_GENERIC);
            }
            // bit/int columns sometimes ship as 0x..; otherwise return the bytes.
            if ($kind === 'int') return (int)hexdec(bin2hex($tok['hex']));
            return $tok['hex'];
        }

        $s = $tok['value'];

        if ($kind === 'auto') {
            // No schema: infer from the literal form.
            if (!$tok['quoted'] && is_numeric($s)) {
                return strpos($s, '.') !== false || stripos($s, 'e') !== false ? (float)$s : $this->intOrString($s);
            }
            return $s;
        }

        switch ($kind) {
            case 'int':
                return $this->intOrString($s);
            case 'double':
                return (float)$s;
            case 'bool':
                return (bool)(int)$s;
            case 'decimal':
                try { return new \MongoDB\BSON\Decimal128($s); }
                catch (\Throwable $e) { return $s; }
            case 'date':
                return $this->toDate($s);
            case 'json':
                $d = json_decode($s, true);
                return $d === null && trim($s) !== 'null' ? $s : $d;
            case 'binary':
                return new \MongoDB\BSON\Binary($s, \MongoDB\BSON\Binary::TYPE_GENERIC);
            case 'string':
            default:
                return $s;
        }
    }

    private function intOrString(string $s)
    {
        if (preg_match('/^-?\d+$/', $s)) {
            // Guard against bigint values outside PHP's signed-64-bit range.
            if (bccomp_safe($s, (string)PHP_INT_MAX) <= 0 && bccomp_safe($s, (string)PHP_INT_MIN) >= 0) {
                return (int)$s;
            }
            return $s; // keep precision as string
        }
        return (float)$s;
    }

    private function toDate(string $s)
    {
        $s = trim($s);
        if ($s === '' || strpos($s, '0000-00-00') === 0) return null;
        $tz = new \DateTimeZone('UTC');
        foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s', 'Y-m-d', 'Y-m-d\TH:i:s.u', 'Y-m-d\TH:i:s'] as $fmt) {
            $dt = \DateTime::createFromFormat('!' . $fmt, $s, $tz);
            if ($dt instanceof \DateTime) {
                $ms = $dt->getTimestamp() * 1000 + (int)$dt->format('v');
                return new \MongoDB\BSON\UTCDateTime($ms);
            }
        }
        $ts = strtotime($s . ' UTC');
        return $ts === false ? $s : new \MongoDB\BSON\UTCDateTime($ts * 1000);
    }

    // ---------------------------------------------------------------------
    // Collections + indexes (deferred to the end)
    // ---------------------------------------------------------------------

    private function finalize(): void
    {
        foreach ($this->tables as $table => $schema) {
            // Ensure the collection exists even if the table had no rows.
            try { $this->mongo->createCollection($this->db, $table); }
            catch (\Throwable $e) { /* already exists */ }

            $pkCol = count($schema['pk']) === 1 ? $schema['pk'][0] : null;
            $rep =& $this->report['tables'][$table];
            $seen = ['_id_' => true]; // index key signatures already covered

            $mapCol = function (string $c) use ($pkCol): string {
                return ($pkCol !== null && $c === $pkCol) ? '_id' : $c;
            };

            // Composite / no single PK → preserve uniqueness with a unique index.
            if ($pkCol === null && $schema['pk']) {
                $this->makeIndex($table, $schema['pk'], $mapCol, true, $seen, $rep, 'indexes');
            }
            foreach ($schema['uniques'] as $u) {
                $this->makeIndex($table, $u['cols'], $mapCol, true, $seen, $rep, 'indexes');
            }
            foreach ($schema['keys'] as $k) {
                $this->makeIndex($table, $k['cols'], $mapCol, false, $seen, $rep, 'indexes');
            }
            // Foreign-key columns: index each so $lookup stays fast.
            foreach ($schema['fks'] as $fk) {
                $this->makeIndex($table, $fk['cols'], $mapCol, false, $seen, $rep, 'fkIndexes');
            }
            unset($rep);
        }

        $this->report['totalCollections'] = count($this->report['tables']);
    }

    private function makeIndex(string $table, array $cols, callable $mapCol, bool $unique, array &$seen, array &$rep, string $counter): void
    {
        $key = [];
        foreach ($cols as $c) {
            if ($c === '') continue;
            $key[$mapCol($c)] = 1;
        }
        if (!$key) return;

        $sig = implode(',', array_keys($key));
        if ($sig === '_id') return;          // _id is already unique & indexed
        if (isset($seen[$sig . ($unique ? '!u' : '')])) return;
        $seen[$sig . ($unique ? '!u' : '')] = true;

        try {
            $this->mongo->createIndex($this->db, $table, $key, $unique ? ['unique' => true] : []);
            $rep[$counter]++;
            $this->report['totalIndexes']++;
        } catch (\Throwable $e) {
            $rep['warnings'][] = "Index on {$sig} skipped: " . $e->getMessage();
        }
    }

    private function parseKeyCols(string $s): array
    {
        $out = [];
        foreach ($this->splitTopLevel($s) as $part) {
            // Strip prefix length `(10)` and ASC/DESC.
            $part = preg_replace('/\s*\(\d+\)\s*/', '', $part);
            $part = preg_replace('/\s+(ASC|DESC)\s*$/i', '', trim($part));
            $name = $this->unquoteIdent(trim($part));
            if ($name !== '') $out[] = $name;
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Low-level parsing utilities
    // ---------------------------------------------------------------------

    private function unquoteIdent(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if ($s[0] === '`' && substr($s, -1) === '`') {
            return str_replace('``', '`', substr($s, 1, -1));
        }
        if ($s[0] === '"' && substr($s, -1) === '"') {
            return str_replace('""', '"', substr($s, 1, -1));
        }
        return $s;
    }

    /** Return the body inside the first balanced (...) of a CREATE TABLE. */
    private function extractParenBody(string $stmt): ?string
    {
        $start = strpos($stmt, '(');
        if ($start === false) return null;
        $depth = 0; $inStr = false; $ch = ''; $inTick = false;
        $n = strlen($stmt);
        for ($i = $start; $i < $n; $i++) {
            $c = $stmt[$i];
            if ($inStr) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $ch) {
                    if ($i + 1 < $n && $stmt[$i + 1] === $ch) { $i++; continue; }
                    $inStr = false;
                }
                continue;
            }
            if ($inTick) { if ($c === '`') $inTick = false; continue; }
            if ($c === "'" || $c === '"') { $inStr = true; $ch = $c; continue; }
            if ($c === '`') { $inTick = true; continue; }
            if ($c === '(') { $depth++; if ($depth === 1) $bodyStart = $i + 1; }
            elseif ($c === ')') { $depth--; if ($depth === 0) return substr($stmt, $bodyStart, $i - $bodyStart); }
        }
        return null;
    }

    /** Split on top-level commas, respecting (), '', "" and ``. */
    private function splitTopLevel(string $s): array
    {
        $out = []; $cur = ''; $depth = 0; $inStr = false; $ch = ''; $inTick = false;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($inStr) {
                $cur .= $c;
                if ($c === '\\') { if ($i + 1 < $n) { $cur .= $s[++$i]; } continue; }
                if ($c === $ch) {
                    if ($i + 1 < $n && $s[$i + 1] === $ch) { $cur .= $s[++$i]; continue; }
                    $inStr = false;
                }
                continue;
            }
            if ($inTick) { $cur .= $c; if ($c === '`') $inTick = false; continue; }
            if ($c === "'" || $c === '"') { $inStr = true; $ch = $c; $cur .= $c; continue; }
            if ($c === '`') { $inTick = true; $cur .= $c; continue; }
            if ($c === '(') { $depth++; $cur .= $c; continue; }
            if ($c === ')') { $depth--; $cur .= $c; continue; }
            if ($c === ',' && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $c;
        }
        if (trim($cur) !== '') $out[] = $cur;
        return $out;
    }

    /**
     * Parse the VALUES region of an INSERT into rows of raw value tokens.
     * Each token is ['null'=>bool, 'quoted'=>bool, 'hex'=>?bytes, 'value'=>string].
     */
    private function parseValueTuples(string $s): array
    {
        $rows = [];
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            // Find the next tuple opener.
            while ($i < $n && $s[$i] !== '(') $i++;
            if ($i >= $n) break;
            [$vals, $i] = $this->readTuple($s, $i, $n);
            if ($vals !== null) $rows[] = $vals;
        }
        return $rows;
    }

    /** Read one ( ... ) tuple starting at $i (which points at '('). */
    private function readTuple(string $s, int $i, int $n): array
    {
        $i++; // skip '('
        $vals = [];
        $cur = ''; $inStr = false; $ch = ''; $depth = 1; $hasContent = false;
        while ($i < $n) {
            $c = $s[$i];
            if ($inStr) {
                $cur .= $c;
                if ($c === '\\') { if ($i + 1 < $n) { $cur .= $s[$i + 1]; $i += 2; continue; } }
                elseif ($c === $ch) {
                    if ($i + 1 < $n && $s[$i + 1] === $ch) { $cur .= $s[$i + 1]; $i += 2; continue; }
                    $inStr = false;
                }
                $i++;
                continue;
            }
            if ($c === "'" || $c === '"') { $inStr = true; $ch = $c; $cur .= $c; $hasContent = true; $i++; continue; }
            if ($c === '(') { $depth++; $cur .= $c; $i++; continue; }
            if ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    if ($hasContent || trim($cur) !== '') $vals[] = $this->classifyToken($cur);
                    return [$vals, $i + 1];
                }
                $cur .= $c; $i++; continue;
            }
            if ($c === ',' && $depth === 1) { $vals[] = $this->classifyToken($cur); $cur = ''; $hasContent = false; $i++; continue; }
            $cur .= $c; $i++;
        }
        return [null, $i]; // unterminated tuple
    }

    private function classifyToken(string $t): array
    {
        $t = trim($t);
        $base = ['null' => false, 'quoted' => false, 'hex' => null, 'value' => $t];

        if ($t === '' || strcasecmp($t, 'NULL') === 0) { $base['null'] = true; return $base; }
        if (strcasecmp($t, 'TRUE') === 0)  { $base['value'] = '1'; return $base; }
        if (strcasecmp($t, 'FALSE') === 0) { $base['value'] = '0'; return $base; }

        // _binary 'xx' / N'xx' prefixes — unwrap to the quoted literal.
        if (preg_match("/^(?:_[A-Za-z0-9]+|N)\s*('.*'|\".*\")$/is", $t, $m)) {
            $t = $m[1];
        }

        $c0 = $t[0];
        if ($c0 === "'" || $c0 === '"') {
            $base['quoted'] = true;
            $base['value'] = $this->decodeString(substr($t, 1, -1), $c0);
            return $base;
        }
        if (preg_match('/^0x([0-9a-fA-F]*)$/', $t, $m)) {
            $base['hex'] = $m[1] === '' ? '' : hex2bin(strlen($m[1]) % 2 ? '0' . $m[1] : $m[1]);
            return $base;
        }
        if (preg_match("/^[xX]'([0-9a-fA-F]*)'$/", $t, $m)) {
            $base['hex'] = $m[1] === '' ? '' : hex2bin(strlen($m[1]) % 2 ? '0' . $m[1] : $m[1]);
            return $base;
        }
        if (preg_match("/^[bB]'([01]+)'$/", $t, $m)) {
            $base['value'] = (string)bindec($m[1]);
            return $base;
        }
        // Bare numeric or other literal.
        return $base;
    }

    /** Decode a MySQL single/double-quoted string body (escapes + doubled quote). */
    private function decodeString(string $s, string $quote): string
    {
        // Collapse doubled quote first.
        $s = str_replace($quote . $quote, $quote, $s);
        $map = [
            '\\0' => "\0", "\\'" => "'", '\\"' => '"', '\\b' => "\x08",
            '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\Z' => "\x1a",
            '\\\\' => '\\', '\\%' => '%', '\\_' => '_',
        ];
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $n) {
                $pair = '\\' . $s[$i + 1];
                if (isset($map[$pair])) { $out .= $map[$pair]; $i++; continue; }
                $out .= $s[$i + 1]; $i++; continue; // unknown escape → literal char
            }
            $out .= $s[$i];
        }
        return $out;
    }

    /**
     * Split a full dump into individual SQL statements, stripping comments and
     * honouring `DELIMITER` directives. Strings, identifiers and comments are
     * respected so semicolons inside them don't break statements.
     */
    private function splitStatements(string $sql): array
    {
        $out = [];
        $delim = ';';
        $cur = '';
        $n = strlen($sql);
        $i = 0;
        while ($i < $n) {
            $c = $sql[$i];

            // DELIMITER directive (only meaningful at a line start, but we accept
            // it whenever the current statement buffer is empty).
            if (trim($cur) === '' && ($i === 0 || $sql[$i - 1] === "\n") && preg_match('/^DELIMITER[ \t]+(\S+)[ \t]*\r?\n/i', substr($sql, $i), $m)) {
                $delim = $m[1];
                $i += strlen($m[0]);
                $cur = '';
                continue;
            }

            // Line comments: -- ... or # ...
            if (($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-' && ($i + 2 >= $n || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r"))
                || $c === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $n : $nl + 1;
                continue;
            }

            // Block comments /* ... */ (including /*! conditional — we don't need them).
            if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $n : $end + 2;
                continue;
            }

            // Quoted strings.
            if ($c === "'" || $c === '"') {
                $cur .= $c;
                $i++;
                while ($i < $n) {
                    $d = $sql[$i];
                    $cur .= $d;
                    if ($d === '\\' && $i + 1 < $n) { $cur .= $sql[$i + 1]; $i += 2; continue; }
                    if ($d === $c) {
                        if ($i + 1 < $n && $sql[$i + 1] === $c) { $cur .= $sql[$i + 1]; $i += 2; continue; }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // Backtick identifiers.
            if ($c === '`') {
                $cur .= $c; $i++;
                while ($i < $n) { $cur .= $sql[$i]; if ($sql[$i] === '`') { $i++; break; } $i++; }
                continue;
            }

            // Statement terminator.
            $dl = strlen($delim);
            if (substr($sql, $i, $dl) === $delim) {
                if (trim($cur) !== '') $out[] = $cur;
                $cur = '';
                $i += $dl;
                continue;
            }

            $cur .= $c;
            $i++;
        }
        if (trim($cur) !== '') $out[] = $cur;
        return $out;
    }
}

/**
 * Safe big-integer compare without requiring the bcmath extension.
 * Returns -1, 0 or 1 like bccomp for two decimal integer strings.
 */
function bccomp_safe(string $a, string $b): int
{
    if (function_exists('bccomp')) return bccomp($a, $b, 0);
    $na = $a[0] === '-'; $nb = $b[0] === '-';
    if ($na !== $nb) return $na ? -1 : 1;
    $aa = ltrim($na ? substr($a, 1) : $a, '0') ?: '0';
    $bb = ltrim($nb ? substr($b, 1) : $b, '0') ?: '0';
    if (strlen($aa) !== strlen($bb)) $cmp = strlen($aa) < strlen($bb) ? -1 : 1;
    else $cmp = strcmp($aa, $bb);
    return $na ? -$cmp : $cmp;
}
