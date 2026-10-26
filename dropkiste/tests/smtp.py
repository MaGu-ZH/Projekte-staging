"""Local SMTP/TLS fixture: no real email or real provider credentials."""
import base64,email,os,socketserver,ssl,subprocess,threading
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];RUN=ROOT/'tests'/'runtime'
CONTAINER=os.environ.get('DROPKISTE_TEST_CONTAINER','dropkiste-users-test')
gateway=subprocess.check_output(['docker','inspect','-f','{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}',CONTAINER],text=True).strip()
cert=RUN/'smtp-cert.pem';key=RUN/'smtp-key.pem'
subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=dropkiste-local-smtp','-addext',f'subjectAltName=IP:{gateway}'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key)
received=[];reject=False;starttls=False
class Handler(socketserver.StreamRequestHandler):
 def handle(self):
  try:
   self.wfile.write(b'220 local.test ESMTP\r\n');self.wfile.flush()
   while line:=self.rfile.readline():
    if line.upper().startswith(b'EHLO'):
     self.wfile.write(b'250-local.test\r\n'+(b'250-STARTTLS\r\n' if starttls else b'')+b'250 AUTH PLAIN\r\n')
    elif line.upper().startswith(b'STARTTLS'):
     self.wfile.write(b'220 Begin TLS\r\n');self.wfile.flush();self.connection=ctx.wrap_socket(self.connection,server_side=True);self.rfile=self.connection.makefile('rb');self.wfile=self.connection.makefile('wb')
    elif line.upper().startswith(b'AUTH PLAIN'):
     parts=line.strip().split()
     if len(parts)==2:self.wfile.write(b'334 \r\n');self.wfile.flush();auth=self.rfile.readline().strip()
     else:auth=parts[-1]
     assert base64.b64decode(auth)==b'\0fixture-user\0fixture-password'
     self.wfile.write(b'235 Authenticated\r\n')
    elif line.upper().startswith(b'MAIL FROM'):self.wfile.write(b'250 Sender OK\r\n')
    elif line.upper().startswith(b'RCPT TO'):self.wfile.write(b'550 Rejected\r\n' if reject else b'250 Recipient OK\r\n')
    elif line.upper().startswith(b'DATA'):
     self.wfile.write(b'354 End with dot\r\n');self.wfile.flush();lines=[]
     while (chunk:=self.rfile.readline()) not in [b'.\r\n',b'']:
      lines.append(chunk[1:] if chunk.startswith(b'..') else chunk)
     received.append(email.message_from_bytes(b''.join(lines)));self.wfile.write(b'250 Message accepted\r\n')
    elif line.upper().startswith(b'QUIT'):self.wfile.write(b'221 Bye\r\n');self.wfile.flush();break
    else:self.wfile.write(b'250 OK\r\n')
    self.wfile.flush()
  except (ssl.SSLError,ConnectionError,OSError):pass
class Server(socketserver.ThreadingTCPServer):
 allow_reuse_address=True;daemon_threads=True
 def get_request(self):
  sock,addr=super().get_request()
  if starttls:return sock,addr
  try:return ctx.wrap_socket(sock,server_side=True),addr
  except ssl.SSLError:sock.close();raise
 def handle_error(self,*args):pass
server=Server((gateway,1465),Handler);threading.Thread(target=server.serve_forever,daemon=True).start()
config=RUN/'smtp-config.php'
config.write_text("<?php\n$c=require __DIR__.'/config.php';\n$c['smtp']=['enabled'=>true,'host'=>'"+gateway+"','port'=>1465,'security'=>getenv('SMTP_TEST_STARTTLS')?'starttls':'tls','username'=>'fixture-user','password'=>'fixture-password','from'=>'sender@example.invalid'];\nif (!getenv('SMTP_TEST_UNTRUSTED')) $c['delivery_ca_file']=__DIR__.'/smtp-cert.pem';\nreturn $c;\n")
script=RUN/'send-mail.php';script.write_text("<?php require '/app/app.php';require '/app/delivery.php';try {send_invitation(['kind'=>'email','identity'=>'recipient@example.invalid','url'=>'https://dropkiste.app/#invite='.str_repeat('a',64)]);echo 'accepted';}catch(Throwable $e){fwrite(STDERR,'rejected');exit(2);}")
def send(*env):return subprocess.run(['docker','exec','-e','DROPKISTE_CONFIG=/app/tests/runtime/smtp-config.php',*[x for e in env for x in ['-e',e]],CONTAINER,'php','/app/tests/runtime/send-mail.php'],capture_output=True,text=True)
try:
 result=send();assert result.returncode==0,(result.stdout,result.stderr)
 assert len(received)==1
 message=received[0]
 assert message['To']=='<recipient@example.invalid>' and message['From']=='<sender@example.invalid>'
 assert 'https://dropkiste.app/#invite='+'a'*64 in message.get_payload(decode=True).decode()
 print('PASS SMTP: authenticated TLS connection and original personal link in decoded email')
 result=send('SMTP_TEST_UNTRUSTED=1');assert result.returncode==2,(result.stdout,result.stderr)
 print('PASS SMTP: untrusted TLS certificate rejected')
 reject=True;result=send();assert result.returncode==2
 print('PASS SMTP: provider rejection is failure, not successful delivery')
 reject=False;starttls=True;result=send('SMTP_TEST_STARTTLS=1');assert result.returncode==0,(result.stdout,result.stderr)
 assert len(received)==2
 print('PASS SMTP: STARTTLS encryption, authentication and acceptance')
finally:
 server.shutdown();server.server_close()
 for p in [config,script,cert,key]:p.unlink(missing_ok=True)
