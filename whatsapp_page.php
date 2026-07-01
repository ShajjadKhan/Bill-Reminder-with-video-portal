<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: portal.php'); exit; }
$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
date_default_timezone_set('Asia/Riyadh');

$WA_BASE = "http://localhost:2785";
$WA_KEY  = "dev-admin-key";
$WA_SID  = "5ecb7afd-213d-4c73-9ea0-e922d02e5ecf";

function waCall($method, $path, $body = null) {
    global $WA_BASE, $WA_KEY;
    $ch = curl_init($WA_BASE . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "X-API-Key: $WA_KEY"]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// Normalize phone: starts with 0 -> Saudi 966. Otherwise keep country code as typed.
function waPhone($raw) {
    $p = preg_replace('/[^0-9]/', '', trim($raw));
    if (substr($p, 0, 1) === '0') $p = '966' . ltrim($p, '0');
    return $p;
}

$ajax = $_GET['ajax'] ?? '';
if ($ajax === 'status') {
    header('Content-Type: application/json');
    global $WA_SID;
    $r = waCall('GET', "/api/sessions/$WA_SID");
    echo json_encode(['status' => $r['status'] ?? 'unknown', 'raw' => $r]);
    exit;
}
if ($ajax === 'qr') {
    header('Content-Type: application/json');
    global $WA_SID;
    $r = waCall('GET', "/api/sessions/$WA_SID/qr");
    echo json_encode(['qr' => $r['qrCode'] ?? null]);
    exit;
}
if ($ajax === 'reconnect') {
    header('Content-Type: application/json');
    global $WA_SID;
    waCall('POST', "/api/sessions/$WA_SID/stop");
    sleep(1);
    $r = waCall('POST', "/api/sessions/$WA_SID/start");
    echo json_encode(['result' => $r]);
    exit;
}
if ($ajax === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    global $WA_SID;
    $phone = waPhone($_POST['phone'] ?? '');
    $text  = trim($_POST['message'] ?? '');
    if (!$phone || !$text) { echo json_encode(['ok' => false, 'error' => 'Phone and message required']); exit; }
    $r = waCall('POST', "/api/sessions/$WA_SID/messages/send-text", ['chatId' => $phone . '@c.us', 'text' => $text]);
    echo json_encode(['ok' => true, 'result' => $r, 'sent_to' => $phone]);
    exit;
}
if ($ajax === 'broadcast' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(0);
    global $WA_SID;
    $text = trim($_POST['message'] ?? '');
    if (!$text) { echo json_encode(['ok' => false, 'error' => 'Message required']); exit; }
    $custs = $db->query("SELECT name, mobile FROM customers WHERE status='active' AND mobile != ''");
    $sent = 0; $failed = 0;
    while ($c = $custs->fetchArray(SQLITE3_ASSOC)) {
        $phone = waPhone($c['mobile']);
        if (!$phone) { $failed++; continue; }
        $r = waCall('POST', "/api/sessions/$WA_SID/messages/send-text", ['chatId' => $phone . '@c.us', 'text' => $text]);
        if ($r) $sent++; else $failed++;
        usleep(1500000); // 1.5s delay between messages to avoid WhatsApp ban
    }
    echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
    exit;
}
$total_active = $db->querySingle("SELECT COUNT(*) FROM customers WHERE status='active' AND mobile != ''");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp Manager — CyberNet</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--bg:#f5f7fa;--card:#fff;--text:#1a202c;--text-muted:#718096;--border:#e2e8f0;--primary:#3b82f6;--success:#22c55e;--danger:#ef4444;--wa:#25D366}
[data-theme=dark]{--bg:#0f0f12;--card:#1a1a2e;--text:#eef2ff;--text-muted:#a0a0c0;--border:#2d2d44}
*{margin:0;padding:0;box-sizing:border-box;font-family:'IBM Plex Sans','Segoe UI',sans-serif}
body{background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:#111827;color:#fff;padding:10px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.topbar a{color:#fff;text-decoration:none;font-size:13px;padding:6px 12px;border-radius:6px}
.topbar a:hover{background:rgba(255,255,255,.1)}
.brand{font-weight:700;font-size:16px;display:flex;align-items:center;gap:8px}
.brand span{color:var(--wa)}
.container{max-width:900px;margin:0 auto;padding:16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;margin-bottom:16px;overflow:hidden}
.card-h{padding:12px 16px;border-bottom:1px solid var(--border);font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px;justify-content:space-between}
.card-b{padding:16px}
.form-control{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:14px;margin-bottom:10px}
textarea.form-control{min-height:100px;resize:vertical}
.btn{padding:10px 18px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
.btn-wa{background:var(--wa);color:#fff}
.btn-primary{background:var(--primary);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.btn:disabled{opacity:.5;cursor:not-allowed}
.status-dot{width:10px;height:10px;border-radius:50%;display:inline-block}
.dot-green{background:var(--success)}.dot-red{background:var(--danger)}.dot-yellow{background:#f59e0b}
#qrbox{text-align:center;padding:10px}
#qrbox img{max-width:260px;border:8px solid #fff;border-radius:8px}
.result{padding:10px;border-radius:8px;font-size:13px;margin-top:8px;display:none}
.result.ok{background:rgba(34,197,94,.1);color:var(--success);display:block}
.result.err{background:rgba(239,68,68,.1);color:var(--danger);display:block}
.hint{font-size:12px;color:var(--text-muted);margin-bottom:8px}
@media(max-width:400px){.container{padding:8px}.card-b{padding:12px}.btn{width:100%;justify-content:center;margin-bottom:6px}}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand"><i class="fab fa-whatsapp" style="color:var(--wa)"></i> WhatsApp <span>Manager</span></div>
  <a href="portal.php"><i class="fas fa-arrow-left"></i> Back to Portal</a>
</div>
<div class="container">

  <div class="card">
    <div class="card-h">
      <span><i class="fas fa-signal"></i> Connection Status</span>
      <span id="statusBadge"><span class="status-dot dot-yellow"></span> Checking…</span>
    </div>
    <div class="card-b">
      <button class="btn btn-primary" onclick="checkStatus()"><i class="fas fa-sync"></i> Refresh Status</button>
      <button class="btn btn-danger" onclick="reconnect()"><i class="fas fa-plug"></i> Reconnect (New QR)</button>
      <div id="qrbox"></div>
      <div id="statusResult" class="result"></div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span><i class="fas fa-paper-plane"></i> Send Single Message</span></div>
    <div class="card-b">
      <div class="hint">Saudi numbers: start with 05… | Other countries: type full number with country code (e.g. 8801712345678 for Bangladesh, 91… for India)</div>
      <input type="text" id="singlePhone" class="form-control" placeholder="Phone number (05xxxxxxxx or country code + number)">
      <textarea id="singleMsg" class="form-control" placeholder="Type your message…"></textarea>
      <button class="btn btn-wa" onclick="sendSingle()" id="sendBtn"><i class="fab fa-whatsapp"></i> Send Message</button>
      <div id="singleResult" class="result"></div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span><i class="fas fa-bullhorn"></i> Broadcast to All Customers</span><span style="font-size:12px;color:var(--text-muted)"><?= $total_active ?> active customers</span></div>
    <div class="card-b">
      <div class="hint">⚠️ Sends to ALL active customers with 1.5 sec delay between each. Takes about <?= ceil($total_active * 1.5 / 60) ?> minutes for <?= $total_active ?> customers. Do not close this page while sending.</div>
      <textarea id="broadcastMsg" class="form-control" placeholder="Type broadcast message…"></textarea>
      <button class="btn btn-wa" onclick="broadcast()" id="bcBtn"><i class="fas fa-bullhorn"></i> Send to All (<?= $total_active ?>)</button>
      <div id="bcResult" class="result"></div>
    </div>
  </div>

</div>
<script>
function show(id, msg, ok) {
  const el = document.getElementById(id);
  el.className = 'result ' + (ok ? 'ok' : 'err');
  el.textContent = msg;
}

function checkStatus() {
  document.getElementById('statusBadge').innerHTML = '<span class="status-dot dot-yellow"></span> Checking…';
  fetch('whatsapp_page.php?ajax=status').then(r => r.json()).then(d => {
    const s = (d.status || 'unknown').toUpperCase();
    let dot = 'dot-red';
    if (s === 'WORKING' || s === 'READY' || s === 'CONNECTED' || s === 'AUTHENTICATED') dot = 'dot-green';
    else if (s === 'SCAN_QR_CODE' || s === 'STARTING' || s === 'CONNECTING') dot = 'dot-yellow';
    document.getElementById('statusBadge').innerHTML = '<span class="status-dot ' + dot + '"></span> ' + s;
    if (s === 'SCAN_QR_CODE') loadQR();
    else document.getElementById('qrbox').innerHTML = '';
  }).catch(() => {
    document.getElementById('statusBadge').innerHTML = '<span class="status-dot dot-red"></span> OpenWA unreachable';
  });
}

function loadQR() {
  fetch('whatsapp_page.php?ajax=qr').then(r => r.json()).then(d => {
    if (d.qr) {
      document.getElementById('qrbox').innerHTML =
        '<p style="margin-bottom:8px;font-size:13px">📱 Scan with WhatsApp → Linked Devices</p><img src="' + d.qr + '">';
    } else {
      document.getElementById('qrbox').innerHTML = '<p style="font-size:13px;color:var(--text-muted)">QR not available yet — click Refresh in a few seconds.</p>';
    }
  });
}

function reconnect() {
  if (!confirm('Reconnect WhatsApp session? Current session will restart and a new QR will appear.')) return;
  show('statusResult', 'Restarting session… wait 5 seconds', true);
  fetch('whatsapp_page.php?ajax=reconnect').then(r => r.json()).then(() => {
    setTimeout(() => { checkStatus(); show('statusResult', 'Session restarted. If QR appears below, scan it.', true); }, 5000);
  });
}

function sendSingle() {
  const phone = document.getElementById('singlePhone').value.trim();
  const msg = document.getElementById('singleMsg').value.trim();
  if (!phone || !msg) { show('singleResult', 'Enter phone number and message', false); return; }
  const btn = document.getElementById('sendBtn');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('phone', phone); fd.append('message', msg);
  fetch('whatsapp_page.php?ajax=send', { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
      btn.disabled = false;
      if (d.ok) { show('singleResult', '✓ Sent to ' + d.sent_to, true); document.getElementById('singleMsg').value = ''; }
      else show('singleResult', '✗ ' + (d.error || 'Failed'), false);
    }).catch(() => { btn.disabled = false; show('singleResult', '✗ Network error', false); });
}

function broadcast() {
  const msg = document.getElementById('broadcastMsg').value.trim();
  if (!msg) { show('bcResult', 'Enter broadcast message', false); return; }
  if (!confirm('Send this message to ALL active customers? This cannot be undone.')) return;
  const btn = document.getElementById('bcBtn');
  btn.disabled = true;
  show('bcResult', '⏳ Sending… this takes several minutes. DO NOT close this page.', true);
  const fd = new FormData();
  fd.append('message', msg);
  fetch('whatsapp_page.php?ajax=broadcast', { method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
      btn.disabled = false;
      if (d.ok) show('bcResult', '✓ Broadcast complete. Sent: ' + d.sent + ' | Failed: ' + d.failed, true);
      else show('bcResult', '✗ ' + (d.error || 'Failed'), false);
    }).catch(() => { btn.disabled = false; show('bcResult', '✗ Error or timeout — check reminder_log or try smaller groups', false); });
}

checkStatus();
// Auto-refresh disabled: setInterval(checkStatus, 30000);
</script>
</body>
</html>
