<?php
/** @var Mongo $mongo */
/** @var string|null $db */
/** @var string|null $coll */
if (!$db || !$coll) { header('Location: index.php'); exit; }

render_header(['title' => "Operations {$db}.{$coll}", 'mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>'operations']);
?>
<div class="card">
  <h2>Update many</h2>
  <form method="post" action="action.php">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="update_many">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <label>Filter (JSON)
      <textarea class="code" name="filter" rows="4">{}</textarea>
    </label>
    <label>Update (JSON, must use operators like <code>$set</code>)
      <textarea class="code" name="update" rows="6">{"$set": {}}</textarea>
    </label>
    <label class="checkbox"><input type="checkbox" name="upsert" value="1"> Upsert</label>
    <div class="form-actions"><button class="btn primary" type="submit">Run update</button></div>
  </form>
</div>

<div class="card">
  <h2>Delete many</h2>
  <form class="confirm-form" method="post" action="action.php" data-confirm="Run delete? This is irreversible.">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="delete_many">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <label>Filter (empty = match all)
      <textarea class="code" name="filter" rows="4"></textarea>
    </label>
    <label>Type <code>DELETE</code> to confirm <input type="text" name="confirm" required></label>
    <div class="form-actions"><button class="btn danger" type="submit">Delete</button></div>
  </form>
</div>

<div class="card">
  <h2>Rename / move</h2>
  <form method="post" action="action.php" class="form-grid">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="rename_collection">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <label>Target database <input type="text" name="new_db" value="<?=h($db)?>" required></label>
    <label>Target collection <input type="text" name="new_coll" value="<?=h($coll)?>" required></label>
    <label class="checkbox"><input type="checkbox" name="drop_target" value="1"> Drop target if exists</label>
    <div class="form-actions"><button class="btn primary" type="submit">Rename</button></div>
  </form>
</div>

<div class="card">
  <h2>Truncate</h2>
  <form class="confirm-form" method="post" action="action.php">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="truncate_collection">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <input type="hidden" name="confirm" value="<?=h($coll)?>" data-confirm-prompt="Type collection name &quot;<?=h($coll)?>&quot; to TRUNCATE:">
    <p class="muted">Removes all documents but keeps the collection and its indexes.</p>
    <div class="form-actions"><button class="btn warn" type="submit">Truncate <?=h($coll)?></button></div>
  </form>
</div>

<div class="card">
  <h2>Drop collection</h2>
  <form class="confirm-form" method="post" action="action.php">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="drop_collection">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <input type="hidden" name="confirm" value="<?=h($coll)?>" data-confirm-prompt="Type collection name &quot;<?=h($coll)?>&quot; to DROP it:">
    <p class="muted">Removes the collection and all its indexes. Cannot be undone.</p>
    <div class="form-actions"><button class="btn danger" type="submit">Drop <?=h($coll)?></button></div>
  </form>
</div>

<?php render_footer(); ?>
