// Real Chrome + isolated MySQL/storage from serve_dictation_lot4_browser.php only.
const fs = require('node:fs');
const origin = 'http://127.0.0.1:8815';
const cdp = 'http://127.0.0.1:8816';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
    const tab = await fetch(`${cdp}/json/new?about:blank`, {method:'PUT'}).then(r=>r.json());
    const ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve=>ws.addEventListener('open',resolve,{once:true}));
    let sequence=0, allowLeave=false;
    const pending=new Map(), errors=[], dialogs=[];
    const send=(method,params={})=>new Promise((resolve,reject)=>{
        const id=++sequence;
        const timer=setTimeout(()=>{pending.delete(id);reject(Error('CDP timeout: '+method));},20000);
        pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params}));
    });
    ws.addEventListener('message',event=>{
        const data=JSON.parse(event.data);
        if(data.method==='Page.javascriptDialogOpening') { dialogs.push(data.params.type);send('Page.handleJavaScriptDialog',{accept:allowLeave}).catch(()=>{}); }
        if(data.method==='Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.exception?.description||data.params.exceptionDetails.text);
        if(data.id&&pending.has(data.id)) { const item=pending.get(data.id);pending.delete(data.id);clearTimeout(item.timer);data.error?item.reject(Error(data.error.message)):item.resolve(data.result); }
    });
    const evaluate=async expression=>{
        const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
        if(result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);
        return result.result.value;
    };
    const wait=async expression=>{
        for(let i=0;i<150;i++) { try {if(await evaluate(`Boolean(${expression})`))return;} catch(error){if(!/context|document|Cannot read properties of null/i.test(error.message))throw error;} await delay(100); }
        throw Error('Timeout: '+expression+'\n'+await evaluate('document.body.innerText.slice(-3000)'));
    };
    const check=async(expression,label)=>{if(!await evaluate(expression))throw Error(label);console.log('OK '+label);};
    const click=async selector=>{
        await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center',behavior:'instant'})`);await delay(150);
        const point=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return{x:r.x+r.width/2,y:r.y+r.height/2}})()`);
        await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});
        await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});
    };
    const field=async value=>evaluate(`(()=>{const el=document.querySelector('#dictation-copy');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    const saved=()=>wait(`document.querySelector('#dictation-save').textContent==='Enregistré'`);
    const ready=()=>wait(`document.querySelector('#dictation-copy') && !document.querySelector('#dictation-copy').disabled`);
    const reload=async()=>{
        await evaluate('window.oldDocument=true'); await send('Page.reload');
        await wait('!window.oldDocument');await ready();
    };
    const button=action=>`[data-action="dictation-writing#${action}"]`;
    const icons = label => check(`[['fullButton','complete'],['playButton','writing']].every(([name,track])=>{
        const btn=document.querySelector('[data-dictation-writing-target="'+name+'"]');const audio=document.querySelector('[data-dictation-writing-target="'+track+'"]');
        const playing=!audio.paused&&!audio.ended;const icon=btn.querySelector('[data-audio-icon]');
        return icon&&icon.getAttribute('aria-hidden')==='true'&&icon.classList.contains('bi-pause-fill')===playing&&icon.classList.contains('bi-play-fill')===!playing&&btn.title===btn.getAttribute('aria-label')&&btn.title.startsWith(playing?'Mettre en pause':'Lire');
    })`, label);
    const keypress = async (key,code,modifiers,vk) => {
        await send('Input.dispatchKeyEvent',{type:key==='Enter'?'keyDown':'rawKeyDown',key,code,modifiers,windowsVirtualKeyCode:vk,...(key==='Enter'?{text:'\r'}:{})});
        await send('Input.dispatchKeyEvent',{type:'keyUp',key,code,modifiers,windowsVirtualKeyCode:vk});
    };
    const input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
    const before = process.argv.includes('--observe-before');
    const activateWord = async word => {
        await evaluate(`window.issue=[...document.querySelectorAll('.dictation-issue')].find(button=>button.textContent===${JSON.stringify(word)});window.issue.scrollIntoView({block:'center',behavior:'instant'});window.issue.focus()`);
        await keypress('Enter','Enter',0,13);
    };
    try {
        await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await send('Page.navigate',{url:origin+'/dictees/8'});
        await wait(`document.querySelector('form[action$="/commencer"]')`);
        await click('form[action$="/commencer"] button');await ready();
        await field(input.copy);await click(button('submit'));
        await wait(`document.querySelector('[data-dictation-writing-target="feedback"]')`);await ready();
        const frozen = await evaluate(`({text:document.querySelector('[data-dictation-writing-target="feedback"]').textContent,summary:document.querySelector('.dictation-summary').textContent})`);
        await activateWord('consciense');
        await check(`document.querySelector('#dictation-copy').value.slice(document.querySelector('#dictation-copy').selectionStart,document.querySelector('#dictation-copy').selectionEnd)==='consciense'`, 'sélection initiale de consciense');
        await field(input.copy.replace('consciense','conscience'));await saved();await reload();
        await evaluate(`window.navigationRequests=[];window.realFetch=window.fetch;window.fetch=(...args)=>{window.navigationRequests.push(args[0]);return window.realFetch(...args)}`);
        await activateWord('améliorerer');
        const selection = await evaluate(`(()=>{const f=document.querySelector('#dictation-copy');return {start:f.selectionStart,end:f.selectionEnd,text:f.value.slice(f.selectionStart,f.selectionEnd)}})()`);
        if(before)console.log('AVANT navigation '+JSON.stringify(selection));
        else {
            if(selection.text!=='améliorerer')throw Error('Target after reload not found: '+JSON.stringify(selection));
            console.log('OK conscience sauvegardé/rechargé : améliorerer sélectionné');
        }
        await evaluate(`window.punctuation=[...document.querySelectorAll('.dictation-punctuation')].find(button=>button.textContent==='.');window.punctuation.dataset.testPoint='true'`);
        for(const width of [1440,390])for(const theme of ['light','dark']){
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            await evaluate(`document.documentElement.setAttribute('data-bs-theme',${JSON.stringify(theme)});window.punctuation.scrollIntoView({block:'center',behavior:'instant'})`);await delay(250);
            const style=await evaluate(`(()=>{const el=window.punctuation,s=getComputedStyle(el),r=el.getBoundingClientRect();return {className:el.className,decoration:s.textDecorationStyle,width:r.width,border:s.borderBottomStyle,title:el.title,name:el.getAttribute('aria-label')}})()`);
            console.log(`${before?'AVANT':'APRÈS'} ponctuation ${width} ${theme} `+JSON.stringify(style));
            const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(`var/dictation-lot4-ux-${before?'before':'after'}-${width}-${theme}.png`,Buffer.from(shot.data,'base64'));
            if(!before)await check(`document.documentElement.scrollWidth<=innerWidth+1`, `pas de débordement ${width}px ${theme}`);
        }
        if(before)return;
        await check(`document.querySelector('#dictation-copy').value===${JSON.stringify(input.copy.replace('consciense','conscience'))}`, 'aucune mutation du texte lors de la navigation');
        await check(`document.querySelector('[data-dictation-writing-target="feedback"]').textContent===${JSON.stringify(frozen.text)}&&document.querySelector('.dictation-summary').textContent===${JSON.stringify(frozen.summary)}`, 'première copie et bilan figés après correction/rechargement');
        await check(`window.navigationRequests.length===0`, 'navigation sans aucune requête');
        await evaluate(`window.punctuation.focus()`);
        await check(`getComputedStyle(window.punctuation.querySelector('.dictation-issue-help')).display==='block'&&getComputedStyle(window.punctuation.querySelector('.dictation-issue-help'),'::before').content.includes('Ponctuation à revoir')&&window.punctuation.getAttribute('aria-label').includes('« . »')&&window.punctuation.title.includes('ponctuation à revoir')`, 'aide visible au focus, cohérente avec le passage et le nom accessible');
        await click('[data-test-point]');
        await check(`document.activeElement.id==='dictation-copy'&&document.activeElement.value.slice(document.activeElement.selectionStart,document.activeElement.selectionEnd)==='.'`, 'ponctuation retrouvée au clic');
        await check(`document.querySelector('#dictation-copy').scrollTop>0`, 'sélection du point éloigné rendue visible dans le textarea étroit');
        const successive = '😀 Introduction. '+input.copy.replace('Une dictée ? ', '').replace('consciense','conscience').replace('Dans quel but ?', 'Pourquoi donc ?');
        await field(successive);await saved();await reload();
        await evaluate(`window.navigationRequests=[];window.realFetch=window.fetch;window.fetch=(...args)=>{window.navigationRequests.push(args[0]);return window.realFetch(...args)}`);
        await activateWord('améliorerer');
        await check(`document.activeElement.selectionStart===document.activeElement.value.indexOf('améliorerer')&&document.activeElement.value.slice(document.activeElement.selectionStart,document.activeElement.selectionEnd)==='améliorerer'`, 'insertions, suppression, remplacements successifs et rechargement : décalage retrouvé');
        await activateWord('le');
        await check(`document.activeElement.value.slice(document.activeElement.selectionStart,document.activeElement.selectionEnd)==='le'&&document.activeElement.selectionStart>document.activeElement.value.indexOf('pollueront')`, 'occurrence répétée retrouvée grâce au contexte global');
        await evaluate(`document.querySelector('.dictation-omission:not(.dictation-punctuation)').focus()`);await keypress('Enter','Enter',0,13);
        await check(`document.activeElement.selectionStart===document.activeElement.selectionEnd&&document.activeElement.selectionStart===document.activeElement.value.indexOf('mot que')`, 'point d’insertion retrouvé après décalage');
        await check(`window.navigationRequests.length===0&&document.querySelector('#dictation-copy').value===${JSON.stringify(successive)}`, 'navigation successive : zéro requête et texte inchangé');
        const ambiguous = successive.replace('le le temps','le temps');
        await field(ambiguous);await saved();
        await evaluate(`document.querySelector('#dictation-copy').setSelectionRange(2,5,'backward')`);
        await activateWord('le');
        await check(`document.activeElement.selectionStart===2&&document.activeElement.selectionEnd===5&&document.activeElement.selectionDirection==='backward'&&document.querySelector('[data-dictation-writing-target="navigationStatus"]').textContent.includes('certitude')`, 'occurrence supprimée ambiguë : sélection conservée et message accessible');
        await activateWord('consciense');
        await check(`document.activeElement.selectionStart===2&&document.activeElement.selectionEnd===5`, 'passage remanié : aucune sélection arbitraire');
        await field(ambiguous.replace('améliorerer',''));await saved();
        await evaluate(`document.querySelector('#dictation-copy').setSelectionRange(3,6,'backward')`);
        await activateWord('améliorerer');
        await check(`document.activeElement.selectionStart===3&&document.activeElement.selectionEnd===6`, 'passage supprimé : sélection conservée');
        await check(`document.querySelector('[data-dictation-writing-target="feedback"]').textContent===${JSON.stringify(frozen.text)}&&document.querySelector('.dictation-summary').textContent===${JSON.stringify(frozen.summary)}`, 'premier bilan et annotations inchangés après toutes les modifications');
        // Visual stress sample uses only artificial learner text/authorized issue
        // shapes. It is detached from the attempt and never submitted.
        await evaluate(`(async()=>{
            const module=await import(performance.getEntriesByType('resource').find(entry=>entry.name.includes('/dictation_feedback')).name);
            window.sample=document.createElement('div');window.sample.className='dictation-frozen';window.sample.id='visual-sample';
            document.querySelector('main').append(window.sample);
            module.renderFeedback(window.sample,'Le chatt. Fin',[{start:3,end:8,domain:'lexical'},{start:3,end:9,domain:'punctuation'},{start:10,end:10,domain:'lexical'},{start:10,end:10,domain:'punctuation'}],()=>{});
        })()`);
        for(const width of [1440,390])for(const theme of ['light','dark']){
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            await evaluate(`document.documentElement.setAttribute('data-bs-theme',${JSON.stringify(theme)});window.sample.scrollIntoView({block:'center',behavior:'instant'});window.sample.querySelector('.dictation-overlap').focus()`);await delay(200);
            await check(`window.sample.textContent==='Le chatt. Fin'&&window.sample.querySelectorAll('.dictation-omission').length===2&&getComputedStyle(window.sample.querySelector('.dictation-overlap')).textDecorationLine==='underline'&&getComputedStyle(window.sample.querySelector('.dictation-overlap')).borderBottomStyle==='dotted'`, `superposition et omissions intactes ${width} ${theme}`);
            const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(`var/dictation-lot4-ux-overlap-${width}-${theme}.png`,Buffer.from(shot.data,'base64'));
        }
        await evaluate(`document.querySelector('#dictation-copy').focus();window.hoverTarget=window.sample.querySelector('.dictation-punctuation:not(.dictation-overlap):not(.dictation-omission)')`);
        const hover=await evaluate(`(()=>{const r=window.hoverTarget.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()`);
        await send('Input.dispatchMouseEvent',{type:'mouseMoved',...hover});
        await check(`getComputedStyle(window.hoverTarget.querySelector('.dictation-issue-help')).display==='block'`, 'aide Ponctuation à revoir au survol');
        if(errors.length)throw Error(errors.join('\n'));
    } finally {
        allowLeave=true;await send('Page.close').catch(()=>{});ws.close();
        for(const item of pending.values())clearTimeout(item.timer);
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
