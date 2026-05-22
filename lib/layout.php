<?php
declare(strict_types=1);

function render_header(array $opts = []): void {
    $title = $opts['title'] ?? 'mongo-php';
    $mongo = $opts['mongo'] ?? null;
    $currentDb = $opts['db'] ?? null;
    $currentColl = $opts['coll'] ?? null;
    $page = $opts['page'] ?? 'home';

    $dbs = [];
    $collections = [];
    if ($mongo) {
        try {
            $dbs = $mongo->listDatabases();
            usort($dbs, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        } catch (\Throwable $e) {
            $dbs = [];
        }
        if ($currentDb) {
            try {
                $collections = $mongo->listCollections($currentDb);
                usort($collections, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
            } catch (\Throwable $e) { $collections = []; }
        }
    }
    $conn = $_SESSION['conn'] ?? [];
    $hostLabel = ($conn['user'] ?? 'anon') . '@' . ($conn['host'] ?? '?') . ':' . ($conn['port'] ?? 27017);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($title)?></title>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/app.js" defer></script>
</head>
<body class="page-<?=h($page)?>">
<header class="topbar">
  <div class="brand"><a href="index.php"><span class="leaf">▣</span> mongo-php</a></div>
  <div class="server"><?=h($hostLabel)?></div>
  <nav class="topnav">
    <a href="index.php" class="<?=$page==='home'?'active':''?>">Server</a>
    <?php if ($currentDb): ?>
      <a href="<?=h(url(['page'=>'database','db'=>$currentDb]))?>" class="<?=$page==='database'?'active':''?>">Database</a>
    <?php endif; ?>
    <a href="<?=h(url(['page'=>'status']))?>" class="<?=$page==='status'?'active':''?>">Status</a>
    <a href="logout.php" class="logout">Logout</a>
  </nav>
</header>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-section">
      <div class="sidebar-title">Databases <span class="count"><?=count($dbs)?></span></div>
      <ul class="db-list">
        <?php foreach ($dbs as $d):
            $name = $d['name'] ?? '';
            $isCurrent = $name === $currentDb;
        ?>
        <li class="<?=$isCurrent?'open':''?>">
          <a class="db-link <?=$isCurrent?'active':''?>" href="<?=h(url(['page'=>'database','db'=>$name]))?>">
            <span class="icon">▾</span><?=h($name)?>
            <span class="meta"><?=format_bytes((int)($d['sizeOnDisk'] ?? 0))?></span>
          </a>
          <?php if ($isCurrent && $collections): ?>
            <ul class="coll-list">
              <?php foreach ($collections as $c):
                  $cname = $c['name'] ?? '';
                  $type = $c['type'] ?? 'collection';
                  $isCurrCo = $cname === $currentColl;
              ?>
                <li>
                  <a class="<?=$isCurrCo?'active':''?>" href="<?=h(url(['page'=>'browse','db'=>$name,'coll'=>$cname]))?>">
                    <span class="icon"><?=$type==='view'?'◇':'▦'?></span><?=h($cname)?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </aside>
  <main class="content">
    <?php foreach (flash_pop() as $f): ?>
      <div class="flash flash-<?=h($f['type'])?>"><?=h($f['msg'])?></div>
    <?php endforeach; ?>
    <?php if ($currentDb): ?>
      <div class="breadcrumb">
        <a href="index.php">Server</a>
        <span class="sep">›</span>
        <a href="<?=h(url(['page'=>'database','db'=>$currentDb]))?>"><?=h($currentDb)?></a>
        <?php if ($currentColl): ?>
          <span class="sep">›</span>
          <a href="<?=h(url(['page'=>'browse','db'=>$currentDb,'coll'=>$currentColl]))?>"><?=h($currentColl)?></a>
        <?php endif; ?>
      </div>
      <?php if ($currentColl): ?>
        <div class="tabs">
          <?php
          $tabs = [
            'browse'    => 'Browse',
            'structure' => 'Structure',
            'query'     => 'Query',
            'insert'    => 'Insert',
            'indexes'   => 'Indexes',
            'collstats' => 'Stats',
            'operations'=> 'Operations',
          ];
          foreach ($tabs as $key => $label):
            $href = url(['page'=>$key,'db'=>$currentDb,'coll'=>$currentColl]);
          ?>
            <a class="tab <?=$page===$key?'active':''?>" href="<?=h($href)?>"><?=h($label)?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
<?php
}

function render_footer(): void {
    ?>
  </main>
</div>
</body>
</html>
<?php
}

function flash_render_error(\Throwable $e, string $prefix = 'Error'): void {
    flash_set('error', $prefix . ': ' . $e->getMessage());
}
