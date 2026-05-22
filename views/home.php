<?php
/** @var Mongo $mongo */
$build = [];
$dbs = [];
$err = null;
try {
    $build = $mongo->buildInfo();
    $dbs = $mongo->listDatabases();
    usort($dbs, fn($a,$b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
} catch (\Throwable $e) {
    $err = $e->getMessage();
}

render_header(['title' => 'Server', 'mongo' => $mongo, 'page' => 'home']);
?>
<div class="card">
  <h2>Server</h2>
  <div class="kv">
    <div><span>MongoDB version</span><b><?=h($build['version'] ?? 'unknown')?></b></div>
    <div><span>Host</span><b><?=h(($_SESSION['conn']['host'] ?? '?') . ':' . ($_SESSION['conn']['port'] ?? '?'))?></b></div>
    <div><span>User</span><b><?=h($_SESSION['conn']['user'] ?? '(none)')?></b></div>
    <div><span>Auth DB</span><b><?=h($_SESSION['conn']['authdb'] ?? '')?></b></div>
    <div><span>TLS</span><b><?=!empty($_SESSION['conn']['tls']) ? 'on' : 'off'?></b></div>
    <?php if (!empty($build['gitVersion'])): ?>
    <div><span>Git</span><b><?=h(substr($build['gitVersion'],0,12))?></b></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($err): ?>
  <div class="flash flash-error"><?=h($err)?></div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2>Databases <span class="muted">(<?=count($dbs)?>)</span></h2>
    <div class="card-actions">
      <a class="btn" href="#create-db">+ Create database</a>
    </div>
  </div>
  <table class="data">
    <thead>
      <tr>
        <th>Name</th>
        <th class="num">Size on disk</th>
        <th class="num">Collections</th>
        <th class="num">Objects</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($dbs as $d):
        $name = $d['name'] ?? '';
        $size = (int)($d['sizeOnDisk'] ?? 0);
        $stats = null;
        if (!is_system_db($name)) {
            try { $stats = $mongo->dbStats($name); } catch (\Throwable $e) {}
        }
    ?>
      <tr>
        <td>
          <a class="link" href="<?=h(url(['page'=>'database','db'=>$name]))?>"><?=h($name)?></a>
          <?php if (is_system_db($name)): ?><span class="badge">system</span><?php endif; ?>
        </td>
        <td class="num"><?=format_bytes($size)?></td>
        <td class="num"><?=h((string)($stats['collections'] ?? '—'))?></td>
        <td class="num"><?=format_number($stats['objects'] ?? '—')?></td>
        <td class="actions">
          <a class="btn small" href="<?=h(url(['page'=>'database','db'=>$name]))?>">Open</a>
          <?php if (!is_system_db($name)): ?>
            <form class="inline confirm-form" method="post" action="action.php">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="drop_db">
              <input type="hidden" name="db" value="<?=h($name)?>">
              <input type="hidden" name="confirm" value="<?=h($name)?>" data-confirm-name="<?=h($name)?>" data-confirm-prompt="Type the database name &quot;<?=h($name)?>&quot; to confirm dropping it:">
              <button class="btn small danger" type="submit">Drop</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" id="create-db">
  <h2>Create database</h2>
  <form method="post" action="action.php" class="form-grid">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="create_db">
    <label>Database name <input type="text" name="name" required pattern="[^/\\. &quot;$*&lt;&gt;:|?]+"></label>
    <label>Initial collection <input type="text" name="initial_collection" value="_init" required></label>
    <div class="form-actions"><button type="submit" class="btn primary">Create</button></div>
    <p class="hint">MongoDB creates databases lazily — we add an initial collection so the database shows up immediately.</p>
  </form>
</div>

<?php render_footer(); ?>
