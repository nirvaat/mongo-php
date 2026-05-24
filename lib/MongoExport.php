<?php
declare(strict_types=1);

/**
 * MongoDB → portable archive (NDJSON of Extended JSON v2).
 *
 * One JSON object per line:
 *   - line 1 is a "meta" header (format, version, source db, timestamp);
 *   - each collection emits a "collection" line carrying its creation options
 *     and index specs, followed by one "doc" line per document.
 *
 * All BSON types (ObjectId, UTCDateTime, Decimal128, Binary, Regex, …) survive
 * via Extended JSON v2, so the archive round-trips losslessly back into any
 * MongoDB through MongoArchiveImporter — the format is meant for migrating a
 * full database between servers. Streamed through a write callback so exporting
 * a large database never loads it all into memory.
 */
class MongoArchiveExporter {
    public const FORMAT  = 'mongo-php-archive';
    public const VERSION = 1;

    private Mongo $mongo;
    /** @var callable */
    private $write;

    public function __construct(Mongo $mongo, callable $write) {
        $this->mongo = $mongo;
        $this->write = $write;
    }

    public function exportDatabase(string $db): void {
        $w = $this->write;

        $colls = $this->mongo->listCollections($db);
        usort($colls, fn($a, $b) => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));

        // Pre-count exportable collections for the meta header.
        $exportable = array_values(array_filter($colls, function ($c) {
            $name = (string)($c['name'] ?? '');
            return ($c['type'] ?? 'collection') === 'collection'
                && $name !== '' && !str_starts_with($name, 'system.');
        }));

        $w($this->jsonLine([
            't'           => 'meta',
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'db'          => $db,
            'createdAt'   => gmdate('Y-m-d\TH:i:s\Z'),
            'collections' => count($exportable),
        ]));

        foreach ($exportable as $c) {
            $this->exportCollection($db, (string)$c['name'], $c['options'] ?? []);
        }
    }

    private function exportCollection(string $db, string $coll, $options): void {
        $w = $this->write;

        $indexes = [];
        foreach ($this->mongo->listIndexes($db, $coll) as $ix) {
            if (!is_array($ix)) continue;
            unset($ix['v'], $ix['ns']); // server-managed, not valid creation input
            $indexes[] = $ix;
        }

        $w($this->jsonLine([
            't'       => 'collection',
            'name'    => $coll,
            'options' => $options ?: (object)[],
            'indexes' => $indexes,
        ]));

        foreach ($this->mongo->cursor($db, $coll) as $doc) {
            // Built by hand so the document is serialized as Extended JSON v2;
            // json_encode would mangle BSON types.
            $w('{"t":"doc","d":' . doc_to_extjson($doc, false) . "}\n");
        }
    }

    private function jsonLine(array $obj): string {
        return json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
