<?php
header('Content-Type: application/json');
$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
$q  = '%'.SQLite3::escapeString(trim($_GET['q'] ?? '')).'%';
$res = $db->query("SELECT id, name, mobile, building, apartment, room
    FROM customers WHERE status='active'
    AND (name LIKE '$q' OR mobile LIKE '$q' OR building LIKE '$q' OR room LIKE '$q')
    ORDER BY name LIMIT 15");
$out = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
echo json_encode($out);
