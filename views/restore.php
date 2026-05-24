<?php
/** @var Mongo $mongo */
/** @var string|null $db */
if (!$db) { flash_set('error', 'No database selected.'); header('Location: index.php'); exit; }

$colls = $mongo->listCollections($db);
$nonSystem = array_filter($colls, fn($c) => !str_starts_with((string)($c['name'] ?? ''), 'system.'));
$isEmpty = count($nonSystem) === 0;

// One-shot report from the last restore.
$report = $_SESSION['restore_report'] ?? null;
unset($_SESSION['restore_report']);

render_header(['title' => "Restore MongoDB → {$db}", 'mongo' => $mongo, 'db' => $db, 'page' => 'restore']);
?>
<div class="card">
  <div class="card-head">
    <h2>Restore MongoDB archive → <code><?=h($db)?></code></h2>
    <div class="card-actions">
      <a class="btn" href="<?=h(url(['page'=>'database','db'=>$db]))?>">Back to database</a>
    </div>
  </div>
  <p class="muted">
    Upload a <code>.mongo.json</code> archive produced by this tool's
    <strong>Export (MongoDB archive)</strong> on another server. The file is
    line-delimited Extended JSON v2, so every BSON type (<code>ObjectId</code>,
    <code>Date</code>, <code>Decimal128</code>, <code>Binary</code>, …) is
    restored exactly. Documents keep their original <code>_id</code>, and
    collection options and indexes are recreated — making this a full
    server-to-server database migration.
  </p>
  <?php if (!$isEmpty): ?>
    <div class="flash flash-error" style="margin:0 0 1rem">
      This database already has <?=count($nonSystem)?> collection(s). Restoring appends documents and may
      collide on <code>_id</code>. Restoring into an <strong>empty</strong> database is recommended.
    </div>
  <?php endif; ?>
  <form method="post" action="action.php" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="import_mongo">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <label>MongoDB archive <input type="file" name="archive" accept=".json,.ndjson,.mongo.json,application/x-ndjson,application/json" required></label>
    <div class="form-actions"><button class="btn primary" type="submit">Restore</button></div>
  </form>
  <p class="muted" style="margin-top:.5rem">
    Max file size is limited by PHP's <code>upload_max_filesize</code> / <code>post_max_size</code>.
  </p>
</div>

<?php if ($report): ?>
<div class="card">
  <h2>Restore result <span class="muted">— <?=h((string)($report['filename'] ?? ''))?><?php
    if (!empty($report['sourceDb'])) echo ' (from <code>'.h((string)$report['sourceDb']).'</code>)'; ?></span></h2>
  <div class="kv">
    <div><span>Collections</span><b><?=format_number($report['totalCollections'] ?? 0)?></b></div>
    <div><span>Documents restored</span><b><?=format_number($report['totalRows'] ?? 0)?></b></div>
    <div><span>Indexes created</span><b><?=format_number($report['totalIndexes'] ?? 0)?></b></div>
  </div>

  <?php if (!empty($report['tables'])): ?>
  <table class="data">
    <thead>
      <tr><th>Collection</th><th class="num">Documents</th><th class="num">Indexes</th><th>Warnings</th></tr>
    </thead>
    <tbody>
    <?php foreach ($report['tables'] as $name => $t): ?>
      <tr>
        <td><a class="link" href="<?=h(url(['page'=>'browse','db'=>$db,'coll'=>$name]))?>"><?=h((string)$name)?></a></td>
        <td class="num"><?=format_number($t['rows'] ?? 0)?></td>
        <td class="num"><?=format_number($t['indexes'] ?? 0)?></td>
        <td><?php
          $w = $t['warnings'] ?? [];
          echo $w ? '<span class="badge">'.count($w).'</span> '.h(implode(' | ', $w)) : '<span class="muted">—</span>';
        ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if (!empty($report['skipped'])): ?>
    <p class="muted">Skipped lines:
      <?php foreach ($report['skipped'] as $k => $n): ?>
        <code><?=h((string)$k)?></code>&times;<?=h((string)$n)?><?=' '?>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if (!empty($report['warnings'])): ?>
    <div class="flash flash-error">
      <?php foreach ($report['warnings'] as $w): ?><div><?=h((string)$w)?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
