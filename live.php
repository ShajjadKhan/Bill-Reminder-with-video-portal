<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Live TV - ISP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white">
<div class="container text-center py-5">
    <h1>🏆 Live Stream 🏆</h1>
    <div id="status" class="alert alert-info">Connecting...</div>
    <div id="videoContainer"></div>
</div>
<script>
let ws = new WebSocket('ws://' + window.location.hostname + ':8085/live/ws');
let viewerId = Math.random().toString(36).substring(7);

ws.onopen = function() {
    ws.send(JSON.stringify({type: 'join', viewerId: viewerId}));
    document.getElementById('status').innerHTML = 'Connected. Waiting for stream...';
};

ws.onmessage = function(e) {
    let data = JSON.parse(e.data);
    if (data.type === 'count') {
        document.getElementById('status').innerHTML = 'Viewers: ' + data.count + '/30';
    } else if (data.type === 'busy') {
        document.getElementById('status').innerHTML = '<div class="alert alert-danger">Server full (30 viewers). Try later.</div>';
    } else if (data.type === 'stream') {
        document.getElementById('videoContainer').innerHTML = '<video controls autoplay class="w-100"><source src="' + data.url + '" type="video/mp4">Not supported</video>';
        document.getElementById('status').innerHTML = 'Streaming live...';
    }
};

ws.onclose = function() {
    document.getElementById('status').innerHTML = '<div class="alert alert-warning">Disconnected. Reload page.</div>';
};
</script>
</body>
</html>
