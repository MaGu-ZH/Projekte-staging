<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/app.php';
cleanup();
echo "Bereinigung abgeschlossen.\n";
