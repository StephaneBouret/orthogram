const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
    const {createCopyLocator} = await import(`data:text/javascript;base64,${fs.readFileSync('assets/js/dictation_navigation.js').toString('base64')}`);
    const select = (initial, current, passage, occurrence = 0) => {
        let start = -1;
        for (let i = 0; i <= occurrence; ++i) start = initial.indexOf(passage, start + 1);
        assert.notEqual(start, -1);
        return createCopyLocator(initial, current)(start, start + passage.length);
    };
    const found = (initial, current, passage, occurrence = 0) => {
        const range = select(initial, current, passage, occurrence);
        assert.ok(range, `Missing ${passage} in ${current}`);
        assert.equal(current.slice(...range), passage);
        return range;
    };
    const first = 'prendre consciense. Vous vous améliorerer.';
    found(first, first.replace('consciense', 'conscience'), 'améliorerer');
    found(first, 'Introduction ajoutée. '+first, 'améliorerer');
    found(first, first.slice('prendre '.length), 'améliorerer');
    found(first, '😀 '+first.replace('consciense', 'conscience').replace('Vous vous', 'Maintenant vous'), 'améliorerer');
    assert.equal(select(first, first.replace('améliorerer', 'améliorerez'), 'améliorerer'), null);
    assert.equal(select(first, first.replace('améliorerer', ''), 'améliorerer'), null);
    assert.equal(select('chatt', 'chattx', 'chatt'), null);
    assert.equal(select('chatt', 'xchatt', 'chatt'), null);
    const repeated = 'Le chatt dort. Puis le chatt joue.';
    const edited = 'Ici '+repeated.replace('dort', 'dormait');
    assert.deepEqual(found(repeated, edited, 'chatt', 1), [edited.lastIndexOf('chatt'), edited.lastIndexOf('chatt') + 5]);
    assert.equal(select('chatt chatt', 'chatt', 'chatt', 0), null);
    assert.equal(select('chatt chatt', 'chatt', 'chatt', 1), null);
    const omitted = 'Le chat dort.';
    assert.deepEqual(createCopyLocator(omitted, '😀 '+omitted)(8, 8), [11, 11]);
    assert.equal(createCopyLocator(omitted, 'Le chat ici dort.')(8, 8), null);
    assert.deepEqual(found('Cette idée.', 'Une autre phrase. Cette idée.', '.'), [28, 29]);
    const unicode = '😀\r\nUn e\u0301té avec chatt.';
    const normalizedLines = 'Préface\n'+unicode.replace('\r\n', '\n');
    found(unicode, normalizedLines, 'chatt');
    assert.equal(createCopyLocator('😀 chatt', '😀 chatt')(1, 2), null);
    assert.equal(createCopyLocator('a'.repeat(20001), 'a')(0, 1), null);
    assert.equal(createCopyLocator('a '.repeat(500)+'chatt', 'b '.repeat(500)+'chatt')(1000, 1005), null);
    console.log('OK cas ciblés : modifications successives, contexte répété, ambiguïtés, suppression, omissions, ponctuation, Unicode et limites');

    // Independent full-matrix oracle: enumerate *all* optimal paths for tiny
    // strings. Every returned character/point must be invariant on every path.
    const strings = [''];
    for (let length = 1; length <= 4; ++length) for (let bits = 0; bits < 2 ** length; ++bits) strings.push(Array.from({length}, (_, i) => bits & (1 << i) ? 'a' : '.').join(''));
    let checks = 0;
    for (const a of strings) for (const b of strings) {
        const dp = Array.from({length: a.length + 1}, () => Array(b.length + 1).fill(0));
        for (let i = 0; i <= a.length; ++i) for (let j = 0; j <= b.length; ++j) dp[i][j] = !i ? j : !j ? i : Math.min(dp[i-1][j]+1, dp[i][j-1]+1, dp[i-1][j-1]+(a[i-1]===b[j-1]?0:1));
        const paths = [];
        const walk = (i, j, matches, points) => {
            points = [...points, [i,j]];
            if (!i && !j) { paths.push({matches, points}); return; }
            if (i && dp[i][j]===dp[i-1][j]+1) walk(i-1,j,{...matches,[i-1]:-1},points);
            if (j && dp[i][j]===dp[i][j-1]+1) walk(i,j-1,matches,points);
            if (i && j && dp[i][j]===dp[i-1][j-1]+(a[i-1]===b[j-1]?0:1)) walk(i-1,j-1,{...matches,[i-1]:a[i-1]===b[j-1]?j-1:-1},points);
        };
        walk(a.length,b.length,{},[]);
        const locate = createCopyLocator(a,b);
        for (let i=0;i<=a.length;++i) {
            const point=locate(i,i);
            if(point)for(const path of paths)for(const [row,col] of path.points)if(row===i)assert.equal(point[0],col,`ambiguous point ${a}/${b}`);
            if(i<a.length){const range=locate(i,i+1);if(range)for(const path of paths)assert.equal(range[0],path.matches[i],`ambiguous character ${a}/${b}`);}
            ++checks;
        }
    }
    console.log(`OK oracle exhaustif : ${strings.length ** 2} paires, ${checks} points/caractères contrôlés`);
    const large = 'a'.repeat(19990)+' chatt.';
    const started = performance.now();
    const range = createCopyLocator(large, 'Z '+large)(19991,19996);
    assert.deepEqual(range, [19993,19998]);
    console.log(`OK limite de 20 000 UTF-16 : ${(performance.now()-started).toFixed(1)} ms ; bande plafonnée à 2 000 000 cellules par matrice`);
    if(process.argv[2]) {
        const {copy} = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
        found(copy,copy.replace('consciense','conscience'),'améliorerer');
        console.log('OK texte intégral de recette fourni : conscience puis améliorerer');
    }
})().catch(error => {console.error(error);process.exitCode=1;});
