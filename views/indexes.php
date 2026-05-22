<?php
/** @var Mongo $mongo */
/** @var string|null $db */
/** @var string|null $coll */
if (!$db || !$coll) { header('Location: index.php'); exit; }

$indexes = $mongo->listIndexes($db, $coll);

render_header(['title' => "Indexes {$db}.{$coll}", 'mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>'indexes']);
?>
<div class="card">
  <h2>Indexes <span class="muted">(<?=count($indexes)?>)</span></h2>
  <table class="data">
    <thead>
      <tr><th>Name</th><th>Key</th><th>Options</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($indexes as $ix):
      $name = (string)($ix['name'] ?? '');
      $key = $ix['key'] ?? [];
      $opts = $ix;
      unset($opts['name'], $opts['key'], $opts['v'], $opts['ns']);
    ?>
      <tr>
        <td><code><?=h($name)?></code></td>
        <td><code><?=h(doc_to_extjson($key, false))?></code></td>
        <td><?=$opts ? '<code>'.h(doc_to_extjson($opts, false)).'</code>' : '<span class="muted">—</span>'?></td>
        <td>
          <?php if ($name !== '_id_'): ?>
            <form class="inline confirm-form" method="post" action="action.php" data-confirm="Drop index '<?=h($name)?>'?">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="drop_index">
              <input type="hidden" name="db" value="<?=h($db)?>">
              <input type="hidden" name="coll" value="<?=h($coll)?>">
              <input type="hidden" name="name" value="<?=h($name)?>">
              <button class="btn small danger" type="submit">Drop</button>
            </form>
          <?php else: ?>
            <span class="muted">primary</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Create index</h2>
  <form method="post" action="action.php" class="form-grid">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create_index">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <label>Key (JSON, e.g. <code>{"email": 1}</code> or <code>{"loc": "2dsphere"}</code>)
      <input type="text" name="key" required placeholder='{"field": 1}'>
    </label>
    <label>Name (optional) <input type="text" name="name"></label>
    <label class="checkbox"><input type="checkbox" name="unique" value="1"> Unique</label>
    <label class="checkbox"><input type="checkbox" name="sparse" value="1"> Sparse</label>
    <div class="form-actions"><button class="btn primary" type="submit">Create</button></div>
  </form>
</div>
<?php render_footer(); ?>
