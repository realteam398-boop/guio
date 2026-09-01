<?php
// WebRTC Signaling — AJAX polling based.
// NOTE: This endpoint is NOT antibot-protected because:
//   1. It requires a valid meeting ID (a 12-digit secret already gated by
//      room.php / join.php which ARE antibot-protected).
//   2. WebRTC signal payloads are useless without a matching peer connection.
//   3. The cookie-based antibot check is fragile behind Cloudflare/CDN IP rewriting,
//      and a single false-negative breaks the entire call.
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$m    = preg_replace('/[^0-9\-]/', '', $_GET['m'] ?? $_POST['m'] ?? '');
$role = in_array($_POST['role'] ?? $_GET['role'] ?? '', ['admin','host'])
      ? ($_POST['role'] ?? $_GET['role'])
      : '';

if (!$m || !file_exists(zm_sig_file($m))) {
    echo json_encode(['ok'=>false,'msg'=>'Meeting not found']); exit;
}

// ── Atomic file read-modify-write (flock with graceful fallback) ───────────────
function sig_file_write($f, callable $mutate) {
    // Try proper exclusive-lock atomic update first
    $fh = @fopen($f, 'c+');
    if ($fh && @flock($fh, LOCK_EX | LOCK_NB)) {
        $content = stream_get_contents($fh, -1, 0);
        $sigs    = json_decode($content, true) ?: ['to_admin'=>[], 'to_host'=>[]];
        $sigs    = $mutate($sigs);
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($sigs, JSON_PRETTY_PRINT));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return true;
    }
    if ($fh) fclose($fh);

    // Fallback: file_get_contents / file_put_contents with LOCK_EX
    // (works on most shared hosts even if flock NB fails)
    $content = @file_get_contents($f) ?: '{}';
    $sigs    = json_decode($content, true) ?: ['to_admin'=>[], 'to_host'=>[]];
    $sigs    = $mutate($sigs);
    return (bool) file_put_contents($f, json_encode($sigs, JSON_PRETTY_PRINT), LOCK_EX);
}

// ── POST: send a signal ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { parse_str(file_get_contents('php://input'), $body); }

    $role = $body['role'] ?? $_POST['role'] ?? '';
    $type = $body['type'] ?? '';   // offer | answer | ice | reoffer_request
    $data = $body['data'] ?? null;

    if (!$role || !$type || $data === null) {
        echo json_encode(['ok'=>false,'msg'=>'Missing fields']); exit;
    }

    $dest  = $role === 'admin' ? 'to_host' : 'to_admin';
    $f     = zm_sig_file($m);
    $entry = ['type'=>$type, 'data'=>$data, 'ts'=>time()];

    sig_file_write($f, function($sigs) use ($dest, $type, $entry) {
        // offer/answer start a fresh handshake — wipe stale signals first
        if ($type === 'offer' || $type === 'answer') {
            $sigs[$dest] = [$entry];
        } else {
            $sigs[$dest][] = $entry;
            if (count($sigs[$dest]) > 80) {
                $sigs[$dest] = array_slice($sigs[$dest], -80);
            }
        }
        return $sigs;
    });

    echo json_encode(['ok'=>true]); exit;
}

// ── GET: receive (and atomically clear) signals ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $f    = zm_sig_file($m);
    $key  = $role === 'admin' ? 'to_admin' : 'to_host';
    $msgs = [];

    sig_file_write($f, function($sigs) use ($key, &$msgs) {
        $msgs        = $sigs[$key] ?? [];
        $sigs[$key]  = [];
        return $sigs;
    });

    echo json_encode(['ok'=>true, 'signals'=>$msgs]); exit;
}

echo json_encode(['ok'=>false]);
