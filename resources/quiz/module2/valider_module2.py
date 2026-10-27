#!/usr/bin/env python3
"""Contrôle éditorial autonome : lecture de fichiers uniquement, aucune base.

Usage : python valider_module2.py [module2_quiz_manifest.json] [--source-docx ORIGINAL.docx]
La simulation reproduit le sélecteur inspecté pour quota NULL et positions uniques.
Ce contrôle ne remplace ni la relecture linguistique, ni la recette Symfony/MySQL.
"""
import argparse
import collections
import hashlib
import json
import re
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()

def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()

def require(condition, message):
    if not condition:
        raise ValueError(message)

def payload(source, copy):
    c = source['candidate']
    p = {f: c[f] for f in ('title', 'text', 'explanation', 'theme', 'multiple', 'contains_error', 'answers')}
    p.update(position=copy['position'], reserve=copy['role'] == 'reserve')
    return p

def simulate(quiz, copies, sources):
    bank = [c for c in copies if c['quiz_key'] == quiz['quiz_key']]
    current = tuple(quiz['initial_source_keys'])
    visited = {}; observed = set(); states = []
    slots = [sources[s]['theme'] for s in current]
    quotas = collections.Counter(slots)
    for _ in range(10000):
        if current in visited:
            return {'quiz_key': quiz['quiz_key'], 'assumptions': 'Bank unchanged; NULL error quota; unique positions as manifest; renewal enabled only in this in-memory simulation.',
                    'attempt_count': len(current), 'unique_sources_observed_before_cycle': len(observed),
                    'cycle_start_attempt': visited[current] + 1, 'cycle_length': len(states) - visited[current],
                    'first_attempt': states[0], 'second_attempt': states[1] if len(states) > 1 else states[0],
                    'not_selected_with_this_unchanged_configuration': [c['source_key'] for c in bank if c['source_key'] not in observed]}
        require(len(set(current)) == quiz['expected_initial_count'], 'Duplicate in simulated attempt')
        require(collections.Counter(sources[s]['theme'] for s in current) == quotas, 'Theme quota changed')
        visited[current] = len(states); states.append(list(current)); observed.update(current)
        ordered = sorted(bank, key=lambda c: (c['source_key'] in current, c['position']))
        selected = {t: [c['source_key'] for c in ordered if sources[c['source_key']]['theme'] == t][:n] for t,n in quotas.items()}
        next_attempt = tuple(selected[t].pop(0) for t in slots)
        theoretical = sum(max(0, 2*n-sum(sources[c['source_key']]['theme']==t for c in bank)) for t,n in quotas.items())
        require(len(set(current) & set(next_attempt)) == theoretical, 'Reuse not minimal')
        current = next_attempt
    raise ValueError('Cycle not established within simulation limit')

def validate(path, docx=None):
    b = path.read_bytes(); m = json.loads(b)
    require(m['manifest_format'] == 'orthogram.quiz.editorial' and m['schema_version'] == 1, 'Format')
    require(m['module_key'] == 'module2', 'Wrong module')
    src = m['sources']; cps = m['copies']; qs = m['quizzes']
    require([s['original_number'] for s in src] == list(range(1,117)), 'Source numbering')
    require(len({s['source_key'] for s in src}) == 116, 'Source key collision')
    source_by_key = {s['source_key']:s for s in src}
    hold = {s['original_number'] for s in src if s['status']=='on_hold'}
    require(hold == {2,44,45,75,80,111}, 'Validated holds changed')
    require(len({c['copy_key'] for c in cps}) == 177 and len(cps) == 177, 'Copy counts or collisions')
    require(len({(c['quiz_key'], c['source_key']) for c in cps}) == 177, 'Duplicate source inside quiz')
    require([q['expected_initial_count'] for q in qs] == [12,12,24], 'Initial counts')
    require([q['expected_reserve_count'] for q in qs] == [19,24,86], 'Reserve counts')
    require(sum(c['role']=='initial' for c in cps) == 48, 'Initial copies')
    require(sum(c['role']=='reserve' for c in cps) == 129, 'Reserve copies')
    for s in src:
        require(s['source_key'] == f"module2_docx_{s['original_number']:03}", 'Unstable source key')
        require(digest(s['original']) == s['original_sha256'], 'Original hash mismatch')
        if s['status']=='on_hold':
            require(s['candidate'] is None and not s['assignments'] and s['hold_reason'], 'Held source importable')
            continue
        c=s['candidate']
        require(s['status']=='approved_for_import' and isinstance(c['contains_error'],bool), 'Explicit category missing')
        require(c['multiple'] is False and c['error_count'] == int(c['contains_error']), 'Correction mode')
        require(c['theme'] == s['theme'], 'Theme mismatch')
        require(all(isinstance(c[f],str) and c[f].strip() for f in ['title','text','corrected_text','explanation','theme']), 'Empty field')
        require(len(c['title']) <= 255 and len(c['theme']) <= 255, 'Column length')
        require(c['answers']==[{'content':'0 erreur','correct':not c['contains_error'],'position':0},{'content':'1 erreur','correct':c['contains_error'],'position':1}], 'Answers not exclusive/coherent')
        require(len(c['correction_edits'])==c['error_count'], 'Number of documented corrections')
        corrected=c['text']
        for edit in c['correction_edits']:
            require(edit['occurrences']==1 and corrected.count(edit['incorrect_fragment'])==1, 'Correction fragment not unique')
            corrected=corrected.replace(edit['incorrect_fragment'],edit['correct_fragment'],1)
        require(corrected == c['corrected_text'], 'Corrected text differs from documented edit')
        text_changes=[e for e in s['editorial_changes'] if e['field']=='text']
        require(bool(text_changes)==(c['text']!=s['original']['text']), 'Untracked wording change')
        if text_changes:
            require(text_changes[0]['original']==s['original']['text'] and text_changes[0]['proposed']==c['text'], 'Text change trace')
        require(any(e['field']=='explanation' and e['proposed']==c['explanation'] and e['original']==s['original']['explanation'] for e in s['editorial_changes']), 'Explanation trace')
        actual=[{k:cp[k] for k in ['copy_key','quiz_key','role','position']} for cp in cps if cp['source_key']==s['source_key']]
        require(actual==s['assignments'], 'Assignments do not match copies')
    require({s['original_number'] for s in src if any(e['field']=='text' for e in s['editorial_changes'])}=={4,37,50,56,71}, 'Unapproved text edits')
    for cp in cps:
        require(cp['source_key'] in source_by_key, 'Unknown source')
        s=source_by_key[cp['source_key']]
        require(s['candidate'] is not None, 'Held source has copy')
        require(cp['role'] in ['initial','reserve'], 'Unknown role')
        require(cp['copy_key']==cp['quiz_key']+'::'+cp['source_key'], 'Copy key')
        require(digest(payload(s,cp))==cp['payload_sha256'], 'Payload hash')
    for q in qs:
        require(q['renewal_enabled'] is False and q['error_question_quota'] is None, 'Import changes mode/quota')
        require(q['course_binding']['policy']=='manual_easyadmin_after_import', 'Binding policy')
        require(all(q['course_binding'][f] is None for f in ['existing_course_id','existing_quiz_id','existing_slug']), 'Invented DB identity')
        bank=sorted([c for c in cps if c['quiz_key']==q['quiz_key']],key=lambda c:c['position'])
        require([c['position'] for c in bank] == list(range(q['expected_total_count'])), 'Position gaps/collisions')
        require([c['source_key'] for c in bank]==q['initial_source_keys']+q['reserve_source_keys'], 'Question order mismatch')
        require([c['source_key'] for c in bank if c['role']=='initial']==q['initial_source_keys'], 'Initial flag mismatch')
        require(len({source_by_key[c['source_key']]['candidate']['text'] for c in bank})==len(bank), 'Duplicate text inside quiz')
        require(q['expected_total_count']==q['expected_initial_count']+q['expected_reserve_count'], 'Total mismatch')
        for t in q['themes']:
            initial=[c['source_key'] for c in bank if c['role']=='initial' and source_by_key[c['source_key']]['theme']==t['theme']]
            reserve=[c['source_key'] for c in bank if c['role']=='reserve' and source_by_key[c['source_key']]['theme']==t['theme']]
            require(initial==t['initial_source_keys'] and reserve==t['reserve_source_keys'], 'Theme partition')
            require(len(initial)==t['initial_count_and_attempt_quota']>0 and len(reserve)==t['reserve_count']>0, 'Empty/mismatched theme counts')
            require(t['minimum_reuse_from_previous_attempt']==max(0,len(initial)-len(reserve)), 'Reuse bound')
        require(set(source_by_key[c['source_key']]['theme'] for c in bank)=={t['theme'] for t in q['themes']}, 'Orphan theme')
        require(sum(source_by_key[s]['candidate']['contains_error'] for s in q['initial_source_keys']) == q['initial_error_count_documentary_only'], 'Error composition')
    multiplicities=collections.Counter(c['source_key'] for c in cps)
    require(collections.Counter(multiplicities.values())=={2:67,1:43}, 'Source/copy count distinction')
    require(all(any(c['quiz_key']=='module2_general' and c['source_key']==sk for c in cps) for sk in multiplicities), 'Source absent from general')
    require(len(set(s for q in qs for s in q['initial_source_keys']))==48, 'Initial sources reused across quizzes')
    source_check='not_requested'
    if docx:
        require(hashlib.sha256(docx.read_bytes()).hexdigest()==m['source_document']['sha256'], 'DOCX fingerprint changed')
        ns={'w':'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
        with zipfile.ZipFile(docx) as z:
            root=ET.fromstring(z.read('word/document.xml'))
        paras=[''.join(n.text or '' for n in p.findall('.//w:t',ns)).strip() for p in root.findall('.//w:p',ns)]
        paras=[p for p in paras if p]
        starts=[i for i,p in enumerate(paras) if re.fullmatch(r'Question \d+',p)]
        require(len(starts)==116,'DOCX question count')
        for s,i in zip(src,starts):
            require(paras[i]==f"Question {s['original_number']}",'DOCX numbering')
            original={'theme':paras[i+2],'text':paras[i+4],'answers':paras[i+6:i+8],'explanation':paras[i+9]}
            require(original==s['original'],f"DOCX source differs: {s['source_key']}")
        source_check='116 original texts, answers and explanations matched DOCX extraction'
    return {'status':'passed','scope':'Editorial structure, traceability, documented correction edits, optional DOCX comparison and in-memory selector simulation. No Symfony or database test executed.',
            'manifest_sha256':hashlib.sha256(b).hexdigest(),'counts':m['counts'],'source_check':source_check,
            'linguistic_limit':'A one-fragment correction check does not by itself prove linguistic correctness. Read the guide and the source/candidate pairs.',
            'renewal_simulation':[simulate(q,cps,source_by_key) for q in qs]}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest',nargs='?',type=Path,default=Path(__file__).with_name('module2_quiz_manifest.json'))
    parser.add_argument('--source-docx',type=Path)
    args=parser.parse_args()
    try:
        result=validate(args.manifest,args.source_docx)
    except (ValueError,KeyError,TypeError,OSError) as exc:
        raise SystemExit(f'ERREUR : {exc}')
    print(json.dumps(result,ensure_ascii=False,indent=2))
