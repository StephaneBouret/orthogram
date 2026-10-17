// UI correspondence between two learner copies only. Never a correction engine.
// Consider every minimum-edit alignment, not an arbitrary tie-breaking path.
const MAX_LENGTH = 20000;
const MAX_DISTANCE = 256;
const MAX_CELLS = 2000000;
const wordCharacter = value => value !== undefined && /^[\p{L}\p{N}\p{M}]$/u.test(value);

function atoms(text) {
    const values = [], offsets = [0];
    for (let offset = 0; offset < text.length;) {
        const value = String.fromCodePoint(text.codePointAt(offset));
        offset += value.length;
        if (value === '\r' && text[offset] === '\n') ++offset;
        values.push(value === '\r' ? '\n' : value);
        offsets.push(offset);
    }
    return { values, offsets };
}

export function createCopyLocator(initial, current) {
    if (initial.length > MAX_LENGTH || current.length > MAX_LENGTH) return () => null;
    const a = atoms(initial), b = atoms(current);
    const n = a.values.length, m = b.values.length;
    const matches = new Int32Array(n).fill(-1);
    const points = new Int32Array(n + 1).fill(-1);
    if (n === m && a.values.every((value, i) => value === b.values[i])) {
        for (let i = 0; i <= n; ++i) { points[i] = i; if (i < n) matches[i] = i; }
    } else {
        const band = Math.min(MAX_DISTANCE, Math.max(n, m), Math.floor((MAX_CELLS / (n + 1) - 1) / 2));
        if (band < 0 || Math.abs(n - m) > band) return () => null;
        const width = 2 * band + 1, infinity = band + 1;
        const forward = new Uint16Array((n + 1) * width).fill(infinity);
        const backward = new Uint16Array(forward.length).fill(infinity);
        const index = (i, j) => i * width + j - i + band;
        const get = (table, i, j) => i < 0 || i > n || j < 0 || j > m || Math.abs(j - i) > band ? infinity : table[index(i, j)];
        for (let i = 0; i <= n; ++i) {
            for (let j = Math.max(0, i - band); j <= Math.min(m, i + band); ++j) {
                forward[index(i, j)] = i === 0 && j === 0 ? 0 : Math.min(infinity,
                    get(forward, i - 1, j) + 1, get(forward, i, j - 1) + 1,
                    get(forward, i - 1, j - 1) + (a.values[i - 1] === b.values[j - 1] ? 0 : 1));
            }
        }
        const distance = get(forward, n, m);
        // A path costing <= band cannot leave this band. Thus no tied optimal
        // path is discarded. Beyond the budget, decline to guess a location.
        if (distance > band) return () => null;
        for (let i = n; i >= 0; --i) {
            for (let j = Math.min(m, i + band); j >= Math.max(0, i - band); --j) {
                backward[index(i, j)] = i === n && j === m ? 0 : Math.min(infinity,
                    get(backward, i + 1, j) + 1, get(backward, i, j + 1) + 1,
                    get(backward, i + 1, j + 1) + (a.values[i] === b.values[j] ? 0 : 1));
            }
        }
        for (let i = 0; i <= n; ++i) {
            let point = -1, match = -1, ambiguousPoint = false, changed = false;
            for (let j = Math.max(0, i - band); j <= Math.min(m, i + band); ++j) {
                const prefix = get(forward, i, j);
                if (prefix + get(backward, i, j) !== distance) continue;
                if (point !== -1 && point !== j) ambiguousPoint = true;
                point = j;
                if (i === n) continue;
                if (prefix + 1 + get(backward, i + 1, j) === distance) changed = true;
                const equal = a.values[i] === b.values[j];
                if (j < m && prefix + (equal ? 0 : 1) + get(backward, i + 1, j + 1) === distance) {
                    if (!equal || (match !== -1 && match !== j)) changed = true;
                    match = j;
                }
            }
            if (!ambiguousPoint) points[i] = point;
            if (i < n && !changed) matches[i] = match;
        }
    }
    return (start, end) => {
        const first = a.offsets.indexOf(start), last = a.offsets.indexOf(end);
        if (first < 0 || last < first) return null; // Includes offsets inside surrogate pairs / CRLF.
        if (first === last) {
            const point = points[first];
            if (point < 0 || (first > 0 && matches[first - 1] !== point - 1) || (first < n && matches[first] !== point)) return null;
            return [b.offsets[point], b.offsets[point]];
        }
        const target = matches[first];
        if (target < 0) return null;
        for (let i = first; i < last; ++i) if (matches[i] !== target + i - first) return null;
        const targetEnd = target + last - first;
        // Do not select an unchanged substring inside a word that was expanded.
        if (wordCharacter(a.values[first]) && !wordCharacter(a.values[first - 1]) && wordCharacter(b.values[target - 1])) return null;
        if (wordCharacter(a.values[last - 1]) && !wordCharacter(a.values[last]) && wordCharacter(b.values[targetEnd])) return null;
        return [b.offsets[target], b.offsets[targetEnd]];
    };
}

// A textarea exposes no range rectangle. A temporary, inert mirror measures the
// selection's line with identical wrapping; it never changes the field's value.
export function revealSelection(field, start, end) {
    const mirror = document.createElement('div');
    const style = getComputedStyle(field);
    for (const property of ['font', 'lineHeight', 'letterSpacing', 'wordSpacing', 'textIndent', 'tabSize', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft']) mirror.style[property] = style[property];
    Object.assign(mirror.style, { position: 'fixed', visibility: 'hidden', pointerEvents: 'none', top: '0', left: '0', width: `${field.clientWidth}px`, boxSizing: 'border-box', whiteSpace: 'pre-wrap', overflowWrap: 'break-word' });
    mirror.setAttribute('aria-hidden', 'true');
    mirror.append(document.createTextNode(field.value.slice(0, start)));
    const marker = document.createElement('span');
    marker.textContent = field.value.slice(start, end) || '\u200b';
    mirror.append(marker, document.createTextNode(field.value.slice(end)));
    document.body.append(mirror);
    const top = marker.getBoundingClientRect().top - mirror.getBoundingClientRect().top;
    mirror.remove();
    field.scrollTop = Math.max(0, top - field.clientHeight / 3);
    field.scrollIntoView({ block: 'center', behavior: 'instant' });
}
