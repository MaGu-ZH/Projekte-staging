<?php
header('Content-Type: text/plain; charset=utf-8');

$f = __DIR__ . "/s.php";
$c = require $f;

echo "LOADED\n";
var_dump($c);
