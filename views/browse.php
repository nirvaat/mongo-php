<?php
/** @var Mongo $mongo */
/** @var string|null $db */
/** @var string|null $coll */
if (!$db || !$coll) { header('Location: index.php'); exit; }

$limit = (int)($_GET['limit'] ?? 25);
$limit = max(1, min(500, $limit));
$skip  = max(0, (int)($_GET['skip'] ?? 0));

$filterStr = (string)($_GET['filter'] ?? '');
$sortStr   = (string)($_GET['sort'] ?? '{"_id": -1}');
$projStr   = (string)($_GET['proj'] ?? '');

$filter = [];
$sort = [];
$proj = [];
$queryError = null;
try {
    if (trim($filterStr) !== '') $filter = (array)extjson_to_doc($filterStr);
    if (trim($sortStr)   !== '') $sort   = (array)extjson_to_doc($sortStr);
    if (trim($projStr)   !== '') $proj   = (array)extjson_to_doc($projStr);
} catch (\Throwable $e) {
    $queryError = $e->getMessage();
}

$total = 0;
$docs = [];
$execTime = null;
if (!$queryError) {
    try {
        $total = $mongo->count($db, $coll, $filter);
        $opts = ['limit' => $limit, 'skip' => $skip];
        if ($sort) $opts['sort'] = $sort;
        if ($proj) $opts['projection'] = $proj;
        $t0 = microtime(true);
        $docs = $mongo->find($db, $coll, $filter, $opts);
        $execTime = microtime(true) - $t0;
    } catch (\Throwable $e) {
        $queryError = $e->getMessage();
    }
}

// derive column list (union of top-level keys, _id first)
$cols = [];
foreach ($docs as $d) {
    foreach (array_keys((array)$d) as $k) {
        if (!isset($cols[$k])) $cols[$k] = true;
    }
}
$cols = array_keys($cols);
if (in_array('_id', $cols, true)) {
    $cols = array_merge(['_id'], array_values(array_diff($cols, ['_id'])));
}

render_header(['title' => "$db.$coll", 'mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>'browse']);
?>
<div class="card">
  <form method="get" action="index.php" class="browse-filter">
    <input type="hidden" name="page" value="browse">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <div class="filter-row">
      <label class="grow">Filter
        <input type="text" name="filter" placeholder='{"field": "value"}' value="<?=h($filterStr)?>">
      </label>
      <label>Sort
        <input type="text" name="sort" value="<?=h($sortStr)?>">
      </label>
      <label>Projection
        <input type="text" name="proj" placeholder='{"field":1}' value="<?=h($projStr)?>">
      </label>
      <label class="small">Limit
        <input type="number" name="limit" value="<?=h((string)$limit)?>" min="1" max="500" style="width:6em">
      </label>
      <label class="small">Skip
        <input type="number" name="skip" value="<?=h((string)$skip)?>" min="0" style="width:7em">
      </label>
      <button class="btn primary" type="submit">Run</button>
    </div>
  </form>
  <?php if ($queryError): ?>
    <div class="flash flash-error">Query error: <?=h($queryError)?></div>
  <?php else: ?>
    <div class="result-meta">
      <span><b><?=format_number($total)?></b> matching · showing <b><?=count($docs)?></b> · skip <?=h((string)$skip)?>
      <?php if ($execTime !== null): ?> · <?=number_format($execTime*1000, 1)?> ms<?php endif; ?>
      </span>
      <span class="pager">
        <?php
          $prev = max(0, $skip - $limit);
          $next = $skip + $limit;
          $base = ['page'=>'browse','db'=>$db,'coll'=>$coll,'filter'=>$filterStr,'sort'=>$sortStr,'proj'=>$projStr,'limit'=>$limit];
        ?>
        <?php if ($skip > 0): ?><a class="btn small" href="<?=h(url(array_merge($base,['skip'=>0])))?>">⏮</a><?php endif; ?>
        <?php if ($skip > 0): ?><a class="btn small" href="<?=h(url(array_merge($base,['skip'=>$prev])))?>">‹ Prev</a><?php endif; ?>
        <?php if ($next < $total): ?><a class="btn small" href="<?=h(url(array_merge($base,['skip'=>$next])))?>">Next ›</a><?php endif; ?>
        <?php if ($next < $total): ?><a class="btn small" href="<?=h(url(array_merge($base,['skip'=>max(0,$total-$limit)])))?>">⏭</a><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
</div>

<?php if (!$queryError && empty($docs)): ?>
  <div class="card"><p class="muted">No documents match.</p></div>
<?php elseif (!$queryError): ?>
  <div class="card no-pad">
    <div class="table-scroll">
    <table class="data docs">
      <thead>
        <tr>
          <th class="row-actions">Actions</th>
          <?php foreach ($cols as $c): ?>
            <th><?=h($c)?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($docs as $d):
        $idVal = is_array($d) ? ($d['_id'] ?? null) : ($d->_id ?? null);
        if ($idVal instanceof \MongoDB\BSON\ObjectId) {
            $idStr = (string)$idVal;
        } else {
            // build a JSON-encodable form for non-OID _ids
            try { $idStr = doc_to_extjson($idVal, false); } catch (\Throwable $e) { $idStr = ''; }
        }
      ?>
        <tr>
          <td class="row-actions">
            <a class="btn small" href="<?=h(url(['page'=>'edit','db'=>$db,'coll'=>$coll,'id'=>$idStr]))?>" title="Edit">✎</a>
            <form class="inline confirm-form" method="post" action="action.php" data-confirm="Delete this document?">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="delete_document">
              <input type="hidden" name="db" value="<?=h($db)?>">
              <input type="hidden" name="coll" value="<?=h($coll)?>">
              <input type="hidden" name="id" value="<?=h($idStr)?>">
              <input type="hidden" name="return" value="<?=h($_SERVER['REQUEST_URI'])?>">
              <button class="btn small danger" type="submit" title="Delete">🗑</button>
            </form>
          </td>
          <?php foreach ($cols as $c): ?>
            <td><?=render_cell(get_field($d, $c))?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
