<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: portal.php'); exit; }
$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
date_default_timezone_set('Asia/Riyadh');

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$from_esc = SQLite3::escapeString($from);
$to_esc   = SQLite3::escapeString($to);

$rows = $db->query("
  SELECT col.collected_date, col.amount, col.month_year, cu.name as customer_name, u.fullname as collector_name
  FROM collections col
  JOIN customers cu ON cu.id = col.customer_id
  LEFT JOIN users u ON u.id = col.collected_by
  WHERE date(col.collected_date) BETWEEN '$from_esc' AND '$to_esc' AND col.amount > 0
  ORDER BY col.collected_date ASC
");

$staff_totals = [];
$grand_total = 0;
$row_data = [];
while ($r = $rows->fetchArray(SQLITE3_ASSOC)) {
    $row_data[] = $r;
    $grand_total += $r['amount'];
    $name = $r['collector_name'] ?: 'Unknown';
    if (!isset($staff_totals[$name])) $staff_totals[$name] = ['count'=>0,'total'=>0];
    $staff_totals[$name]['count']++;
    $staff_totals[$name]['total'] += $r['amount'];
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>CyberNet Collection Report</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; color: #1a202c; background: #fff; padding: 30px; max-width: 900px; margin: 0 auto; }
  @media (max-width: 600px) {
    body { padding: 12px; }
    .header { flex-direction: column; align-items: flex-start; gap: 8px; }
    .meta { flex-direction: column; gap: 4px; }
    table { font-size: 11px; }
    th, td { padding: 5px 6px; }
  }
  .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #1a202c; padding-bottom: 16px; margin-bottom: 20px; }
  .header h1 { margin: 0; font-size: 24px; }
  .header .subtitle { color: #718096; font-size: 13px; margin-top: 4px; }
  .meta { display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 13px; color: #4a5568; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 13px; }
  th { background: #1a202c; color: #fff; padding: 8px 10px; text-align: left; }
  td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
  tr:nth-child(even) { background: #f7fafc; }
  .totals-table th { background: #2d3748; }
  .grand-total { background: #edf2f7; font-weight: bold; font-size: 15px; }
  .print-btn { position: fixed; top: 20px; right: 20px; padding: 10px 18px; background: #3182ce; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
  @media print { .print-btn { display: none; } body { padding: 0; } }
  .footer { margin-top: 30px; font-size: 11px; color: #a0aec0; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 12px; }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>

<div class="header">
  <div>
    <h1>📶 CyberNet ISP</h1>
    <div class="subtitle">Collection Report</div>
  </div>
  <div style="text-align:right;font-size:13px;color:#718096">
    Generated: <?= date('d M Y, H:i') ?><br>
    By: <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?>
  </div>
</div>

<div class="meta">
  <div><strong>Period:</strong> <?= date('d M Y', strtotime($from)) ?> → <?= date('d M Y', strtotime($to)) ?></div>
  <div><strong>Total Records:</strong> <?= count($row_data) ?></div>
</div>

<table class="totals-table">
  <thead><tr><th>Collector</th><th style="text-align:right">Records</th><th style="text-align:right">Total (SAR)</th></tr></thead>
  <tbody>
  <?php foreach ($staff_totals as $name => $st): ?>
    <tr><td><?= htmlspecialchars($name) ?></td><td style="text-align:right"><?= $st['count'] ?></td><td style="text-align:right"><?= number_format($st['total'],2) ?></td></tr>
  <?php endforeach; ?>
    <tr class="grand-total"><td>TOTAL</td><td style="text-align:right"><?= count($row_data) ?></td><td style="text-align:right"><?= number_format($grand_total,2) ?> SAR</td></tr>
  </tbody>
</table>

<table>
  <thead><tr><th>Date</th><th>Customer</th><th>Month Paid</th><th style="text-align:right">Amount (SAR)</th><th>Collected By</th></tr></thead>
  <tbody>
  <?php foreach ($row_data as $r): ?>
    <tr>
      <td><?= date('d M Y', strtotime($r['collected_date'])) ?></td>
      <td><?= htmlspecialchars($r['customer_name']) ?></td>
      <td><?= date('M Y', strtotime($r['month_year'].'-01')) ?></td>
      <td style="text-align:right"><?= number_format($r['amount'],2) ?></td>
      <td><?= htmlspecialchars($r['collector_name'] ?: '-') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($row_data)): ?>
    <tr><td colspan="5" style="text-align:center;color:#a0aec0;padding:20px">No collections in this period</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<div class="footer">CyberNet ISP — Riyadh, Saudi Arabia | Internal Report</div>

</body>
</html>
