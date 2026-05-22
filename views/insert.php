<?php
/** @var Mongo $mongo */
/** @var string|null $db */
/** @var string|null $coll */
if (!$db || !$coll) { header('Location: index.php'); exit; }

$template = "{\n  \n}";
render_header(['title' => "Insert into {$db}.{$coll}", 'mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>'insert']);
?>
<div class="card">
  <h2>Insert document</h2>
  <p class="muted">Into <code><?=h($db)?>.<?=h($coll)?></code></p>
  <form method="post" action="action.php">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="insert_document">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <textarea class="code" name="document" rows="20" spellcheck="false" placeholder='{"field":"value"}'><?=h($template)?></textarea>
    <p class="hint">Extended JSON v2. Omit <code>_id</code> to auto-generate an ObjectId. Examples:
       <code>{"$oid":"..."}</code> for ObjectId,
       <code>{"$date":"2026-01-01T00:00:00Z"}</code> for dates,
       <code>{"$numberLong":"42"}</code> for int64.
    </p>
    <div class="form-actions">
      <button type="submit" class="btn primary">Insert</button>
      <a class="btn" href="<?=h(url(['page'=>'browse','db'=>$db,'coll'=>$coll]))?>">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>
