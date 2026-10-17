// Destructive recipe: only the disposable server serve_dictation_lot2b_browser.php.
const fs = require('node:fs');
const origin = `http://127.0.0.1:${Number(process.argv[2] || 8815)}`;
const cdp = `http://127.0.0.1:${Number(process.argv[3] || 8816)}`;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
    const tab = await fetch(`${cdp}/json/new?about:blank`, {method:'PUT'}).then(r=>r.json());
    const ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve=>ws.addEventListener('open',resolve,{once:true}));
    let sequence=0, confirm=false, leave=false;
    const pending=new Map(), dialogs=[], errors=[];
    const send=(method,params={})=>new Promise((resolve,reject)=>{
        const id=++sequence, timer=setTimeout(()=>{pending.delete(id);reject(Error('CDP timeout '+method));},20000);
        pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params}));
    });
    ws.addEventListener('message',event=>{
        const data=JSON.parse(event.data);
        if(data.method==='Page.javascriptDialogOpening') { dialogs.push(data.params);send('Page.handleJavaScriptDialog',{accept:data.params.type==='confirm'?confirm:leave}).catch(()=>{}); }
        if(data.method==='Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.exception?.description||data.params.exceptionDetails.text);
        if(data.id&&pending.has(data.id)){const item=pending.get(data.id);pending.delete(data.id);clearTimeout(item.timer);data.error?item.reject(Error(data.error.message)):item.resolve(data.result);}
    });
    const evaluate=async expression=>{
        const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
        if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);
        return result.result.value;
    };
    const wait=async expression=>{
        for(let i=0;i<150;i++){try{if(await evaluate(`Boolean(${expression})`))return;}catch(e){if(!/context|document|Cannot read properties/i.test(e.message))throw e;}await delay(100);}
        throw Error('Timeout '+expression+'\n'+await evaluate('document.body.innerText.slice(-2000)'));
    };
    const check=async(expression,label)=>{if(!await evaluate(expression))throw Error(label);console.log('OK '+label);};
    const load=async url=>{leave=true;await evaluate('window.oldPage=true');await send('Page.navigate',{url:origin+url});await wait('!window.oldPage && document.readyState==="complete"');leave=false;await delay(200);};
    const click=async selector=>{
        await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center',behavior:'instant'})`);await delay(200);
        const point=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return{x:r.x+r.width/2,y:r.y+r.height/2}})()`);
        await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});
        await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});
    };
    const type=async(selector,value)=>evaluate(`(()=>{const f=document.querySelector(${JSON.stringify(selector)});f.value=${JSON.stringify(value)};f.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    const enter=async()=>{await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,text:'\r'});await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});};
    const instrument=()=>evaluate(`window.calls=[];window.realFetch=window.fetch;window.fetch=async(...args)=>{const call={url:String(args[0]),body:args[1]?.body};window.calls.push(call);const response=await window.realFetch(...args);call.status=response.status;return response;};`);
    const clone=()=>evaluate(`(async()=>{const page=await fetch('/admin/dictees/versions/1').then(r=>r.text());const doc=new DOMParser().parseFromString(page,'text/html');const f=doc.querySelector('form[action$="/clone"]');const response=await fetch(f.action,{method:'POST',body:new FormData(f)});if(!response.ok)throw Error('Clone failed');return new URL(response.url).pathname})()`);
    const deletion='[data-delete-draft]';
    try {
        await send('Page.enable');await send('Runtime.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await load('/admin/dictees/versions/1');
        await check('!document.querySelector("[data-delete-draft]")','version publiée : action absente');
        const third=await clone(), fourth=await clone();
        await load('/admin/dictees/1');await wait('document.querySelector("[data-delete-draft]") && !document.querySelector("[data-delete-draft]").disabled');
        await check('document.querySelectorAll("[data-delete-draft]").length===3','liste : trois brouillons éligibles, aucune action sur la publiée');
        for(const theme of ['light','dark']){
            await evaluate(`document.documentElement.setAttribute('data-bs-theme',${JSON.stringify(theme)});document.body.classList.toggle('ea-dark-scheme',${theme==='dark'});document.body.classList.toggle('ea-light-scheme',${theme==='light'});document.querySelector('form[data-version-number="2"] button').focus()`);
            await enter();await delay(200);
            if(dialogs.at(-1)?.message!=='Supprimer définitivement le brouillon version 2 ?\nCette action est irréversible.')throw Error('Wrong confirmation');
            await check('document.activeElement.matches("[data-delete-draft]") && location.pathname==="/admin/dictees/1" && parseFloat(getComputedStyle(document.activeElement).outlineWidth)>0',`annulation clavier ${theme} : page conservée, focus rendu et visible`);
            const shot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(`var/dictation-delete-list-${theme}.png`,Buffer.from(shot.data,'base64'));
        }
        await load('/admin/dictees/versions/2');await wait('!document.querySelector("[data-delete-draft]").disabled');
        await click('#form_text');await type('#form_text','Saisie locale non enregistrée.');await instrument();
        const revision=await evaluate('document.querySelector("#form_revision").value');
        await click(deletion);await delay(200);
        if(!dialogs.at(-1).message.includes('modifications non enregistrées'))throw Error('Missing unsaved warning');
        await check('document.querySelector("#form_text").value==="Saisie locale non enregistrée." && window.calls.length===0 && document.activeElement.matches("[data-delete-draft]")','annulation avec saisie : aucune requête, texte et focus conservés');
        await evaluate(`window.token=document.querySelector('form[action$="/delete"] input[name="_token"]').value;document.querySelector('form[action$="/delete"] input[name="_token"]').value='wrong'`);
        confirm=true;await click(deletion);await wait('document.querySelector("[data-dictation-editor-target=status]").textContent.includes("Jeton")');
        await check('window.calls.length===1 && window.calls[0].status===403 && document.querySelector("#form_text").value==="Saisie locale non enregistrée." && !document.querySelector("#form_text").disabled','refus CSRF réel : saisie conservée, édition rétablie');
        await evaluate(`document.querySelector('form[action$="/delete"] input[name="_token"]').value=window.token`);
        await evaluate(`(async()=>{const f=document.querySelector('form[name="form"]');const data=new FormData(f);data.set('form[text]','L’école ouvre.');data.set('form[title]','Titre concurrent');data.set('form[save]','');const r=await window.realFetch(location.href,{method:'POST',body:data});if(!r.ok)throw Error('Concurrent save failed');})()`);
        await click(deletion);await wait('document.querySelector("[data-dictation-editor-target=status]").textContent.includes("La version a changé")');
        await check(`window.calls.at(-1).status===409 && document.querySelector('#form_revision').value===${JSON.stringify(revision)} && document.querySelector('#form_text').value==='Saisie locale non enregistrée.'`,'révision concurrente : refus 409, texte et révision affichée préservés');
        confirm=false;const beforeLeave=dialogs.length;await click('a[href="/admin/dictees/1"]');await delay(300);
        if(dialogs.length!==beforeLeave+1||dialogs.at(-1).type!=='beforeunload')throw Error('Dirty navigation guard lost');
        await check('location.pathname==="/admin/dictees/versions/2"','annulation navigation après refus : page conservée');
        await load('/admin/dictees/versions/2');await type('#form_title','');await click('#form_save');await delay(200);
        // HTML required validation prevents the request: force a real server validation response.
        await evaluate(`document.querySelector('form[name="form"]').noValidate=true`);await click('#form_save');
        await wait('document.querySelector("[data-dictation-editor-unsaved-value=true]") && !document.querySelector("[data-delete-draft]").disabled');
        await click(deletion);await delay(100);
        if(!dialogs.at(-1).message.includes('modifications non enregistrées'))throw Error('Dirty after 422 lost');
        await check('document.querySelector("#form_title").value===""','annulation après validation 422 : saisie refusée conservée');
        await load('/admin/dictees/versions/2');await type('#form_text','Dernière saisie explicitement abandonnée.');
        const deleted=await evaluate(`(()=>{const f=document.querySelector('form[action$="/delete"]');return{url:f.action,body:[...new FormData(f)]}})()`);
        const beforeSuccess=dialogs.length;confirm=true;await click(deletion);await wait('location.pathname==="/admin/dictees/1" && document.querySelector("table")');
        if(dialogs.length!==beforeSuccess+1||dialogs.at(-1).type!=='confirm')throw Error('Redundant confirmation on success');
        await check(`!document.querySelector('form[data-version-number="2"]') && document.querySelector('form[data-version-number="3"]') && document.body.innerText.includes('Le brouillon a été supprimé')`,'suppression confirmée : redirection et message, autres versions conservées');
        await check(`(async()=>{const r=await fetch(${JSON.stringify(deleted.url)},{method:'POST',body:new URLSearchParams(${JSON.stringify(deleted.body)})});return r.status===404})()`,'requête répétée : 404 maîtrisé');
        await check(`(async()=>{const r=await fetch('/admin/dictees/versions/1/audio/writing/listen',{headers:{Range:'bytes=0-99'}});return r.status===206&&(await r.arrayBuffer()).byteLength===100})()`,'audio partagé de la publiée toujours lisible');
        const fifth=await clone();await load(fifth);
        await check(`document.querySelector('form[data-version-number="5"]')`,'duplication après trou : version 5 créée');
        await load(fourth);await type('#form_text','Texte local après publication concurrente.');
        await evaluate(`(async()=>{const html=await fetch(location.href).then(r=>r.text());const doc=new DOMParser().parseFromString(html,'text/html');const data=new FormData(doc.querySelector('form[name="form"]'));data.set('form[publish]','');const r=await fetch(location.href,{method:'POST',body:data});if(!r.ok)throw Error('Publication failed');})()`);
        await click(deletion);await wait('document.querySelector("[data-dictation-editor-target=status]").textContent.includes("La version a changé")');
        await check('document.querySelector("#form_text").value==="Texte local après publication concurrente."','publication concurrente : suppression refusée, saisie locale conservée');
        await load('/admin/dictees/1');confirm=false;await type('#form_title','Nouveau brouillon non enregistré');await click('form[data-version-number="3"] button');
        await check('document.querySelector("#form_title").value==="Nouveau brouillon non enregistré"','annulation dans la liste : saisie du nouveau brouillon conservée');
        confirm=true;await click('form[data-version-number="3"] button');await wait(`document.readyState==='complete' && document.querySelector('form[data-version-number="5"]') && !document.querySelector('form[data-version-number="3"]')`);
        await check(`document.querySelector('form[data-version-number="5"]') && !document.querySelector('form[data-version-number="4"]')`,'suppression depuis la liste, publiée et autre brouillon conservés');
        if(errors.length)throw Error(errors.join('\n'));
        console.log('OK recette suppression complète, confirmations natives CDP et aucune exception JavaScript');
    } finally {leave=true;await send('Page.close').catch(()=>{});ws.close();for(const item of pending.values())clearTimeout(item.timer);}
})().catch(error=>{console.error(error);process.exitCode=1;});
