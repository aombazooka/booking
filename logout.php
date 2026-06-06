<?php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
require __DIR__ . '/app/auth.php';
logout();
header('Location: ' . $base . '/login.php');
exit;
