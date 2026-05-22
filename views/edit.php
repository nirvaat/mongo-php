<?php
/** @var Mongo $mongo */
/** @var string|null $db */
/** @var string|null $coll */
if (!$db || !$coll) { header('Location: index.php'); exit; }

$idRaw = (string)($_GET['id'] ?? '');
$id = parse_id($idRaw);
$doc = null;
$err = null;
try {
    $doc = $mongo->findOne($db, $coll, ['_id' => $id]);
    if ($doc === null) throw new RuntimeException('Document not found.');
} catch (\Throwable $e) {
    $err = $e->getMessage();
}

$json = '';
if ($doc !== null) {
    try { $json = doc_to_extjson($doc, true); } catch (\Throwable $e) { $err = $e->getMessage(); }
}

render_header(['title' => "Edit {$db}.{$coll}", 'mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>'browse']);
?>
<div class="card">
  <h2>Edit document</h2>
  <p class="muted">Collection <code><?=h($db)?>.<?=h($coll)?></code> · _id <code><?=h($idRaw)?></code></p>
  <?php if ($err): ?>
    <div class="flash flash-error"><?=h($err)?></div>
  <?php else: ?>
  <form method="post" action="action.php">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="update_document">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <input type="hidden" name="id" value="<?=h($idRaw)?>">
    <textarea class="code" name="document" rows="24" spellcheck="false"><?=h($json)?></textarea>
    <p class="hint">Document is in Extended JSON v2 format. <code>_id</code> is preserved on save; you cannot change it from here.</p>
    <div class="form-actions">
      <button type="submit" class="btn primary">Save (replace document)</button>
      <a class="btn" href="<?=h(url(['page'=>'browse','db'=>$db,'coll'=>$coll]))?>">Cancel</a>
    </div>
  </form>
  <form class="inline confirm-form" method="post" action="action.php" data-confirm="Delete this document?" style="margin-top:0.5em">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="delete_document">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <input type="hidden" name="coll" value="<?=h($coll)?>">
    <input type="hidden" name="id" value="<?=h($idRaw)?>">
    <button type="submit" class="btn danger">Delete this document</button>
  </form>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
