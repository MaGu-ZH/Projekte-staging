<?php
declare(strict_types=1);
require dirname(__DIR__).'/app.php';
ini_set('display_errors','0');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
try {
    config();
    session_name('dropkiste');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>str_starts_with(config()['base_url'],'https://'),'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
    $action=$_GET['action']??'';
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='session') {
        json_response(['ok'=>true,'csrf'=>$_SESSION['csrf'],'admin'=>isset($_SESSION['admin_at']) && time()-$_SESSION['admin_at']<=config()['session_seconds'],
            'limits'=>array_intersect_key(config(),array_flip(['max_file_bytes','max_retention_days','max_recipients']))]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') fail('Methode nicht erlaubt.',405);
    if ($action==='download') download();
    csrf();
    if ($action==='upload') upload_transfer();
    $in=input();
    if ($action==='login') {
        rate_limit('admin');
        if (empty(config()['admin_password_hash']) || !password_verify((string)($in['password']??''),config()['admin_password_hash'])) fail('Anmeldung fehlgeschlagen.',401);
        session_regenerate_id(true);$_SESSION['admin_at']=time();json_response(['ok'=>true]);
    }
    if ($action==='logout') {$_SESSION=[];session_destroy();json_response(['ok'=>true]);}
    if ($action==='list') {
        admin();cleanup();
        $rows=query('SELECT * FROM transfers ORDER BY created_at DESC')->fetchAll();
        foreach($rows as &$row) {
            $row['recipients']=array_map(fn($r)=>['kind'=>$r['kind'],'identity'=>mask_identity($r['kind'],$r['identity']),'downloaded_at'=>$r['downloaded_at']],query('SELECT * FROM recipients WHERE transfer_id=?',[$row['id']])->fetchAll());
        }
        json_response(['ok'=>true,'transfers'=>$rows]);
    }
    if ($action==='revoke') {
        admin();
        transaction(function() use($in) {
            $id=(string)($in['id']??'');
            query('UPDATE transfers SET expires_at=? WHERE id=? AND deleted_at IS NULL',[time(),$id]);cleanup_locked();
        });
        json_response(['ok'=>true]);
    }
    if ($action==='invite') {
        cleanup();$r=recipient((string)($in['token']??''));
        if (!available($r)) fail('Diese Einladung ist abgelaufen oder die Datei wurde bereits gelöscht.',410);
        json_response(['ok'=>true,'name'=>$r['name'],'bytes'=>$r['bytes'],'expires_at'=>$r['expires_at'],'kind'=>$r['kind']]);
    }
    if ($action==='verify') {
        rate_limit('recipient');cleanup();$r=recipient((string)($in['token']??''));
        if (!available($r)) fail('Diese Einladung ist nicht mehr verfügbar.',410);
        try {$identity=normalize_identity($r['kind'],(string)($in['identity']??''));}
        catch(InvalidArgumentException $e) {fail('Die Eingabe stimmt nicht mit der Einladung überein.',403);}
        if (!hash_equals($r['identity'],$identity)) fail('Die Eingabe stimmt nicht mit der Einladung überein.',403);
        $_SESSION['verified'][$r['id']]=time()+config()['session_seconds'];
        json_response(['ok'=>true]);
    }
    fail('Unbekannte Aktion.',404);
} catch(InvalidArgumentException $e) { fail($e->getMessage()); }
catch(Throwable $e) {
    error_log('Dropkiste: '.get_class($e).' '.$e->getMessage());
    fail('Serverfehler. Bitte später erneut versuchen.',500);
}
