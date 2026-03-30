<?php
require 'HttpServer.php';

$ip = '0.0.0.0';
$port = 8004;
$username = 'admin';
$password = 'Corepay@1';

$server = new HttpServer($ip, $port, $username, $password);
$server->start();
