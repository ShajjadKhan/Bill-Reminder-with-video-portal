<?php
require_once __DIR__ . '/billing_logic.php';

$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
$db->busyTimeout(10000);
date_default_timezone_set('Asia/Riyadh');

$movie_server = getSetting($db, 'movie_server') ?: 'http://10.12.14.16:8082';
$s1n = getSetting($db, 'support_1_name'); $s1p = getSetting($db, 'support_1_phone');
$s2n = getSetting($db, 'support_2_name'); $s2p = getSetting($db, 'support_2_phone');

$today = new DateTime();
$target_5 = (clone $today)->modify('+5 days');
$target_2 = (clone $today)->modify('+2 days');
$target_days = array_unique([(int)$target_5->format('j'), (int)$target_2->format('j')]);
$current_month = $today->format('Y-m');

$customers = $db->query("SELECT id, name, mobile, due_day, billing_day, billing_start_date, monthly_fee, pay_by_day FROM customers WHERE status='active'");

$sent = 0;
$skipped = 0;

while ($c = $customers->fetchArray(SQLITE3_ASSOC)) {
    $cid = (int)$c['id'];
    $due_day = (int)($c['due_day'] ?: $c['billing_day'] ?: 1);
    $days_in_month = (int)date('t');
    $effective_due = min($due_day, $days_in_month);
    
    // Check if due_day matches target days (5 days or 2 days before due day)
    if (!in_array($effective_due, $target_days)) {
        continue;
    }

    // 1. Vacation hold check: full dates, NULL end = still away
    $vholds = [];
    $on_vacation_now = false;
    $today_dt = new DateTime(date('Y-m-d'));
    $hres = $db->query("SELECT hold_start, hold_end FROM vacation_holds WHERE customer_id=$cid");
    while ($h = $hres->fetchArray(SQLITE3_ASSOC)) {
        $vs = new DateTime($h['hold_start']);
        $ve = $h['hold_end'] ? new DateTime($h['hold_end']) : new DateTime(date('Y-m-d'));
        $vholds[] = ['s' => $vs, 'e' => $ve];
        if (!$h['hold_end'] && $today_dt >= $vs) {
            $on_vacation_now = true;
        }
    }
    if ($on_vacation_now) {
        echo "SKIPPED (on vacation): {$c['name']}\n";
        $skipped++;
        continue;
    }

    $fee = max(1, floatval($c['monthly_fee'] ?: 30));

    // 2. Portal unified unpaid months calculation ("all pendings are normal")
    $unpaid = getUnpaidMonths($db, $cid, $current_month);
    $total_unpaid = 0;
    foreach ($unpaid as $u) {
        $total_unpaid += floatval($u['due']);
    }
    $total_unpaid = round($total_unpaid, 2);

    $bal = getCustomerBalance($db, $cid);
    $account_balance = $bal ? floatval($bal['balance']) : -$total_unpaid;
    $owes = $bal ? floatval($bal['owes']) : $total_unpaid;

    // Skip Condition A: Customer is NOT pending in the portal (0 unpaid months / fully paid)
    if (empty($unpaid) || $total_unpaid <= 0) {
        echo "SKIPPED (paid/normal - 0 unpaid months in portal): {$c['name']}\n";
        $skipped++;
        continue;
    }

    // Skip Condition B: Customer has credit balance
    if ($account_balance >= 0) {
        echo "SKIPPED (paid ahead / credit balance +{$account_balance} SAR): {$c['name']}\n";
        $skipped++;
        continue;
    }

    // Skip Condition C: User explicit rule — if customer paid this month AND still due under 10 SAR
    $paid_this_month = (float)$db->querySingle("SELECT COALESCE(SUM(amount), 0) FROM collections WHERE customer_id=$cid AND (strftime('%Y-%m', collected_date)='$current_month' OR month_year='$current_month')");

    if ($paid_this_month > 0 && ($total_unpaid <= 10 || $owes <= 10)) {
        echo "SKIPPED (paid {$paid_this_month} SAR this month, remaining due <= 10 SAR [unpaid: {$total_unpaid} SAR, owes: {$owes} SAR]): {$c['name']}\n";
        $skipped++;
        continue;
    }

    // If we reach here, the customer has genuine unpaid balance > 10 SAR and is pending
    $total_due = $total_unpaid;
    $pay_by_day = intval($c['pay_by_day'] ?? 10) ?: 10;
    $join_dt = new DateTime($c['billing_start_date'] ?: date('Y-m-01'));

    // Determine active_until based on the oldest unpaid month
    $oldest_unpaid_month = $unpaid[0]['month'];
    $oldest_dt = new DateTime($oldest_unpaid_month . '-01');
    $dim = (int)$oldest_dt->format('t');
    $safe_day = min($effective_due, $dim);
    $active_until = new DateTime($oldest_unpaid_month . '-' . sprintf('%02d', $safe_day));

    $expired = $active_until < $today;
    $days_diff = abs($today->diff($active_until)->days);

    $message = "📶 CYBERNET ACCOUNT STATUS\n";
    $message .= "Assalamu Alaikum {$c['name']}!\n\n";
    $message .= "📅 Connected since: " . $join_dt->format('d M Y') . "\n";
    if ($expired) {
        $message .= "⚠️ Your service expired on: " . $active_until->format('d M Y') . " ($days_diff days ago)\n\n";
        $message .= "💰 Please recharge $total_due SAR to continue service\n";
    } else {
        $message .= "✅ Your service is active until: " . $active_until->format('d M Y') . "\n\n";
        $message .= "💰 Please recharge $total_due SAR before it ends\n";
    }
    $message .= "📆 Pay by: Day $pay_by_day of this month\n\n";
    $message .= "🎬 Movies: $movie_server\n";
    $message .= "⚽ Live Football: http://10.12.14.16:8086\n\n";
    $message .= "📞 Support (24/7):\n";
    if ($s1n && $s1p) $message .= "$s1n: $s1p\n";
    if ($s2n && $s2p) $message .= "$s2n: $s2p\n";
    $message .= "\nPlease recharge on time to avoid service interruption. 🙏\n";

    // Send via sendWhatsAppMessage from billing_logic.php
    if (!getenv('DRY_RUN')) { $res = sendWhatsAppMessage($db, $c['mobile'], $message); }

    $status_str = $expired ? "-{$days_diff}d (expired)" : "+{$days_diff}d left";
    echo "SENT to {$c['name']} ({$c['mobile']}) - due day {$due_day}, status: $status_str, total due: $total_due SAR\n";
    $sent++;
}

echo "\nDone. Sent: $sent | Skipped (paid/normal): $skipped\n";
?>
