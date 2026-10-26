<?php
declare(strict_types=1);
// Included only after session initialization, POST and CSRF validation.
function users_action(string $action,array $in): never {
    $me=admin();
    if ($action==='users_list') {
        json_response(['ok'=>true,'users'=>query('SELECT id,email,name,role,active,created_at FROM users ORDER BY created_at DESC')->fetchAll()]);
    }
    if ($action==='users_create') {
        $email=normalize_identity('email',(string)($in['email']??''));
        $name=trim((string)($in['name']??''));
        if ($name==='' || strlen($name)>100) fail('Bitte einen Namen mit höchstens 100 Zeichen eingeben.');
        $password=(string)($in['password']??'');validate_password($password);
        $role=(string)($in['role']??'user');
        if (!in_array($role,['admin','user'],true)) fail('Ungültige Rolle.');
        $id=bin2hex(random_bytes(16));
        try {query('INSERT INTO users(id,email,name,password_hash,role,created_at) VALUES(?,?,?,?,?,?)',[$id,$email,$name,password_hash($password,PASSWORD_DEFAULT),$role,time()]);}
        catch(PDOException $e) {if ((int)($e->errorInfo[1]??0)===19) fail('Diese E-Mail-Adresse ist bereits vergeben.',409);throw $e;}
        json_response(['ok'=>true,'id'=>$id],201);
    }
    $id=(string)($in['id']??'');
    if ($id===$me['id']) fail('Das eigene Konto kann hier nicht gesperrt oder geändert werden.');
    if (!query('SELECT id FROM users WHERE id=?',[$id])->fetchColumn()) fail('Benutzer nicht gefunden.',404);
    if ($action==='users_active') {
        if (!isset($in['active']) || !is_bool($in['active'])) fail('Ungültiger Status.');
        query('UPDATE users SET active=?,auth_version=auth_version+1 WHERE id=?',[$in['active']?1:0,$id]);
        json_response(['ok'=>true]);
    }
    if ($action==='users_password') {
        $password=(string)($in['password']??'');validate_password($password);
        query('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]);
        json_response(['ok'=>true]);
    }
    fail('Unbekannte Aktion.',404);
}
