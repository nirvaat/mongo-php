<?php
declare(strict_types=1);

/**
 * Restores a MongoArchiveExporter archive (NDJSON / Extended JSON v2) into a
 * MongoDB database — the import side of server-to-server migration.
 *
 * Documents keep their original _id, collection creation options and index
 * specs are replayed. The file is read line by line and documents are inserted
 * in batches, so restoring a large archive doesn't exhaust memory. Returns a
 * report shaped like SqlImporter's so the view can render it the same way.
 */
class MongoArchiveImporter {
    private Mongo $mongo;
    private string $db;
    private int $batchSize;

    public function __construct(Mongo $mongo, string $db, int $batchSize = 500) {
        $this->mongo = $mongo;
        $this->db = $db;
        $this->batchSize = max(1, $batchSize);
    }

    public function importFile(string $path): array {
        $fh = fopen($path, 'rb');
        if ($fh === false) throw new RuntimeException('Cannot open uploaded file.');
        try {
            return $this->importStream($fh);
        } finally {
            fclose($fh);
        }
    }

    /** @param resource $fh */
    private function importStream($fh): array {
        $report = [
            'tables'           => [],
            'warnings'         => [],
            'skipped'          => [],
            'totalRows'        => 0,
            'totalCollections' => 0,
            'totalIndexes'     => 0,
            'sourceDb'         => null,
        ];

        $current = null; // current collection name
        $batch   = [];
        $lineNo  = 0;
        $sawMeta = false;

        $flush = function () use (&$batch, &$current, &$report) {
            if (!$batch || $current === null) { $batch = []; return; }
            try {
                $this->mongo->insertMany($this->db, $current, $batch);
                $report['tables'][$current]['rows'] += count($batch);
                $report['totalRows'] += count($batch);
            } catch (\Throwable $e) {
                $report['tables'][$current]['warnings'][] = $e->getMessage();
            }
            $batch = [];
        };

        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            $line = trim($line);
            if ($line === '') continue;

            try {
                $obj = extjson_to_doc($line);
            } catch (\Throwable $e) {
                $report['warnings'][] = "Line {$lineNo}: invalid JSON ({$e->getMessage()}).";
                continue;
            }
            if (!is_array($obj)) continue;
            $t = $obj['t'] ?? null;

            if ($t === 'meta') {
                $sawMeta = true;
                $report['sourceDb'] = $obj['db'] ?? null;
                if (($obj['format'] ?? '') !== MongoArchiveExporter::FORMAT) {
                    $report['warnings'][] = 'File header is not a mongo-php archive; attempting import anyway.';
                }
            } elseif ($t === 'collection') {
                $flush();
                $current = (string)($obj['name'] ?? '');
                if ($current === '') { $current = null; continue; }
                if (!isset($report['tables'][$current])) {
                    $report['tables'][$current] = ['rows' => 0, 'indexes' => 0, 'warnings' => [], 'pk' => null, 'fkIndexes' => 0];
                    $report['totalCollections']++;
                }
                $this->ensureCollection($current, $obj['options'] ?? []);
                $this->createIndexes($current, $obj['indexes'] ?? [], $report);
            } elseif ($t === 'doc') {
                if ($current === null) {
                    $report['skipped']['doc before collection'] = ($report['skipped']['doc before collection'] ?? 0) + 1;
                    continue;
                }
                if (!array_key_exists('d', $obj)) continue;
                $batch[] = $obj['d'];
                if (count($batch) >= $this->batchSize) $flush();
            } else {
                $report['skipped']['unknown line'] = ($report['skipped']['unknown line'] ?? 0) + 1;
            }
        }
        $flush();

        if (!$sawMeta && empty($report['tables'])) {
            throw new InvalidArgumentException('No mongo-php archive content found in file.');
        }
        return $report;
    }

    private function ensureCollection(string $name, $options): void {
        $opts = is_array($options) ? $options : (array)$options;
        try {
            $this->mongo->createCollection($this->db, $name, $opts);
        } catch (\Throwable $e) {
            // Already exists, or an option the target server rejects — inserts
            // still work, so this is non-fatal.
        }
    }

    private function createIndexes(string $name, $indexes, array &$report): void {
        if (!is_array($indexes)) return;
        foreach ($indexes as $ix) {
            if (!is_array($ix)) continue;
            $indexName = (string)($ix['name'] ?? '');
            if ($indexName === '_id_') continue; // auto-created by the server
            $keySpec = $ix['key'] ?? null;
            if (!is_array($keySpec) || !$keySpec) continue;
            $opts = $ix;
            unset($opts['key'], $opts['v'], $opts['ns']);
            try {
                $this->mongo->createIndex($this->db, $name, $keySpec, $opts);
                $report['tables'][$name]['indexes']++;
                $report['totalIndexes']++;
            } catch (\Throwable $e) {
                $report['tables'][$name]['warnings'][] = 'index ' . $indexName . ': ' . $e->getMessage();
            }
        }
    }
}
