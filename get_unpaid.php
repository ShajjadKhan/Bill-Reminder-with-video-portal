<?php
header('Content-Type: application/json');
$db = new SQLite3(__DIR__ . '/bills.db');
$cid = intval($_GET['cid'] ?? 0);
if (!$cid) {
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/billing_logic.php';

$unpaid = getUnpaidMonths($db, $cid);
echo json_encode($unpaid);
