<?php
date_default_timezone_set('Asia/Riyadh');
define('DB_PATH', '/home/tserver/cybernet/cybernet.db');
$db = new SQLite3(DB_PATH);
$stream_title = $db->querySingle("SELECT value FROM settings WHERE key='stream_title'") ?: 'CyberNet Live';
$stream_desc  = $db->querySingle("SELECT value FROM settings WHERE key='stream_desc'") ?: 'Live broadcast';
$is_live = file_exists('/tmp/hls/cybernet2026.m3u8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($stream_title)?> — CyberNet Live</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d1117;color:#e6edf3;font-family:'IBM Plex Sans',sans-serif;min-height:100vh}
.topbar{background:#010409;border-bottom:1px solid #30363d;padding:0 20px;height:52px;display:flex;align-items:center;gap:12px}
.brand{font-weight:700;font-size:15px;color:#fff;display:flex;align-items:center;gap:8px}
.brand span{color:#0ea5e9}
.live-badge{background:#e53e3e;color:#fff;border-radius:4px;padding:3px 8px;font-size:11px;font-weight:700;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
.offline-badge{background:#30363d;color:#8b949e;border-radius:4px;padding:3px 8px;font-size:11px;font-weight:700}
.main{max-width:960px;margin:0 auto;padding:20px}
.player-wrap{background:#000;border-radius:12px;overflow:hidden;aspect-ratio:16/9;position:relative;margin-bottom:16px}
video{width:100%;height:100%;display:block}
.offline-screen{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#8b949e;gap:12px}
.offline-screen i{font-size:48px;color:#30363d}
.offline-screen h2{font-size:18px;font-weight:600}
.offline-screen p{font-size:13px;text-align:center;max-width:300px;line-height:1.5}
.stream-info{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:16px}
.stream-title{font-size:18px;font-weight:700;margin-bottom:6px}
.stream-meta{font-size:13px;color:#8b949e;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.viewer-count{display:flex;align-items:center;gap:5px;font-size:13px;color:#8b949e}
@media(max-width:600px){.main{padding:10px}}
</style>
</head>
<body>
<nav class="topbar">
 <div class="brand"><i class="fas fa-wifi" style="color:#0ea5e9"></i>Cyber<span>Net</span> Live</div>
 <?php if($is_live):?>
 <span class="live-badge">● LIVE</span>
 <?php else:?>
 <span class="offline-badge">● OFFLINE</span>
 <?php endif;?>
</nav>
<div class="main">
 <div class="player-wrap">
  <?php if($is_live):?>
  <video id="video" controls autoplay playsinline></video>
  <?php else:?>
  <div class="offline-screen">
   <i class="fas fa-broadcast-tower"></i>
   <h2>Stream is Offline</h2>
   <p>The live broadcast has not started yet. Please check back later.</p>
   <p style="margin-top:8px;font-size:12px">This page refreshes automatically.</p>
  </div>
  <?php endif;?>
 </div>
 <div class="stream-info">
  <div class="stream-title"><?=htmlspecialchars($stream_title)?></div>
  <div class="stream-meta">
   <span><?=htmlspecialchars($stream_desc)?></span>
   <span class="viewer-count"><i class="fas fa-eye"></i> <span id="vc">...</span> watching</span>
  </div>
 </div>
</div>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<?php if($is_live):?>
<script>
var video = document.getElementById('video');
var hls_url = '/hls/cybernet2026.m3u8';
if (Hls.isSupported()) {
    var hls = new Hls({liveSyncDurationCount:3,liveMaxLatencyDurationCount:6,maxBufferLength:30,maxMaxBufferLength:60,enableWorker:true});
    hls.loadSource(hls_url);
    hls.attachMedia(video);
    hls.on(Hls.Events.MANIFEST_PARSED, function(){video.play();});
} else if (video.canPlayType('application/vnd.apple.mpegurl')) {
    video.src = hls_url;
    video.play();
}
</script>
<?php endif;?>
<script>
// Generate unique viewer ID
var _vid = sessionStorage.getItem('_vid');
if(!_vid){_vid=Math.random().toString(36).substr(2,10);sessionStorage.setItem('_vid',_vid);}

// Ping viewer counter every 30s
function pingViewer(){
  fetch('viewer_ping.php?vid='+_vid+'&action=ping')
    .then(function(r){return r.json();})
    .then(function(d){
      var el=document.getElementById('vc');
      if(el) el.textContent=d.count;
    }).catch(function(){});
}
pingViewer();
setInterval(pingViewer, 30000);

// On leave
window.addEventListener('beforeunload',function(){
  navigator.sendBeacon('viewer_ping.php?vid='+_vid+'&action=leave');
});

// Poll stream status without page reload
setInterval(function(){
  fetch("/hls/cybernet2026.m3u8",{cache:"no-store"}).then(function(r){
  }).catch(function(){window.location.reload();});
}, 20000);
</script>
</body>
</html>
