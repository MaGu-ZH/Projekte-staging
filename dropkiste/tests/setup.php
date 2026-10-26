<?php
// Local tests only. The fixed password is intentionally not a production credential.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$dir=__DIR__.'/runtime';
if (!is_dir($dir)) mkdir($dir,0700,true);
$path=$dir.'/config.php';
if (file_exists($path)) {echo "Vorhandene Testkonfiguration bleibt erhalten.\n";exit;}
$config= <<<'PHP_CONFIG'
<?php
$c=require dirname(__DIR__,2).'/config.example.php';
$c['base_url']='http://127.0.0.1:8090';
$c['storage_dir']=__DIR__.'/storage';
$c['admin_password_hash']=password_hash('local-test-password',PASSWORD_DEFAULT);
$c['max_file_bytes']=1048576;
$c['max_storage_bytes']=2097152;
$c['attempts_per_window']=100;
return $c;
PHP_CONFIG;
$config=str_replace("password_hash('local-test-password',PASSWORD_DEFAULT)",var_export(password_hash('local-test-password',PASSWORD_DEFAULT),true),$config);
file_put_contents($path,$config."\n");chmod($path,0600);
echo "Isolierte Testkonfiguration erstellt.\n";
