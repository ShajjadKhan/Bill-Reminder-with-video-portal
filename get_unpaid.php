<?php
header('Content-Type: application/json');
$db  = new SQLite3('/home/tserver/billing_reminder/bills.db');
$cid = intval($_GET['cid'] ?? 0);

$c = $db->querySingle("SELECT billing_start_date, billing_day, monthly_fee FROM customers WHERE id=$cid", true);
if (!$c) { echo json_encode([]); exit; }

$fee = max(1, floatval($c['monthly_fee'] ?: 30));
$bd  = (int)($c['billing_day'] ?: 1);

$dt       = new DateTime($c['billing_start_date'] ?: date('Y-m-01'));
$join_day = (int)$dt->format('j');
if ($join_day > $bd) {
    $dt->modify('first day of next month');
} else {
    $dt = new DateTime($dt->format('Y-m').'-01');
}

$end = (new DateTime(date('Y-m-01')))->modify('+1 month');
$months = [];
$period = new DatePeriod($dt, new DateInterval('P1M'), $end);
foreach ($period as $d) $months[] = $d->format('Y-m');

$res  = $db->query("SELECT month_year, SUM(amount) as total, MAX(is_settled) as settled FROM collections WHERE customer_id=$cid GROUP BY month_year");
$paid = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $paid[$r['month_year']] = ['total'=>floatval($r['total']),'settled'=>intval($r['settled'])];

$unpaid = [];
foreach ($months as $m) {
    $p = $paid[$m] ?? null;
    if ($p && ($p['settled'] == 1 || $p['total'] == 0)) continue;
    $paid_amt = $p ? $p['total'] : 0;
    $due = round($fee - $paid_amt, 2);
    if ($due > 0) $unpaid[] = ['month' => $m, 'due' => $due, 'fee' => $fee];
}

echo json_encode($unpaid);
