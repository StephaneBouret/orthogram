// Real Chrome + isolated MySQL/storage from serve_dictation_lot5_browser.php --automated only.
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
    const phase = state => wait(`document.querySelector('[data-dictation-writing-phase-value]')?.dataset.dictationWritingPhaseValue===${JSON.stringify(state)} && !document.querySelector('#dictation-copy').disabled`);
    const begin = async id => {
        await send('Page.navigate',{url:origin+'/dictees/'+id});
        await wait(`document.querySelector('form[action$="/commencer"]')`);
        await click('form[action$="/commencer"] button');await ready();
    };
    const intercept = async (suffix, lose=false) => evaluate(`
        window.realFetch=window.realFetch||window.fetch;window.calls=[];window.release=null;window.held=null;window.once=true;
        window.fetch=async(...args)=>{
            if(!args[1]?.body)return window.realFetch(...args);
            const call={url:args[0],body:JSON.parse(args[1].body)};window.calls.push(call);
            const response=await window.realFetch(...args);call.status=response.status;call.data=await response.clone().json();
            if(window.once&&String(args[0]).endsWith(${JSON.stringify(suffix)})){
                window.once=false;window.held=call;await new Promise(resolve=>window.release=resolve);
                if(${lose})throw Error('Response lost after real commit');
            }return response;
        };`);
    const phaseButton = '[data-dictation-phase-target="button"]';
    const closed = async id => {
        await send('Page.navigate', {url:origin+'/dictees/'+id});
        await wait(`document.querySelector('#dictation-closed-copy') && !document.querySelector('${phaseButton}').disabled`);
    };
    const resume = async id => { await closed(id);await click(phaseButton);await phase('resuming'); };
    const completed = () => wait(`document.querySelector('#dictation-final-copy')`);
    try {
        await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await resume(1);
        await check(`document.querySelector('#dictation-copy').value===${JSON.stringify('L’ecole ouvre.\nCopie clôturée.')}`, 'Resume repart de la copie clôturée exacte');
        await check(`!document.querySelector('.dictation-issue, [data-dictation-writing-issues-value], .dictation-initial, #dictation-corrected-text') && !document.body.innerText.includes('Explications synthétiques')`, 'reprise sans premier signalement, résultat ni corrigé');
        await intercept('/enregistrer');await field('Sauvegarde A');await wait('window.release');
        await evaluate('window.saveRelease=window.release');
        await intercept('/finaliser',true);
        const visible = 'Erreur restante.\n<script>copie définitive</script> 😀';
        await field(visible);await click(button('submit'));
        await evaluate(`document.querySelector(${JSON.stringify(button('submit'))}).click()`);
        await check(`window.calls.length===0&&document.querySelector('#dictation-copy').readOnly`, 'Terminer attend SAVE en vol, capture B et bloque le double clic');
        await evaluate('window.saveRelease()');await wait('window.release');
        await check(`window.calls.length===1&&window.held.body.copy===${JSON.stringify(visible)}&&window.held.body.revision===4&&window.held.data.state==='completed'`, 'Finalize évalue le texte visible B à la révision confirmée, sans SAVE supplémentaire');
        const finalizeBody = await evaluate('window.held.body');
        await evaluate('window.release()');await wait(`document.querySelector('#dictation-save').textContent.includes('Confirmation non reçue')`);
        await check(`document.querySelector('#dictation-copy').readOnly&&!document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'réponse perdue après commit : copie protégée et téléchargeable');
        await evaluate(`window.fetch=async(...args)=>{if(JSON.stringify(JSON.parse(args[1].body))!==${JSON.stringify(JSON.stringify(finalizeBody))})throw Error('Replay changed');return window.realFetch(...args)}`);
        await click(button('retry'));await completed();
        await check(`document.querySelector('#dictation-final-copy').textContent===${JSON.stringify(visible)}&&!document.querySelector('#dictation-final-copy script')&&document.querySelector('#dictation-corrected-text').textContent==='L’école ouvre.'`, 'rejeu exact : copie définitive avec erreurs et HTML échappé, corrigé distinct');
        await check(`document.querySelector('#dictation-explanations').textContent===${JSON.stringify('Explications synthétiques.\n<b>Texte inerte</b>')}&&!document.querySelector('#dictation-explanations b')`, 'explications : sauts de ligne et échappement HTML préservés');
        const finalSummary = await evaluate(`document.querySelector('.dictation-summary').textContent`);
        await send('Page.reload');await completed();
        await check(`document.querySelector('.dictation-summary').textContent===${JSON.stringify(finalSummary)}&&!document.querySelector('textarea')`, 'rechargement : résultat définitif identique et aucune édition');

        await resume(2);
        for (const width of [1440,390]) for (const theme of ['light','dark']) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});
            await evaluate(`document.documentElement.dataset.bsTheme=${JSON.stringify(theme)}`);
            await check(`document.documentElement.scrollWidth<=innerWidth&&document.querySelector('label[for="dictation-copy"]').textContent==='Votre dictée après les cours'`, `reprise ${width}px ${theme} sans débordement`);
            await evaluate(`document.querySelector('#dictation-copy').focus()`);
            await keypress('Tab','Tab',0,9);await keypress('Enter','Enter',0,13);await saved();
            await check(`document.querySelector('#dictation-copy').value.includes('À')&&document.activeElement.id==='dictation-copy'`, 'caractère spécial activé au clavier, focus rendu à la copie');
            await keypress('F8','F8',0,119);await delay(150);
            await icons('F8 et état accessible des lecteurs');
            await wait(`document.querySelector('[data-dictation-writing-target="writing"]').paused`);
            await check(`Math.abs(document.querySelector('[data-dictation-writing-target="writing"]').currentTime-0.6)<0.08`, 'lecture audio réelle arrêtée à la borne de 600 ms');
            await click(button('full'));await delay(120);
            await check(`document.querySelector('[data-dictation-writing-target="writing"]').paused`, 'exclusivité des pistes');
            await click(button('full'));
            const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            fs.writeFileSync(`var/dictation-lot5-resuming-${width}-${theme}.png`,Buffer.from(shot.data,'base64'));
        }
        await field('😀 Après les cours.\nœ À');await saved();await reload();
        await check(`document.querySelector('#dictation-copy').value===${JSON.stringify('😀 Après les cours.\nœ À')}`, 'autosauvegarde et rechargement Unicode de la reprise');
        await click(button('submit'));await completed();
        for (const width of [1440,390]) for (const theme of ['light','dark']) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});
            await evaluate(`document.documentElement.dataset.bsTheme=${JSON.stringify(theme)}`);
            await check(`document.documentElement.scrollWidth<=innerWidth&&!!document.querySelector('#dictation-corrected-text')`, `bilan ${width}px ${theme} sans débordement`);
            const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
            fs.writeFileSync(`var/dictation-lot5-completed-${width}-${theme}.png`,Buffer.from(shot.data,'base64'));
        }

        await resume(3);
        const url=await evaluate('location.href');
        const second=await fetch(`${cdp}/json/new?${encodeURIComponent(url)}`,{method:'PUT'}).then(r=>r.json());
        const otherWs=new WebSocket(second.webSocketDebuggerUrl);await new Promise(resolve=>otherWs.addEventListener('open',resolve,{once:true}));
        let otherId=0;const otherPending=new Map();
        otherWs.addEventListener('message',event=>{const data=JSON.parse(event.data);if(otherPending.has(data.id)){otherPending.get(data.id)(data.result);otherPending.delete(data.id);}});
        const otherEval=expression=>new Promise(resolve=>{const id=++otherId;otherPending.set(id,resolve);otherWs.send(JSON.stringify({id,method:'Runtime.evaluate',params:{expression,awaitPromise:true,returnByValue:true}}));});
        try {
            let loaded=false;
            for(let i=0;i<100;i++){const r=await otherEval(`!!document.querySelector('#dictation-copy')&&!document.querySelector('#dictation-copy').disabled`);if(r.result?.value){loaded=true;break;}await delay(100);}
            if(!loaded)throw Error('Second tab not ready');
            await field('Copie finale onglet A');await click(button('submit'));await completed();
            await otherEval(`document.querySelector('#dictation-copy').value='Copie locale B';document.querySelector('[data-dictation-writing-target="submit"]').click()`);
            let conflict=false;
            for(let i=0;i<100;i++){const r=await otherEval(`document.querySelector('#dictation-save').textContent.includes('périmée')&&document.querySelector('#dictation-copy').value==='Copie locale B'&&!document.querySelector('[data-dictation-writing-target="recovery"]').hidden`);if(r.result?.value){conflict=true;break;}await delay(100);}
            if(!conflict)throw Error('FINALIZE tab conflict not shown');
            console.log('OK deux vrais onglets : Finalize concurrent refusé, copie B récupérable');
            await otherEval(`window.addEventListener('beforeunload',e=>e.stopImmediatePropagation(),{capture:true});location.reload()`);
            let restored=false;
            for(let i=0;i<100;i++){const r=await otherEval(`document.querySelector('#dictation-final-copy')?.textContent==='Copie finale onglet A'`);if(r.result?.value){restored=true;break;}await delay(100);}
            if(!restored)throw Error('Final copy overwritten');
            console.log('OK rechargement volontaire du second onglet : copie finale A intacte');
        } finally {otherWs.close();await fetch(`${cdp}/json/close/${second.id}`);}

        for (const id of [4,5]) {
            await closed(id);
            await intercept('/reprendre',true);await click(phaseButton);await wait('window.release');
            const resumeBody=await evaluate('window.held.body');
            await evaluate('window.release()');await wait(`document.querySelector('[data-dictation-phase-target="status"]').textContent.includes('Confirmation non reçue')`);
            await evaluate(`window.fetch=async(...args)=>{if(JSON.stringify(JSON.parse(args[1].body))!==${JSON.stringify(JSON.stringify(resumeBody))})throw Error('Resume replay changed');return window.realFetch(...args)}`);
            await click(phaseButton);await wait(`document.querySelector('[data-dictation-phase-action-value="finalize"]')&&!document.querySelector('${phaseButton}').disabled`);
            await check(`!document.querySelector('textarea, #dictation-corrected-text')&&document.querySelector('#dictation-closed-copy').textContent==='L’école ouvre.'`, `sans erreur ${id}: Resume rejoué, reviewing en lecture seule`);
            await intercept('/finaliser',true);await click(phaseButton);await wait('window.release');
            const body=await evaluate('window.held.body');
            await check(`window.held.body.copy===null`, 'finalisation sans nouvelle copie');
            await evaluate('window.release()');await wait(`document.querySelector('[data-dictation-phase-target="status"]').textContent.includes('Confirmation non reçue')`);
            await evaluate(`window.fetch=async(...args)=>{if(JSON.stringify(JSON.parse(args[1].body))!==${JSON.stringify(JSON.stringify(body))})throw Error('Review replay changed');return window.realFetch(...args)}`);
            await click(phaseButton);await completed();
            await check(`!!document.querySelector('#dictation-corrected-text')&&!!document.querySelector('#dictation-explanations')===${id===4}`, `corrigé disponible ${id===4?'avec':'sans'} explications`);
        }
        await closed(6);
        await check(`document.querySelector('.dictation-summary').textContent.includes('100,0')&&document.querySelector('.dictation-summary').textContent.includes('1 zone')`, 'score lexical 100 % avec erreur de ponctuation');
        await click(phaseButton);await phase('resuming');
        await check(`document.querySelector('#dictation-copy').value==='L’école ouvre'`, 'noErrors=false impose la reprise modifiable malgré 100 %');
        await click(button('submit'));await completed();

        await resume(7);await intercept('/enregistrer',true);await field('SAVE incertain');await wait('window.release');
        await field('Copie visible finale');await click(button('submit'));await evaluate('window.release()');
        await wait(`document.querySelector('#dictation-save').textContent.includes('Confirmation non reçue')`);
        await check(`window.calls.length===1&&document.querySelector('#dictation-copy').readOnly`, 'SAVE incertain : Finalize attend le reçu');
        await evaluate(`window.fetch=async(...args)=>{window.calls.push({url:args[0],body:JSON.parse(args[1].body)});const response=await window.realFetch(...args);if(String(args[0]).endsWith('/finaliser'))await new Promise(resolve=>window.releaseFinal=resolve);return response}`);
        await click(button('retry'));await wait('window.releaseFinal');
        await check(`window.calls.length===3&&JSON.stringify(window.calls[0].body)===JSON.stringify(window.calls[1].body)&&window.calls[2].body.copy==='Copie visible finale'&&window.calls[2].body.revision===4`, 'rejeu SAVE exact puis Finalize unique avec le texte visible');
        await evaluate('window.releaseFinal()');await completed();
        if(errors.length)throw Error(errors.join('\n'));
        console.log('OK lot 5 : reprise, finalisation, corrigé, concurrence, clavier et audio');
    } finally {
        allowLeave=true;await send('Page.close').catch(()=>{});ws.close();
        for(const item of pending.values())clearTimeout(item.timer);
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
