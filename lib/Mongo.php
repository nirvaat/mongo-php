<?php
declare(strict_types=1);

use MongoDB\Driver\Manager;
use MongoDB\Driver\Command;
use MongoDB\Driver\Query;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\ReadPreference;

class Mongo {
    private Manager $manager;
    private array $conn;

    public function __construct(array $conn) {
        $this->conn = $conn;
        $uri = self::buildUri($conn);
        $opts = [];
        if (!empty($conn['tls'])) {
            $opts['tls'] = true;
            if (!empty($conn['tls_insecure'])) {
                $opts['tlsAllowInvalidCertificates'] = true;
            }
        }
        $this->manager = new Manager($uri, $opts);
    }

    public static function buildUri(array $c): string {
        if (!empty($c['uri'])) {
            return $c['uri'];
        }
        $host = $c['host'] ?? '127.0.0.1';
        $port = (int)($c['port'] ?? 27017);
        $auth = '';
        if (!empty($c['user'])) {
            $auth = rawurlencode($c['user']);
            if (isset($c['pass']) && $c['pass'] !== '') {
                $auth .= ':' . rawurlencode($c['pass']);
            }
            $auth .= '@';
        }
        $params = [];
        if (!empty($c['authdb'])) $params['authSource'] = $c['authdb'];
        if (!empty($c['replset'])) $params['replicaSet'] = $c['replset'];
        $qs = $params ? '?' . http_build_query($params) : '';
        return "mongodb://{$auth}{$host}:{$port}/{$qs}";
    }

    public function manager(): Manager { return $this->manager; }

    /** Run a database command and return decoded array results. */
    public function cmd(string $db, array $command): array {
        $cmd = new Command($command);
        $cursor = $this->manager->executeCommand($db, $cmd);
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
        return $cursor->toArray();
    }

    public function listDatabases(): array {
        $r = $this->cmd('admin', ['listDatabases' => 1]);
        return $r[0]['databases'] ?? [];
    }

    public function listCollections(string $db): array {
        try {
            $r = $this->cmd($db, ['listCollections' => 1, 'nameOnly' => false]);
            return $r;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function dbStats(string $db): array {
        $r = $this->cmd($db, ['dbStats' => 1]);
        return $r[0] ?? [];
    }

    public function collStats(string $db, string $coll): array {
        try {
            $r = $this->cmd($db, ['collStats' => $coll]);
            return $r[0] ?? [];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function buildInfo(): array {
        $r = $this->cmd('admin', ['buildInfo' => 1]);
        return $r[0] ?? [];
    }

    public function serverStatus(): array {
        $r = $this->cmd('admin', ['serverStatus' => 1]);
        return $r[0] ?? [];
    }

    public function hostInfo(): array {
        try {
            $r = $this->cmd('admin', ['hostInfo' => 1]);
            return $r[0] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function createCollection(string $db, string $name, array $options = []): void {
        $this->cmd($db, array_merge(['create' => $name], $options));
    }

    public function dropCollection(string $db, string $coll): void {
        $this->cmd($db, ['drop' => $coll]);
    }

    public function dropDatabase(string $db): void {
        $this->cmd($db, ['dropDatabase' => 1]);
    }

    public function renameCollection(string $fromDb, string $fromColl, string $toDb, string $toColl, bool $dropTarget = false): void {
        $this->cmd('admin', [
            'renameCollection' => "{$fromDb}.{$fromColl}",
            'to'               => "{$toDb}.{$toColl}",
            'dropTarget'       => $dropTarget,
        ]);
    }

    public function count(string $db, string $coll, array $filter = []): int {
        try {
            $r = $this->cmd($db, ['count' => $coll, 'query' => (object)$filter]);
            return (int)($r[0]['n'] ?? 0);
        } catch (\Throwable $e) {
            // fallback to aggregation count
            $r = $this->cmd($db, [
                'aggregate' => $coll,
                'pipeline'  => [['$match' => (object)$filter], ['$count' => 'n']],
                'cursor'    => new stdClass(),
            ]);
            $batch = $r[0]['cursor']['firstBatch'] ?? [];
            return (int)($batch[0]['n'] ?? 0);
        }
    }

    /**
     * Run a find. Returns an array of documents as arrays.
     */
    public function find(string $db, string $coll, array $filter = [], array $options = []): array {
        $query = new Query($filter ?: (object)[], $options);
        $cursor = $this->manager->executeQuery("{$db}.{$coll}", $query);
        $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
        return $cursor->toArray();
    }

    public function findOne(string $db, string $coll, array $filter, array $options = []) {
        $options['limit'] = 1;
        $r = $this->find($db, $coll, $filter, $options);
        return $r[0] ?? null;
    }

    public function aggregate(string $db, string $coll, array $pipeline, array $options = []): array {
        $r = $this->cmd($db, array_merge([
            'aggregate' => $coll,
            'pipeline'  => $pipeline,
            'cursor'    => (object)['batchSize' => $options['batchSize'] ?? 1000],
        ], $options));
        return $r[0]['cursor']['firstBatch'] ?? [];
    }

    public function insertOne(string $db, string $coll, $doc): \MongoDB\Driver\WriteResult {
        $bulk = new BulkWrite();
        $bulk->insert($doc);
        return $this->manager->executeBulkWrite("{$db}.{$coll}", $bulk);
    }

    public function insertMany(string $db, string $coll, array $docs): \MongoDB\Driver\WriteResult {
        $bulk = new BulkWrite();
        foreach ($docs as $d) $bulk->insert($d);
        return $this->manager->executeBulkWrite("{$db}.{$coll}", $bulk);
    }

    public function replaceOne(string $db, string $coll, array $filter, $replacement): \MongoDB\Driver\WriteResult {
        $bulk = new BulkWrite();
        $bulk->update($filter, $replacement, ['multi' => false, 'upsert' => false]);
        return $this->manager->executeBulkWrite("{$db}.{$coll}", $bulk);
    }

    public function updateMany(string $db, string $coll, array $filter, array $update, array $opts = []): \MongoDB\Driver\WriteResult {
        $bulk = new BulkWrite();
        $bulk->update($filter, $update, array_merge(['multi' => true, 'upsert' => false], $opts));
        return $this->manager->executeBulkWrite("{$db}.{$coll}", $bulk);
    }

    public function deleteOne(string $db, string $coll, array $filter): \MongoDB\Driver\WriteResult {
        $bulk = new BulkWrite();
        $bulk->delete($filter, ['limit' => 1]);
        return $this->manager->executeBulkWrite("{$db}.{$coll}", $bulk);
    }

    public function deleteMany(string $db, string $coll, array $filter): \MongoDB\Driver\WriteResult {
        $bulk = new BulkWrite();
        $bulk->delete($filter, ['limit' => 0]);
        return $this->manager->executeBulkWrite("{$db}.{$coll}", $bulk);
    }

    public function listIndexes(string $db, string $coll): array {
        try {
            $r = $this->cmd($db, ['listIndexes' => $coll]);
            return $r[0]['cursor']['firstBatch'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function createIndex(string $db, string $coll, array $key, array $opts = []): void {
        $spec = array_merge(['key' => $key], $opts);
        if (empty($spec['name'])) {
            $parts = [];
            foreach ($key as $k => $v) $parts[] = $k . '_' . $v;
            $spec['name'] = implode('_', $parts);
        }
        $this->cmd($db, ['createIndexes' => $coll, 'indexes' => [$spec]]);
    }

    public function dropIndex(string $db, string $coll, string $name): void {
        $this->cmd($db, ['dropIndexes' => $coll, 'index' => $name]);
    }

    /**
     * Sample documents to infer collection structure (top-level fields and observed BSON types).
     */
    public function inferStructure(string $db, string $coll, int $sample = 100): array {
        try {
            $docs = $this->aggregate($db, $coll, [['$sample' => ['size' => $sample]]]);
        } catch (\Throwable $e) {
            $docs = $this->find($db, $coll, [], ['limit' => $sample]);
        }
        $fields = [];
        foreach ($docs as $d) {
            if (!is_array($d)) $d = (array)$d;
            foreach ($d as $k => $v) {
                $t = self::bsonType($v);
                if (!isset($fields[$k])) {
                    $fields[$k] = ['count' => 0, 'types' => []];
                }
                $fields[$k]['count']++;
                $fields[$k]['types'][$t] = ($fields[$k]['types'][$t] ?? 0) + 1;
            }
        }
        return ['sampled' => count($docs), 'fields' => $fields];
    }

    public static function bsonType($v): string {
        if ($v === null) return 'null';
        if (is_bool($v)) return 'bool';
        if (is_int($v)) return 'int';
        if (is_float($v)) return 'double';
        if (is_string($v)) return 'string';
        if ($v instanceof \MongoDB\BSON\ObjectId) return 'objectId';
        if ($v instanceof \MongoDB\BSON\UTCDateTime) return 'date';
        if ($v instanceof \MongoDB\BSON\Binary) return 'binData';
        if ($v instanceof \MongoDB\BSON\Decimal128) return 'decimal';
        if ($v instanceof \MongoDB\BSON\Regex) return 'regex';
        if ($v instanceof \MongoDB\BSON\Timestamp) return 'timestamp';
        if (is_array($v)) {
            // detect numeric (list) vs assoc
            $isList = array_keys($v) === range(0, count($v) - 1);
            return $isList ? 'array' : 'object';
        }
        if (is_object($v)) return 'object';
        return 'unknown';
    }
}
