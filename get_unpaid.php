<?php
header('Content-Type: application/json');
$db = new SQLite3('bills.db');
$cid = intval($_GET['cid']);
$cust = $db->querySingle("SELECT billing_start_date FROM customers WHERE id=$cid", true);
if (!$cust) { echo json_encode([]); exit; }

$start = new DateTime($cust['billing_start_date']);
$now = new DateTime(date('Y-m-01'));
$months = [];
$period = new DatePeriod($start, new DateInterval('P1M'), $now->modify('+1 month'));
foreach ($period as $dt) { $months[] = $dt->format('Y-m'); }

// Get all collections for this customer
$collections = $db->query("SELECT month_year, SUM(amount) as total FROM collections WHERE customer_id=$cid GROUP BY month_year");
$paid_map = [];
while ($col = $collections->fetchArray(SQLITE3_ASSOC)) {
    $paid_map[$col['month_year']] = floatval($col['total']);
}

// Calculate remaining unpaid months by applying payments to oldest months first
$unpaid = [];
$remaining_payment = 0;
foreach (array_reverse($months) as $month) {
    $paid = isset($paid_map[$month]) ? $paid_map[$month] : 0;
    $due = 30 - $paid;
    if ($due > 0) {
        array_unshift($unpaid, ['month' => $month, 'due' => $due]);
    }
}
echo json_encode($unpaid);
?>
