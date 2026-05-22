<?php
require __DIR__ . '/lib/bootstrap.php';
unset($_SESSION['conn']);
session_regenerate_id(true);
header('Location: login.php');
exit;
