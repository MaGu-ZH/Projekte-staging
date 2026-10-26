<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
$path=getenv('DROPKISTE_CONFIG')?:'/etc/dropkiste/config.php';
$parts=explode("\0",stream_get_contents(STDIN));
if (count($parts)!==6) throw new RuntimeException('Ungültige Eingabe.');
[$host,$port,$user,$password,$from]=$parts;
if (!preg_match('/^[a-zA-Z0-9.-]+$/',$host) || !in_array($port,['465','587'],true) || !filter_var($from,FILTER_VALIDATE_EMAIL) || $user==='' || $password==='') throw new RuntimeException('SMTP-Angaben prüfen.');
$c=require $path;
$c['smtp']=['enabled'=>true,'host'=>$host,'port'=>(int)$port,'security'=>$port==='465'?'tls':'starttls','username'=>$user,'password'=>$password,'from'=>$from];
$temp=tempnam(dirname($path),'.smtp.');
if ($temp===false) throw new RuntimeException('Konfiguration nicht schreibbar.');
try {
    if (file_put_contents($temp,"<?php\nreturn ".var_export($c,true).";\n")===false || !chown($temp,'root') || !chgrp($temp,'www-data') || !chmod($temp,0640) || !rename($temp,$path)) throw new RuntimeException('Konfiguration konnte nicht gespeichert werden.');
} finally {if (file_exists($temp)) unlink($temp);}
echo "SMTP-Konfiguration gespeichert. Noch keine Nachricht gesendet.\n";
