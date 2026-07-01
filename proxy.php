<?php
/**
 * RedForce.live Stream Proxy
 * Fetches fresh token-based stream URLs from redforce.live player pages
 * and redirects to the actual m3u8 stream.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$streamId = isset($_GET['stream']) ? intval($_GET['stream']) : 0;

if ($streamId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid stream ID']));
}

// Fetch the player page with proper headers
$playerUrl = "http://redforce.live/player.php?stream=" . $streamId;

$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'timeout' => 10,
        'header'  => implode("\r\n", [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: http://redforce.live/',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ]),
    ]
]);

$html = @file_get_contents($playerUrl, false, $context);

if ($html === false) {
    http_response_code(502);
    die(json_encode(['error' => 'Could not reach redforce.live player page']));
}

// Extract primarySource URL
if (preg_match("/var primarySource = '([^']+)'/", $html, $matches)) {
    $streamUrl = $matches[1];
    
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
    die(json_encode(['error' => 'Stream URL not found in player page', 'stream_id' => $streamId]));
}
