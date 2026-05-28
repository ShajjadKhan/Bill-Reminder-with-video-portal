<?php
$db  = new SQLite3('/home/tserver/billing_reminder/bills.db');
$cid = intval($_GET['cid'] ?? 0);

$cust = $db->querySingle("SELECT name, billing_start_date, billing_day, monthly_fee FROM customers WHERE id=$cid", true);
if (!$cust) { echo '<p>Customer not found.</p>'; exit; }

$fee = floatval($cust['monthly_fee'] ?: 30);
$bd  = (int)($cust['billing_day'] ?: 1);

$dt       = new DateTime($cust['billing_start_date'] ?: date('Y-m-01'));
$join_day = (int)$dt->format('j');
if ($join_day > $bd) {
    $dt->modify('first day of next month');
} else {
    $dt = new DateTime($dt->format('Y-m').'-01');
}

$end    = (new DateTime(date('Y-m-01')))->modify('+1 month');
$months = [];
$period = new DatePeriod($dt, new DateInterval('P1M'), $end);
foreach ($period as $d) $months[] = $d->format('Y-m');

$res  = $db->query("SELECT month_year, SUM(amount) as total FROM collections WHERE customer_id=$cid GROUP BY month_year");
$paid = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $paid[$r['month_year']] = $r;

$total_due  = 0;
$total_paid = 0;

echo "<div style='font-size:13px'>";
echo "<div style='margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--border)'>";
echo "<strong style='font-size:15px'>{$cust['name']}</strong><br>";
echo "<span style='color:var(--text-muted);font-size:12px'>Monthly fee: $fee SAR · Billing day: $bd</span>";
echo "</div>";
echo "<div style='overflow-x:auto'><table style='width:100%;border-collapse:collapse;font-size:12px'>";
echo "<thead><tr style='background:var(--surface2)'>
    <th style='padding:7px 10px;border-bottom:2px solid var(--border);text-align:left;color:var(--text-muted);font-size:11px'>Month</th>
    <th style='padding:7px 10px;border-bottom:2px solid var(--border);text-align:right;color:var(--text-muted);font-size:11px'>Fee</th>
    <th style='padding:7px 10px;border-bottom:2px solid var(--border);text-align:right;color:var(--text-muted);font-size:11px'>Paid</th>
    <th style='padding:7px 10px;border-bottom:2px solid var(--border);text-align:right;color:var(--text-muted);font-size:11px'>Balance</th>
    <th style='padding:7px 10px;border-bottom:2px solid var(--border);text-align:center;color:var(--text-muted);font-size:11px'>Status</th>
</tr></thead><tbody>";

foreach ($months as $m) {
    $p      = isset($paid[$m]) ? floatval($paid[$m]['total']) : 0;
    $due    = round($fee - $p, 2);
    $total_paid += $p;
    if ($due > 0) $total_due += $due;
    $label   = date('F Y', strtotime($m.'-01'));
    $status  = $due <= 0 ? "<span style='color:var(--success);font-weight:600'>✓ Paid</span>"
                         : ($p > 0 ? "<span style='color:var(--warning);font-weight:600'>Partial</span>"
                                   : "<span style='color:var(--danger);font-weight:600'>Unpaid</span>");
    $bg      = $due <= 0 ? '' : ($p > 0 ? 'background:rgba(245,158,11,.05)' : 'background:rgba(239,68,68,.05)');
    echo "<tr style='border-bottom:1px solid var(--border);$bg'>
        <td style='padding:7px 10px'><strong>$label</strong></td>
        <td style='padding:7px 10px;text-align:right;font-family:monospace'>$fee</td>
        <td style='padding:7px 10px;text-align:right;font-family:monospace;color:var(--success)'>$p</td>
        <td style='padding:7px 10px;text-align:right;font-family:monospace;color:".($due>0?'var(--danger)':'var(--success)')."'>$due</td>
        <td style='padding:7px 10px;text-align:center'>$status</td>
    </tr>";
}

echo "</tbody><tfoot><tr style='background:var(--surface2);font-weight:700'>
    <td style='padding:8px 10px'>TOTAL</td>
    <td style='padding:8px 10px;text-align:right;font-family:monospace'>".($fee*count($months))."</td>
    <td style='padding:8px 10px;text-align:right;font-family:monospace;color:var(--success)'>$total_paid</td>
    <td style='padding:8px 10px;text-align:right;font-family:monospace;color:var(--danger)'>$total_due</td>
    <td></td>
</tr></tfoot></table></div></div>";

