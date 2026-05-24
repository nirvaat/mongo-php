<?php
/** @var Mongo $mongo */
/** @var string|null $db */
if (!$db) { flash_set('error', 'No database selected.'); header('Location: index.php'); exit; }

$colls = $mongo->listCollections($db);
$nonSystem = array_filter($colls, fn($c) => !str_starts_with((string)($c['name'] ?? ''), 'system.'));
$isEmpty = count($nonSystem) === 0;

// One-shot report from the last import.
$report = $_SESSION['import_report'] ?? null;
unset($_SESSION['import_report']);

render_header(['title' => "Import SQL → {$db}", 'mongo' => $mongo, 'db' => $db, 'page' => 'import']);
?>
<div class="card">
  <div class="card-head">
    <h2>Import MySQL dump → <code><?=h($db)?></code></h2>
    <div class="card-actions">
      <a class="btn" href="<?=h(url(['page'=>'database','db'=>$db]))?>">Back to database</a>
    </div>
  </div>
  <p class="muted">
    Upload a <code>mysqldump</code>-style <code>.sql</code> file. Each table becomes a collection,
    rows become documents, and MySQL column types are mapped to BSON (<code>INT</code>→int,
    <code>DECIMAL</code>→Decimal128, <code>DATETIME</code>→date, <code>JSON</code>→nested object,
    <code>BLOB</code>→binary, <code>TINYINT(1)</code>→bool). A single-column
    <code>PRIMARY KEY</code> is folded into <code>_id</code>; <code>UNIQUE</code>/<code>KEY</code>
    indexes are recreated. MongoDB has no joins, so <code>FOREIGN KEY</code>s are not enforced —
    instead each foreign-key column is indexed so <code>$lookup</code> stays fast.
  </p>
  <?php if (!$isEmpty): ?>
    <div class="flash flash-error" style="margin:0 0 1rem">
      This database already has <?=count($nonSystem)?> collection(s). Importing appends documents and may
      collide on <code>_id</code>. Importing into an <strong>empty</strong> database is recommended.
    </div>
  <?php endif; ?>
  <form method="post" action="action.php" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="import_sql">
    <input type="hidden" name="db" value="<?=h($db)?>">
    <label>SQL dump file <input type="file" name="dump" accept=".sql,.txt,text/plain,application/sql" required></label>
    <div class="form-actions"><button class="btn primary" type="submit">Import</button></div>
  </form>
  <p class="muted" style="margin-top:.5rem">
    Max file size is limited by PHP's <code>upload_max_filesize</code> / <code>post_max_size</code>.
  </p>
</div>

<?php if ($report): ?>
<div class="card">
  <h2>Import result <span class="muted">— <?=h((string)($report['filename'] ?? ''))?></span></h2>
  <div class="kv">
    <div><span>Collections</span><b><?=format_number($report['totalCollections'] ?? 0)?></b></div>
    <div><span>Rows imported</span><b><?=format_number($report['totalRows'] ?? 0)?></b></div>
    <div><span>Indexes created</span><b><?=format_number($report['totalIndexes'] ?? 0)?></b></div>
  </div>

  <?php if (!empty($report['tables'])): ?>
  <table class="data">
    <thead>
      <tr><th>Collection</th><th class="num">Rows</th><th>_id from</th><th class="num">Indexes</th><th class="num">FK indexes</th><th>Warnings</th></tr>
    </thead>
    <tbody>
    <?php foreach ($report['tables'] as $name => $t): ?>
      <tr>
        <td><a class="link" href="<?=h(url(['page'=>'browse','db'=>$db,'coll'=>$name]))?>"><?=h((string)$name)?></a></td>
        <td class="num"><?=format_number($t['rows'] ?? 0)?></td>
        <td><?=$t['pk'] ? '<code>'.h((string)$t['pk']).'</code>' : '<span class="muted">ObjectId</span>'?></td>
        <td class="num"><?=format_number($t['indexes'] ?? 0)?></td>
        <td class="num"><?=format_number($t['fkIndexes'] ?? 0)?></td>
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
    <p class="muted">Skipped statements (no MongoDB equivalent):
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
