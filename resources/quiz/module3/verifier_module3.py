"""Contrôle en lecture seule. Python 3 : python verifier_module3.py [chemin/Module_3_quiz.docx]"""
from pathlib import Path
import collections as co, json, hashlib, sys, zipfile
from xml.etree import ElementTree as ET
def check(ok,msg):
    if not ok: raise ValueError(msg)
def h(x):return hashlib.sha256(json.dumps(x,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()
d=Path(__file__).resolve().parent; f=d/'module3_quiz_manifest.json'; m=json.loads(f.read_bytes()); ss=m['sources'];cc=m['copies'];qs=m['quizzes'];by={s['source_key']:s for s in ss}
check([s['original_number'] for s in ss]==list(range(1,113)) and len(by)==112,'Inventaire')
check(len({' '.join(s['original']['text'].casefold().split()) for s in ss})==112,'Doublon après normalisation')
check({s['original_number'] for s in ss if s['status']=='future_reserve_candidate'}=={11,18,33,41,43,44,58,72,90,95,97,108},'12 candidates')
check({s['original_number'] for s in ss if s['status']=='other_pedagogical_use'}=={54,112},'Autre usage')
check(len(cc)==len({x['copy_key'] for x in cc})==168 and len(qs)==3,'Copies / quiz')
mult=co.Counter(x['source_key'] for x in cc)
check(co.Counter(mult.values())=={2:70,1:28},'Multiplicités')
for s in ss:
    k=s['source_key'];n=s['original_number'];c=s['candidate'];o=s['original']
    check(k==f'module3_docx_{n:03}' and h(o)==s['original_sha256'],k)
    check((s['status']=='retained')==(c is not None)==(mult[k]>0),k)
    check(s['assignments']==[{k:v for k,v in x.items() if k not in ('source_key','payload_sha256')} for x in cc if x['source_key']==s['source_key']],k)
    if c is None:check(bool(s['reason']) and not s['assignments'],k);continue
    ok=c['contains_error']; ans=c['answers'];check(type(ok) is bool and not c['multiple'] and c['theme']==s['theme'] and c['error_count']==int(ok),k)
    check(ans==[dict(content='0 erreur',correct=not ok,position=0),dict(content='1 erreur',correct=ok,position=1)] and o['answers'][int(ok)].strip().endswith(', vrai'),k)
    text=c['text'];ed=c['correction_edits'];check(len(ed)==int(ok),k)
    for e in ed:
        x=e['incorrect_fragment'];check(text.count(x)==e['occurrences']==1,k);text=text.replace(x,e['correct_fragment'])
    check(text==c['corrected_text'] and ok==(c['text']!=text) and c['explanation'].strip(),k)
check([q['quiz_key'] for q in qs]==['module3_test1','module3_test2','module3_general'],'Clés des quiz')
for q,(ni,nr) in zip(qs,[(10,25),(12,23),(20,78)]):
    k=q['quiz_key'];xs=[x for x in cc if x['quiz_key']==k];check(len(xs)==ni+nr and [x['position'] for x in xs]==list(range(ni+nr)),k)
    check(q['renewal_enabled'] is False and q['error_question_quota'] is None,k)
    check(q['initial_source_keys']==[x['source_key'] for x in xs[:ni]],k)
    check([x['role'] for x in xs]==['initial']*ni+['reserve']*nr,k)
    for x in xs:
        s=by[x['source_key']]; c=s['candidate'];p={a:c[a] for a in ('title','text','explanation','theme','multiple','contains_error','answers')};p.update(position=x['position'],reserve=x['role']=='reserve')
        check(x['copy_key']==k+'::'+s['source_key'] and h(p)==x['payload_sha256'],x['copy_key'])
    rows=q['theme_matrix']; check(len({r['theme'] for r in rows})==len(rows) and {r['theme'] for r in rows}=={by[x['source_key']]['theme'] for x in xs},k)
    for r in rows:
        for role in ['initial','reserve']:
            check(r[role]==[by[x['source_key']]['original_number'] for x in xs if x['role']==role and by[x['source_key']]['theme']==r['theme']],k)
        check(0<r['per_attempt']==len(r['initial'])<=len(r['reserve']) and r['min_repeat_vs_previous']==0,k)
source_check='non fourni'
if len(sys.argv)>1:
    doc=Path(sys.argv[1]);check(hashlib.sha256(doc.read_bytes()).hexdigest()==m['source_document']['sha256'],'SHA du Word')
    w='{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
    with zipfile.ZipFile(doc) as z:root=ET.fromstring(z.read('word/document.xml'))
    lines=[''.join(t.text or '' for t in p.iter(w+'t')) for p in root.iter(w+'p')];lines=[t for t in lines if t.strip()]
    check(len(lines)==1121,'Paragraphes Word')
    for i,s in enumerate(ss):
        heading,_,theme,_,text,_,a,b,_,expl=lines[1+10*i:11+10*i]
        check(heading.strip()==f'Question {i+1}' and s['original']==dict(theme=theme,text=text,answers=[a,b],explanation=expl),s['source_key'])
    source_check='112 entrées identiques au Word : numéro, thème, phrase, réponses, explication.'
print(json.dumps({'ok':True,'source_check':source_check,'manifest_sha256':hashlib.sha256(f.read_bytes()).hexdigest(),'counts':m['counts'],'limits':'Contrôle documentaire ; aucune base ni exécution du moteur Symfony.'},ensure_ascii=False,indent=2))
