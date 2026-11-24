// Real user submission against the disposable Page creation fixture only.
const {chromium} = require('playwright-core');
const fs = require('fs'), path = require('path');
const [root,base,out] = process.argv.slice(2);
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base) || !path.basename(root).startsWith('bms-creation-test-') || process.env.BMS_DB_DANGER_RESET !== '1') throw Error('Disposable fixture required');
fs.mkdirSync(out,{recursive:true});
const result = {version:'',assertions:[],screenshots:[]};
const expect = (ok,label) => { if(!ok) throw Error(label); result.assertions.push(label); console.log('PASS '+label); };
(async()=>{
 const browser = await chromium.launch({headless:true,executablePath:process.env.BMS_BROWSER_EXECUTABLE||'/usr/bin/google-chrome',args:['--no-sandbox']});
 result.version = browser.version();
 try {
  const context = await browser.newContext({viewport:{width:1440,height:1000}});
  await context.route('**/*', r => new URL(r.request().url()).origin===base ? r.continue() : r.abort());
  const page = await context.newPage();
  await page.goto(base+'/admin/login.php');
  await page.locator('[name=username]').fill('owner');
  await page.locator('[name=password]').fill('disposable-password');
  await Promise.all([page.waitForNavigation(),page.locator('button[type=submit]').click()]);
  for(const status of ['draft','published']) {
   const slug = 'browser-'+status;
   const fill = async()=>{
    await page.locator('[name=page_title]').fill('Browser '+status);
    if (!(await page.locator('[name=page_slug]').isVisible())) await page.locator('[data-editor-card=page-url] [data-side-card-toggle]').click();
    await page.locator('[name=page_slug]').fill(slug);
    await page.locator('#editor-tab-markdown').click();
    await page.locator('[name=body_markdown]').fill('Browser verified **body**.');
   };
   const submit = async()=>{
    const control = page.locator('.editor-sidebar-column button[type=submit][name=stream_submit_action][value='+ (status==='draft'?'draft':'publish') +']').first();
    await control.focus();
    await Promise.all([page.waitForNavigation(),page.keyboard.press('Enter')]);
   };
   await page.goto(base+'/admin/page-new.php'); await fill();
   fs.writeFileSync(root+'/_bonumark_stream/app/creation-fault','terms-after');
   await submit();
   expect(page.url().endsWith('/admin/page-new.php') && await page.getByText('Page creation failed. Please try again.',{exact:true}).isVisible(),status+': visible failure feedback after keyboard submission');
   expect(!fs.existsSync(root+'/_bonumark_stream/app/creation-fault'),status+': controlled failure reached');
   const failed = status+'-failure.png'; await page.screenshot({path:path.join(out,failed),fullPage:true});result.screenshots.push(failed);
   await fill(); await submit();
   expect(page.url().includes('page-edit.php?type='+status+'&file='+slug+'.md'),status+': retry keeps requested slug');
   expect(await page.getByText(status==='draft' ? /Draft page created\./ : /Page published\./).isVisible(),status+': success feedback only after completed operation');
   expect(await page.locator('[name=page_title]').inputValue()==='Browser '+status,status+': editor retains submitted title');
   const success = status+'-retry.png'; await page.screenshot({path:path.join(out,success),fullPage:true});result.screenshots.push(success);
  }
  await context.close();
 } finally { await browser.close(); fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(result,null,2)); }
})().catch(e=>{console.error(e);process.exitCode=1;});
