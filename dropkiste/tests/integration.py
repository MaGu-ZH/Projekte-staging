"""Run against the isolated test container only (see README)."""
import http.cookiejar, json, sqlite3, urllib.request, urllib.error, uuid
from pathlib import Path
BASE='http://127.0.0.1:8090'
STORE=Path(__file__).parent/'runtime'/'storage'
checks=0
class Client:
 def __init__(self):
  self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  self.csrf=self.request('session',method='GET')[1]['csrf']
 def request(self,action,payload=None,method='POST',raw=None,content_type='application/json',csrf=True):
  headers={'X-CSRF-Token':self.csrf} if csrf and hasattr(self,'csrf') else {}
  if method=='GET': data=None
  else:
   data=raw if raw is not None else json.dumps(payload or {}).encode()
   headers['Content-Type']=content_type
  req=urllib.request.Request(BASE+'/api.php?action='+action,data=data,method=method,headers=headers)
  try:r=self.op.open(req,timeout=15)
  except urllib.error.HTTPError as e:r=e
  body=r.read()
  try:body=json.loads(body)
  except (ValueError,UnicodeDecodeError):pass
  return r.status,body
 def upload(self,people,content=b'private file content',days=30):
  boundary='dropkiste'+uuid.uuid4().hex
  parts=[]
  for name,value in [('days',str(days)),('recipients',json.dumps(people))]:
   parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
  parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="Test ü.txt"\r\nContent-Type: text/plain\r\n\r\n'.encode()+content+b'\r\n')
  parts.append(f'--{boundary}--\r\n'.encode())
  return self.request('upload',raw=b''.join(parts),content_type='multipart/form-data; boundary='+boundary)
 def download(self,token):
  from urllib.parse import urlencode
  return self.request('download',raw=urlencode({'csrf':self.csrf,'token':token}).encode(),content_type='application/x-www-form-urlencoded')
def check(condition,label):
 global checks
 assert condition,label
 checks+=1
 print('PASS',label)
def token(link):return link['url'].split('#invite=')[1]
def sql(query,args=()):
 with sqlite3.connect(STORE/'dropkiste.sqlite') as c:return c.execute(query,args).fetchall()
admin=Client();guest=Client()
check(admin.request('session',method='GET')[1]['limits']['max_file_bytes']==1048576,'isolated test configuration')
sql('DELETE FROM transfers')
sql('DELETE FROM attempts')
for f in STORE.glob('*.blob'):f.unlink()
check(admin.request('list')[0]==401,'admin protected')
check(guest.request('invite',{'token':'x'*17000})[0]==413,'oversize API request denied')
check(admin.request('login',{'password':'wrong'})[0]==401,'wrong admin password')
check(admin.request('login',{'password':'local-test-password'},csrf=False)[0]==403,'CSRF protected')
check(admin.request('login',{'password':'local-test-password'})[0]==200,'admin login')
people=[{'kind':'email','identity':'Receiver@Example.invalid'},{'kind':'phone','identity':'0041 79 123 45 67'}]
check(guest.upload(people)[0]==401,'anonymous upload denied')
check(admin.upload(people,days=31)[0]==400,'retention above 30 days denied')
check(admin.upload(people,days=0)[0]==400,'zero retention denied')
check(admin.upload(people,content=b'')[0]==400,'empty file denied')
check(admin.upload(people,content=b'x'*1048577)[0]==400,'oversize upload denied')
check(admin.upload([people[0],people[0]])[0]==400,'duplicate recipients denied')
check(admin.upload([{'kind':'phone','identity':'0791234567'}])[0]==400,'phone requires country prefix')
status,created=admin.upload(people)
check(status==201,'upload and two invitations')
t1,t2=[token(l) for l in created['links']];tid=created['id']
check((STORE/(tid+'.blob')).exists(),'file exists privately')
check(created['links'][1]['identity']=='+41791234567','phone normalization')
check(guest.request('invite',{'token':t1})[1]['kind']=='email','email invitation metadata')
check(guest.download(t1)[0]==403,'download requires identity verification')
check(guest.request('verify',{'token':t1,'identity':'wrong@example.invalid'})[0]==403,'wrong identity denied')
check(guest.request('verify',{'token':t1,'identity':'RECEIVER@example.invalid'})[0]==200,'matching email accepted')
check(guest.download(t1)==(200,b'private file content'),'verified file bytes downloaded')
check((STORE/(tid+'.blob')).exists(),'first download keeps file for last recipient')
check(guest.download(t2)[0]==403,'verification bound to individual recipient')
phone=Client()
check(phone.request('verify',{'token':t2,'identity':'+41 (79) 123-45-67'})[0]==200,'matching phone accepted')
check(phone.download(t2)==(200,b'private file content'),'last recipient receives original bytes')
check(not (STORE/(tid+'.blob')).exists(),'last successful download deletes physical file')
check(guest.request('invite',{'token':t1})[0]==410,'completed link unavailable')
check(sql('SELECT identity FROM recipients WHERE transfer_id=?',(tid,))==[('',),('',)],'contact details erased after deletion')
check(sql('SELECT delete_reason FROM transfers WHERE id=?',(tid,))[0][0]=='downloaded','download deletion recorded')
_,exp=admin.upload([people[0]])
sql('UPDATE transfers SET expires_at=0 WHERE id=?',(exp['id'],))
check(guest.request('invite',{'token':token(exp['links'][0])})[0]==410,'expired invitation denied')
check(not (STORE/(exp['id']+'.blob')).exists(),'expired physical file removed')
_,rev=admin.upload([people[0]])
check(guest.request('revoke',{'id':rev['id']})[0]==401,'only uploader can revoke')
check(admin.request('revoke',{'id':rev['id']})[0]==200,'admin revocation succeeds')
check(not (STORE/(rev['id']+'.blob')).exists(),'revocation removes file')
check(guest.request('invite',{'token':'bad'})[0]==410,'invalid token denied')
a=admin.upload([people[0]],content=b'a'*1048576)[1]
b=admin.upload([people[0]],content=b'b'*1048576)[1]
check(admin.upload([people[0]],content=b'overflow')[0]==400,'total storage quota enforced')
for t in [a,b]:admin.request('revoke',{'id':t['id']})
listing=admin.request('list')[1]
check('token_hash' not in json.dumps(listing),'admin listing does not leak invitation tokens')
check(admin.request('logout')[0]==200,'logout succeeds')
admin.csrf=admin.request('session',method='GET')[1]['csrf']
check(admin.request('list')[0]==401,'logout revokes admin session')
print(f'\n{checks} integration checks passed.')
