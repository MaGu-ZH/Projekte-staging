'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
const {chromium}=require('playwright');
const BASE=process.env.DROPKISTE_TEST_URL||'http://127.0.0.1:8090';
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const errors=[];
 try{
  const admin=await browser.newContext({viewport:{width:1366,height:1000}});
  const p=await admin.newPage();p.on('pageerror',e=>errors.push(e.message));
  await p.goto(BASE);
  await p.getByLabel('Passwort',{exact:true}).fill('local-test-password');
  await p.getByRole('button',{name:'Anmelden'}).click();
  await p.locator('#workspace').waitFor({state:'visible'});
  await p.locator('#file').setInputFiles({name:'Browser-Test.txt',mimeType:'text/plain',buffer:Buffer.from('Browser test original content')});
  await p.locator('.recipient input').fill('browser@example.invalid');
  await p.getByRole('button',{name:'Weitere Person einladen'}).click();
  const second=p.locator('.recipient').nth(1);
  await second.locator('select').selectOption('phone');await second.locator('input').fill('+41791234567');
  await p.getByRole('button',{name:'Datei hochladen & Links erstellen'}).click();
  await p.locator('#links-panel').waitFor({state:'visible'});
  assert.equal(await p.locator('.link-row').count(),2);
  await p.screenshot({path:path.join(__dirname,'runtime','desktop-upload.png'),fullPage:true});
  const invitations=[];
  for(const anchor of await p.locator('.link-row a').all()){
   const href=await anchor.getAttribute('href');const body=new URLSearchParams(href.split('?')[1]).get('body');
   invitations.push(body.match(/https?:\/\/\S+#invite=[a-f0-9]+/)[0]);
  }
  for(let i=0;i<2;i++){
   const context=await browser.newContext({viewport:i?{width:390,height:844}:{width:1366,height:1000},isMobile:!!i,hasTouch:!!i,acceptDownloads:true});
   const r=await context.newPage();r.on('pageerror',e=>errors.push(e.message));
   await r.goto(invitations[i]);await r.locator('#verify-form').waitFor({state:'visible'});
   assert.equal(await r.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal overflow');
   if(i===0){await r.locator('#identity').fill('wrong@example.invalid');await r.getByRole('button',{name:'Bestätigen'}).click();await r.locator('#status.error').waitFor({state:'visible'});assert.equal(await r.locator('#download-form').isVisible(),false);}
   await r.locator('#identity').fill(i?'+41 79 123 45 67':'browser@example.invalid');
   await r.getByRole('button',{name:'Bestätigen'}).click();await r.locator('#download-form').waitFor({state:'visible'});
   if(i)await r.screenshot({path:path.join(__dirname,'runtime','mobile-download.png'),fullPage:true});
   const downloadPromise=r.waitForEvent('download');await r.getByRole('button',{name:'Datei herunterladen'}).click();
   const download=await downloadPromise;assert.equal(await download.failure(),null);assert.equal(download.suggestedFilename(),'Browser-Test.txt');
   assert.equal(await fs.readFile(await download.path(),'utf8'),'Browser test original content');
   await context.close();
  }
  await p.getByRole('button',{name:'Aktualisieren'}).click();
  await p.waitForFunction(()=>[...document.querySelectorAll('.transfer')].some(t=>t.textContent.includes('Browser-Test.txt')&&t.textContent.includes('2/2 heruntergeladen')&&t.textContent.includes('Beendet')));
  const mobile=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});const m=await mobile.newPage();await m.goto(BASE);await m.getByLabel('Passwort',{exact:true}).fill('local-test-password');await m.getByRole('button',{name:'Anmelden'}).click();await m.locator('#workspace').waitFor({state:'visible'});assert.equal(await m.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await m.screenshot({path:path.join(__dirname,'runtime','mobile-upload.png'),fullPage:true});
  assert.deepEqual(errors,[]);
  console.log('PASS browser: desktop upload, per-recipient links, wrong identity rejection, desktop + mobile downloads, completion status, responsive mobile admin, no JavaScript errors.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
