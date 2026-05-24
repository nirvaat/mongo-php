<?php
/** @var Mongo $mongo */
/** @var string|null $db */
if (!$db) { flash_set('error', 'No database selected.'); header('Location: index.php'); exit; }

$colls = $mongo->listCollections($db);
usort($colls, fn($a,$b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
$dbStats = [];
try { $dbStats = $mongo->dbStats($db); } catch (\Throwable $e) {}

render_header(['title' => $db, 'mongo' => $mongo, 'db' => $db, 'page' => 'database']);
?>
<div class="card">
  <div class="card-head">
    <h2>Database: <code><?=h($db)?></code></h2>
    <div class="card-actions">
      <a class="btn" href="#create-coll">+ Create collection</a>
      <a class="btn" href="export.php?db=<?=h(urlencode($db))?>">Export to SQL (MySQL)</a>
      <?php if (!is_system_db($db)): ?>
        <form class="inline confirm-form" method="post" action="action.php">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="drop_db">
          <input type="hidden" name="db" value="<?=h($db)?>">
          <input type="hidden" name="confirm" value="<?=h($db)?>" data-confirm-prompt="Type the database name &quot;<?=h($db)?>&quot; to confirm dropping it:">
          <button class="btn danger" type="submit">Drop database</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="kv">
    <div><span>Collections</span><b><?=h((string)($dbStats['collections'] ?? count($colls)))?></b></div>
    <div><span>Objects</span><b><?=format_number($dbStats['objects'] ?? '—')?></b></div>
    <div><span>Data size</span><b><?=format_bytes((int)($dbStats['dataSize'] ?? 0))?></b></div>
    <div><span>Storage size</span><b><?=format_bytes((int)($dbStats['storageSize'] ?? 0))?></b></div>
    <div><span>Indexes</span><b><?=format_number($dbStats['indexes'] ?? '—')?></b></div>
    <div><span>Index size</span><b><?=format_bytes((int)($dbStats['indexSize'] ?? 0))?></b></div>
  </div>
</div>

<div class="card">
  <h2>Collections <span class="muted">(<?=count($colls)?>)</span></h2>
  <?php if (empty($colls)): ?>
    <p class="muted">No collections in this database.</p>
  <?php else: ?>
  <table class="data">
    <thead>
      <tr>
        <th>Name</th>
        <th>Type</th>
        <th class="num">Documents</th>
        <th class="num">Size</th>
        <th class="num">Avg obj</th>
        <th class="num">Indexes</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($colls as $c):
        $name = $c['name'] ?? '';
        $type = $c['type'] ?? 'collection';
        $st = $mongo->collStats($db, $name);
    ?>
      <tr>
        <td><a class="link" href="<?=h(url(['page'=>'browse','db'=>$db,'coll'=>$name]))?>"><?=h($name)?></a>
          <?php if ($type === 'view'): ?><span class="badge">view</span><?php endif; ?>
          <?php if (!empty($st['capped'])): ?><span class="badge">capped</span><?php endif; ?>
        </td>
        <td><?=h($type)?></td>
        <td class="num"><?=format_number($st['count'] ?? '—')?></td>
        <td class="num"><?=isset($st['size']) ? format_bytes((int)$st['size']) : '—'?></td>
        <td class="num"><?=isset($st['avgObjSize']) ? format_bytes((int)$st['avgObjSize']) : '—'?></td>
        <td class="num"><?=h((string)($st['nindexes'] ?? '—'))?></td>
        <td class="actions">
          <a class="btn small" href="<?=h(url(['page'=>'browse','db'=>$db,'coll'=>$name]))?>">Browse</a>
          <a class="btn small" href="<?=h(url(['page'=>'structure','db'=>$db,'coll'=>$name]))?>">Structure</a>
          <a class="btn small" href="<?=h(url(['page'=>'operations','db'=>$db,'coll'=>$name]))?>">Operations</a>
          <form class="inline confirm-form" method="post" action="action.php">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="truncate_collection">
            <input type="hidden" name="db" value="<?=h($db)?>">
            <input type="hidden" name="coll" value="<?=h($name)?>">
            <input type="hidden" name="confirm" value="<?=h($name)?>" data-confirm-prompt="Type collection name &quot;<?=h($name)?>&quot; to TRUNCATE (delete all documents):">
            <button class="btn small warn" type="submit">Truncate</button>
          </form>
          <form class="inline confirm-form" method="post" action="action.php">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="drop_collection">
            <input type="hidden" name="db" value="<?=h($db)?>">
            <input type="hidden" name="coll" value="<?=h($name)?>">
            <input type="hidden" name="confirm" value="<?=h($name)?>" data-confirm-prompt="Type collection name &quot;<?=h($name)?>&quot; to DROP it:">
            <button class="btn small danger" type="submit">Drop</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card" id="create-coll">
  <h2>Create collection</h2>
  <form method="post" action="action.php" class="form-grid">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create_collection">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <label>Name <input type="text" name="name" required></label>
    <label class="checkbox"><input type="checkbox" name="capped" value="1" id="cap"> Capped collection</label>
    <label>Size (bytes) <input type="number" name="cap_size" min="1"></label>
    <label>Max documents <input type="number" name="cap_max" min="1"></label>
    <div class="form-actions"><button type="submit" class="btn primary">Create</button></div>
  </form>
</div>

<?php render_footer(); ?>
