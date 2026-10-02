param(
    [string]$Origin = 'http://127.0.0.1:8813',
    [string]$Cdp = 'http://127.0.0.1:8814'
)

# Local fixtures only; run with courses_lot3_browser_router.php and isolated Chrome.
$ErrorActionPreference = 'Stop'
$tab = Invoke-RestMethod -Method Put -Uri "$Cdp/json/new?about:blank"
$socket = [System.Net.WebSockets.ClientWebSocket]::new()
$null = $socket.ConnectAsync([Uri]$tab.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
$sequence = 0
function Send-Cdp($method, $parameters = @{}) {
    $script:sequence++
    $id = $script:sequence
    $json = @{ id = $id; method = $method; params = $parameters } | ConvertTo-Json -Depth 30 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $timeout = [Threading.CancellationTokenSource]::new(20000)
    try {
        $socket.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $timeout.Token).GetAwaiter().GetResult()
        do {
            $message = [IO.MemoryStream]::new()
            do {
                $buffer = [byte[]]::new(65536)
                $received = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $timeout.Token).GetAwaiter().GetResult()
                $message.Write($buffer, 0, $received.Count)
            } until ($received.EndOfMessage)
            $reply = [Text.Encoding]::UTF8.GetString($message.ToArray()) | ConvertFrom-Json -Depth 100
            $message.Dispose()
        } until ($reply.id -eq $id)
        if ($reply.error) { throw ($reply.error | ConvertTo-Json -Compress) }
        return $reply.result
    } finally { $timeout.Dispose() }
}

function Evaluate($expression) {
    $result = Send-Cdp 'Runtime.evaluate' @{ expression = $expression; returnByValue = $true; awaitPromise = $true }
    if ($result.exceptionDetails) { throw ($result.exceptionDetails | ConvertTo-Json -Depth 10) }
    return $result.result.value
}

function Wait-Js($expression) {
    for ($i = 0; $i -lt 80; $i++) {
        if (Evaluate $expression) { return }
        Start-Sleep -Milliseconds 100
    }
    throw "Timeout: $expression"
}

function Navigate($path, $expected) {
    $null = Send-Cdp 'Page.navigate' @{ url = $Origin + $path }
    $expectedJson = ConvertTo-Json -Compress $expected
    Wait-Js "location.pathname === $expectedJson && document.readyState === 'complete'"
    $null = Evaluate "document.fonts.ready.then(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))))"
}

function Screenshot($name) {
    $null = Evaluate "document.querySelector('#lesson-navigation').scrollIntoView({block:'center'}); new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))"
    $shot = Send-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = $false }
    [IO.File]::WriteAllBytes((Join-Path $PWD "var/navigation-$name.png"), [Convert]::FromBase64String($shot.data))
}

$reader = '/courses/formation-en-orthographe/quiz-section/lecture-gratuite'
$summary = '/courses/formation-en-orthographe'
$results = [Collections.Generic.List[object]]::new()
try {
    $null = Send-Cdp 'Page.enable'
    $null = Send-Cdp 'Runtime.enable'
    $null = Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 425; height = 900; deviceScaleFactor = 1; mobile = $false }
    Navigate '/__lot3/profile/anonymous' $summary
    Navigate $reader $reader
    $locked = Evaluate "[...document.querySelectorAll('.course-navigation-locked')].map(el => el.outerHTML)"
    if ($locked.Count -ne 2) { throw 'Expected two real locked neighbors in anonymous fixture.' }
    $computed = Evaluate @'
(() => {
    const el = document.querySelector('.course-navigation-locked'), nav = el.parentElement;
    const s = getComputedStyle(el), n = getComputedStyle(nav);
    return {display:s.display, whiteSpace:s.whiteSpace, minWidth:s.minWidth, maxWidth:s.maxWidth,
        flex:s.flex, navWrap:n.flexWrap, navAlign:n.justifyContent, width:el.getBoundingClientRect().width};
})()
'@
    Write-Output ('Computed styles at 425px: ' + ($computed | ConvertTo-Json -Compress))

    # Reproduce the original cascade in memory, without modifying application files.
    $baseline = Evaluate @'
(() => {
    for (const sheet of document.styleSheets) {
        for (let i = sheet.cssRules.length - 1; i >= 0; --i) {
            if (sheet.cssRules[i].selectorText?.includes('.course-navigation')) sheet.deleteRule(i);
        }
    }
    const el = document.querySelector('.course-navigation-locked'), r = el.getBoundingClientRect();
    return {whiteSpace:getComputedStyle(el).whiteSpace, left:r.left, width:r.width, viewport:innerWidth};
})()
'@
    Write-Output ('Original cascade reproduced: ' + ($baseline | ConvertTo-Json -Compress))
    if ($baseline.whiteSpace -ne 'nowrap' -or $baseline.left -ge 0) { throw 'Original overflow was not reproduced.' }

    Navigate '/__lot3/profile/subscriber' $summary
    Navigate $reader $reader
    $parts = Evaluate @'
(() => {
    const nav = document.querySelector('.course-navigation');
    return {prev:nav.querySelector('a[rel="prev"]').outerHTML,
        next:nav.querySelector('a[rel="next"]').outerHTML,
        completion:nav.querySelector('#completion-button').outerHTML};
})()
'@
    # Combine real rendered fragments for layout stress cases. This changes only this tab's DOM;
    # private authorization is still tested separately, never relaxed for these combinations.
    foreach ($width in @(320, 390, 425, 1440)) {
        $null = Send-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $width; height = 900; deviceScaleFactor = 1; mobile = $false }
        foreach ($case in @('reserved-reserved', 'accessible-reserved', 'accessible-accessible')) {
            foreach ($validation in @($false, $true)) {
                $html = switch ($case) {
                    'reserved-reserved' { $locked[0] + $locked[1] }
                    'accessible-reserved' { $parts.prev + $locked[1] }
                    'accessible-accessible' { $parts.prev + $parts.next }
                }
                if ($validation) { $html += $parts.completion }
                $htmlJson = ConvertTo-Json -Compress $html
                $null = Evaluate "document.querySelector('.course-navigation').innerHTML = $htmlJson"
                $measurement = Evaluate @'
(async () => {
    const nav = document.querySelector('.course-navigation');
    const titles = [
        'Précédent : Comprendre tous les accords complexes du participe passé dans les propositions subordonnées et leurs exceptions — Réservé aux abonnés',
        'Suivant : Approfondir les règles d’orthographe et reconnaître les constructions grammaticales les plus longues sans aucune abréviation — Réservé aux abonnés'
    ];
    nav.querySelectorAll('.course-navigation-label').forEach(el => { el.textContent = titles[el.textContent.trim().startsWith('Suivant') ? 1 : 0]; });
    await document.fonts.ready;
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const bounds = nav.getBoundingClientRect(), issues = [];
    const inside = (r, parent) => r.left >= parent.left - 1 && r.right <= parent.right + 1 && r.top >= parent.top - 1 && r.bottom <= parent.bottom + 1;
    if (document.documentElement.scrollWidth > innerWidth) issues.push('page overflow');
    if (bounds.left < 0 || bounds.right > innerWidth + 1 || nav.scrollWidth > nav.clientWidth + 1) issues.push('nav overflow');
    for (const el of nav.children) {
        if (!inside(el.getBoundingClientRect(), bounds)) issues.push('child outside nav');
    }
    const boxes = [...nav.children].map(el => el.getBoundingClientRect());
    for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
        if (Math.min(boxes[i].right, boxes[j].right) > Math.max(boxes[i].left, boxes[j].left) + 1 &&
            Math.min(boxes[i].bottom, boxes[j].bottom) > Math.max(boxes[i].top, boxes[j].top) + 1) issues.push('overlapping controls');
    }
    let lines = [];
    for (const el of nav.querySelectorAll('.course-navigation-locked')) {
        const r = el.getBoundingClientRect(), label = el.querySelector('.course-navigation-label');
        const svg = el.querySelector('svg').getBoundingClientRect(), style = getComputedStyle(el);
        if (style.whiteSpace !== 'normal' || style.overflowX !== 'visible') issues.push('nowrap or clipping');
        if (svg.width < 16 || svg.height < 16 || !inside(svg, r)) issues.push('compressed or clipped lock');
        if (getComputedStyle(el.querySelector('svg')).fill !== style.color) issues.push('lock does not follow text color');
        if (el.tagName !== 'SPAN' || el.hasAttribute('href') || el.tabIndex >= 0 || el.onclick) issues.push('clickable lock');
        const range = document.createRange(); range.selectNodeContents(label);
        const rects = [...range.getClientRects()];
        if (!rects.every(line => inside(line, r))) issues.push('text outside locked block');
        if (label.scrollWidth > label.clientWidth + 1 || getComputedStyle(label).textOverflow === 'ellipsis') issues.push('truncated text');
        lines.push(rects.length);
    }
    return {width:innerWidth, issues, lines, locked:nav.querySelectorAll('.course-navigation-locked').length,
        links:nav.querySelectorAll('a[rel]').length, validation:!!nav.querySelector('#completion-button button'),
        pageWidth:document.documentElement.scrollWidth};
})()
'@
                if ($measurement.issues.Count -gt 0) { throw "$width / $case / validation=$validation : $($measurement.issues -join ', ')" }
                if ($measurement.validation -ne $validation) { throw 'Validation button missing.' }
                $results.Add(@{ case = $case; validation = $validation; measurement = $measurement })
                Write-Output "OK ${width}px / $case / validation=$validation"
                Screenshot "$width-$case-$validation"
            }
        }
    }
    # Real navigation and POST, using the subscriber's actual server-rendered form.
    Navigate $reader $reader
    $before = Evaluate "document.querySelector('#completion-button button').textContent.trim()"
    $null = Evaluate "document.querySelector('#completion-button button').click()"
    $beforeJson = ConvertTo-Json -Compress $before
    Wait-Js "document.querySelector('#completion-button button') && document.querySelector('#completion-button button').textContent.trim() !== $beforeJson"
    $null = Evaluate "document.querySelector('#completion-button button').click()"
    Wait-Js "document.querySelector('#completion-button button')?.textContent.trim() === $beforeJson"
    $null = Evaluate "document.querySelector('.course-navigation a[rel=prev]').click()"
    Wait-Js "location.pathname.endsWith('/cours-reserve') && document.readyState === 'complete'"
    Navigate $reader $reader
    $null = Evaluate "document.querySelector('.course-navigation a[rel=next]').click()"
    Wait-Js "location.pathname.endsWith('/quiz-cours') && document.readyState === 'complete'"
    Write-Output 'OK real previous/next links and validation/cancellation POST on isolated fixture.'
    $results | ConvertTo-Json -Depth 10 | Set-Content -Encoding utf8 var/courses-navigation-measurements.json
} finally {
    $socket.Dispose()
    $null = Invoke-RestMethod -Uri "$Cdp/json/close/$($tab.id)"
}
