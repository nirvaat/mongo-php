<?php
/** @var Mongo $mongo */
$status = $err = null;
try { $status = $mongo->serverStatus(); } catch (\Throwable $e) { $err = $e->getMessage(); }
$build = [];
try { $build = $mongo->buildInfo(); } catch (\Throwable $e) {}

render_header(['title' => 'Server status', 'mongo'=>$mongo, 'db'=>$db ?? null, 'coll'=>$coll ?? null, 'page'=>'status']);
?>
<div class="card">
  <h2>Server status</h2>
  <?php if ($err): ?>
    <div class="flash flash-error"><?=h($err)?></div>
  <?php elseif ($status): ?>
    <div class="kv">
      <div><span>Version</span><b><?=h($build['version'] ?? '?')?></b></div>
      <div><span>Host</span><b><?=h((string)($status['host'] ?? ''))?></b></div>
      <div><span>Uptime</span><b><?=format_number((int)($status['uptime'] ?? 0))?> s</b></div>
      <div><span>Local time</span><b><?php
        $lt = $status['localTime'] ?? null;
        if ($lt instanceof \MongoDB\BSON\UTCDateTime) echo h($lt->toDateTime()->format('Y-m-d H:i:s')); else echo '—';
      ?></b></div>
      <div><span>Connections current</span><b><?=h((string)($status['connections']['current'] ?? '—'))?></b></div>
      <div><span>Connections available</span><b><?=h((string)($status['connections']['available'] ?? '—'))?></b></div>
      <div><span>Network bytes in</span><b><?=format_bytes((int)($status['network']['bytesIn'] ?? 0))?></b></div>
      <div><span>Network bytes out</span><b><?=format_bytes((int)($status['network']['bytesOut'] ?? 0))?></b></div>
      <?php if (isset($status['opcounters'])): foreach ($status['opcounters'] as $k=>$v): ?>
        <div><span>op:<?=h($k)?></span><b><?=format_number($v)?></b></div>
      <?php endforeach; endif; ?>
    </div>
    <details>
      <summary>Full serverStatus output</summary>
      <pre class="code"><?=h(doc_to_extjson($status, true))?></pre>
    </details>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
