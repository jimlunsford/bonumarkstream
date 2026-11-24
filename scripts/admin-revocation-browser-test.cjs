// Real Chromium interaction against the task-owned disposable fixture only.
const {chromium}=require('playwright-core');
const fs=require('fs'),path=require('path'),cp=require('child_process');
const [root,base,out,mode]=process.argv.slice(2), negative=mode==='negative';
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)||!path.basename(root).startsWith('bms-revocation-test-')||process.env.BMS_DB_DANGER_RESET!=='1')throw Error('Disposable fixture required');
const fixtures=JSON.parse(fs.readFileSync(root+'/fixtures.json','utf8'));
const result={mode,version:'',assertions:[],captures:[],csp:[],violations:[],dialogs:0,sharedDialogs:[]};
fs.mkdirSync(out,{recursive:true});
const expect=(ok,label)=>{if(!ok)throw Error(label);result.assertions.push(label);console.log('PASS '+label);};
const snapshot=()=>JSON.parse(cp.execFileSync(process.env.BMS_PHP_BINARY||'php',[root+'/revocation-state.php'],{encoding:'utf8'}));
const route=k=>k==='token'?'remote-posting':'registration';
const table=k=>k==='token'?'api_tokens':'registration_invites';
const record=(s,k,id)=>s[table(k)].find(r=>Number(r.id)===Number(id));
const visit=(p,k)=>p.goto(base+'/admin/'+route(k)+'.php');
const row=(p,k,f)=>p.locator(k==='token'?'article.settings-token-record':'article.registration-invite-record').filter({has:p.getByText(f.label,{exact:true})});
const navigate=async(p,control,keyboard=false)=>{if(keyboard){await control.focus();await Promise.all([p.waitForNavigation(),p.keyboard.press('Enter')]);}else await Promise.all([p.waitForNavigation(),control.click()]);};
async function login(browser,js=true,user='owner'){
 const ctx=await browser.newContext({javaScriptEnabled:js});
 await ctx.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
 const p=await ctx.newPage();await p.goto(base+'/admin/login.php');await p.locator('[name=username]').fill(user);await p.locator('[name=password]').fill('disposable-password');await navigate(p,p.locator('button[type=submit]'));
 p.on('dialog',async d=>{if(p.url().includes('/admin/media.php'))result.sharedDialogs.push(d.message());else result.dialogs++;await d.dismiss();});
 p.on('console',m=>{if(m.type()==='error'&&/content security|inline|violat/i.test(m.text()))result.violations.push(m.text());});
 p.on('pageerror',e=>result.violations.push(e.message));
 return {ctx,p};
}
async function inspect(p,label,width){
 expect(await p.locator('h1').count()===1,label+' has one page heading');
 expect(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+' fits '+width+'px');
 const name=label.replace(/[^a-z0-9]+/gi,'-')+'.png';await p.screenshot({path:path.join(out,name),fullPage:true});result.captures.push(name);
}
async function api(ctx,f){return (await ctx.request.get(base+'/api/v1/status.php',{headers:{Authorization:'Bearer '+f.token}})).status();}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BMS_BROWSER_EXECUTABLE||'/usr/bin/google-chrome',args:['--no-sandbox']});result.version=browser.version();
 try{
 const {ctx,p}=await login(browser);
 for(const kind of ['token','invite']){
   const r=await visit(p,kind);const csp=r.headers()['content-security-policy']||'';result.csp.push(csp);
   expect(csp.includes("script-src 'self'")&&!csp.includes("'unsafe-inline'"),kind+' enforces existing self-only script CSP');
   expect(!(await p.locator('script[src]').evaluateAll(es=>es.map(e=>e.src))).some(s=>s.includes('editor.js')),kind+' ordinary Settings path does not load editor.js');
 }
 if(negative){
   for(const kind of ['token','invite']){
     await visit(p,kind);const f=fixtures.negative,before=result.dialogs;let posts=0;
     const listener=r=>{if(r.method()==='POST'&&r.url().includes('/admin/'+route(kind)+'.php'))posts++;};p.on('request',listener);
     await navigate(p,row(p,kind,f).getByRole('button',{name:'Revoke',exact:true}));p.off('request',listener);
     expect(posts===1&&result.dialogs===before,kind+' negative control submits without a dialog');
     expect(record(snapshot(),kind,f[kind+'_id']).status==='revoked',kind+' negative control actually revokes');
   }
   expect(result.violations.some(v=>/inline event handler/i.test(v)), 'Registration inline handler blocked by actual browser CSP');
 }else{
   const initial=snapshot(), secure=fixtures.security;
   for(const kind of ['token','invite']){
     await visit(p,kind);const csrf=await p.locator('[name=csrf_token]').first().inputValue();
     for(const bad of [undefined,'invalid']){
       const form={action:'revoke_'+kind,[kind+'_id']:secure[kind+'_id']};if(bad!==undefined)form.csrf_token=bad;
       const r=await ctx.request.post(base+'/admin/'+route(kind)+'.php',{form,maxRedirects:0});
       expect(r.status()===403,kind+' rejects '+(bad?'invalid':'missing')+' CSRF');
     }
     for(const user of ['guest','commenter']){
       let c;if(user==='guest')c=await browser.newContext();else c=(await login(browser,true,'commenter')).ctx;
       const r=await c.request.post(base+'/admin/'+route(kind)+'.php',{form:{action:'revoke_'+kind,[kind+'_id']:secure[kind+'_id'],csrf_token:csrf},maxRedirects:0});
       expect(r.status()===(user==='guest'?302:403),kind+' rejects '+user+' revocation');
       const g=await c.request.get(base+'/admin/'+route(kind)+'.php?revoke_'+kind+'='+secure[kind+'_id'],{maxRedirects:0});
       expect(g.status()===(user==='guest'?302:403),kind+' protects confirmation from '+user);await c.close();
     }
     expect(record(snapshot(),kind,secure[kind+'_id']).status==='active',kind+' unauthorized requests preserve target');
     await visit(p,kind);
     expect(await row(p,kind,fixtures.revoked).getByRole('link',{name:'Revoke',exact:true}).count()===0,kind+' revoked record has no Revoke action');
     if(kind==='token')expect(await row(p,kind,fixtures.expired).getByRole('link',{name:'Revoke',exact:true}).count()===0,'Expired token has no Revoke action');
     for(const id of [999999,fixtures.revoked[kind+'_id']]){
       await p.goto(base+'/admin/'+route(kind)+'.php?revoke_'+kind+'='+id);
       expect(await p.locator('form.revocation-confirm-form').count()===0,kind+' missing or stale review target has no destructive form');
     }
     await p.goto(base+'/admin/'+route(kind)+'.php?revoke_'+kind+'='+fixtures.escaped[kind+'_id']);
     expect(await p.getByText(fixtures.escaped.label,{exact:true}).count()===1&&await p.locator('img[src=x]').count()===0,kind+' identity escaped safely on review');
   }
   expect(!snapshot().invite_valid.expired&&!snapshot().invite_valid.exhausted&&!snapshot().invite_valid.revoked,'Expired, exhausted and revoked invites stay invalid');
   // Exercise the unchanged editor.js listener and admin.js dynamic messages on real media controls.
   for(const [status,actions] of [['active',['trash']],['trash',['restore','delete_permanently','restore']]]){
     await p.goto(base+'/admin/media.php?status='+status);await p.locator('[name="media_ids[]"]').first().check();let submissions=0;
     const listener=r=>{if(r.method()==='POST')submissions++;};p.on('request',listener);
     for(const action of actions){
       await p.locator('#bulk_action').selectOption(action);const before=result.sharedDialogs.length;
       await p.getByRole('button',{name:'Apply',exact:true}).click();
       expect(result.sharedDialogs.length===before+1,'Media '+action+' confirms exactly once');
       expect(result.sharedDialogs.at(-1).startsWith(action==='trash'?'Move selected':action==='restore'?'Restore selected':'Permanently delete selected'),'Media '+action+' uses current dynamic message');
     }
     expect(submissions===0,'Media '+status+' cancellation sends no POST');p.off('request',listener);
   }
   for(const kind of ['token','invite']){
     await visit(p,kind);const csrf=await p.locator('[name=csrf_token]').first().inputValue();
     const error=await ctx.request.post(base+'/admin/'+route(kind)+'.php',{form:{csrf_token:csrf,action:'revoke_'+kind,[kind+'_id']:0}});
     expect((await error.text()).includes(kind==='token'?'Remote posting action failed.':'Could not update commenter registration controls.'),kind+' existing error feedback preserved');
   }
   await ctx.close();
   for(const js of [true,false]){
     const {ctx,p}=await login(browser,js);
     for(const width of [1440,390,320]){
       await p.setViewportSize({width,height:1000});const name=(js?'js':'nojs')+'-'+width,f=fixtures[name];
       for(const kind of ['token','invite']){
         await visit(p,kind);const before=snapshot(), label=kind+' '+name;let posts=0;
         const listener=r=>{if(r.method()==='POST'&&r.url().includes('/admin/'+route(kind)+'.php'))posts++;};p.on('request',listener);
         expect(kind==='token'?await api(ctx,f)===200:before.invite_valid[name],label+' credential initially works');
         await navigate(p,row(p,kind,f).getByRole('link',{name:'Revoke',exact:true}),width!==1440);
         expect(await p.locator('form.revocation-confirm-form').count()===1,label+' shows exactly one confirmation');
         expect(await p.getByText(f.label,{exact:true}).count()===1,label+' identifies selected record');
         if(kind==='token')expect((await p.textContent('main')).includes('Connected clients using it will stop working.'),label+' explains client consequence');
         expect(!(await p.content()).includes(f.token)&&!(await p.content()).includes(f.code),label+' never displays full secrets');
         expect(JSON.stringify(snapshot()[table(kind)])===JSON.stringify(before[table(kind)])&&posts===0,label+' review is non-destructive');
         await inspect(p,label+' confirmation',width);
         const cancel=p.getByRole('link',{name:'Cancel',exact:true});await cancel.focus();
         expect(await cancel.evaluate(e=>parseFloat(getComputedStyle(e).outlineWidth)>=2),label+' Cancel has visible keyboard focus');
         await navigate(p,cancel,width!==1440);await p.reload();
         expect(posts===0&&record(snapshot(),kind,f[kind+'_id']).status==='active',label+' Cancel and reload preserve active record without POST');
         // Reopen twice to expose any duplicate or stale confirmation behavior.
         await navigate(p,row(p,kind,f).getByRole('link',{name:'Revoke',exact:true}));await navigate(p,p.getByRole('link',{name:'Cancel',exact:true}));
         await navigate(p,row(p,kind,f).getByRole('link',{name:'Revoke',exact:true}));
         const confirm=p.getByRole('button',{name:kind==='token'?'Revoke API token':'Revoke invite code',exact:true});
         await p.getByRole('link',{name:'Cancel',exact:true}).focus();await p.keyboard.press('Tab');expect(await confirm.evaluate(e=>e===document.activeElement),label+' Tab moves from Cancel to Confirm');
         await confirm.focus();expect(await confirm.evaluate(e=>parseFloat(getComputedStyle(e).outlineWidth)>=2),label+' Confirm has visible keyboard focus');
         await navigate(p,confirm,width!==1440);
         const after=snapshot();expect(posts===1&&record(after,kind,f[kind+'_id']).status==='revoked',label+' one confirmed POST revokes target');
         expect(JSON.stringify(before[table(kind)].filter(r=>Number(r.id)!==f[kind+'_id']))===JSON.stringify(after[table(kind)].filter(r=>Number(r.id)!==f[kind+'_id'])),label+' unrelated records unchanged');
         expect(JSON.stringify(before.settings)===JSON.stringify(after.settings),label+' settings unchanged');
         expect((await p.textContent('main')).includes(kind==='token'?'API token revoked.':'Invite code revoked.'),label+' success feedback preserved');
         expect(kind==='token'?await api(ctx,f)===401:!after.invite_valid[name],label+' revoked credential cannot authorize');
         expect(await api(ctx,fixtures.unrelated)===200&&after.invite_valid.unrelated,label+' unrelated credentials remain valid');
         await inspect(p,label+' result',width);p.off('request',listener);
       }
     }
     await ctx.close();
   }
   expect(result.dialogs===0,'Server-rendered confirmation never triggers duplicate native dialogs');
   expect(result.violations.length===0,'Corrected actions have no CSP or JavaScript errors');
   expect(JSON.stringify(initial.settings)===JSON.stringify(snapshot().settings),'All interactions preserve settings');
 }
 }finally{await browser.close();}
 console.log('Revocation browser regression passed: '+result.assertions.length+' checks.');
})().catch(e=>{result.error=e.message;console.error(e.stack);process.exitCode=1;}).finally(()=>fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(result,null,2)));
