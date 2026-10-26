"""Verify additive upgrade from a real v1-shaped DB with an active invitation."""
from pathlib import Path
import hashlib,os,sqlite3,subprocess,time,shutil
ROOT=Path(__file__).resolve().parents[1];TARGET=ROOT/'tests'/'runtime'/'migration'
TARGET.mkdir(exist_ok=True)
path=TARGET/'dropkiste.sqlite'
if path.exists():path.unlink()
with sqlite3.connect(path) as c:
 c.executescript('''CREATE TABLE transfers(id TEXT PRIMARY KEY,name TEXT NOT NULL,bytes INTEGER NOT NULL,created_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,deleted_at INTEGER,delete_reason TEXT);
 CREATE TABLE recipients(id TEXT PRIMARY KEY,transfer_id TEXT NOT NULL REFERENCES transfers(id),kind TEXT NOT NULL,identity TEXT NOT NULL,token_hash TEXT NOT NULL UNIQUE,downloaded_at INTEGER);
 CREATE TABLE leases(id TEXT PRIMARY KEY,transfer_id TEXT NOT NULL REFERENCES transfers(id),expires_at INTEGER NOT NULL);
 CREATE TABLE attempts(bucket TEXT PRIMARY KEY,count INTEGER NOT NULL,reset_at INTEGER NOT NULL);''')
 c.execute('INSERT INTO transfers(id,name,bytes,created_at,expires_at) VALUES(?,?,?,?,?)',('legacy','legacy.txt',8,int(time.time()),int(time.time())+86400))
 c.execute('INSERT INTO recipients(id,transfer_id,kind,identity,token_hash) VALUES(?,?,?,?,?)',('recipient','legacy','email','legacy@example.invalid',hashlib.sha256(b'a'*64).hexdigest()))
(TARGET/'legacy.blob').write_bytes(b'old file')
config=ROOT/'tests'/'runtime'/'migration-config.php'
config.write_text("<?php $c=require __DIR__.'/config.php'; $c['storage_dir']=__DIR__.'/migration'; foreach(['smtp','sms','max_user_storage_bytes','max_active_transfers_per_user','delivery_timeout_seconds','max_direct_recipients'] as $k) unset($c[$k]);return $c;")
container=os.environ.get('DROPKISTE_TEST_CONTAINER','dropkiste-users-test')
try:
 for i in range(2):
  subprocess.run(['docker','exec','-e','DROPKISTE_CONFIG=/app/tests/runtime/migration-config.php',container,'php','/app/bin/cleanup.php'],check=True,capture_output=True)
  with sqlite3.connect(path) as c:
   assert c.execute('SELECT owner_id,name,bytes FROM transfers WHERE id="legacy"').fetchone()==('admin','legacy.txt',8)
   assert c.execute('SELECT identity,token_hash,delivery_status FROM recipients').fetchone()==('legacy@example.invalid',hashlib.sha256(b'a'*64).hexdigest(),'manual')
   assert c.execute('SELECT COUNT(*) FROM users').fetchone()[0]==0
  assert (TARGET/'legacy.blob').read_bytes()==b'old file'
 print('PASS migration: v1 database + legacy configuration upgraded twice, invitation token and file unchanged, original transfers owned by administrator, new user table created.')
finally:
 config.unlink(missing_ok=True);shutil.rmtree(TARGET)
