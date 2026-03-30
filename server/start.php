<?php
require 'HttpServer.php';

$ip = '0.0.0.0';
$port = 8004;
$username = 'admin';
$password = 'unimills2026';

$server = new HttpServer($ip, $port, $username, $password);
$server->start();
