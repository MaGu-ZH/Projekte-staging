'use strict';
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const BASE=process.env.DROPKISTE_TEST_URL||'http://127.0.0.1:8091';
(async()=>{const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
 const admin=await browser.newContext();const p=await admin.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.goto(BASE);await p.getByLabel('Passwort',{exact:true}).fill('local-test-password');await p.getByRole('button',{name:'Anmelden'}).click();await p.locator('#users-panel').waitFor({state:'visible'});
 const email='ui-'+Date.now()+'@example.invalid';const password='Browser-user-password';
 await p.getByLabel('Name',{exact:true}).fill('Browser User');await p.locator('#user-create-form').getByLabel('E-Mail-Adresse',{exact:true}).fill(email);await p.getByLabel('Startpasswort (mindestens 14 Zeichen)').fill(password);await p.getByRole('button',{name:'Benutzer anlegen'}).click();
 const row=p.locator('.user-row').filter({hasText:email});await row.waitFor();assert.equal(await p.locator('#user-password').inputValue(),'');
 const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});const u=await context.newPage();u.on('pageerror',e=>errors.push(e.message));await u.goto(BASE);await u.getByLabel('E-Mail-Adresse oder admin').fill(email);await u.getByLabel('Passwort',{exact:true}).fill(password);await u.getByRole('button',{name:'Anmelden'}).click();await u.locator('#workspace').waitFor({state:'visible'});assert.equal(await u.locator('#users-panel').isVisible(),false);assert.equal(await u.locator('#password-panel').isVisible(),true);assert.equal(await u.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);assert.match(await u.locator('#delivery-info').textContent(),/noch nicht eingerichtet/);
 await row.getByRole('button',{name:'Zugang sperren'}).click();await row.getByRole('button',{name:'Zugang aktivieren'}).waitFor();await u.getByRole('button',{name:'Aktualisieren'}).click();await u.locator('#status.error').waitFor();assert.match(await u.locator('#status').textContent(),/anmelden/);
 await row.getByRole('button',{name:'Passwort neu setzen'}).click();await row.getByLabel('Neues Passwort für Browser User').fill('New-browser-password');await row.getByRole('button',{name:'Neues Passwort speichern'}).click();await row.locator('form').waitFor({state:'hidden'});await row.getByRole('button',{name:'Zugang aktivieren'}).click();await row.getByRole('button',{name:'Zugang sperren'}).waitFor();
 await u.reload();await u.getByLabel('E-Mail-Adresse oder admin').fill(email);await u.getByLabel('Passwort',{exact:true}).fill('New-browser-password');await u.getByRole('button',{name:'Anmelden'}).click();await u.locator('#workspace').waitFor({state:'visible'});
 await u.getByLabel('Aktuelles Passwort').fill('New-browser-password');await u.getByLabel('Neues Passwort',{exact:true}).fill('User-changed-password');await u.getByRole('button',{name:'Passwort ändern und abmelden'}).click();await u.locator('#login-panel').waitFor({state:'visible'});
 assert.deepEqual(errors,[]);await p.screenshot({path:require('node:path').join(__dirname,'runtime','users-desktop.png'),fullPage:true});
 console.log('PASS browser user management: create, mobile regular login, role-specific UI, disable active session, reset password, enable, own password change, no JavaScript errors.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
