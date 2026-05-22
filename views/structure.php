<?php
/** @var Mongo $mongo */
/** @var string|null $db */
/** @var string|null $coll */
if (!$db || !$coll) { header('Location: index.php'); exit; }

$sample = max(10, min(1000, (int)($_GET['sample'] ?? 100)));
$err = null;
$info = ['sampled'=>0,'fields'=>[]];
try { $info = $mongo->inferStructure($db, $coll, $sample); }
catch (\Throwable $e) { $err = $e->getMessage(); }

render_header(['title' => "Structure {$db}.{$coll}", 'mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>'structure']);
?>
<div class="card">
  <div class="card-head">
    <h2>Inferred structure <span class="muted">(sampled <?=h((string)$info['sampled'])?> docs)</span></h2>
    <form method="get" class="inline">
      <input type="hidden" name="page" value="structure">
      <input type="hidden" name="db" value="<?=h($db)?>">
      <input type="hidden" name="coll" value="<?=h($coll)?>">
      <label class="small">Sample size <input type="number" name="sample" value="<?=h((string)$sample)?>" min="10" max="1000"></label>
      <button class="btn small" type="submit">Re-sample</button>
    </form>
  </div>
  <?php if ($err): ?>
    <div class="flash flash-error"><?=h($err)?></div>
  <?php endif; ?>
  <p class="hint">MongoDB has no fixed schema. This is a best-effort schema based on a random sample of documents.</p>
  <table class="data">
    <thead>
      <tr>
        <th>Field</th>
        <th class="num">Presence</th>
        <th>Observed types</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($info['fields'] as $name => $f):
        $pct = $info['sampled'] ? round(100 * $f['count'] / $info['sampled'], 1) : 0;
        arsort($f['types']);
        $types = [];
        foreach ($f['types'] as $t => $n) $types[] = "{$t} ({$n})";
    ?>
      <tr>
        <td><code><?=h((string)$name)?></code></td>
        <td class="num"><?=$pct?>% (<?=$f['count']?>)</td>
        <td><?=h(implode(', ', $types))?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Rename collection</h2>
  <form method="post" action="action.php" class="form-grid">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="rename_collection">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <label>New database <input type="text" name="new_db" value="<?=h($db)?>" required></label>
    <label>New collection name <input type="text" name="new_coll" value="<?=h($coll)?>" required></label>
    <label class="checkbox"><input type="checkbox" name="drop_target" value="1"> Drop target if exists</label>
    <div class="form-actions"><button class="btn primary" type="submit">Rename</button></div>
    <p class="hint">Requires privileges on <code>admin</code>.</p>
  </form>
</div>

<?php render_footer(); ?>
