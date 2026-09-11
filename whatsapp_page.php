<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: portal.php'); exit; }

$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
date_default_timezone_set('Asia/Riyadh');

// OpenWA config – will be updated dynamically
$WA_BASE = "http://localhost:2785";
$WA_KEY  = "dev-admin-key";
$WA_SID  = "8cc17322-a9d3-4b88-89ac-d4d95fb57ff4"; // current session

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

function waPhone($raw) {
    $p = preg_replace('/[^0-9]/', '', trim($raw));
    if (substr($p, 0, 1) === '0') $p = '966' . ltrim($p, '0');
    return $p;
}

// AJAX handlers
$ajax = $_GET['ajax'] ?? '';
if ($ajax === 'status') {
    header('Content-Type: application/json');
    $r = waCall('GET', "/api/sessions/$WA_SID");
    echo json_encode(['status' => $r['status'] ?? 'unknown', 'phone' => $r['phone'] ?? null, 'pushName' => $r['pushName'] ?? null]);
    exit;
}
if ($ajax === 'qr') {
    header('Content-Type: application/json');
    $r = waCall('GET', "/api/sessions/$WA_SID/qr");
    echo json_encode(['qr' => $r['qrCode'] ?? null]);
    exit;
}
if ($ajax === 'reconnect') {
    header('Content-Type: application/json');
    waCall('POST', "/api/sessions/$WA_SID/stop");
    sleep(1);
    $r = waCall('POST', "/api/sessions/$WA_SID/start");
    echo json_encode(['result' => $r]);
    exit;
}
if ($ajax === 'recipients') {
    header('Content-Type: application/json');
    $custs = $db->query("SELECT name, mobile FROM customers WHERE status='active' AND mobile != '' ORDER BY name");
    $out = [];
    while ($c = $custs->fetchArray(SQLITE3_ASSOC)) {
        $out[] = ['name' => $c['name'], 'mobile' => $c['mobile']];
    }
    echo json_encode($out);
    exit;
}
if ($ajax === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $phone = waPhone($_POST['phone'] ?? '');
    $text  = trim($_POST['message'] ?? '');
    if (!$phone || !$text) { echo json_encode(['ok' => false, 'error' => 'Phone and message required']); exit; }
    $r = waCall('POST', "/api/sessions/$WA_SID/messages/send-text", ['chatId' => $phone . '@c.us', 'text' => $text]);
    // OpenWA may return 500 even when message is sent – treat any non-null response as success
    $ok = ($r !== null);
    echo json_encode(['ok' => $ok, 'result' => $r, 'sent_to' => $phone]);
    exit;
}
if ($ajax === 'broadcast' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_time_limit(0);
    $text = trim($_POST['message'] ?? '');
    if (!$text) { echo json_encode(['ok' => false, 'error' => 'Message required']); exit; }
    $custs = $db->query("SELECT name, mobile FROM customers WHERE status='active' AND mobile != ''");
    $sent = 0; $failed = 0;
    while ($c = $custs->fetchArray(SQLITE3_ASSOC)) {
        $phone = waPhone($c['mobile']);
        if (!$phone) { $failed++; continue; }
        $r = waCall('POST', "/api/sessions/$WA_SID/messages/send-text", ['chatId' => $phone . '@c.us', 'text' => $text]);
        if ($r !== null) $sent++; else $failed++;
        usleep(1500000);
    }
    echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
    exit;
}

$total_active = $db->querySingle("SELECT COUNT(*) FROM customers WHERE status='active' AND mobile != ''");
$dark = isset($_COOKIE['darkMode']) && $_COOKIE['darkMode'] === 'enabled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>WhatsApp Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f5f7fa; transition: background 0.2s; }
        body.dark { background: #0f0f12; color: #eef2ff; }
        body.dark .card, body.dark .modal-content { background: #1a1a2e; color: #eef2ff; border-color: #2d2d44; }
        body.dark .form-control, body.dark .form-select { background: #2d2d44; color: #eef2ff; border-color: #3d3d5c; }
        body.dark .card-header { background: #1a1a2e; border-color: #2d2d44; }
        .status-badge { font-size: 0.9rem; padding: 0.5rem 1rem; border-radius: 20px; }
        .status-ready { background: #25D36620; color: #25D366; }
        .status-disconnected { background: #dc354520; color: #dc3545; }
        .status-connecting { background: #ffc10720; color: #ffc107; }
        .qr-container img { max-width: 280px; border: 4px solid #fff; border-radius: 12px; }
        .dark .qr-container img { border-color: #2d2d44; }
        .btn-wa { background: #25D366; color: #fff; }
        .btn-wa:hover { background: #128C7E; color: #fff; }
        .card { border: 1px solid #e2e8f0; border-radius: 12px; }
        .dark .card { border-color: #2d2d44; }
        .card-header { background: transparent; border-bottom: 1px solid #e2e8f0; font-weight: 600; }
        .dark .card-header { border-color: #2d2d44; }
        .hint { font-size: 0.85rem; color: #6c757d; }
        .dark .hint { color: #a0a0c0; }
        @media(max-width: 768px) {
            .container { padding-left: 12px !important; padding-right: 12px !important; }
            input, select, textarea, .form-control, .form-select { font-size: 16px !important; min-height: 44px; }
            .btn-wa { width: 100%; min-height: 44px; font-size: 15px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
            .qr-container img { max-width: 220px; }
        }
    </style>
</head>
<body class="<?= $dark ? 'dark' : '' ?>">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><i class="fab fa-whatsapp text-success"></i> WhatsApp Manager</h4>
        <div>
            <button class="btn btn-outline-secondary btn-sm me-2" onclick="toggleDark()"><i class="fas fa-moon"></i></button>
            <a href="portal.php" class="btn btn-primary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <!-- Status Card -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-signal"></i> Connection Status</span>
            <span id="statusBadge"><span class="badge bg-secondary">Checking…</span></span>
        </div>
        <div class="card-body text-center">
            <div id="qrContainer" class="qr-container my-3"></div>
            <div id="statusDetails" class="mb-3"></div>
            <div class="d-flex justify-content-center gap-2">
                <button class="btn btn-outline-primary btn-sm" onclick="checkStatus()"><i class="fas fa-sync"></i> Refresh</button>
                <button class="btn btn-danger btn-sm" onclick="reconnect()"><i class="fas fa-plug"></i> Reconnect (New QR)</button>
            </div>
            <div id="statusMsg" class="mt-3 small"></div>
        </div>
    </div>

    <!-- Single Message -->
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-paper-plane"></i> Send Single Message</div>
        <div class="card-body">
            <div class="hint mb-2">Saudi: 05xxxxxxxx | Other: country code + number (e.g. 8801712345678)</div>
            <input type="text" id="singlePhone" class="form-control mb-2" placeholder="Phone number">
            <textarea id="singleMsg" class="form-control mb-2" rows="3" placeholder="Message text"></textarea>
            <button class="btn btn-wa" onclick="sendSingle()"><i class="fab fa-whatsapp"></i> Send</button>
            <div id="singleResult" class="mt-2"></div>
        </div>
    </div>

    <!-- Broadcast -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-bullhorn"></i> Broadcast</span>
            <span class="badge bg-secondary"><?= $total_active ?> active customers</span>
        </div>
        <div class="card-body">
            <div class="mb-2">
                <label class="form-label small text-muted">Quick Template (use {name} to personalize)</label>
                <select class="form-select form-select-sm mb-2" onchange="if(this.value){document.getElementById('broadcastMsg').value=this.value;}">
                    <option value="">-- Choose a template or type custom message --</option>
                    <option value="Assalamu Alaikum {name}! Your CyberNet internet bill is due soon. Please recharge on time to avoid service interruption. Movies: http://10.12.14.16:8082 | Live Sports: http://10.12.14.16:8086">📶 Bill Due Reminder</option>
                    <option value="🎬 NEW MOVIES ADDED! Enjoy free movies & shows on our local entertainment portal: http://10.12.14.16:8082 (Connect to WiFi first)">🎬 Free Movie Portal Update</option>
                    <option value="⚽ LIVE FOOTBALL TONIGHT! Watch live sports and matches on our local player: http://10.12.14.16:8086 Enjoy!">⚽ Live Football & Sports</option>
                    <option value="⚠️ CyberNet Network Notice: Scheduled network maintenance tonight from 2:00 AM to 3:00 AM. Thank you for your patience.">⚠️ Network Maintenance Notice</option>
                </select>
            </div>
            <textarea id="broadcastMsg" class="form-control mb-2" rows="4" placeholder="Type your broadcast message here. Use {name} for customer name."></textarea>
            <div class="d-flex gap-2 align-items-center">
                <button id="bcBtn" class="btn btn-wa" onclick="broadcast()"><i class="fas fa-bullhorn"></i> Send to All (<?= $total_active ?>)</button>
                <button id="bcCancelBtn" class="btn btn-outline-secondary btn-sm" onclick="bcCancelled=true;this.disabled=true;" style="display:none"><i class="fas fa-pause"></i> Stop</button>
            </div>
            <div id="broadcastProgress" class="mt-3" style="display:none">
                <div class="progress" style="height:14px;border-radius:7px">
                    <div id="bcProgressBar" class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                </div>
                <div class="small text-muted mt-1 d-flex justify-content-between">
                    <span id="bcStatusDetail">Preparing...</span>
                    <span id="bcProgressPercent">0%</span>
                </div>
            </div>
            <div id="broadcastResult" class="mt-2"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showResult(id, msg, ok) {
    const el = document.getElementById(id);
    el.innerHTML = `<div class="alert alert-${ok ? 'success' : 'danger'} alert-dismissible fade show">${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
}

function checkStatus() {
    const badge = document.getElementById('statusBadge');
    badge.innerHTML = '<span class="badge bg-secondary">Checking…</span>';
    fetch('whatsapp_page.php?ajax=status')
        .then(r => r.json())
        .then(d => {
            const s = (d.status || 'unknown').toUpperCase();
            const cls = s === 'READY' || s === 'AUTHENTICATED' ? 'bg-success' :
                        s === 'SCAN_QR_CODE' || s === 'CONNECTING' || s === 'INITIALIZING' ? 'bg-warning' : 'bg-danger';
            badge.innerHTML = `<span class="badge ${cls}">${s}</span>`;
            document.getElementById('statusDetails').innerHTML = d.phone ? `📱 ${d.phone} ${d.pushName ? '— ' + d.pushName : ''}` : '';
            if (s === 'SCAN_QR_CODE' || s === 'CONNECTING' || s === 'INITIALIZING') loadQR();
            else document.getElementById('qrContainer').innerHTML = '';
            showResult('statusMsg', '', true);
        })
        .catch(() => {
            badge.innerHTML = '<span class="badge bg-danger">Unreachable</span>';
            showResult('statusMsg', 'OpenWA is not responding. Check container.', false);
        });
}

function loadQR() {
    fetch('whatsapp_page.php?ajax=qr')
        .then(r => r.json())
        .then(d => {
            if (d.qr) {
                document.getElementById('qrContainer').innerHTML = `<img src="${d.qr}" alt="QR Code"><br><small class="text-muted">Scan with WhatsApp → Linked Devices</small>`;
            } else {
                document.getElementById('qrContainer').innerHTML = '<p class="text-muted">QR not ready yet. Refresh in a few seconds.</p>';
            }
        });
}

function reconnect() {
    if (!confirm('Restart WhatsApp session? A new QR will be generated.')) return;
    showResult('statusMsg', 'Restarting… wait 5 seconds.', true);
    fetch('whatsapp_page.php?ajax=reconnect')
        .then(() => {
            setTimeout(() => {
                checkStatus();
                setTimeout(loadQR, 2000);
            }, 5000);
        });
}

function sendSingle() {
    const phone = document.getElementById('singlePhone').value.trim();
    const msg = document.getElementById('singleMsg').value.trim();
    if (!phone || !msg) { showResult('singleResult', 'Enter phone and message', false); return; }
    const fd = new FormData();
    fd.append('phone', phone);
    fd.append('message', msg);
    fetch('whatsapp_page.php?ajax=send', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) showResult('singleResult', `✓ Sent to ${d.sent_to}`, true);
            else showResult('singleResult', '✗ ' + (d.error || 'Failed'), false);
        })
        .catch(() => showResult('singleResult', '✗ Network error', false));
}

let bcCancelled = false;
async function broadcast() {
    const msg = document.getElementById('broadcastMsg').value.trim();
    if (!msg) { showResult('broadcastResult', 'Please enter a message to broadcast.', false); return; }
    if (!confirm('Send to ALL active customers? Messages will be sent with delivery tracking without browser timeouts.')) return;

    const btn = document.getElementById('bcBtn');
    const cancelBtn = document.getElementById('bcCancelBtn');
    btn.disabled = true;
    cancelBtn.style.display = 'inline-block';
    cancelBtn.disabled = false;
    bcCancelled = false;

    showResult('broadcastResult', 'Loading recipient list...', true);
    const prog = document.getElementById('broadcastProgress');
    const bar = document.getElementById('bcProgressBar');
    const stat = document.getElementById('bcStatusDetail');
    const pctEl = document.getElementById('bcProgressPercent');
    prog.style.display = 'block';
    bar.classList.add('progress-bar-animated');

    let custs = [];
    try {
        const res = await fetch('whatsapp_page.php?ajax=recipients');
        custs = await res.json();
    } catch(e) {
        showResult('broadcastResult', 'Failed to load recipients list.', false);
        btn.disabled = false;
        cancelBtn.style.display = 'none';
        return;
    }

    if (!custs || !custs.length) {
        showResult('broadcastResult', 'No active customers found.', false);
        btn.disabled = false;
        cancelBtn.style.display = 'none';
        return;
    }

    let sent = 0, failed = 0;
    for (let i = 0; i < custs.length; i++) {
        if (bcCancelled) break;
        const c = custs[i];
        const personalizedMsg = msg.replace('{name}', c.name);
        const pct = Math.round(((i + 1) / custs.length) * 100);
        bar.style.width = pct + '%';
        pctEl.textContent = pct + '% (' + (i + 1) + '/' + custs.length + ')';
        stat.textContent = 'Sending to ' + c.name + ' (' + c.mobile + ')...';

        const fd = new FormData();
        fd.append('phone', c.mobile);
        fd.append('message', personalizedMsg);

        try {
            const r = await fetch('whatsapp_page.php?ajax=send', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.ok) sent++; else failed++;
        } catch(e) {
            failed++;
        }

        // Wait 1.3s between messages to prevent rate-limiting
        await new Promise(resolve => setTimeout(resolve, 1300));
    }

    btn.disabled = false;
    cancelBtn.style.display = 'none';
    bar.classList.remove('progress-bar-animated');

    if (bcCancelled) {
        showResult('broadcastResult', `⏸️ Broadcast stopped. Sent: ${sent} | Failed: ${failed}`, false);
    } else {
        showResult('broadcastResult', `✓ Broadcast complete! Sent: ${sent} | Failed: ${failed}`, true);
    }
}

function toggleDark() {
    document.body.classList.toggle('dark');
    const isDark = document.body.classList.contains('dark');
    document.cookie = 'darkMode=' + (isDark ? 'enabled' : 'disabled') + '; path=/';
}

// Initial check
checkStatus();
setInterval(checkStatus, 30000);
</script>
</body>
</html>
