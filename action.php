<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
csrf_check();

$mongo = new Mongo($_SESSION['conn']);
$action = (string)($_POST['action'] ?? '');
$db = (string)($_POST['db'] ?? '');
$coll = (string)($_POST['coll'] ?? '');
$return = (string)($_POST['return'] ?? 'index.php');

// Basic safety: only allow same-origin relative returns.
if (!preg_match('#^[a-zA-Z0-9_./?=&%-]+$#', $return) || str_starts_with($return, '//')) {
    $return = 'index.php';
}

try {
    switch ($action) {
        case 'create_db': {
            $name = trim((string)$_POST['name']);
            $coll0 = trim((string)($_POST['initial_collection'] ?? '_init'));
            if ($name === '') throw new InvalidArgumentException('Database name required.');
            if (preg_match('#[/\\\\. "$*<>:|?\x00]#', $name)) throw new InvalidArgumentException('Invalid database name.');
            // MongoDB creates a DB lazily; create an initial collection to make it visible.
            $mongo->createCollection($name, $coll0);
            flash_set('success', "Database '{$name}' created (with collection '{$coll0}').");
            $return = url(['page'=>'database','db'=>$name]);
            break;
        }
        case 'drop_db': {
            if (!$db) throw new InvalidArgumentException('Missing db.');
            $confirm = $_POST['confirm'] ?? '';
            if ($confirm !== $db) throw new InvalidArgumentException("Confirmation does not match '{$db}'.");
            $mongo->dropDatabase($db);
            flash_set('success', "Database '{$db}' dropped.");
            $return = 'index.php';
            break;
        }
        case 'create_collection': {
            if (!$db) throw new InvalidArgumentException('Missing db.');
            $name = trim((string)$_POST['name']);
            if ($name === '') throw new InvalidArgumentException('Collection name required.');
            $options = [];
            if (!empty($_POST['capped'])) {
                $size = (int)($_POST['cap_size'] ?? 0);
                if ($size <= 0) throw new InvalidArgumentException('Capped size must be > 0.');
                $options['capped'] = true;
                $options['size'] = $size;
                if (!empty($_POST['cap_max'])) $options['max'] = (int)$_POST['cap_max'];
            }
            $mongo->createCollection($db, $name, $options);
            flash_set('success', "Collection '{$db}.{$name}' created.");
            $return = url(['page'=>'browse','db'=>$db,'coll'=>$name]);
            break;
        }
        case 'drop_collection': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $mongo->dropCollection($db, $coll);
            flash_set('success', "Collection '{$db}.{$coll}' dropped.");
            $return = url(['page'=>'database','db'=>$db]);
            break;
        }
        case 'truncate_collection': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $confirm = $_POST['confirm'] ?? '';
            if ($confirm !== $coll) throw new InvalidArgumentException("Confirmation does not match '{$coll}'.");
            $r = $mongo->deleteMany($db, $coll, []);
            flash_set('success', "Truncated {$coll}: " . $r->getDeletedCount() . ' documents removed.');
            $return = url(['page'=>'browse','db'=>$db,'coll'=>$coll]);
            break;
        }
        case 'rename_collection': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $newDb = trim((string)($_POST['new_db'] ?? $db));
            $newColl = trim((string)$_POST['new_coll']);
            if ($newColl === '') throw new InvalidArgumentException('New collection name required.');
            $mongo->renameCollection($db, $coll, $newDb ?: $db, $newColl, !empty($_POST['drop_target']));
            flash_set('success', "Renamed to {$newDb}.{$newColl}.");
            $return = url(['page'=>'browse','db'=>$newDb,'coll'=>$newColl]);
            break;
        }
        case 'insert_document': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $doc = extjson_to_doc((string)$_POST['document']);
            $r = $mongo->insertOne($db, $coll, $doc);
            flash_set('success', 'Inserted ' . $r->getInsertedCount() . ' document.');
            $return = url(['page'=>'browse','db'=>$db,'coll'=>$coll]);
            break;
        }
        case 'update_document': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $idRaw = (string)$_POST['id'];
            $newDoc = extjson_to_doc((string)$_POST['document']);
            $filter = ['_id' => parse_id($idRaw)];
            // Preserve _id in the replacement: if user removed/changed it, we keep the original.
            if (isset($newDoc['_id'])) unset($newDoc['_id']);
            $newDoc = array_merge(['_id' => $filter['_id']], $newDoc);
            $r = $mongo->replaceOne($db, $coll, $filter, $newDoc);
            flash_set('success', 'Matched ' . $r->getMatchedCount() . ', modified ' . $r->getModifiedCount() . '.');
            $return = url(['page'=>'browse','db'=>$db,'coll'=>$coll]);
            break;
        }
        case 'delete_document': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $idRaw = (string)$_POST['id'];
            $r = $mongo->deleteOne($db, $coll, ['_id' => parse_id($idRaw)]);
            flash_set('success', 'Deleted ' . $r->getDeletedCount() . ' document.');
            $return = url(['page'=>'browse','db'=>$db,'coll'=>$coll]);
            break;
        }
        case 'delete_many': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $filterJson = trim((string)($_POST['filter'] ?? ''));
            $filter = $filterJson === '' ? [] : extjson_to_doc($filterJson);
            $confirm = $_POST['confirm'] ?? '';
            if ($confirm !== 'DELETE') throw new InvalidArgumentException("Type DELETE to confirm.");
            $r = $mongo->deleteMany($db, $coll, $filter);
            flash_set('success', 'Deleted ' . $r->getDeletedCount() . ' document(s).');
            $return = url(['page'=>'browse','db'=>$db,'coll'=>$coll]);
            break;
        }
        case 'update_many': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $filter = extjson_to_doc((string)$_POST['filter']);
            $update = extjson_to_doc((string)$_POST['update']);
            $upsert = !empty($_POST['upsert']);
            $r = $mongo->updateMany($db, $coll, $filter, $update, ['upsert' => $upsert]);
            flash_set('success', 'Matched ' . $r->getMatchedCount() . ', modified ' . $r->getModifiedCount() . ', upserted ' . $r->getUpsertedCount() . '.');
            $return = url(['page'=>'operations','db'=>$db,'coll'=>$coll]);
            break;
        }
        case 'create_index': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $keyJson = trim((string)$_POST['key']);
            if ($keyJson === '') throw new InvalidArgumentException('Index key JSON required.');
            $key = extjson_to_doc($keyJson);
            $opts = [];
            if (!empty($_POST['name']))    $opts['name']   = trim((string)$_POST['name']);
            if (!empty($_POST['unique']))  $opts['unique'] = true;
            if (!empty($_POST['sparse']))  $opts['sparse'] = true;
            $mongo->createIndex($db, $coll, $key, $opts);
            flash_set('success', 'Index created.');
            $return = url(['page'=>'indexes','db'=>$db,'coll'=>$coll]);
            break;
        }
        case 'drop_index': {
            if (!$db || !$coll) throw new InvalidArgumentException('Missing db/coll.');
            $name = (string)$_POST['name'];
            if ($name === '' || $name === '_id_') throw new InvalidArgumentException('Cannot drop _id index or empty name.');
            $mongo->dropIndex($db, $coll, $name);
            flash_set('success', "Index '{$name}' dropped.");
            $return = url(['page'=>'indexes','db'=>$db,'coll'=>$coll]);
            break;
        }
        case 'run_command': {
            // Handled inline by the query page (no redirect), but POST goes here.
            if (!$db) throw new InvalidArgumentException('Missing db.');
            $_SESSION['last_command'] = [
                'db'      => $db,
                'command' => (string)$_POST['command'],
            ];
            $return = url(['page'=>'query','db'=>$db,'coll'=>$coll,'rancmd'=>1]);
            break;
        }
        default:
            throw new InvalidArgumentException('Unknown action: ' . $action);
    }
} catch (\Throwable $e) {
    flash_set('error', $e->getMessage());
}

header('Location: ' . $return);
exit;
