<?php
header('Content-Type: application/json');
$db = new SQLite3('bills.db');
$q = SQLite3::escapeString($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}
$results = $db->query("SELECT id, name, mobile, building, apartment, room, billing_day, billing_start_date 
                       FROM customers 
                       WHERE name LIKE '%$q%' OR mobile LIKE '%$q%' OR building LIKE '%$q%' OR room LIKE '%$q%'
                       LIMIT 20");
$data = [];
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $data[] = $row;
}
echo json_encode($data);
?>
