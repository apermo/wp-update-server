<?php

require __DIR__ . '/loader.php';

$server = new Apermo\WpUpdateServer\UpdateServer();
$server->handleRequest();
