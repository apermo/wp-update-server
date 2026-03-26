<?php

use Apermo\WpUpdateServer\UpdateServer;
require __DIR__ . '/loader.php';

$server = new UpdateServer();
$server->handleRequest();
