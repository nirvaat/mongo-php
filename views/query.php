<?php
/** @var Mongo $mongo */
/** @var string|null $db */
/** @var string|null $coll */
if (!$db) { header('Location: index.php'); exit; }

$mode = (string)($_GET['mode'] ?? 'find');
if (!in_array($mode, ['find','aggregate','command'], true)) $mode = 'find';

$filter   = (string)($_GET['filter']   ?? '{}');
$sort     = (string)($_GET['sort']     ?? '{"_id":-1}');
$proj     = (string)($_GET['proj']     ?? '');
$limit    = (int)($_GET['limit']  ?? 50);
$skip     = (int)($_GET['skip']   ?? 0);
$pipeline = (string)($_GET['pipeline'] ?? "[\n  { \"\$match\": {} },\n  { \"\$limit\": 50 }\n]");
$cmdJson  = (string)($_GET['cmd']      ?? "{\n  \"buildInfo\": 1\n}");

$result = null;
$resErr = null;
$execMs = null;

if (!empty($_GET['run'])) {
    try {
        $t0 = microtime(true);
        if ($mode === 'find') {
            if (!$coll) throw new RuntimeException('Select a collection for find queries.');
            $f = trim($filter) === '' ? [] : (array)extjson_to_doc($filter);
            $opts = ['limit' => max(1, min(500, $limit)), 'skip' => max(0, $skip)];
            if (trim($sort) !== '') $opts['sort'] = (array)extjson_to_doc($sort);
            if (trim($proj) !== '') $opts['projection'] = (array)extjson_to_doc($proj);
            $result = $mongo->find($db, $coll, $f, $opts);
        } elseif ($mode === 'aggregate') {
            if (!$coll) throw new RuntimeException('Select a collection for aggregations.');
            $pipe = (array)extjson_to_doc($pipeline);
            $result = $mongo->aggregate($db, $coll, $pipe);
        } else {
            $cmd = (array)extjson_to_doc($cmdJson);
            $result = $mongo->cmd($db, $cmd);
        }
        $execMs = (microtime(true) - $t0) * 1000;
    } catch (\Throwable $e) {
        $resErr = $e->getMessage();
    }
}

render_header(['title' => "Query {$db}".($coll ? ".$coll" : ''), 'mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>'query']);
?>
<div class="card">
  <div class="subtabs">
    <?php foreach (['find'=>'Find','aggregate'=>'Aggregate','command'=>'Run command'] as $m => $lbl):
      $href = url(['page'=>'query','db'=>$db,'coll'=>$coll,'mode'=>$m]);
    ?>
      <a class="subtab <?=$mode===$m?'active':''?>" href="<?=h($href)?>"><?=h($lbl)?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="index.php">
    <input type="hidden" name="page" value="query">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <?php if ($coll): ?><input type="hidden" name="coll" value="<?=h($coll)?>"><?php endif; ?>
    <input type="hidden" name="mode" value="<?=h($mode)?>">
    <input type="hidden" name="run" value="1">

    <?php if ($mode === 'find'): ?>
      <label>Filter (JSON)
        <textarea class="code" name="filter" rows="4"><?=h($filter)?></textarea>
      </label>
      <div class="filter-row">
        <label class="grow">Sort <input type="text" name="sort" value="<?=h($sort)?>"></label>
        <label class="grow">Projection <input type="text" name="proj" value="<?=h($proj)?>"></label>
        <label class="small">Limit <input type="number" name="limit" value="<?=h((string)$limit)?>" min="1" max="500"></label>
        <label class="small">Skip <input type="number" name="skip" value="<?=h((string)$skip)?>" min="0"></label>
      </div>
    <?php elseif ($mode === 'aggregate'): ?>
      <label>Aggregation pipeline (JSON array)
        <textarea class="code" name="pipeline" rows="12"><?=h($pipeline)?></textarea>
      </label>
    <?php else: ?>
      <label>Command (JSON, run against <code><?=h($db)?></code>)
        <textarea class="code" name="cmd" rows="8"><?=h($cmdJson)?></textarea>
      </label>
      <p class="hint">Examples: <code>{"ping":1}</code>, <code>{"serverStatus":1}</code>, <code>{"collStats":"<?=h((string)$coll)?>"}</code></p>
    <?php endif; ?>
    <div class="form-actions">
      <button class="btn primary" type="submit">Run</button>
    </div>
  </form>
</div>

<?php if ($resErr !== null): ?>
  <div class="card"><div class="flash flash-error"><?=h($resErr)?></div></div>
<?php elseif ($result !== null): ?>
  <div class="card">
    <div class="result-meta"><span><b><?=is_array($result) ? count($result) : 0?></b> document(s)<?php if ($execMs!==null):?> · <?=number_format($execMs,1)?> ms<?php endif;?></span></div>
    <pre class="code result"><?php
      try { echo h(doc_to_extjson($result, true)); }
      catch (\Throwable $e) { echo h('Output not serializable: ' . $e->getMessage()); }
    ?></pre>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
