<?php
declare(strict_types=1);

function config(): array {
    static $c;
    if ($c !== null) return $c;
    $path = getenv('DROPKISTE_CONFIG') ?: __DIR__.'/config.php';
    if (!is_file($path)) throw new RuntimeException('Die Serverkonfiguration fehlt.');
    $c = require $path;
    $c += ['max_user_storage_bytes'=>$c['max_storage_bytes']??2147483648, 'max_active_transfers_per_user'=>20, 'delivery_timeout_seconds'=>20, 'max_direct_recipients'=>5, 'smtp'=>[], 'sms'=>[]];
    if (!is_array($c) || empty($c['storage_dir']) || empty($c['base_url'])) throw new RuntimeException('Ungültige Serverkonfiguration.');
    foreach (['max_file_bytes','max_storage_bytes','max_retention_days','max_recipients','tombstone_retention_days','cleanup_interval_seconds','min_admin_password_length','session_seconds','max_api_request_bytes','attempts_per_window','rate_window_seconds','max_parallel_downloads','download_lease_seconds','download_chunk_bytes','max_user_storage_bytes','max_active_transfers_per_user','delivery_timeout_seconds','max_direct_recipients'] as $key) {
        if (!isset($c[$key]) || !is_int($c[$key]) || $c[$key] < 1) throw new RuntimeException('Ungültige Konfiguration: '.$key);
    }
    $c['max_retention_days'] = min(30, $c['max_retention_days']);
    $c['base_url'] = rtrim($c['base_url'], '/');
    return $c;
}
function db(): PDO {
    static $db;
    if ($db) return $db;
    $dir = config()['storage_dir'];
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Speicher nicht verfügbar.');
    $db = new PDO('sqlite:'.$dir.'/dropkiste.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL;');
    $db->exec('CREATE TABLE IF NOT EXISTS transfers (
        id TEXT PRIMARY KEY, name TEXT NOT NULL, bytes INTEGER NOT NULL,
        created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
        deleted_at INTEGER, delete_reason TEXT
    );
    CREATE TABLE IF NOT EXISTS recipients (
        id TEXT PRIMARY KEY, transfer_id TEXT NOT NULL REFERENCES transfers(id) ON DELETE CASCADE,
        kind TEXT NOT NULL, identity TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
        downloaded_at INTEGER
    );
    CREATE TABLE IF NOT EXISTS leases (
        id TEXT PRIMARY KEY, transfer_id TEXT NOT NULL REFERENCES transfers(id) ON DELETE CASCADE,
        expires_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS attempts (bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, reset_at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS recipients_transfer ON recipients(transfer_id);
    CREATE INDEX IF NOT EXISTS leases_transfer ON leases(transfer_id);');
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "user",
        active INTEGER NOT NULL DEFAULT 1, auth_version INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL
    );');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $columns=array_column($db->query('PRAGMA table_info(transfers)')->fetchAll(),'name');
        if (!in_array('owner_id',$columns,true)) $db->exec("ALTER TABLE transfers ADD COLUMN owner_id TEXT NOT NULL DEFAULT 'admin'");
        $recipientColumns=array_column($db->query('PRAGMA table_info(recipients)')->fetchAll(),'name');
        if (!in_array('delivery_status',$recipientColumns,true)) $db->exec("ALTER TABLE recipients ADD COLUMN delivery_status TEXT NOT NULL DEFAULT 'manual'");
        $db->exec('CREATE INDEX IF NOT EXISTS transfers_owner ON transfers(owner_id)');
        $db->exec('COMMIT');
    } catch (Throwable $e) {$db->exec('ROLLBACK');throw $e;}
    return $db;
}
function query(string $sql,array $args=[]): PDOStatement { $s=db()->prepare($sql);$s->execute($args);return $s; }
function transaction(callable $fn): mixed {
    db()->exec('BEGIN IMMEDIATE');
    try { $r=$fn();db()->exec('COMMIT');return $r; }
    catch(Throwable $e) { db()->exec('ROLLBACK');throw $e; }
}
function json_response(array $body,int $status=200): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
function fail(string $message,int $status=400): never { json_response(['ok'=>false,'error'=>$message],$status); }
function input(): array {
    $limit=config()['max_api_request_bytes'];
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>$limit) fail('Anfrage ist zu groß.',413);
    $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,$limit+1);fclose($stream);
    if (strlen($raw)>$limit) fail('Anfrage ist zu groß.',413);
    $x=json_decode($raw?:'{}',true);return is_array($x)?$x:[];
}
function csrf(): void {
    if (!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??($_POST['csrf']??''))) fail('Bitte die Seite neu laden.',403);
}
function current_user(): ?array {
    if (empty($_SESSION['user_id']) || time()-($_SESSION['admin_at']??0)>config()['session_seconds']) return null;
    if ($_SESSION['user_id']==='admin') {
        if (!hash_equals(hash('sha256',config()['admin_password_hash']??''),$_SESSION['auth_version']??'')) return null;
        return ['id'=>'admin','name'=>'Administrator','email'=>'','role'=>'admin'];
    }
    $u=query('SELECT id,name,email,role,active,auth_version FROM users WHERE id=?',[$_SESSION['user_id']])->fetch();
    if (!$u || !$u['active'] || (string)$u['auth_version']!==(string)($_SESSION['auth_version']??'')) return null;
    return array_intersect_key($u,array_flip(['id','name','email','role']));
}
function authenticated(): array {
    $u=current_user();
    if (!$u) fail('Bitte anmelden.',401);
    $_SESSION['admin_at']=time();return $u;
}
function admin(): array {
    $u=authenticated();if ($u['role']!=='admin') fail('Nur für Administratoren.',403);return $u;
}
function validate_password(string $password): void {
    if (strlen($password)<config()['min_admin_password_length'] || strlen($password)>72) throw new InvalidArgumentException('Passwort muss zwischen '.config()['min_admin_password_length'].' Zeichen und höchstens 72 Bytes lang sein.');
}
function normalize_identity(string $kind,string $value): string {
    $value=trim($value);
    if ($kind==='email') {
        $value=strtolower($value);
        if (strlen($value)>254 || !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse eingeben.');
    } elseif ($kind==='phone') {
        $value=preg_replace('/[\s().-]/','',$value);
        if (str_starts_with($value,'00')) $value='+'.substr($value,2);
        if (!preg_match('/^\+[1-9][0-9]{6,14}$/',$value)) throw new InvalidArgumentException('Telefonnummer mit Ländervorwahl eingeben, z. B. +41791234567.');
    } else throw new InvalidArgumentException('Unbekannte Einladungsart.');
    return $value;
}
function rate_limit(string $purpose): void {
    $key=hash('sha256',$purpose.'|'.($_SERVER['REMOTE_ADDR']??'local'));
    transaction(function() use($key) {
        query('DELETE FROM attempts WHERE reset_at < ?',[time()]);
        $r=query('SELECT * FROM attempts WHERE bucket=?',[$key])->fetch();
        if ($r && $r['count']>=config()['attempts_per_window']) fail('Zu viele Versuche. Bitte später erneut versuchen.',429);
        query('INSERT INTO attempts(bucket,count,reset_at) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET count=count+1',[$key,time()+config()['rate_window_seconds']]);
    });
}
function file_path(string $id): string { return config()['storage_dir'].'/'.$id.'.blob'; }
// Caller holds an IMMEDIATE SQLite transaction. Active streams retain their file until they close.
function cleanup_locked(): void {
    $now=time();
    query('DELETE FROM leases WHERE expires_at<=?',[$now]);
    $rows=query('SELECT t.* FROM transfers t WHERE deleted_at IS NULL AND (
        expires_at<=? OR NOT EXISTS(SELECT 1 FROM recipients r WHERE r.transfer_id=t.id AND r.downloaded_at IS NULL)
    )',[$now])->fetchAll();
    foreach ($rows as $r) {
        if (query('SELECT COUNT(*) FROM leases WHERE transfer_id=?',[$r['id']])->fetchColumn()>0) continue;
        $path=file_path($r['id']);
        if (is_file($path) && !unlink($path)) throw new RuntimeException('Datei konnte nicht gelöscht werden.');
        query('UPDATE transfers SET deleted_at=?,delete_reason=? WHERE id=?',[$now,$r['expires_at']<=$now?'expired':'downloaded',$r['id']]);
        // Contact details are no longer needed after deletion.
        query("UPDATE recipients SET identity='' WHERE transfer_id=?",[$r['id']]);
    }
    // Remove old tombstones and brute force counters; no file content remains in SQLite.
    query('DELETE FROM transfers WHERE deleted_at IS NOT NULL AND deleted_at<?',[$now-config()['tombstone_retention_days']*86400]);
    query('DELETE FROM attempts WHERE reset_at<?',[$now]);
}
function cleanup(): void { transaction(fn()=>cleanup_locked()); }
function recipient(string $token): array|false {
    if (!preg_match('/^[a-f0-9]{64}$/',$token)) return false;
    return query('SELECT r.*, t.name,t.bytes,t.expires_at,t.deleted_at FROM recipients r JOIN transfers t ON t.id=r.transfer_id WHERE r.token_hash=?',[hash('sha256',$token)])->fetch();
}
function available(array|false $r): bool { return $r && $r['deleted_at']===null && $r['expires_at']>time(); }
function mask_identity(string $kind,string $identity): string {
    if ($identity==='') return 'Gelöscht';
    if ($kind==='phone') return substr($identity,0,3).' … '.substr($identity,-3);
    [$local,$domain]=explode('@',$identity,2); return substr($local,0,1).'…@'.$domain;
}
function upload_transfer(): never {
    $owner=authenticated();csrf();
    if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) fail('Upload fehlgeschlagen. Datei- und PHP-Uploadlimit prüfen.');
    $f=$_FILES['file'];
    if (!is_uploaded_file($f['tmp_name'])) fail('Ungültiger Upload.');
    $size=filesize($f['tmp_name']);
    if ($size===false || $size<1 || $size>config()['max_file_bytes']) fail('Die Datei ist leer oder überschreitet das Größenlimit.');
    $days=filter_var($_POST['days']??null,FILTER_VALIDATE_INT);
    if (!$days || $days<1 || $days>config()['max_retention_days']) fail('Ungültige Aufbewahrungsdauer.');
    $list=json_decode($_POST['recipients']??'',true);
    if (!is_array($list) || count($list)<1 || count($list)>config()['max_recipients']) fail('Bitte die zulässige Anzahl Empfänger eintragen.');
    $mode=$_POST['delivery']??'manual';
    if (!in_array($mode,['manual','direct'],true)) fail('Ungültige Versandart.');
    if ($mode==='direct' && count($list)>config()['max_direct_recipients']) fail('Direkter Versand ist auf '.config()['max_direct_recipients'].' Empfänger pro Übergabe begrenzt.');
    if ($mode==='direct') rate_limit('send:'.$owner['id']);
    $recipients=[];$seen=[];
    foreach ($list as $r) {
        if (!is_array($r)) fail('Ungültige Empfänger.');
        $kind=(string)($r['kind']??'');
        try { $identity=normalize_identity($kind,(string)($r['identity']??'')); } catch(InvalidArgumentException $e) { fail($e->getMessage()); }
        $key=$kind.':'.$identity;
        if (isset($seen[$key])) fail('Jede Adresse bzw. Telefonnummer darf nur einmal vorkommen.');
        $seen[$key]=true;$recipients[]=['kind'=>$kind,'identity'=>$identity,'token'=>bin2hex(random_bytes(32))];
    }
    $id=bin2hex(random_bytes(16));
    $name=preg_replace('/[\x00-\x1f\x7f\\\\\/]/u','_',basename($f['name']));
    $name=substr($name?:'Datei',0,180);
    try {
        transaction(function() use($id,$size,$name,$days,$recipients,$f,$owner) {
            cleanup_locked();
            $used=(int)query('SELECT COALESCE(SUM(bytes),0) FROM transfers WHERE deleted_at IS NULL')->fetchColumn();
            if ($used+$size>config()['max_storage_bytes']) throw new InvalidArgumentException('Der Dateispeicher ist voll.');
            $personal=(int)query('SELECT COALESCE(SUM(bytes),0) FROM transfers WHERE owner_id=? AND deleted_at IS NULL',[$owner['id']])->fetchColumn();
            if ($personal+$size>config()['max_user_storage_bytes']) throw new InvalidArgumentException('Dein persönlicher Speicher ist voll.');
            $active=(int)query('SELECT COUNT(*) FROM transfers WHERE owner_id=? AND deleted_at IS NULL',[$owner['id']])->fetchColumn();
            if ($active>=config()['max_active_transfers_per_user']) throw new InvalidArgumentException('Zu viele aktive Übergaben. Bitte zuerst alte Übergaben beenden.');
            query('INSERT INTO transfers(id,name,bytes,created_at,expires_at,owner_id) VALUES(?,?,?,?,?,?)',[$id,$name,$size,time(),time()+$days*86400,$owner['id']]);
            foreach($recipients as $r) query('INSERT INTO recipients(id,transfer_id,kind,identity,token_hash) VALUES(?,?,?,?,?)',[bin2hex(random_bytes(16)),$id,$r['kind'],$r['identity'],hash('sha256',$r['token'])]);
            if (!move_uploaded_file($f['tmp_name'],file_path($id))) throw new RuntimeException('Upload konnte nicht gespeichert werden.');
            chmod(file_path($id),0600);
        });
    } catch(Throwable $e) { if(is_file(file_path($id))) unlink(file_path($id)); throw $e; }
    $links=array_map(fn($r)=>['kind'=>$r['kind'],'identity'=>$r['identity'],'url'=>config()['base_url'].'/#invite='.$r['token']],$recipients);
    if (($_POST['delivery']??'manual')==='direct') {
        require_once __DIR__.'/delivery.php';
        foreach ($links as &$link) {
            try {send_invitation($link);$link['delivery']='accepted';}
            catch(Throwable $e) {$link['delivery']='failed';}
            query('UPDATE recipients SET delivery_status=? WHERE transfer_id=? AND token_hash=?',[$link['delivery'],$id,hash('sha256',substr($link['url'],strrpos($link['url'],'=')+1))]);
        }
        unset($link);
    }
    json_response(['ok'=>true,'id'=>$id,'links'=>$links],201);
}
function download(): never {
    csrf();$token=(string)($_POST['token']??'');
    $lease=bin2hex(random_bytes(16));
    $r=transaction(function() use($token,$lease) {
        cleanup_locked();$r=recipient($token);
        if (!available($r)) fail('Diese Einladung ist nicht mehr verfügbar.',410);
        if (($_SESSION['verified'][$r['id']]??0)<time()) fail('Bitte zuerst die Empfängeradresse bestätigen.',403);
        if (query('SELECT COUNT(*) FROM leases WHERE transfer_id=?',[$r['transfer_id']])->fetchColumn()>=config()['max_parallel_downloads']) fail('Gerade laufen zu viele Downloads. Bitte kurz warten.',429);
        query('INSERT INTO leases VALUES(?,?,?)',[$lease,$r['transfer_id'],time()+config()['download_lease_seconds']]);
        return $r;
    });
    $handle=fopen(file_path($r['transfer_id']),'rb');
    if (!$handle) {query('DELETE FROM leases WHERE id=?',[$lease]); fail('Datei nicht verfügbar.',410);}
    session_write_close();
    ignore_user_abort(true);set_time_limit(0);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\''.rawurlencode($r['name']));
    header('Content-Length: '.$r['bytes']);header('Accept-Ranges: none');
    header('X-Accel-Buffering: no');
    while (ob_get_level()) ob_end_clean();
    $sent=0;$last=time();$checked=0;$success=false;
    try {
        while (!feof($handle)) {
            // Expiration is enforced even if a download is already running.
            if (time()!==$checked) {
                $current=query('SELECT expires_at FROM transfers WHERE id=?',[$r['transfer_id']])->fetchColumn();
                $r['expires_at']=min($r['expires_at'],(int)$current);$checked=time();
            }
            if (time()>=$r['expires_at']) break;
            $chunk=fread($handle,config()['download_chunk_bytes']);
            if ($chunk===false) break;
            echo $chunk;flush();$sent+=strlen($chunk);
            if (connection_aborted()) break;
            if (time()-$last>=15) {
                query('UPDATE leases SET expires_at=? WHERE id=?',[time()+config()['download_lease_seconds'],$lease]);$last=time();
            }
        }
        $success=$sent===(int)$r['bytes'] && !connection_aborted();
    } finally {
        fclose($handle);
        transaction(function() use($lease,$r,$success) {
            if ($success) query('UPDATE recipients SET downloaded_at=COALESCE(downloaded_at,?) WHERE id=?',[time(),$r['id']]);
            query('DELETE FROM leases WHERE id=?',[$lease]);cleanup_locked();
        });
    }
    exit;
}
