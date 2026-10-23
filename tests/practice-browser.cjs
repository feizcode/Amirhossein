// Real Chrome + PHP HTTP server, backed exclusively by an approved disposable fixture.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),net=require('node:net');
const {spawn,execFileSync}=require('node:child_process');
let playwright;try{playwright=require(process.env.PLAYWRIGHT_MODULE||'playwright');}catch{playwright=require(path.join(os.homedir(),'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));}
const source=path.resolve(__dirname,'..'),helper=path.join(__dirname,'practice-browser-router.php');
const php='C:/xampp/php/php.exe',root=fs.mkdtempSync('C:/Users/afeyz/AppData/Local/Temp/opencode/practice-browser-');
const env={...process.env,PRACTICE_BROWSER_ROOT:root},results=[],bugs=[],errors=[];
const check=(ok,label)=>{results.push({ok:!!ok,label});console.log(`${ok?'PASS':'FAIL'} ${label}`);if(!ok)bugs.push(label);};
const state=()=>{
 const out=execFileSync(php,['-d','display_startup_errors=0',helper,'state'],{env,encoding:'utf8'});
 const idx=out.indexOf('{');
 return JSON.parse(idx===-1?out:out.slice(idx));
};
(async()=>{
 let server,browser,log;
 try{
  execFileSync(php,[helper,'setup'],{env,stdio:'pipe'});
  const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
  const base=`http://127.0.0.1:${port}`;
  log=fs.openSync(path.join(root,'server.log'),'a');
  server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root,helper],{cwd:source,env,stdio:['ignore',log,log]});
  for(let i=0;i<60;i++){try{const r=await fetch(base+'/login');if(r.status===200)break;}catch{}if(i===59)throw Error('PHP server did not start');await new Promise(r=>setTimeout(r,100));}
  browser=await playwright.chromium.launch({headless:true,executablePath:process.env.CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe',args:['--disable-gpu','--no-first-run']});
  const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();
  page.on('pageerror',e=>errors.push(e.message));
  const click=async selector=>{
   try{await Promise.all([page.waitForNavigation({timeout:10000,waitUntil:'domcontentloaded'}),page.locator(selector).click()]);}
   catch(e){fs.writeFileSync(path.join(root,'failure.html'),await page.content());await page.screenshot({path:path.join(source,'work/practice-failure.png'),fullPage:true});console.error('Click failed: '+selector+' '+page.url());throw e;}
  };
  const screenshot=async name=>{await page.screenshot({path:path.join(source,`work/practice-${name}.png`),fullPage:true});};
  const responsive=async name=>{
   for(const width of [320,390,1440]){
    await page.setViewportSize({width,height:900});await page.evaluate(()=>document.fonts.ready);
    const metrics=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,small:[...document.querySelectorAll('main button,main a,main select,main input:not([type=hidden]):not([type=radio]),main .ks-practice-option,main summary')].filter(e=>e.getClientRects().length).map(e=>{const r=e.getBoundingClientRect();return {text:(e.innerText||e.name||e.tagName).trim().slice(0,90),width:Math.round(r.width),height:Math.round(r.height)};}).filter(r=>r.width<44||r.height<44)}));
    check(metrics.scroll<=metrics.width,`${name}: no horizontal overflow at ${width}px (${metrics.scroll}px)`);
    check(!metrics.small.length,`${name}: 44px touch targets at ${width}px ${JSON.stringify(metrics.small)}`);
    await screenshot(`${name}-${width}`);
   }
  };
  await page.goto(base+'/practice-prototype');check(page.url().endsWith('/login'),'Anonymous practice redirects to login');
  await page.locator('input[name=password]').fill('isolated-practice-password');await click('button[type=submit]');
  check(!page.url().endsWith('/login'),'Real form login succeeds');
  await page.goto(base+'/');
  await page.locator('.ks-practice-start').waitFor();
  for(const width of [320,390,1440]) {
   await page.setViewportSize({width,height:900});
   const cta=await page.locator('.ks-practice-start').evaluate(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return {height:r.height,width:r.width,display:s.display,underline:s.textDecorationLine,overflow:el.scrollWidth>el.clientWidth};});
   check(cta.height>=48 && cta.width>=44 && cta.display==='inline-flex' && cta.underline==='none' && !cta.overflow,`Home practice CTA fits and has 48px height at ${width}px`);
   await screenshot(`home-cta-${width}`);
  }
  const api=async(url,data)=>page.evaluate(async({url,data})=>{const r=await fetch('/api/v1'+url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(data)});return {status:r.status,text:await r.text()};},{url,data});
  // Seed through the authenticated application, not raw SQL or the user's database.
  const seed=await api('/activities',{subject:'math',source:'school',topic:'math-g1-t2',date:new Date().toISOString().slice(0,10),note:'Isolated real browser fixture',kind:'practice',complete:true});
  check(seed.status===201,'School math activity seeded through authenticated API');
  await page.goto(base+'/practice-prototype');await responsive('start');
  const seen=new Set();
  for(const skill of ['fraction_of_quantity','fraction_addition']){
   if(skill==='fraction_addition'){
    const extra=await api('/activities',{subject:'math',source:'school',topic:'math-g1-t1',date:new Date().toISOString().slice(0,10),note:'Isolated multiple-choice eligibility',kind:'practice',complete:true});
    check(extra.status===201,'School fraction-addition topic seeded for MC eligibility');
    await page.reload();
   }
   await page.locator('select[name=skill]').selectOption(skill);await click('button[type=submit]');
   check(state().questions.some(q=>q.skill===skill),`Start ${skill} persists snapshot`);
   let first=true;
   while(!state().finished){
    const s=state(),q=s.questions.find(q=>q.id===s.ids[s.index]);seen.add(q.format);
    if(first){
     await responsive(q.format);
     const before=state();await page.reload();check(JSON.stringify(state())===JSON.stringify(before),'Reload resumes exact durable state');
     await click('button[value=hint]');check(await page.locator('[role=status]').innerText().then(t=>t.includes('راهنمایی ذخیره‌شده')),'Hint renders after persisted POST');
     const hinted=state();await page.reload();check(JSON.stringify(state())===JSON.stringify(hinted),'Reload retains hint and current question');
     first=false;
    }
    if(q.format==='multiple_choice')await page.locator(`input[name=answer][value="${q.answer}"]`).check();else await page.locator('input[name=answer]').fill(String(q.answer));
    await click('button[value=answer]');check((await page.locator('[role=status]').innerText()).includes('درست حل کردی'),`${q.id}: correct ${q.format} answer feedback`);
    if(await page.locator('button[value=solution]').count()){await click('button[value=solution]');check((await page.locator('[role=status]').innerText()).includes('راه‌حل ذخیره‌شده'),'Solution visible and persisted');}
    await click('button[value=next]');
   }
   check(await page.locator('#summary-title').count()===1,`${skill}: session reaches summary`);await responsive(`summary-${skill}`);
  }
  check(seen.has('numeric')&&seen.has('multiple_choice'),'Both numeric and multiple-choice exercised');
  const parent=await api('/context',{role:'parent'});check(parent.status===200,'Explicit parent context selected');
  await page.goto(base+'/practice-prototype?view=parent');check((await page.locator('h1').innerText()).includes('گزارش تمرین والدین'),'Parent report renders');await responsive('parent');
  check(errors.length===0,'No uncaught browser errors '+JSON.stringify(errors));
  console.log(JSON.stringify({fixture:root,checks:results.length,failed:bugs.length,bugs},null,2));
  fs.writeFileSync(path.join(root,'results.json'),JSON.stringify({results,errors},null,2));
  if(bugs.length)process.exitCode=1;
 }finally{
  if(browser)await browser.close();
  if(server){const exited=new Promise(resolve=>server.once('exit',resolve));server.kill();await exited;}
  if(log!==undefined)fs.closeSync(log);
  console.log('Disposable fixture retained: '+root+'; PHP server stopped.');
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
