<?php
/**
 * RedForce.live Stream Proxy with Fallback Provider
 * Fetches fresh token-based stream URLs from redforce.live or 10.99.99.99 player pages
 * and redirects to the actual m3u8 stream.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Check Server Speed/Status ──
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
    
    // Default to 10.99.99.99, but if redforce is faster/working and 10.99.99.99 is not, switch.
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

    // Return as JSON if ?json=1
    if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        echo json_encode(['stream_id' => $streamId, 'url' => $streamUrl]);
        exit;
    }
    
    // Otherwise redirect to the stream
    header('Location: ' . $streamUrl);
    http_response_code(302);
    exit;
} else {
    http_response_code(404);
    die(json_encode(['error' => 'Stream URL not found on any provider', 'stream_id' => $streamId]));
}


