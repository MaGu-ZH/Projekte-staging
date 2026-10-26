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
        json_response(['ok'=>true,'csrf'=>$_SESSION['csrf'],'admin'=>current_user()!==null, 'user'=>current_user(), 'delivery'=>['email'=>!empty(config()['smtp']['enabled']),'phone'=>!empty(config()['sms']['enabled'])],
            'limits'=>array_intersect_key(config(),array_flip(['max_file_bytes','max_retention_days','max_recipients']))]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') fail('Methode nicht erlaubt.',405);
    if ($action==='download') download();
    csrf();
    if ($action==='upload') upload_transfer();
    $in=input();
    if ($action==='login') {
        rate_limit('admin');
        $login=strtolower(trim((string)($in['email']??'admin')));
        $password=(string)($in['password']??'');
        $u=null;
        if ($login==='admin' || $login==='') {
            if (!empty(config()['admin_password_hash']) && password_verify($password,config()['admin_password_hash'])) $u=['id'=>'admin','auth_version'=>hash('sha256',config()['admin_password_hash'])];
        } else {
            $row=query('SELECT id,password_hash,auth_version,active FROM users WHERE email=?',[$login])->fetch();
            // Always do a password hash check, including for an unknown address.
            $valid=password_verify($password,$row['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
            if ($row && $row['active'] && $valid) $u=$row;
        }
        if (!$u) fail('Anmeldung fehlgeschlagen.',401);
        session_regenerate_id(true);$_SESSION['user_id']=$u['id'];$_SESSION['auth_version']=$u['auth_version'];$_SESSION['admin_at']=time();json_response(['ok'=>true]);
    }
    if (str_starts_with($action,'users_')) {require dirname(__DIR__).'/users-api.php';users_action($action,$in);}
    if ($action==='password') {
        $u=authenticated();rate_limit('password');
        if ($u['id']==='admin') fail('Das Administrator-Passwort bitte über den Server ändern.');
        $row=query('SELECT password_hash FROM users WHERE id=?',[$u['id']])->fetch();
        if (!password_verify((string)($in['current_password']??''),$row['password_hash'])) fail('Aktuelles Passwort stimmt nicht.',403);
        $password=(string)($in['password']??'');validate_password($password);
        query('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
        $_SESSION=[];session_destroy();json_response(['ok'=>true]);
    }
    if ($action==='logout') {$_SESSION=[];session_destroy();json_response(['ok'=>true]);}
    if ($action==='list') {
        $u=authenticated();cleanup();
        $rows=query('SELECT * FROM transfers WHERE owner_id=? ORDER BY created_at DESC',[$u['id']])->fetchAll();
        foreach($rows as &$row) {
            $row['recipients']=array_map(fn($r)=>['kind'=>$r['kind'],'identity'=>mask_identity($r['kind'],$r['identity']),'downloaded_at'=>$r['downloaded_at'],'delivery'=>$r['delivery_status']],query('SELECT * FROM recipients WHERE transfer_id=?',[$row['id']])->fetchAll());
        }
        json_response(['ok'=>true,'transfers'=>$rows]);
    }
    if ($action==='revoke') {
        $u=authenticated();
        if (!query('SELECT id FROM transfers WHERE id=? AND owner_id=?',[(string)($in['id']??''),$u['id']])->fetchColumn()) fail('Übergabe nicht gefunden.',404);
        transaction(function() use($in,$u) {
            $id=(string)($in['id']??'');
            query('UPDATE transfers SET expires_at=? WHERE id=? AND owner_id=? AND deleted_at IS NULL',[time(),$id,$u['id']]);cleanup_locked();
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
