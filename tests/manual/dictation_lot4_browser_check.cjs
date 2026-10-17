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
    try {
        await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await begin(2);
        await intercept('/enregistrer');
        await field('A');await wait('window.release');
        const initial = '😀 L’ ouvre.\n<script>alert(1)</script>\u00a0';
        await field(initial);
        await click(button('submit'));
        await evaluate(`document.querySelector(${JSON.stringify(button('submit'))}).click()`);
        await check(`window.calls.length===1&&document.querySelector('#dictation-copy').readOnly`, 'double clic bloqué, saisie protégée tant que SAVE est en vol');
        await evaluate('window.release()');await phase('self_correction');
        await check(`document.querySelector('[data-dictation-writing-target="feedback"]').textContent===${JSON.stringify(initial)}&&document.querySelector('#dictation-copy').value===${JSON.stringify(initial)}`, 'évaluation du texte visible B après SAVE A ; espaces, Unicode et HTML conservés comme texte');
        await check(`!document.querySelector('[data-dictation-writing-target="feedback"] script')`, 'aucune interprétation HTML');
        await check(`document.querySelector('[data-dictation-writing-revision-value]').dataset.dictationWritingRevisionValue==='2'`, 'une sauvegarde A et une évaluation B seulement : révision 2');
        await check(`document.querySelectorAll('.dictation-issue').length>0&&document.querySelectorAll('.dictation-omission').length>0`, 'intervalles et omissions rendus');
        // Tooltip placement is transient presentation; all copy, issue attributes
        // and descriptions must remain frozen through navigation and SAVE.
        const feedbackSnapshot = `(()=>{const copy=document.querySelector('[data-dictation-writing-target="feedback"]').cloneNode(true);copy.querySelectorAll('.dictation-issue-help').forEach(help=>help.removeAttribute('style'));return copy.innerHTML})()`;
        const feedback = await evaluate(feedbackSnapshot);
        await click('.dictation-issue');
        await check(`document.activeElement.id==='dictation-copy'`, 'signalement au clic rejoint l’éditeur');
        await field('Préfixe beaucoup plus long '+initial);await saved();
        await evaluate(`document.querySelector('#dictation-copy').setSelectionRange(2,5,'backward');document.querySelector('.dictation-issue').focus()`);
        await keypress('Enter','Enter',0,13);
        await check(`document.activeElement.id==='dictation-copy'&&document.activeElement.selectionStart==='Préfixe beaucoup plus long '.length&&document.activeElement.selectionEnd==='Préfixe beaucoup plus long '.length+2`, 'activation clavier après ajout : sélection du caractère hors BMP à sa nouvelle position');
        await check(`${feedbackSnapshot}===${JSON.stringify(feedback)}`, 'signalement initial figé pendant SAVE');
        await click('#dictation-copy');await evaluate(`document.querySelector('#dictation-copy').setSelectionRange(2,5,'backward')`);
        await keypress('F8','F8',2,119); // Ctrl: next
        await check(`document.querySelector('#dictation-segment').value==='1'`, 'Ctrl F8 en autocorrection');
        await keypress('F8','F8',8,119);
        await check(`document.querySelector('#dictation-segment').value==='0'`, 'Maj F8 en autocorrection');
        await keypress('F8','F8',0,119);
        await wait(`!document.querySelector('[data-dictation-writing-target="writing"]').paused`);
        await wait(`document.querySelector('[data-dictation-writing-target="writing"]').paused`);
        await check(`Math.abs(document.querySelector('[data-dictation-writing-target="writing"]').currentTime-.6)<.06&&document.querySelector('#dictation-copy').selectionStart===2&&document.querySelector('#dictation-copy').selectionEnd===5`, 'lecture autorisée, arrêt borné, sélection préservée');
        await icons('icônes audio en autocorrection');
        for(const width of [1440,390])for(const theme of ['light','dark']){
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
            await evaluate(`document.documentElement.setAttribute('data-bs-theme',${JSON.stringify(theme)});document.querySelector('.dictation-issue').focus()`);
            await delay(300);
            await check(`document.documentElement.scrollWidth<=innerWidth+1&&getComputedStyle(document.querySelector('.dictation-issue')).outlineWidth==='3px'`, `autocorrection ${width}px ${theme} : pas de débordement, focus visible`);
            const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(`var/dictation-lot4-${width}-${theme}.png`,Buffer.from(shot.data,'base64'));
        }
        // Isolated renderer stress: overlaps, coincident omissions and surrogate pairs.
        await evaluate(`(async()=>{window.renderModule=await import(performance.getEntriesByType('resource').find(entry=>entry.name.includes('/dictation_feedback')).name);
            window.renderBox=document.createElement('div');
            window.renderText='😀é <b> x\\n';
            window.renderIssues=[{start:2,end:4,domain:'lexical'},{start:2,end:10,domain:'punctuation'},{start:4,end:4,domain:'lexical'},{start:4,end:4,domain:'punctuation'}];
            window.renderModule.renderFeedback(window.renderBox,window.renderText,window.renderIssues,()=>{});window.renderFirst=window.renderBox.innerHTML;})()`);
        await check(`window.renderBox.textContent===window.renderText&&window.renderBox.querySelectorAll('.dictation-omission').length===2&&!window.renderBox.querySelector('b')`, 'rendu superposé : texte intact, deux repères au même point, HTML inerte');
        await evaluate(`window.renderModule.renderFeedback(window.renderBox,window.renderText,window.renderIssues,()=>{})`);
        await check(`window.renderFirst===window.renderBox.innerHTML`, 'ordre de rendu déterministe');
        // CLOSE response lost after the actual commit: remain locked until exact replay.
        await intercept('/enregistrer');await field('Autocorrection A en vol');await wait('window.release');
        await evaluate('window.saveRelease=window.release');
        await intercept('/terminer',true);
        const finalCopy='L’école ouvre.';
        await field(finalCopy);await click(button('submit'));
        await check(`window.calls.length===0&&document.querySelector('#dictation-copy').readOnly`, 'CLOSE attend la sauvegarde en vol');
        await evaluate('window.saveRelease()');await wait('window.release');
        const closeBody=await evaluate('window.held.body');
        await check(`window.calls.filter(c=>c.url.endsWith('/terminer')).length===1`, 'clôture unique, texte soumis sans attendre la temporisation');
        await evaluate('window.release()');await wait(`document.querySelector('#dictation-save').textContent.includes('Confirmation non reçue')`);
        await check(`document.querySelector('#dictation-copy').readOnly&&!document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'clôture incertaine : saisie protégée et récupération disponible');
        await evaluate(`window.fetch=async(...args)=>{if(args[1]?.body){const body=JSON.parse(args[1].body);if(JSON.stringify(body)!==${JSON.stringify(JSON.stringify(closeBody))})throw Error('Replay changed');}return window.realFetch(...args)}`);
        await click(button('retry'));await wait(`document.querySelector('#dictation-closed-copy')`);
        await check(`document.querySelector('#dictation-closed-copy').textContent===${JSON.stringify(finalCopy)}&&!document.querySelector('[data-dictation-writing-target="feedback"]')&&!document.querySelector('textarea')`, 'CLOSE rejoué : copie exacte clôturée, aucun signalement ni éditeur');

        await begin(3);
        await click(button('submit'));await wait(`document.querySelector('#dictation-save').textContent.includes('vide')`);
        await check(`!document.querySelector('#dictation-copy').readOnly`, '422 évaluation vide : saisie libérée, première évaluation non consommée');
        await intercept('/evaluer',true);await field('L’école ouvre');await click(button('submit'));await wait('window.release');
        const evaluateBody=await evaluate('window.held.body');
        await check(`window.held.status===200&&window.held.data.state==='self_correction'`, '100 % avec ponctuation incorrecte : autocorrection maintenue');
        await evaluate('window.release()');await wait(`document.querySelector('#dictation-save').textContent.includes('Confirmation non reçue')`);
        await check(`document.querySelector('#dictation-copy').readOnly`, 'évaluation incertaine : aucune édition concurrente');
        await evaluate(`window.fetch=async(...args)=>{if(JSON.stringify(JSON.parse(args[1].body))!==${JSON.stringify(JSON.stringify(evaluateBody))})throw Error('Replay changed');return window.realFetch(...args)}`);
        await click(button('retry'));await phase('self_correction');
        await check(`document.querySelector('[data-dictation-writing-revision-value]').dataset.dictationWritingRevisionValue==='1'`, 'réponse perdue EVALUATE : le rejeu conserve la révision 1');
        await check(`document.querySelector('.dictation-summary').textContent.includes('100,0')&&document.querySelector('.dictation-punctuation')`, 'évaluation rejouée sans second calcul ; ponctuation affichée sans signe attendu');
        await reload();await phase('self_correction');
        await check(`document.querySelector(${JSON.stringify(button('submit'))}).textContent==='Terminer'`, 'rechargement : aucune seconde évaluation proposée');
        await click(button('submit'));await wait(`document.querySelector('#dictation-closed-copy')`);
        await check(`document.querySelector('.dictation-summary').textContent.includes('1 zone')`, 'clôture autorisée sans modifier chaque signalement');

        await begin(4);await field('L’école ouvre.');await click(button('submit'));await wait(`document.querySelector('#dictation-closed-copy')`);
        await check(`document.querySelector('main').textContent.includes('Bravo')&&!document.querySelector('textarea')&&!document.querySelector('.dictation-issue')`, 'copie parfaite : clôture directe, aucun corrigé');
        // Two real tabs, each holding the same revision, compete for EVALUATE then CLOSE.
        await begin(5);
        const url=await evaluate('location.href');
        const second=await fetch(`${cdp}/json/new?${encodeURIComponent(url)}`,{method:'PUT'}).then(r=>r.json());
        const otherWs=new WebSocket(second.webSocketDebuggerUrl);await new Promise(resolve=>otherWs.addEventListener('open',resolve,{once:true}));
        let otherId=0;const otherPending=new Map();
        otherWs.addEventListener('message',event=>{const data=JSON.parse(event.data);if(otherPending.has(data.id)){otherPending.get(data.id)(data.result);otherPending.delete(data.id);}});
        const otherEval=expression=>new Promise(resolve=>{const id=++otherId;otherPending.set(id,resolve);otherWs.send(JSON.stringify({id,method:'Runtime.evaluate',params:{expression,awaitPromise:true,returnByValue:true}}));});
        try {
            for(let i=0;i<100;i++){const r=await otherEval(`!!document.querySelector('#dictation-copy')&&!document.querySelector('#dictation-copy').disabled`);if(r.result?.value)break;await delay(100);}
            await field('L’ ouvre.');await click(button('submit'));await phase('self_correction');
            await otherEval(`document.querySelector('#dictation-copy').value='Texte local B';document.querySelector('[data-dictation-writing-target="submit"]').click()`);
            let conflict=false;for(let i=0;i<100;i++){const r=await otherEval(`document.querySelector('#dictation-save').textContent.includes('périmée')&&document.querySelector('#dictation-copy').value==='Texte local B'&&!document.querySelector('[data-dictation-writing-target="recovery"]').hidden`);if(r.result?.value){conflict=true;break;}await delay(100);}
            if(!conflict)throw Error('EVALUATE tab conflict not shown');console.log('OK deux onglets : évaluation concurrente refusée, texte B préservé');
            await otherEval(`window.addEventListener('beforeunload',e=>e.stopImmediatePropagation(),{capture:true});location.reload()`);await delay(1200);
            await click(button('submit'));await wait(`document.querySelector('#dictation-closed-copy')`);
            await otherEval(`document.querySelector('#dictation-copy').value='Autocorrection locale B';document.querySelector('[data-dictation-writing-target="submit"]').click()`);
            conflict=false;for(let i=0;i<100;i++){const r=await otherEval(`document.querySelector('#dictation-save').textContent.includes('périmée')&&document.querySelector('#dictation-copy').value==='Autocorrection locale B'`);if(r.result?.value){conflict=true;break;}await delay(100);}
            if(!conflict)throw Error('CLOSE tab conflict not shown');console.log('OK deux onglets : clôture concurrente refusée, aucune adoption de révision');
        } finally {otherWs.close();await fetch(`${cdp}/json/close/${second.id}`);}
        await begin(6);await intercept('/enregistrer',true);await field('SAVE incertain');await wait('window.release');
        await field('L’ ouvre.');await click(button('submit'));await evaluate('window.release()');
        await wait(`document.querySelector('#dictation-save').textContent.includes('Confirmation non reçue')`);
        await check(`window.calls.length===1&&document.querySelector('#dictation-copy').readOnly`, 'SAVE incertain avant évaluation : aucune transition envoyée');
        await evaluate(`window.fetch=async(...args)=>{window.calls.push({url:args[0],body:JSON.parse(args[1].body)});const response=await window.realFetch(...args);if(String(args[0]).endsWith('/evaluer')){window.transitionSent=window.calls.at(-1);await new Promise(resolve=>window.releaseTransition=resolve);}return response}`);
        await click(button('retry'));await wait('window.releaseTransition');
        await check(`window.calls.length===3&&JSON.stringify(window.calls[0].body)===JSON.stringify(window.calls[1].body)&&window.calls[2].body.revision===1&&window.calls[2].body.copy==='L’ ouvre.'`, 'rejeu SAVE strict, puis évaluation du texte capturé à la révision confirmée');
        await evaluate('window.releaseTransition()');await phase('self_correction');
        await begin(7);
        const originalCRLF='😀\r\nL’ ouvre.\r';
        await evaluate(`(async()=>{const el=document.querySelector('[data-controller="dictation-writing"]');const r=await fetch(el.dataset.dictationWritingTransitionUrlValue,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':el.dataset.dictationWritingTransitionTokenValue},body:JSON.stringify({revision:0,copy:${JSON.stringify(originalCRLF)}})});if(!r.ok)throw Error('CRLF setup rejected');location.reload()})()`);
        await phase('self_correction');
        await check(`document.querySelector('[data-dictation-writing-target="feedback"]').textContent===${JSON.stringify(originalCRLF)}`, 'copie ancienne/API : CRLF et CR préservés avant application des offsets UTF-16');
        if(errors.length)throw Error(errors.join('\n'));
        console.log('OK lot 4 : parcours, concurrence, clavier, rendu et protections');
    } finally {
        allowLeave=true;await send('Page.close').catch(()=>{});ws.close();
        for(const item of pending.values())clearTimeout(item.timer);
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
