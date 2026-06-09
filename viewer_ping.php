<?php
// Simple viewer counter using temp files
$dir = '/tmp/stream_viewers/';
if (!is_dir($dir)) mkdir($dir, 0777, true);

$action = $_GET['action'] ?? 'ping';
$vid = $_GET['vid'] ?? '';
if (!$vid || !preg_match('/^[a-zA-Z0-9]+$/', $vid)) {
    echo json_encode(['count'=>0]); exit;
}

$file = $dir . $vid;

if ($action === 'leave') {
    @unlink($file);
} else {
    file_put_contents($file, time());
}

// Count active viewers (pinged in last 45 seconds)
$count = 0;
foreach (glob($dir . '*') as $f) {
    if (time() - filemtime($f) < 45) $count++;
}
echo json_encode(['count' => $count]);
