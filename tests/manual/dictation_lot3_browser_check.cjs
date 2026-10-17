// Real Chrome + isolated MySQL/storage from serve_dictation_lot3_browser.php only.
const fs = require('node:fs');
const origin = 'http://127.0.0.1:8815';
const cdp = 'http://127.0.0.1:8816';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const observeRaces = process.argv.includes('--observe-races-before');
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
        await send('Input.dispatchKeyEvent',{type:'rawKeyDown',key,code,modifiers,windowsVirtualKeyCode:vk});
        await send('Input.dispatchKeyEvent',{type:'keyUp',key,code,modifiers,windowsVirtualKeyCode:vk});
    };
    try {
        await send('Page.enable');await send('Runtime.enable');await send('Network.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await send('Page.navigate',{url:origin+'/dictees/1'});
        await wait(`document.querySelector('form[action$="/commencer"]')||document.querySelector('#dictation-copy')`);
        if(await evaluate(`!!document.querySelector('form[action$="/commencer"]')`)) await click('form[action$="/commencer"] button');
        await ready();
        if(process.argv.includes('--observe-keyboard-before')) {
            console.log('BROWSER '+JSON.stringify(await send('Browser.getVersion')));
            await click('#dictation-copy');
            await evaluate(`window.keyEvents=[];document.addEventListener('keydown',event=>{
                const record={key:event.key,code:event.code,alt:event.altKey,shift:event.shiftKey,ctrl:event.ctrlKey,meta:event.metaKey,trusted:event.isTrusted,target:event.target.id,prevented:event.defaultPrevented};
                window.keyEvents.push(record);
            });document.querySelector('#dictation-copy').setSelectionRange(0,0);`);
            for(const [key,code,vk,modifiers] of [['ArrowRight','ArrowRight',39,9],['ArrowLeft','ArrowLeft',37,9],[' ','Space',32,9],['ArrowRight','',39,9],['ArrowRight','ArrowRight',39,11]]) {
                await send('Input.dispatchKeyEvent',{type:'rawKeyDown',key,code,modifiers,windowsVirtualKeyCode:vk});
                await send('Input.dispatchKeyEvent',{type:'keyUp',key,code,modifiers,windowsVirtualKeyCode:vk});
                console.log('AVANT CLAVIER '+JSON.stringify(await evaluate(`({event:window.keyEvents.at(-1),segment:document.querySelector('#dictation-segment').value,paused:document.querySelector('[data-dictation-writing-target="writing"]').paused,focus:document.activeElement.id,selection:[document.querySelector('#dictation-copy').selectionStart,document.querySelector('#dictation-copy').selectionEnd]})`)));
            }
            return;
        }
        await check(`document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'téléchargement absent du parcours normal');
        await icons('icônes lecture initiales, noms accessibles et infobulles cohérents');
        // Real saves, with delivery of the first response held after the server answered.
        const wrapSave = () => evaluate(`window.realFetch=window.realFetch||window.fetch;window.calls=[];window.responses=[];window.release=null;window.hold=true;
            window.fetch=async(...args)=>{const body=JSON.parse(args[1].body);window.calls.push(body);const response=await window.realFetch(...args);
                window.responses.push({status:response.status,data:await response.clone().json()});
                if(window.hold){window.hold=false;await new Promise(resolve=>window.release=resolve);}return response;}`);
        const revision = () => evaluate(`Number(document.querySelector('[data-controller="dictation-writing"]').dataset.dictationWritingRevisionValue)`);
        const persisted = () => evaluate(`window.realFetch(location.href).then(r=>r.text()).then(html=>{
            const doc=new DOMParser().parseFromString(html,'text/html');return {copy:doc.querySelector('#dictation-copy').value,
                revision:Number(doc.querySelector('[data-controller="dictation-writing"]').dataset.dictationWritingRevisionValue),count:doc.querySelector('#dictation-count').textContent};})`);
        await field('Base stable');await saved();
        const initialRevision = await revision();
        await wrapSave();
        await field('A');await wait('window.release');
        await field('B');await evaluate('window.release()');await saved();await delay(900);
        const duplicate = await evaluate('window.calls');
        const duplicatePersisted = await persisted();
        if(observeRaces) console.log('AVANT doublon '+JSON.stringify({calls:duplicate,persisted:duplicatePersisted}));
        else {
            if(JSON.stringify(duplicate)!==JSON.stringify([{revision:initialRevision,copy:'A'},{revision:initialRevision+1,copy:'B'}]))throw Error('Duplicate save: '+JSON.stringify(duplicate));
            if(duplicatePersisted.copy!=='B'||duplicatePersisted.revision!==initialRevision+2||!duplicatePersisted.count.includes('1 / 3'))throw Error('Duplicate persisted state');
            await check(`document.querySelector('#dictation-save').textContent==='Enregistré'&&document.querySelector('#dictation-count').textContent.includes('1 / 3')`, 'A puis B avant la fin du debounce : deux requêtes, deux révisions, B persistée, compteur et statut confirmés');
            // Undo a local edit before debounce: no request, restore the cached server count.
            await evaluate('window.calls=[]');await field('Autre saisie provisoire');await field('B');
            await check(`document.querySelector('#dictation-save').textContent==='Enregistré'&&document.querySelector('#dictation-count').textContent.includes('1 / 3')`, 'retour au texte confirmé : affichage rétabli avant la temporisation');
            await delay(850);
            await check(`window.calls.length===0&&document.querySelector('#dictation-save').textContent==='Enregistré'&&document.querySelector('#dictation-count').textContent.includes('1 / 3')`, 'retour au texte confirmé : aucune requête, compteur et statut immédiatement cohérents');
        }
        await evaluate('window.fetch=window.realFetch');
        // Test both a still-pending debounce and one already elapsed during the request.
        for(const elapsed of observeRaces?[750]:[0,750]) {
            await field('Avant refus');await saved();
            const beforeRejection = await revision();
            await wrapSave();await field('a '.repeat(751));await wait('window.release');
            await field('Copie corrigée');await delay(elapsed);await evaluate('window.release()');await delay(950);
            const result = await evaluate(`({calls:window.calls.map(c=>({revision:c.revision,copy:c.copy.length>100?'[751 mots]':c.copy})),responses:window.responses,status:document.querySelector('#dictation-save').textContent,count:document.querySelector('#dictation-count').textContent,visible:document.querySelector('#dictation-copy').value})`);
            const stored = await persisted();
            if(observeRaces) console.log('AVANT refus '+JSON.stringify({result,persisted:stored}));
            else {
                if(result.calls.length!==2||result.calls[0].revision!==beforeRejection||result.calls[1].revision!==beforeRejection||result.responses[0].status!==422||result.responses[1].status!==200)throw Error('Validation sequence: '+JSON.stringify(result));
                if(stored.copy!=='Copie corrigée'||stored.revision!==beforeRejection+1||!stored.count.includes('2 / 3')||result.visible!==stored.copy||result.status!=='Enregistré'||!result.count.includes('2 / 3'))throw Error('Validation persisted/display state: '+JSON.stringify({result,stored}));
                console.log(`OK correction avant 422, temporisation ${elapsed?'expirée':'en attente'} : deux requêtes, révision inchangée pour la reprise, copie/compteur/statut confirmés`);
            }
            await evaluate('window.fetch=window.realFetch');
        }
        if(observeRaces)return;
        // Rejection with no intervening edit must remain blocked; returning to confirmed needs no write.
        const confirmedBeforeRefusal = await evaluate(`document.querySelector('#dictation-copy').value`);
        const revisionBeforeRefusal = await revision();
        await wrapSave();await field('a '.repeat(751));await wait('window.release');await evaluate('window.release()');await delay(850);
        await check(`window.calls.length===1&&document.querySelector('#dictation-save').textContent.includes('Limite de mots')&&document.querySelector('#dictation-count').textContent.includes('attente')`, '422 sur la copie encore visible : erreur conservée, aucun nouvel envoi');
        await check(`!document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'récupération disponible après échec de validation non résolu');
        await field(confirmedBeforeRefusal);await delay(850);
        await check(`window.calls.length===1&&document.querySelector('#dictation-save').textContent==='Enregistré'&&document.querySelector('#dictation-count').textContent.includes('2 / 3')`, 'après 422, retour à la copie confirmée sans écriture supplémentaire');
        await check(`document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'récupération masquée quand la copie redevient confirmée');
        await wrapSave();await field('a '.repeat(751));await wait('window.release');await field(confirmedBeforeRefusal);await evaluate('window.release()');await delay(850);
        const returnedStored = await persisted();
        await check(`window.calls.length===1&&document.querySelector('#dictation-save').textContent==='Enregistré'&&document.querySelector('#dictation-count').textContent.includes('2 / 3')`, 'retour au texte confirmé avant 422 : état propre, pas de sauvegarde inutile');
        if(returnedStored.revision!==revisionBeforeRefusal||returnedStored.copy!==confirmedBeforeRefusal)throw Error('Rejected request changed persisted copy');
        // Even equality with the last confirmed text cannot skip a lost operation's exact replay.
        await evaluate(`window.calls=[];window.lose=true;window.fetch=async(...args)=>{window.calls.push(JSON.parse(args[1].body));const response=await window.realFetch(...args);if(window.lose){window.lose=false;throw new TypeError('Lost response');}return response;}`);
        await field('Texte incertain différent');await wait(`document.querySelector('#dictation-save').textContent.includes('Confirmation non reçue')`);
        await field(confirmedBeforeRefusal);await delay(850);
        await check(`window.calls.length===1&&document.querySelector('#dictation-save').textContent.includes('Confirmation non reçue')&&document.querySelector('#dictation-count').textContent.includes('attente')`, 'texte redevenu identique mais opération incertaine : aucune fausse confirmation');
        await check(`!document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'récupération disponible tant que la réponse reste incertaine');
        await click(button('retry'));await saved();await delay(850);
        const recoveredStored = await persisted();
        await check(`window.calls.length===3&&JSON.stringify(window.calls[0])===JSON.stringify(window.calls[1])&&window.calls[2].revision===window.calls[0].revision+1&&window.calls[2].copy===${JSON.stringify(confirmedBeforeRefusal)}&&document.querySelector('#dictation-count').textContent.includes('2 / 3')`, 'opération incertaine rejouée exactement avant de réenregistrer le texte revenu à la valeur confirmée');
        if(recoveredStored.revision!==revisionBeforeRefusal+2||recoveredStored.copy!==confirmedBeforeRefusal)throw Error('Lost response recovery state');
        await evaluate('window.fetch=window.realFetch');
        // A real access refusal, delayed while the user edits, must not release a new SAVE.
        const beforeAccessRefusal = await revision();
        await evaluate(`window.calls=[];window.release=null;window.fetch=async(url,options)=>{
            window.calls.push(JSON.parse(options.body));const response=await window.realFetch(url,{...options,headers:{...options.headers,'X-CSRF-TOKEN':'invalid'}});
            if(response.status!==403)throw Error('Expected real CSRF refusal');await new Promise(resolve=>window.release=resolve);return response;}`);
        await field('Copie refusée accès');await wait('window.release');await field('Nouvelle copie après refus accès');await evaluate('window.release()');await delay(850);
        await check(`window.calls.length===1&&document.querySelector('#dictation-save').textContent.includes('Session ou jeton expiré')&&document.querySelector('#dictation-count').textContent.includes('attente')`, '403 retardé : nouvelle saisie préservée, aucun déblocage automatique');
        await check(`!document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'récupération disponible après refus d’accès');
        if((await persisted()).revision!==beforeAccessRefusal)throw Error('Access refusal changed revision');
        await evaluate('window.fetch=window.realFetch');await click(button('retry'));await saved();
        await check(`!document.body.innerHTML.includes('L’école ouvre.')&&!document.body.innerHTML.includes('SECRET_CORRECTION_TEST')&&[...document.querySelectorAll('audio')].every(a=>a.paused)`, 'projection sans corrigé ; aucune lecture automatique');
        const unicode='\n😀 L’e\u0301cole ouvre.\nœ porte-monnaie\u00a0…';
        await field(unicode);await saved();await reload();
        await check(`document.querySelector('#dictation-copy').value===${JSON.stringify(unicode)}`, 'reprise exacte Unicode, accent décomposé, espace insécable et saut de ligne initial');
        await check(`document.querySelector('#dictation-count').textContent.includes('5 / 3')`, 'décompte serveur partagé, supérieur au nombre de référence');
        await field('abc');await saved();
        await evaluate(`document.querySelector('#dictation-copy').setSelectionRange(1,2)`);
        await click('[data-character="œ"]');
        await check(`document.querySelector('#dictation-copy').value==='aœc'&&document.activeElement.id==='dictation-copy'&&document.activeElement.selectionStart===2`, 'insertion remplace la sélection, conserve le curseur et rend le focus');
        await saved();
        // Hold the first successful response after the real server has committed it.
        await evaluate(`window.realFetch=window.fetch;window.calls=[];window.hold=true;window.fetch=async(...args)=>{window.calls.push(JSON.parse(args[1].body));const response=await window.realFetch(...args);if(window.hold){window.hold=false;await new Promise(resolve=>window.release=resolve);}return response;}`);
        await field('Première copie');await wait('window.release');
        await field('Dernière copie œ');await delay(850);
        await check(`window.calls.length===1&&!document.querySelector('#dictation-save').textContent.includes('Enregistré')&&document.querySelector('#dictation-count').textContent.includes('attente')`, 'une sauvegarde en vol ; ancien compteur signalé en attente');
        await evaluate('window.release()');await saved();
        await check(`window.calls.length===2&&window.calls[1].copy==='Dernière copie œ'&&document.querySelector('#dictation-copy').value==='Dernière copie œ'`, 'réponse retardée sans remplacement ; nouvelles frappes regroupées');
        // Lose a response only after committing; next request must replay byte-for-byte.
        await evaluate(`window.calls=[];window.lose=true;window.fetch=async(...args)=>{window.calls.push(JSON.parse(args[1].body));const response=await window.realFetch(...args);if(window.lose){window.lose=false;throw new TypeError('Lost response');}return response;}`);
        await field('Copie dont la réponse se perd');await wait(`document.querySelector('#dictation-save').textContent.includes('Confirmation non reçue')`);
        await field('Modifications après perte');await delay(750);
        await click(button('retry'));await saved();
        await check(`window.calls.length===3&&JSON.stringify(window.calls[0])===JSON.stringify(window.calls[1])&&window.calls[2].copy==='Modifications après perte'`, 'réponse perdue : rejeu strict puis nouvelles modifications, sans conflit artificiel');
        await evaluate('window.fetch=window.realFetch');
        // A real second tab loads its own revision, then autosaves independently.
        const second=await fetch(`${cdp}/json/new?about:blank`,{method:'PUT'}).then(r=>r.json());
        const secondSocket=new WebSocket(second.webSocketDebuggerUrl);
        await new Promise(resolve=>secondSocket.addEventListener('open',resolve,{once:true}));
        try {
            const loaded=new Promise((resolve,reject)=>{
                const timer=setTimeout(()=>reject(Error('Second tab navigation timeout')),15000);
                secondSocket.addEventListener('message',event=>{if(JSON.parse(event.data).method==='Page.loadEventFired'){clearTimeout(timer);resolve();}});
            });
            secondSocket.send(JSON.stringify({id:100,method:'Page.enable'}));
            secondSocket.send(JSON.stringify({id:101,method:'Page.navigate',params:{url:await evaluate('location.href')}}));
            await loaded;
            await new Promise((resolve,reject)=>{
                const timer=setTimeout(()=>reject(Error('Second tab timeout')),15000);
                secondSocket.addEventListener('message',event=>{
                    const data=JSON.parse(event.data);if(data.id!==1)return;clearTimeout(timer);
                    data.error||data.result?.exceptionDetails?reject(Error(JSON.stringify(data))):resolve();
                });
                secondSocket.send(JSON.stringify({id:1,method:'Runtime.evaluate',params:{awaitPromise:true,expression:`(async()=>{
                    for(let i=0;i<100;i++){if(document.querySelector('#dictation-copy')&&!document.querySelector('#dictation-copy').disabled)break;await new Promise(r=>setTimeout(r,100));}
                    const field=document.querySelector('#dictation-copy');field.value='Copie distante autre onglet';field.dispatchEvent(new Event('input',{bubbles:true}));
                    for(let i=0;i<100;i++){if(document.querySelector('#dictation-save').textContent==='Enregistré')return;await new Promise(r=>setTimeout(r,100));}throw Error('second save');
                })()`}}));
            });
        } finally { secondSocket.close();await fetch(`${cdp}/json/close/${second.id}`); }
        await wrapSave();await field('Copie locale initiale');await wait('window.release');
        await field('Copie locale à récupérer');await evaluate('window.release()');await wait(`document.querySelector('#dictation-save').textContent.includes('Conflit')`);await delay(850);
        await check(`window.calls.length===1&&window.responses[0].status===409&&document.querySelector('#dictation-count').textContent.includes('attente')`, '409 retardé après modification locale : aucun nouvel envoi ni nouvelle révision');
        await evaluate('window.fetch=window.realFetch');
        await check(`document.querySelector('#dictation-copy').value==='Copie locale à récupérer'&&document.querySelector('[data-dictation-writing-target="retry"]').hidden`, 'conflit : saisie locale préservée, aucune nouvelle révision automatique');
        await check(`!document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'récupération proposée dans le conflit');
        const downloadPath=fs.mkdtempSync(require('node:path').resolve('var/dictation-lot3-downloads-'));
        await send('Browser.setDownloadBehavior',{behavior:'allow',downloadPath});
        const beforeDownload=await persisted();
        const requestsBeforeDownload=await evaluate('window.calls.length');
        await click(button('recover'));
        for(let i=0;i<50&&!fs.existsSync(downloadPath+'/ma-copie.txt');i++)await delay(100);
        if(fs.readFileSync(downloadPath+'/ma-copie.txt','utf8')!=='Copie locale à récupérer')throw Error('recovery download');
        if(JSON.stringify(await persisted())!==JSON.stringify(beforeDownload)||await evaluate('window.calls.length')!==requestsBeforeDownload)throw Error('Download wrote a copy');
        console.log('OK récupération de la saisie en fichier texte');
        await send('Page.navigate',{url:origin+'/dictees/1'});await delay(400);
        if(!dialogs.includes('beforeunload'))throw Error('missing unload protection');
        await check(`document.querySelector('#dictation-copy').value==='Copie locale à récupérer'`, 'sortie refusée : copie locale conservée');
        allowLeave=true;await reload();allowLeave=false;
        await check(`document.querySelector('#dictation-copy').value==='Copie distante autre onglet'`, 'rechargement explicite : copie distante intacte');
        await evaluate(`window.realFetch=window.fetch;window.fetch=async()=>new Response(JSON.stringify({message:'Session expirée'}),{status:401,headers:{'Content-Type':'application/json'}})`);
        await field('Saisie pendant expiration');await wait(`document.querySelector('#dictation-save').textContent.includes('Session expirée')`);
        await check(`document.querySelector('#dictation-copy').value==='Saisie pendant expiration'`, 'session expirée : aucune perte de saisie');
        await evaluate('window.fetch=window.realFetch');await click(button('retry'));await saved();
        await field('a '.repeat(751));await wait(`document.querySelector('#dictation-save').textContent.includes('Limite de mots')`);
        await check(`document.querySelector('#dictation-copy').value.length===1502`, 'limite serveur : saisie conservée intégralement');
        await field('Texte corrigé');await saved();
        await check(`document.querySelector('[data-dictation-writing-target="recovery"]').hidden`, 'enregistrement rétabli : téléchargement de nouveau masqué');
        await click(button('replay'));await wait(`document.querySelector('[data-dictation-writing-target="audioStatus"]').textContent.includes('Fin du segment 1')`);
        await icons('fin de segment : icône lecture restaurée');
        await check(`document.querySelector('[data-dictation-writing-target="writing"]').paused&&Math.abs(document.querySelector('[data-dictation-writing-target="writing"]').currentTime-.6)<.08&&document.querySelector('#dictation-segment').value==='0'`, 'arrêt à la borne de fin, sans segment suivant automatique');
        await click(button('next'));await click(button('replay'));await click(button('full'));
        await check(`document.querySelector('[data-dictation-writing-target="writing"]').paused`, 'écoute complète exclusive après segment');
        await icons('changement de piste : icônes et infobulles synchronisées');
        await click(button('next'));await click(button('replay'));await click(button('previous'));await click(button('replay'));
        await wait(`document.querySelector('[data-dictation-writing-target="audioStatus"]').textContent.includes('Fin du segment 2')`);
        await check(`document.querySelector('[data-dictation-writing-target="complete"]').paused&&document.querySelector('#dictation-segment').value==='1'`, 'changements rapides : une seule piste, bornes du segment courant');
        await icons('changements rapides : icônes toujours présentes');
        // Focus and a non-empty selection stay untouched by each advertised shortcut.
        await click('#dictation-copy');
        await evaluate(`document.querySelector('#dictation-copy').setSelectionRange(2,8,'backward');window.selectionBefore={text:document.querySelector('#dictation-copy').value,start:2,end:8,direction:'backward'};document.querySelector('[data-dictation-writing-target="writing"]').playbackRate=.25;window.shortcutEvents=[];document.addEventListener('keydown',e=>window.shortcutEvents.push({key:e.key,code:e.code,alt:e.altKey,ctrl:e.ctrlKey,shift:e.shiftKey,prevented:e.defaultPrevented}));`);
        const selection = label => check(`document.activeElement.id==='dictation-copy'&&document.querySelector('#dictation-copy').value===window.selectionBefore.text&&document.querySelector('#dictation-copy').selectionStart===2&&document.querySelector('#dictation-copy').selectionEnd===8&&document.querySelector('#dictation-copy').selectionDirection==='backward'`,label);
        await keypress('F8','F8',2,119);
        await check(`document.querySelector('#dictation-segment').value==='2'&&window.shortcutEvents.at(-1).prevented`, 'Ctrl+F8 : segment suivant pendant la saisie');await selection('suivant : texte, sélection et focus conservés');
        await keypress('F8','F8',8,119);
        await check(`document.querySelector('#dictation-segment').value==='1'&&window.shortcutEvents.at(-1).prevented`, 'Maj+F8 : segment précédent pendant la saisie');await selection('précédent : texte, sélection et focus conservés');
        await keypress('F8','F8',0,119);await wait(`!document.querySelector('[data-dictation-writing-target="writing"]').paused`);
        await icons('F8 : lecture effective, icône pause et nom accessible');await selection('lecture : texte, sélection et focus conservés');
        await keypress('F8','F8',0,119);
        await check(`document.querySelector('[data-dictation-writing-target="writing"]').paused&&window.shortcutEvents.at(-1).prevented`, 'F8 : pause pendant la saisie');await icons('pause : icône lecture et infobulle restaurées');await selection('pause : sélection conservée');
        await keypress('ArrowRight','',9,39);
        await check(`document.querySelector('#dictation-segment').value==='2'&&window.shortcutEvents.at(-1).prevented`, 'ancien raccourci : repli sur key quand code est vide');await selection('repli key : sélection conservée');
        await keypress('Unidentified','F8',8,119);
        await check(`document.querySelector('#dictation-segment').value==='1'&&window.shortcutEvents.at(-1).prevented`, 'key non identifié : repli sur code pour Maj+F8');await selection('repli code : sélection conservée');
        await keypress('F8','F8',2,119);
        await evaluate(`document.querySelector('[data-dictation-writing-target="writing"]').playbackRate=1`);
        // Ordinary text/editing combinations are not claimed by the controller.
        await keypress('ArrowLeft','ArrowLeft',2,37);
        await check(`!window.shortcutEvents.at(-1).prevented&&document.querySelector('#dictation-segment').value==='2'`, 'Ctrl+flèche conserve sa commande habituelle d’édition');
        await send('Input.insertText',{text:' z'});await saved();
        await check(`document.querySelector('#dictation-copy').value.includes(' z')`, 'frappe ordinaire non interceptée');
        await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
        await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
        await check(`document.activeElement.dataset.character==='À'`, 'ordre de tabulation : saisie puis caractères spéciaux');
        await send('Input.dispatchKeyEvent',{type:'rawKeyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
        await send('Input.dispatchKeyEvent',{type:'char',text:'\r',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
        await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
        await saved();
        await check(`document.activeElement.id==='dictation-copy'&&document.querySelector('#dictation-copy').value.includes('À')`, 'insertion au clavier avec Entrée et retour au texte');
        await click(button('full'));await wait(`!document.querySelector('[data-dictation-writing-target="complete"]').paused`);
        await evaluate(`document.querySelector('[data-dictation-writing-target="complete"]').pause()`);await delay(50);await icons('pause externe du média : icône synchronisée sans clic sur le bouton');
        await click(button('full'));await wait(`document.querySelector('[data-dictation-writing-target="complete"]').ended`);await icons('fin naturelle de la piste complète : icône lecture');
        for(const width of [1440,390]) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<500});
            for(const theme of ['light','dark']) {
                await evaluate(`document.documentElement.setAttribute('data-bs-theme','${theme}')`);await delay(150);
                await check('document.documentElement.scrollWidth<=window.innerWidth',`aucun débordement horizontal ${width}px ${theme}`);
                await check(`[...document.querySelectorAll('.dictation-audio-toggle, #dictation-segment')].every(el=>{const r=el.getBoundingClientRect();return r.width>=44&&r.height>=44})&&document.querySelector('h3').textContent==='Écoute complète'`, `intitulé conservé et cibles audio 44 × 44 : ${width}px ${theme}`);
                await evaluate(`document.querySelector('[data-dictation-writing-target="previous"]').focus()`);
                await keypress('Tab','Tab',0,9);
                await check(`document.activeElement.matches('.dictation-audio-toggle')&&document.activeElement.matches(':focus-visible')&&parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=3`, `focus clavier visible : ${width}px ${theme}`);
                const shot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
                fs.writeFileSync(`var/dictation-lot3-${width}-${theme}.png`,Buffer.from(shot.data,'base64'));
            }
        }
        const oldDialogs=dialogs.length;
        await reload();
        if(dialogs.length!==oldDialogs)throw Error('clean page still protected');
        await evaluate(`const select=document.querySelector('#dictation-segment');select.value='2';select.dispatchEvent(new Event('change',{bubbles:true}))`);
        await click(button('replay'));
        await wait(`document.querySelector('[data-dictation-writing-target="audioStatus"]').textContent.includes('Fin du segment 3')`);
        await check(`Math.abs(document.querySelector('[data-dictation-writing-target="writing"]').currentTime-1.8)<.08`, 'sélection avant chargement audio : bornes du troisième segment conservées');
        await evaluate(`document.querySelector('[data-dictation-writing-target="writing"]').src='/dictees/tentatives/0/audio/writing'`);
        await click(button('replay'));
        await wait(`document.querySelector('[data-dictation-writing-target="audioStatus"]').textContent.includes('impossible')`);
        await check(`document.querySelector('#dictation-copy').value.length>0&&[...document.querySelectorAll('audio')].every(a=>a.paused)`, 'erreur audio visible, lecture arrêtée et saisie intacte');
        await icons('erreur audio : icônes lecture, aucune suppression du SVG ou pictogramme');
        if(errors.length)throw Error(errors.join('\n'));
        console.log('OK état propre après sauvegarde ; aucune exception JavaScript');
    } finally {
        allowLeave=true;await send('Page.close').catch(()=>{});ws.close();
        for(const item of pending.values())clearTimeout(item.timer);
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
