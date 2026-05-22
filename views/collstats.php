<?php
/** @var Mongo $mongo */
/** @var string|null $db */
/** @var string|null $coll */
if (!$db || !$coll) { header('Location: index.php'); exit; }

$st = $mongo->collStats($db, $coll);

render_header(['title' => "Stats {$db}.{$coll}", 'mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>'collstats']);
?>
<div class="card">
  <h2>Collection stats <code><?=h($db)?>.<?=h($coll)?></code></h2>
  <?php if (isset($st['error'])): ?>
    <div class="flash flash-error"><?=h($st['error'])?></div>
  <?php else: ?>
  <div class="kv">
    <div><span>Documents</span><b><?=format_number($st['count'] ?? 0)?></b></div>
    <div><span>Avg obj size</span><b><?=isset($st['avgObjSize']) ? format_bytes((int)$st['avgObjSize']) : '—'?></b></div>
    <div><span>Data size</span><b><?=format_bytes((int)($st['size'] ?? 0))?></b></div>
    <div><span>Storage size</span><b><?=format_bytes((int)($st['storageSize'] ?? 0))?></b></div>
    <div><span>Indexes</span><b><?=h((string)($st['nindexes'] ?? '—'))?></b></div>
    <div><span>Total index size</span><b><?=format_bytes((int)($st['totalIndexSize'] ?? 0))?></b></div>
    <div><span>Capped</span><b><?=!empty($st['capped']) ? 'yes' : 'no'?></b></div>
    <?php if (!empty($st['capped'])): ?>
      <div><span>Max documents</span><b><?=format_number($st['max'] ?? 0)?></b></div>
      <div><span>Max size</span><b><?=format_bytes((int)($st['maxSize'] ?? 0))?></b></div>
    <?php endif; ?>
  </div>
  <details>
    <summary>Raw stats</summary>
    <pre class="code"><?=h(doc_to_extjson($st, true))?></pre>
  </details>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
