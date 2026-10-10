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

try {
    $null = Send-Cdp 'Page.enable'
    $null = Send-Cdp 'Runtime.enable'
    Navigate '/__lot3/profile/subscriber' '/courses/formation-en-orthographe'
    Wait-Js '!!window.Turbo'
    $null = Evaluate (Get-Content tests/manual/course_bookmark_order_scenarios.js -Raw)
    $mode = if ($BeforeFix) { 'true' } else { 'false' }
    $result = Evaluate "runBookmarkOrderScenarios($mode)"
    $result.report | ForEach-Object { Write-Output $_ }
    Write-Output ('Ordre des requêtes/réponses : ' + ($result.events | ConvertTo-Json -Depth 8 -Compress))
} finally {
    $null = Send-Cdp 'Page.close'
    $socket.Dispose()
}
