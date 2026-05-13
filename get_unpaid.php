<?php
header('Content-Type: application/json');
$db = new SQLite3('bills.db');
$cid = intval($_GET['cid']);
$current_month = date('Y-m');
$cust = $db->querySingle("SELECT billing_start_date FROM customers WHERE id=$cid", true);
$unpaid = [];
if ($cust) {
    $start = new DateTime($cust['billing_start_date']);
    $now = new DateTime($current_month . '-01');
    $interval = new DateInterval('P1M');
    $period = new DatePeriod($start, $interval, $now->modify('+1 month'));
    foreach ($period as $dt) {
        $month_year = $dt->format('Y-m');
        $paid = $db->querySingle("SELECT SUM(amount) FROM collections WHERE customer_id=$cid AND month_year='$month_year'");
        $due = 30 - ($paid ?: 0);
        if ($due > 0) {
            $unpaid[] = ['month' => $month_year, 'due' => $due];
        }
    }
}
echo json_encode($unpaid);
?>
