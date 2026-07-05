<?php
/**
 * RedForce.live Stream Proxy with Fallback Provider & Internal HLS Proxying
 * Fetches fresh token-based stream URLs from redforce.live or 10.99.99.99 player pages.
 * Supports internal proxying of m3u8 and ts segments to bypass HTTPS Mixed Content blocks.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Helper: Resolve relative URL to absolute URL
function resolve_url($base, $relative) {
    if (parse_url($relative, PHP_URL_SCHEME) != '') return $relative;
    if (strlen($relative) > 1 && $relative[0] == '/' && $relative[1] == '/') return 'http:' . $relative;
    
    $p = parse_url($base);
    $scheme = isset($p['scheme']) ? $p['scheme'] : 'http';
    $host = isset($p['host']) ? $p['host'] : '';
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = isset($p['path']) ? $p['path'] : '/';
    
    if ($relative[0] == '/') {
        $path = '';
    } else {
        $ap = explode('/', $path);
        array_pop($ap);
        $path = implode('/', $ap) . '/';
    }
    
    $path .= $relative;
    
    // Resolve relative path references (like /./ and /../)
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for($n=1; $n>0; $path=preg_replace($re, '/', $path, -1, $n)) {}
    
    return $scheme . '://' . $host . $port . $path;
}

// ── 1. Internal Proxy Handler (ts, key, m3u8 files) ──
if (isset($_GET['proxy_url'])) {
    $targetUrl = base64_decode($_GET['proxy_url']);
    if (filter_var($targetUrl, FILTER_VALIDATE_URL) === false) {
        http_response_code(400);
        exit('Invalid target URL');
    }
    
    $parsedHost = parse_url($targetUrl, PHP_URL_HOST);
    
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 8,
            'header'  => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                "Referer: http://" . $parsedHost . "/",
                'Accept: */*',
            ]),
        ]
    ]);
    
    $data = @file_get_contents($targetUrl, false, $context);
    if ($data === false) {
        http_response_code(502);
        exit('Error fetching remote resource');
    }
    
    $isM3u8 = (strpos(strtolower(parse_url($targetUrl, PHP_URL_PATH)), '.m3u8') !== false || isset($_GET['m3u8']));
    
    if ($isM3u8) {
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-cache');
        
        $lines = explode("\n", $data);
        $rewrittenLines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            
            if ($line[0] === '#') {
                // If it is a key URI, rewrite it
                if (preg_match('/URI="([^"]+)"/', $line, $keyMatches)) {
                    $absoluteKeyUrl = resolve_url($targetUrl, $keyMatches[1]);
                    $proxiedKeyUrl = 'proxy.php?proxy_url=' . urlencode(base64_encode($absoluteKeyUrl));
                    $line = str_replace($keyMatches[1], $proxiedKeyUrl, $line);
                }
                $rewrittenLines[] = $line;
            } else {
                // Segment URL or Sub-playlist URL
                $absoluteSegmentUrl = resolve_url($targetUrl, $line);
                $isSubM3u8 = (strpos(strtolower(parse_url($absoluteSegmentUrl, PHP_URL_PATH)), '.m3u8') !== false);
                $proxiedUrl = 'proxy.php?proxy_url=' . urlencode(base64_encode($absoluteSegmentUrl)) . ($isSubM3u8 ? '&m3u8=1' : '');
                $rewrittenLines[] = $proxiedUrl;
            }
        }
        echo implode("\n", $rewrittenLines);
    } else {
        header('Content-Type: video/MP2T');
        header('Cache-Control: max-age=3600');
        echo $data;
    }
    exit;
}

// ── 2. Check Server Speed/Status ──
if (isset($_GET['check'])) {
    $hosts = ['10.99.99.99', 'redforce.live'];
    $results = [];
    foreach ($hosts as $host) {
        $start = microtime(true);
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 2, // 2 seconds timeout for check
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nReferer: http://$host/\r\n"
            ]
        ]);
        $html = @file_get_contents("http://$host/player.php?stream=1", false, $context);
        if ($html !== false && preg_match("/var primarySource = '([^']+)'/", $html)) {
            $results[$host] = microtime(true) - $start;
        }
    }
    
    $preferred = '10.99.99.99';
    if (!empty($results)) {
        asort($results); // sort by latency (ascending)
        $preferred = array_key_first($results);
    }
    
    setcookie('preferred_provider', $preferred, time() + 86400, '/');
    header('Content-Type: application/json');
    echo json_encode(['preferred' => $preferred, 'latencies' => $results]);
    exit;
}

// ── 3. Main Stream ID Handler ──
$streamId = isset($_GET['stream']) ? intval($_GET['stream']) : 0;

if ($streamId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid stream ID']));
}

function getStreamUrl($host, $streamId) {
    $playerUrl = "http://" . $host . "/player.php?stream=" . $streamId;
    
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 3, // 3 seconds timeout for fast switching/fallback
            'header'  => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                "Referer: http://" . $host . "/",
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ]),
        ]
    ]);
    
    $html = @file_get_contents($playerUrl, false, $context);
    if ($html !== false && preg_match("/var primarySource = '([^']+)'/", $html, $matches)) {
        return $matches[1];
    }
    return null;
}

// Check if a preferred provider is stored in the cookie, default to '10.99.99.99'
$preferred = isset($_COOKIE['preferred_provider']) ? $_COOKIE['preferred_provider'] : '10.99.99.99';

if ($preferred === 'redforce.live') {
    $providers = ['redforce.live', '10.99.99.99'];
} else {
    $providers = ['10.99.99.99', 'redforce.live'];
}

$streamUrl = null;
$workingProvider = null;

// Iterate and play from whichever server works first
foreach ($providers as $provider) {
    $streamUrl = getStreamUrl($provider, $streamId);
    if ($streamUrl) {
        $workingProvider = $provider;
        break;
    }
}

if ($streamUrl) {
    // If the working provider is different from the saved preferred one, update the cookie
    if ($workingProvider !== $preferred) {
        setcookie('preferred_provider', $workingProvider, time() + 86400, '/');
    }

    // Build the proxied internal URL to bypass HTTPS mixed content block
    $proxiedStreamUrl = 'proxy.php?proxy_url=' . urlencode(base64_encode($streamUrl)) . '&m3u8=1';

    // Return as JSON if ?json=1
    if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        echo json_encode(['stream_id' => $streamId, 'url' => $proxiedStreamUrl]);
        exit;
    }
    
    // Otherwise redirect to the proxied stream
    header('Location: ' . $proxiedStreamUrl);
    http_response_code(302);
    exit;
} else {
    http_response_code(404);
    die(json_encode(['error' => 'Stream URL not found on any provider', 'stream_id' => $streamId]));
}
