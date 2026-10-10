param(
    [string]$Origin = 'http://127.0.0.1:8823',
    [string]$Cdp = 'http://127.0.0.1:8824',
    [switch]$BeforeFix
)

# Local disposable fixtures and isolated Chrome; setup is in course_bookmark_recipe.md.
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
    $timeout = [Threading.CancellationTokenSource]::new(60000)
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

function Check-CoursePost($label) {
    $null = Evaluate "Turbo.visit('/courses/formation-en-orthographe/quiz-section/lecture-gratuite')"
    Wait-Js "location.pathname.endsWith('/lecture-gratuite') && !!document.querySelector('[data-controller~=course-bookmark]') && !document.documentElement.hasAttribute('aria-busy')"
    if ($BeforeFix) {
        $null = Evaluate 'new Promise(resolve => setTimeout(resolve, 500))'
        if ((Evaluate 'bookmarkLifecycleProbe.posts.length') -ne 0) { throw 'Expected missing POST on V2' }
        Write-Output "BUG REPRODUIT [$label] : aucun POST après retour puis navigation Turbo vers le cours"
    } else {
        Wait-Js 'bookmarkLifecycleProbe.posts.some(post => post.status === 204)'
        Write-Output "PASS [$label] : navigation Turbo vers le cours, POST 204 observé"
    }
    Write-Output (Evaluate 'JSON.stringify(bookmarkLifecycleProbe)')
}

try {
    $null = Send-Cdp 'Page.enable'
    $null = Send-Cdp 'Runtime.enable'
    Write-Output ('Chrome : ' + (Invoke-RestMethod "$Cdp/json/version").Browser)
    Write-Output ('Mode : ' + $(if ($BeforeFix) { 'V2 avant correction' } else { 'V3 corrigée' }))
    $null = Send-Cdp 'Page.addScriptToEvaluateOnNewDocument' @{ source = @'
window.bookmarkLifecycleProbe = { id: crypto.randomUUID(), events: [], posts: [] };
for (const name of ['pagehide', 'pageshow']) window.addEventListener(name, event => {
    bookmarkLifecycleProbe.events.push({ name, persisted: event.persisted, trusted: event.isTrusted });
});
const originalFetch = window.fetch;
window.fetch = async function(url, options) {
    const post = options?.method === 'POST' && String(url).endsWith('/bookmark') ? { url: String(url) } : null;
    if (post) bookmarkLifecycleProbe.posts.push(post);
    const response = await originalFetch.call(this, url, options);
    if (post) post.status = response.status;
    return response;
};
'@ }
    Navigate '/__lot3/profile/subscriber' '/courses/formation-en-orthographe'
    Navigate '/' '/'
    Wait-Js '!!window.Turbo'
    if (Evaluate '!!document.querySelector(`meta[name="turbo-cache-control"][content="no-cache"]`)') { throw 'Home must have no no-cache meta' }
    $identity = Evaluate 'bookmarkLifecycleProbe.id'
    $history = Send-Cdp 'Page.getNavigationHistory'
    $entry = $history.entries[$history.currentIndex].id
    # A full navigation to a local static resource, then a real history traversal.
    # No synthetic lifecycle event is dispatched in this part of the test.
    Navigate '/favicon/favicon.ico' '/favicon/favicon.ico'
    $null = Send-Cdp 'Page.navigateToHistoryEntry' @{ entryId = $entry }
    Wait-Js "location.pathname === '/' && document.readyState === 'complete' && !!window.Turbo"
    $identityJson = ConvertTo-Json -Compress $identity
    $native = Evaluate "bookmarkLifecycleProbe.id === $identityJson && bookmarkLifecycleProbe.events.some(e => e.name === 'pageshow' && e.persisted && e.trusted)"
    Write-Output ('Trace retour natif : ' + (Evaluate 'JSON.stringify(bookmarkLifecycleProbe)'))
    if ($native) {
        Write-Output 'RESTAURATION NATIVE OBSERVÉE : même document, pageshow persisted=true et isTrusted=true'
        Check-CoursePost 'BFCache natif'
    } else {
        Write-Output 'RESTAURATION NATIVE NON OBSERVÉE : le navigateur a rechargé le document ; ce passage ne valide pas BFCache'
    }

    Navigate '/' '/'
    Wait-Js '!!window.Turbo'
    if (Evaluate '!!document.querySelector(`meta[name="turbo-cache-control"][content="no-cache"]`)') { throw 'Home must have no no-cache meta' }
    $null = Evaluate "window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })); window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));"
    Check-CoursePost 'événements simulés sur accueil sans no-cache'

    if (!$BeforeFix) {
        Navigate '/' '/'
        $null = Evaluate (Get-Content tests/manual/course_bookmark_lifecycle_scenarios.js -Raw)
        $sourceJson = ConvertTo-Json -Compress (Get-Content assets/js/course_bookmark.js -Raw)
        Evaluate "runBookmarkLifecycleScenarios($sourceJson)" | ForEach-Object { Write-Output $_ }

        Navigate '/ma-formation' '/ma-formation'
        Wait-Js '!!window.Turbo'
        if (!(Evaluate '!!document.querySelector(`meta[name="turbo-cache-control"][content="no-cache"]`)')) { throw 'Personal page must have no-cache meta' }
        $identityJson = ConvertTo-Json -Compress (Evaluate 'bookmarkLifecycleProbe.id')
        $null = Evaluate "setTimeout(() => { window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })); window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })); }, 20)"
        Wait-Js "bookmarkLifecycleProbe.id !== $identityJson && document.readyState === 'complete' && !!window.Turbo"
        Write-Output 'PASS [événements simulés sur page personnelle no-cache] : rechargement réel du document conservé'
    }
} finally {
    $null = Send-Cdp 'Page.close'
    $socket.Dispose()
}

