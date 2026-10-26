"""Run after integration.py against isolated development configuration only."""
import http.cookiejar,json,os,urllib.request,urllib.error,uuid
BASE=os.environ.get('DROPKISTE_TEST_URL','http://127.0.0.1:8091')
checks=0
class Client:
 def __init__(self):
  self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  self.csrf=self.call('session',get=True)[1]['csrf']
 def call(self,action,body=None,get=False):
  req=urllib.request.Request(BASE+'/api.php?action='+action,method='GET' if get else 'POST',data=None if get else json.dumps(body or {}).encode(),headers={} if get else {'Content-Type':'application/json','X-CSRF-Token':self.csrf})
  try:r=self.op.open(req,timeout=30)
  except urllib.error.HTTPError as e:r=e
  return r.status,json.load(r)
 def login(self,email,password):return self.call('login',{'email':email,'password':password})
 def upload(self,delivery='manual'):
  boundary='test'+uuid.uuid4().hex
  people=json.dumps([{'kind':'email','identity':'recipient@example.invalid'}])
  fields={'days':'30','delivery':delivery,'recipients':people}
  body=b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k,v in fields.items())
  body+=f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="user-file.txt"\r\nContent-Type: text/plain\r\n\r\noriginal bytes\r\n--{boundary}--\r\n'.encode()
  req=urllib.request.Request(BASE+'/api.php?action=upload',method='POST',data=body,headers={'X-CSRF-Token':self.csrf,'Content-Type':'multipart/form-data; boundary='+boundary})
  try:r=self.op.open(req,timeout=30)
  except urllib.error.HTTPError as e:r=e
  return r.status,json.load(r)
def check(value,label):
 global checks
 assert value,label
 checks+=1;print('PASS',label)
admin=Client();check(admin.login('admin','local-test-password')[0]==200,'existing administrator login preserved')
check(admin.call('session',get=True)[1]['user']['role']=='admin','bootstrap administrator role')
run=uuid.uuid4().hex[:10]
email='alice-'+run+'@example.invalid';bobmail='bob-'+run+'@example.invalid'
pw='User-password-'+run
check(admin.call('users_create',{'name':'Alice','email':email,'password':'short'})[0]==400,'weak account password denied')
check(admin.call('users_create',{'name':'Alice','email':email,'password':'x'*73})[0]==400,'password beyond bcrypt byte limit denied')
code,a=admin.call('users_create',{'name':'Alice','email':email,'password':pw});check(code==201,'administrator creates account')
code,b=admin.call('users_create',{'name':'Bob','email':bobmail,'password':pw});check(code==201,'second account created')
check(admin.call('users_create',{'name':'Duplicate','email':email.upper(),'password':pw})[0]==409,'normalized duplicate email denied')
alice=Client();bob=Client()
check(alice.login(email,pw)[0]==200 and bob.login(bobmail,pw)[0]==200,'regular users log in')
check(alice.call('users_list')[0]==403,'regular user cannot list accounts')
check(alice.call('users_create',{'name':'Hacker','email':'hack@example.invalid','password':pw,'role':'admin'})[0]==403,'regular user cannot create administrator')
check(alice.call('users_active',{'id':b['id'],'active':False})[0]==403,'regular user cannot disable another user')
check(alice.call('users_password',{'id':b['id'],'password':pw})[0]==403,'regular user cannot reset another password')
code,ta=alice.upload();check(code==201,'regular user uploads own transfer')
code,tb=bob.upload();check(code==201,'second user uploads own transfer')
check({t['id'] for t in alice.call('list')[1]['transfers']}=={ta['id']},'Alice sees only her transfer')
check({t['id'] for t in bob.call('list')[1]['transfers']}=={tb['id']},'Bob sees only his transfer')
check(ta['id'] not in {t['id'] for t in admin.call('list')[1]['transfers']},'administrator dashboard does not mix other owners transfers')
check(alice.call('revoke',{'id':tb['id']})[0]==404,'cross-owner deletion denied')
check(bob.call('revoke',{'id':ta['id']})[0]==404,'reverse cross-owner deletion denied')
check(admin.call('users_active',{'id':a['id'],'active':False})[0]==200,'administrator disables account')
check(alice.call('list')[0]==401 and alice.upload()[0]==401,'disabled account loses existing session immediately')
check(Client().login(email,pw)[0]==401,'disabled account cannot log in')
check(admin.call('users_active',{'id':a['id'],'active':True})[0]==200,'administrator re-enables account')
check(alice.call('list')[0]==401,'re-enabling does not revive old sessions')
check(alice.login(email,pw)[0]==200,'enabled user can log in afresh')
second=Client();second.login(email,pw)
newpw='New-password-'+run
check(admin.call('users_password',{'id':a['id'],'password':newpw})[0]==200,'administrator resets password')
check(alice.call('list')[0]==401 and second.call('list')[0]==401,'password reset revokes all old sessions')
check(Client().login(email,pw)[0]==401,'old password no longer accepted')
check(alice.login(email,newpw)[0]==200,'new password accepted')
check(alice.call('password',{'current_password':'wrong','password':pw})[0]==403,'own password change requires old password')
check(alice.call('password',{'current_password':newpw,'password':pw})[0]==200,'user changes own password')
check(Client().login(email,newpw)[0]==401,'own password change invalidates previous password')
alice=Client();alice.login(email,pw)
code,direct=alice.upload('direct')
check(code==201 and direct['links'][0]['delivery']=='failed','missing provider reported as failed, not sent')
check(alice.call('invite',{'token':direct['links'][0]['url'].split('=')[-1]})[0]==200,'failed delivery keeps usable manual invitation')
listing=admin.call('users_list')[1]
check('password_hash' not in json.dumps(listing) and 'auth_version' not in json.dumps(listing),'user management never exposes password hashes')
check(admin.call('users_active',{'id':'admin','active':False})[0]==400,'bootstrap administrator cannot disable self')
for client,tid in [(alice,ta['id']),(alice,direct['id']),(bob,tb['id'])]:check(client.call('revoke',{'id':tid})[0]==200,'owner can revoke own transfer')
print(f'\n{checks} multiuser checks passed.')
