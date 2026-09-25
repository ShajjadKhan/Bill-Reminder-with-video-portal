<?php
$db = new SQLite3(__DIR__ . '/bills.db');
$cid = intval($_GET['cid'] ?? 0);
if (!$cid) {
    echo "<div style='padding:20px;text-align:center;color:var(--text-muted)'>Invalid customer ID</div>";
    exit;
}

$cust = $db->querySingle("SELECT name, mobile, building, apartment, room FROM customers WHERE id=$cid", true);
$collections = $db->query("SELECT c.month_year, c.amount, c.collected_date, c.is_settled, COALESCE(u.fullname, u.username, 'System') as collector 
                          FROM collections c 
                          LEFT JOIN users u ON c.collected_by = u.id 
                          WHERE c.customer_id = $cid 
                          ORDER BY c.collected_date DESC");

$rows = [];
$total_paid = 0;
while ($c = $collections->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $c;
    $total_paid += floatval($c['amount']);
}

if (empty($rows)) {
    echo "<div style='text-align:center;padding:36px 16px;color:var(--text-muted)'>
            <i class='fas fa-receipt' style='font-size:32px;opacity:.35;display:block;margin-bottom:12px;color:var(--text-muted)'></i>
            <div style='font-weight:700;font-size:14px;color:var(--text)'>No Payment Records Found</div>
            <div style='font-size:12px;margin-top:4px;color:var(--text-muted)'>No collection history has been recorded for this customer yet.</div>
          </div>";
    exit;
}
?>
<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap;gap:8px">
  <div style="font-size:13px">
    <span style="color:var(--text-muted);font-weight:500">Total Payments:</span>
    <strong style="color:var(--text);margin-left:4px"><?= count($rows) ?> records</strong>
  </div>
  <div style="font-size:13px">
    <span style="color:var(--text-muted);font-weight:500">Total Collected:</span>
    <strong class="mono" style="font-size:16px;color:var(--success);margin-left:4px"><?= number_format($total_paid, 2) ?> SAR</strong>
  </div>
</div>

<div class="table-wrap" style="max-height:55vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
  <table style="width:100%;border-collapse:collapse;color:var(--text);font-size:13px;margin:0">
    <thead>
      <tr style="border-bottom:1px solid var(--border);background:var(--surface2)">
        <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:left">Month</th>
        <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:right">Amount</th>
        <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:left">Collected Date</th>
        <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:left">Collector</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:10px 14px;font-weight:600;color:var(--text)">
          <span class="badge badge-blue" style="font-size:11px;font-family:'IBM Plex Mono',monospace"><?= htmlspecialchars($r['month_year']) ?></span>
          <?php if (!empty($r['is_settled'])): ?>
            <span class="badge badge-green" style="font-size:9px;padding:2px 5px;margin-left:4px">✓ Settled</span>
          <?php endif; ?>
        </td>
        <td style="padding:10px 14px;text-align:right">
          <strong class="mono" style="color:var(--success);font-size:13.5px"><?= number_format(floatval($r['amount']), 2) ?> SAR</strong>
        </td>
        <td style="padding:10px 14px;font-size:12px;color:var(--text-muted)" class="mono">
          <?= !empty($r['collected_date']) ? date('d M Y, h:i A', strtotime($r['collected_date'])) : '—' ?>
        </td>
        <td style="padding:10px 14px;font-size:12.5px;color:var(--text)">
          <i class="fas fa-user-circle" style="color:var(--accent);margin-right:5px;font-size:11px"></i><?= htmlspecialchars($r['collector'] ?: 'System') ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
