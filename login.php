<?php
require __DIR__ . '/lib/bootstrap.php';

$error = null;
$defaults = $_SESSION['login_defaults'] ?? [
    'host'   => '127.0.0.1',
    'port'   => '27017',
    'user'   => '',
    'authdb' => 'admin',
    'tls'    => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $conn = [
        'host'   => trim((string)($_POST['host'] ?? '127.0.0.1')),
        'port'   => (int)($_POST['port'] ?? 27017),
        'user'   => trim((string)($_POST['user'] ?? '')),
        'pass'   => (string)($_POST['pass'] ?? ''),
        'authdb' => trim((string)($_POST['authdb'] ?? '')),
        'tls'    => !empty($_POST['tls']),
        'tls_insecure' => !empty($_POST['tls_insecure']),
        'replset' => trim((string)($_POST['replset'] ?? '')),
    ];
    if ($conn['host'] === '' || $conn['port'] <= 0) {
        $error = 'Host and port are required.';
    } else {
        try {
            $mongo = new Mongo($conn);
            $mongo->buildInfo(); // ping
            session_regenerate_id(true);
            $_SESSION['conn'] = $conn;
            $_SESSION['login_defaults'] = [
                'host'   => $conn['host'],
                'port'   => (string)$conn['port'],
                'user'   => $conn['user'],
                'authdb' => $conn['authdb'],
                'tls'    => $conn['tls'],
            ];
            header('Location: index.php');
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>mongo-php — login</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" action="login.php" autocomplete="off">
    <h1><span class="leaf">▣</span> mongo-php</h1>
    <p class="muted">Connect to a MongoDB server</p>
    <?php if ($error): ?>
      <div class="flash flash-error"><?=h($error)?></div>
    <?php endif; ?>
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <label>Host
      <input type="text" name="host" value="<?=h($defaults['host'])?>" required>
    </label>
    <label>Port
      <input type="number" name="port" value="<?=h($defaults['port'])?>" min="1" max="65535" required>
    </label>
    <label>Username <span class="hint">(optional)</span>
      <input type="text" name="user" value="<?=h($defaults['user'])?>" autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="pass" value="" autocomplete="current-password">
    </label>
    <label>Auth DB <span class="hint">(default: admin)</span>
      <input type="text" name="authdb" value="<?=h($defaults['authdb'])?>">
    </label>
    <label>Replica Set <span class="hint">(optional)</span>
      <input type="text" name="replset" value="">
    </label>
    <label class="checkbox"><input type="checkbox" name="tls" value="1" <?=!empty($defaults['tls'])?'checked':''?>> Use TLS</label>
    <label class="checkbox"><input type="checkbox" name="tls_insecure" value="1"> Allow invalid TLS certs (insecure)</label>
    <button type="submit" class="btn primary">Connect</button>
    <p class="footnote muted">Credentials are stored in your PHP session only.</p>
  </form>
</body>
</html>
