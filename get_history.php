<?php
$db = new SQLite3(__DIR__ . '/bills.db');
require_once __DIR__ . '/billing_logic.php';

$cid = intval($_GET['cid'] ?? 0);
if (!$cid) {
    echo "<div style='padding:24px;text-align:center;color:var(--text-muted)'>Invalid customer ID</div>";
    exit;
}

$cust = $db->querySingle("SELECT id, name, mobile, building, apartment, room, billing_start_date, billing_day, monthly_fee, status, COALESCE(reminders_enabled, 1) as reminders_enabled FROM customers WHERE id=$cid", true);
if (!$cust) {
    echo "<div style='padding:24px;text-align:center;color:var(--text-muted)'>Customer not found</div>";
    exit;
}

$bal = getCustomerBalance($db, $cid);
$collections = $db->query("SELECT c.id, c.month_year, c.amount, c.collected_date, c.is_settled, COALESCE(u.fullname, u.username, 'System') as collector 
                          FROM collections c 
                          LEFT JOIN users u ON c.collected_by = u.id 
                          WHERE c.customer_id = $cid 
                          ORDER BY c.collected_date DESC, c.id DESC");

$rows = [];
$total_paid = 0;
while ($c = $collections->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $c;
    $total_paid += floatval($c['amount']);
}

// Vacation holds
$vholds = [];
$vh_res = $db->query("SELECT hold_start, hold_end, reason, created_date FROM vacation_holds WHERE customer_id=$cid ORDER BY hold_start DESC");
while ($vh = $vh_res->fetchArray(SQLITE3_ASSOC)) {
    $vholds[] = $vh;
}

$phone_clean = preg_replace('/^0+/', '', trim($cust['mobile']));
if (!preg_match('/^966/', $phone_clean)) $phone_clean = '966' . $phone_clean;
$wa_url = "https://wa.me/" . $phone_clean;

$bal_val = $bal ? floatval($bal['balance']) : 0;
$bal_status = $bal_val > 0 ? 'CREDIT' : ($bal_val == 0 ? 'SETTLED' : 'OWING');
$bal_badge = $bal_val > 0 ? 'badge-green' : ($bal_val == 0 ? 'badge-blue' : 'badge-red');
?>
<!-- Customer Info Header Card -->
<div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:14px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
    <div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <h4 style="margin:0;font-size:16px;font-weight:700;color:var(--text)"><?= htmlspecialchars($cust['name']) ?></h4>
        <span class="badge <?= $bal_badge ?>" style="font-size:11px"><?= $bal_status ?></span>
        <?php if (!empty($cust['status']) && $cust['status'] !== 'active'): ?>
          <span class="badge" style="background:#6b7280;color:#fff;font-size:10px"><?= strtoupper($cust['status']) ?></span>
        <?php endif; ?>
      </div>
      <div style="font-size:12.5px;color:var(--text-muted);margin-top:4px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        <span><i class="fas fa-phone" style="margin-right:4px"></i><a href="tel:<?= $cust['mobile'] ?>" style="color:var(--text);font-family:'IBM Plex Mono',monospace"><?= $cust['mobile'] ?></a></span>
        <?php if (!empty($cust['building']) || !empty($cust['room'])): ?>
          <span><i class="fas fa-map-marker-alt" style="margin-right:4px"></i><?= htmlspecialchars(trim(($cust['building'] ?? '') . ' ' . ($cust['apartment'] ?? '') . ' ' . (!empty($cust['room']) ? 'R' . $cust['room'] : ''))) ?></span>
        <?php endif; ?>
        <?php if (!empty($cust['billing_start_date'])): ?>
          <span><i class="fas fa-calendar-alt" style="margin-right:4px"></i>Since: <?= date('d M Y', strtotime($cust['billing_start_date'])) ?></span>
        <?php endif; ?>
        <span><i class="fas fa-tag" style="margin-right:4px"></i><?= number_format(floatval($cust['monthly_fee'] ?: 30), 2) ?> SAR/mo</span>
      </div>
    </div>
    <div style="display:flex;gap:6px;align-items:center">
      <a href="<?= $wa_url ?>" target="_blank" class="btn btn-whatsapp btn-xs" style="text-decoration:none"><i class="fab fa-whatsapp"></i> WhatsApp</a>
      <a href="tel:<?= $cust['mobile'] ?>" class="btn btn-secondary btn-xs"><i class="fas fa-phone"></i> Call</a>
    </div>
  </div>
</div>

<!-- Financial & Balance Metrics Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;margin-bottom:14px">
  <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:10px 12px">
    <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px">Billable Days</div>
    <div class="mono" style="font-size:17px;font-weight:700;color:var(--text);margin-top:2px"><?= $bal['billable_days'] ?? '—' ?> <span style="font-size:11px;font-weight:400;color:var(--text-muted)">days</span></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:1px">Owed: <strong><?= number_format($bal['total_owed'] ?? 0, 2) ?> SAR</strong></div>
  </div>
  <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:10px 12px">
    <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px">Total Collected</div>
    <div class="mono" style="font-size:17px;font-weight:700;color:var(--success);margin-top:2px"><?= number_format($total_paid, 2) ?> <span style="font-size:11px;font-weight:400">SAR</span></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:1px"><?= count($rows) ?> transaction<?= count($rows) == 1 ? '' : 's' ?></div>
  </div>
  <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:10px 12px">
    <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px">Net Balance</div>
    <div class="mono" style="font-size:17px;font-weight:700;margin-top:2px;color:<?= $bal_val > 0 ? 'var(--success)' : ($bal_val < 0 ? 'var(--danger)' : 'var(--text-muted)') ?>">
      <?= $bal_val > 0 ? '+' : '' ?><?= number_format($bal_val, 2) ?> <span style="font-size:11px;font-weight:400">SAR</span>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:1px"><?= $bal_val < 0 ? 'Amount owing' : ($bal_val > 0 ? 'Prepaid credit' : 'Fully settled') ?></div>
  </div>
</div>

<!-- Vacation Holds (if any) -->
<?php if (!empty($vholds)): ?>
<div style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:10px 14px;margin-bottom:14px">
  <div style="font-size:12px;font-weight:700;color:#d97706;display:flex;align-items:center;gap:6px;margin-bottom:6px">
    <i class="fas fa-umbrella-beach"></i> Vacation Holds (Billing paused during these periods)
  </div>
  <?php foreach ($vholds as $vh): ?>
    <div style="font-size:12px;color:var(--text);display:flex;justify-content:space-between;align-items:center;padding:3px 0">
      <span>
        <strong><?= date('d M Y', strtotime($vh['hold_start'])) ?></strong> &rarr; 
        <strong><?= $vh['hold_end'] ? date('d M Y', strtotime($vh['hold_end'])) : '<span class="badge badge-warning" style="font-size:10px">CURRENTLY AWAY</span>' ?></strong>
        <?php if (!empty($vh['reason'])): ?>
          <span style="color:var(--text-muted);margin-left:6px">(<?= htmlspecialchars($vh['reason']) ?>)</span>
        <?php endif; ?>
      </span>
      <span class="mono" style="font-size:11px;color:var(--text-muted)">
        <?php 
          if ($vh['hold_end']) {
            $diff = (new DateTime($vh['hold_start']))->diff(new DateTime($vh['hold_end']))->days + 1;
            echo $diff . ' days paused';
          } else {
            echo 'Ongoing';
          }
        ?>
      </span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Payment Transactions Table -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
  <div style="font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:6px">
    <i class="fas fa-receipt" style="color:var(--accent)"></i> Payment & Transaction Ledger
  </div>
  <span style="font-size:11.5px;color:var(--text-muted)"><?= count($rows) ?> record<?= count($rows) == 1 ? '' : 's' ?></span>
</div>

<?php if (empty($rows)): ?>
  <div style="text-align:center;padding:36px 16px;background:var(--surface2);border:1px solid var(--border);border-radius:10px;color:var(--text-muted)">
    <i class="fas fa-receipt" style="font-size:32px;opacity:.35;display:block;margin-bottom:12px"></i>
    <div style="font-weight:700;font-size:14px;color:var(--text)">No Payment Records Found</div>
    <div style="font-size:12px;margin-top:4px">No collection history has been recorded for this customer yet.</div>
  </div>
<?php else: ?>
  <div class="table-wrap" style="max-height:48vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
    <table style="width:100%;border-collapse:collapse;color:var(--text);font-size:13px;margin:0">
      <thead>
        <tr style="border-bottom:1px solid var(--border);background:var(--surface2)">
          <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:left">#</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:left">Month</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:right">Amount</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:left">Collected Date</th>
          <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;text-align:left">Collector</th>
        </tr>
      </thead>
      <tbody>
        <?php $r_idx = 0; foreach ($rows as $r): $r_idx++; ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:10px 14px;color:var(--text-muted);font-size:11.5px" class="mono"><?= $r_idx ?></td>
          <td style="padding:10px 14px;font-weight:600;color:var(--text)">
            <span class="badge badge-blue" style="font-size:11.5px;font-family:'IBM Plex Mono',monospace"><?= htmlspecialchars($r['month_year']) ?></span>
            <?php if (!empty($r['is_settled'])): ?>
              <span class="badge badge-green" style="font-size:9.5px;padding:2px 6px;margin-left:4px">✓ Settled</span>
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
      <tfoot>
        <tr style="background:var(--surface2);font-weight:700;border-top:2px solid var(--border)">
          <td colspan="2" style="padding:10px 14px;text-align:left;color:var(--text)">Total Paid</td>
          <td style="padding:10px 14px;text-align:right" class="mono"><strong style="color:var(--success);font-size:14px"><?= number_format($total_paid, 2) ?> SAR</strong></td>
          <td colspan="2" style="padding:10px 14px;font-size:12px;color:var(--text-muted)"><?= count($rows) ?> transaction<?= count($rows) == 1 ? '' : 's' ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
<?php endif; ?>
