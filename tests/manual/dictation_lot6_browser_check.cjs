// Real Chrome/CDP; only the isolated automated server on 8815.
const fs = require('node:fs');
const origin = 'http://127.0.0.1:8815';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
    const tab = await fetch('http://127.0.0.1:8816/json/new?about:blank', {method:'PUT'}).then(r=>r.json());
    const ws = new WebSocket(tab.webSocketDebuggerUrl);
    await new Promise(resolve=>ws.addEventListener('open',resolve,{once:true}));
    let sequence=0;
    const pending=new Map(), errors=[];
    const send=(method,params={})=>new Promise((resolve,reject)=>{
        const id=++sequence;
        const timer=setTimeout(()=>{pending.delete(id);reject(Error('CDP timeout: '+method));},20000);
        pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params}));
    });
    ws.addEventListener('message',event=>{
        const data=JSON.parse(event.data);
        if(data.method==='Page.javascriptDialogOpening')send('Page.handleJavaScriptDialog',{accept:true}).catch(()=>{});
        if(data.method==='Runtime.exceptionThrown')errors.push(data.params.exceptionDetails.exception?.description||data.params.exceptionDetails.text);
        if(data.id&&pending.has(data.id)){const p=pending.get(data.id);pending.delete(data.id);clearTimeout(p.timer);data.error?p.reject(Error(data.error.message)):p.resolve(data.result);}
    });
    const evaluate=async expression=>{
        const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
        if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);
        return r.result.value;
    };
    const wait=async expression=>{
        for(let i=0;i<150;i++){try{if(await evaluate(`Boolean(${expression})`))return;}catch(e){if(!/context|document|Cannot read properties/i.test(e.message))throw e;}await delay(100);}
        throw Error('Timeout: '+expression+'\n'+await evaluate('document.body.innerText.slice(-2500)'));
    };
    const check=async(expression,label)=>{if(!await evaluate(expression))throw Error(label);console.log('OK '+label);};
    const click=async selector=>{
        await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center',behavior:'instant'})`);await delay(150);
        const point=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return{x:r.x+r.width/2,y:r.y+r.height/2}})()`);
        await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});
        await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});
    };
    const navigate=async path=>{await send('Page.navigate',{url:origin+path});};
    const field=async value=>evaluate(`(()=>{const e=document.querySelector('#dictation-copy');e.value=${JSON.stringify(value)};e.dispatchEvent(new Event('input',{bubbles:true}));})()`);
    const phase=state=>wait(`document.querySelector('[data-dictation-writing-phase-value]')?.dataset.dictationWritingPhaseValue===${JSON.stringify(state)}&&!document.querySelector('#dictation-copy').disabled`);
    try {
        await send('Page.enable');await send('Runtime.enable');
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await navigate('/courses/lot6');
        await wait('document.querySelector(".program-course-content .dictation-journey-entry")');
        await wait('document.readyState === "complete"');
        for(const width of [1440,390])for(const theme of ['light','dark']){
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width===390});
            await evaluate(`document.documentElement.dataset.bsTheme=${JSON.stringify(theme)}`);
            await check(`document.documentElement.scrollWidth<=innerWidth`,`${width}px ${theme} : aucun débordement`);
            await check(`(()=>{const list=document.querySelector('.program-course-content ol');return list.firstElementChild.dataset.dictationPosition==='before'&&list.lastElementChild.dataset.dictationPosition==='after'&&!list.lastElementChild.querySelector('a')})()`,`${width}px ${theme} : entrées avant/après et reprise indisponible`);
            await evaluate(`document.querySelector('.program-course-content [data-dictation-position="before"] a').focus()`);
            await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
            await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
            await evaluate(`document.querySelector('.program-course-content [data-dictation-position="before"] a').focus()`);
            await check(`document.activeElement.matches('[data-dictation-position="before"] a')&&getComputedStyle(document.activeElement).outlineStyle!=='none'`,`${width}px ${theme} : focus visible`);
            if(width===390){
                await click('.course-mobile-menu-toggle');
                await wait(`document.querySelector('.course-mobile-menu-toggle').getAttribute('aria-expanded')==='true'`);
                await check(`!!document.querySelector('.course-mobile-menu-panel [data-dictation-position="before"] a')`,'menu mobile : dictée accessible');
                await click('.course-mobile-menu-toggle');
            }
            await evaluate(`document.querySelector('.program-course-content').scrollIntoView({block:'center',behavior:'instant'})`);
            fs.writeFileSync(`var/dictation-lot6-${width}-${theme}.png`,Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
        }
        await navigate('/courses/lot6/base/libre');
        await wait(`document.querySelector('.course-navigation a[href^="/dictees/"]')`);
        await wait('document.readyState === "complete"');
        await check(`document.documentElement.scrollWidth<=innerWidth`,'navigation mobile : titre long du cours verrouillé sans débordement');
        await click('.course-navigation a[href^="/dictees/"]');
        await wait(`document.querySelector('form[action$="/commencer"]')`);
        await check(`document.body.textContent.includes('Aucun score minimum')&&document.body.textContent.includes('cours privés')`,'navigation précédent : pédagogie et prérequis');
        await navigate('/courses/lot6/section/cours');
        await wait(`document.querySelector('form[action$="/commencer"]')`);
        await check(`location.pathname.startsWith('/dictees/')&&!document.body.textContent.includes('Contenu de cours synthétique')`,'URL directe orientée vers le prérequis');
        await click('form[action$="/commencer"] button');await phase('writing');
        await field('Erreur.');
        await click('[data-action="dictation-writing#submit"]');await phase('self_correction');
        await navigate('/courses/lot6/section');
        await wait(`document.querySelector('[data-dictation-position="before"]')?.textContent.includes('Poursuivre l’autocorrection')`);
        await check(`!document.body.textContent.includes('SECRET_CORRECTION_TEST')`,'évaluation seule : autocorrection requise, aucun corrigé');
        await click('.main-content .card [data-dictation-position="before"] a');await phase('self_correction');
        await click('[data-action="dictation-writing#submit"]');
        await wait(`document.querySelector('#dictation-closed-copy')`);
        await click('a[href="/courses/lot6/section"]');
        await wait(`document.querySelector('.main-content .card a[href="/courses/lot6/section/cours"]')`);
        await click('.main-content .card a[href="/courses/lot6/section/cours"]');
        await wait(`document.querySelector('#completion-button button')`);
        await check(`document.body.textContent.includes('Contenu de cours synthétique')`,'clôture avec erreurs : cours ouvert');
        await click('#completion-button button');
        await wait(`document.querySelector('#completion-button button')?.textContent.includes('Terminé')`);
        await navigate('/courses/lot6/section');
        await wait(`document.querySelector('.main-content .card [data-dictation-position="after"] a')`);
        await click('.main-content .card [data-dictation-position="after"] a');
        await wait(`document.querySelector('[data-dictation-phase-target="button"]')&&!document.querySelector('[data-dictation-phase-target="button"]').disabled`);
        await click('[data-dictation-phase-target="button"]');await phase('resuming');
        await check(`document.querySelector('#dictation-copy').value==='Erreur.'`,'reprise de la même copie après validation');
        await field('L’école ouvre.');await click('[data-action="dictation-writing#submit"]');
        await wait(`document.querySelector('#dictation-corrected-text')`);
        await check(`document.querySelector('#dictation-corrected-text').textContent==='L’école ouvre.'`,'finalisation : corrigé de la version d’origine');
        await navigate('/courses/lot6/section');
        await wait(`document.querySelector('.main-content .card [data-dictation-position="after"] a')?.textContent.includes('résultat définitif')`);
        if(errors.length)throw Error(errors.join('\n'));
        console.log('OK lot 6 : parcours intégré complet, desktop/mobile, clair/sombre.');
    } finally {
        await send('Page.close').catch(()=>{});ws.close();
        for(const p of pending.values())clearTimeout(p.timer);
        fs.writeFileSync('var/dictation-6-browser.stop','stop');
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
