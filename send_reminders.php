<?php
$db = new SQLite3('/home/tserver/billing_reminder/bills.db');
date_default_timezone_set('Asia/Riyadh');

function getSetting($db, $key) {
    $v = $db->querySingle("SELECT value FROM settings WHERE key='" . SQLite3::escapeString($key) . "'");
    return $v ?: '';
}
$movie_server = getSetting($db, 'movie_server');
$s1n = getSetting($db, 'support_1_name'); $s1p = getSetting($db, 'support_1_phone');
$s2n = getSetting($db, 'support_2_name'); $s2p = getSetting($db, 'support_2_phone');

$today = new DateTime();
$target_5 = (clone $today)->modify('+5 days');
$target_2 = (clone $today)->modify('+2 days');
$target_days = array_unique([$target_5->format('j'), $target_2->format('j')]);
$current_month = $today->format('Y-m');

$wa_sid = getSetting($db, 'openwa_session_id') ?: '8cc17322-a9d3-4b88-89ac-d4d95fb57ff4';
$openwa_url = "http://localhost:2785/api/sessions/{$wa_sid}/messages/send-text";
$api_key = "dev-admin-key";

$customers = $db->query("SELECT id, name, mobile, due_day, billing_start_date, monthly_fee, pay_by_day FROM customers WHERE status='active'");

$sent = 0;
$skipped = 0;

while ($c = $customers->fetchArray(SQLITE3_ASSOC)) {
    // Check if due_day matches target days
    $due_day = (int)$c['due_day'];
    // Handle due_day 31 - treat as last day of month
    $days_in_month = (int)date('t');
    $effective_due = min($due_day, $days_in_month);
    
    if (!in_array($effective_due, $target_days)) continue;

    // Calculate total paid vs total owed across all months
    $start = new DateTime($c['billing_start_date']);
    $now = new DateTime(date('Y-m-01'));
    $period = new DatePeriod($start, new DateInterval('P1M'), $now->modify('+1 month'));
    
    // Vacation holds: full dates, NULL end = still away
    $vholds = [];
    $on_vacation_now = false;
    $today_dt = new DateTime(date('Y-m-d'));
    $hres = $db->query("SELECT hold_start, hold_end FROM vacation_holds WHERE customer_id={$c['id']}");
    while ($h = $hres->fetchArray(SQLITE3_ASSOC)) {
        $vs = new DateTime($h['hold_start']);
        $ve = $h['hold_end'] ? new DateTime($h['hold_end']) : new DateTime(date('Y-m-d'));
        $vholds[] = ['s' => $vs, 'e' => $ve];
        if (!$h['hold_end'] && $today_dt >= $vs) $on_vacation_now = true;
    }
    if ($on_vacation_now) {
        echo "SKIPPED (on vacation): {$c['name']}\n";
        $skipped++;
        continue;
    }
    
    $fee = max(1, floatval($c['monthly_fee'] ?: 30));
    $total_owed = 0;
    $total_paid = 0;
    $unpaid_months = [];
    
    foreach ($period as $dt) {
        $month_year = $dt->format('Y-m');
        $mStart = new DateTime($month_year . '-01');
        $mEnd = clone $mStart; $mEnd->modify('last day of this month');
        $dim = (int)$mEnd->format('j');
        $billable = 0; $d = clone $mStart;
        while ($d <= $mEnd) {
            $oh = false;
            foreach ($vholds as $vh) { if ($d >= $vh['s'] && $d <= $vh['e']) { $oh = true; break; } }
            if (!$oh) $billable++;
            $d->modify('+1 day');
        }
        if ($billable == 0) continue;
        $mfee = round($fee * $billable / $dim, 2);
        $total_owed += $mfee;
        $paid = (float)$db->querySingle("SELECT COALESCE(SUM(amount),0) FROM collections WHERE customer_id={$c['id']} AND month_year='$month_year'");
        $total_paid += $paid;
        $due = round($mfee - $paid, 2);
        if ($due > 0) {
            $unpaid_months[] = $dt->format('F Y') . ": $due SAR";
        }
    }

    $balance = $total_paid - $total_owed;

    // Skip if customer has credit (paid ahead) or no unpaid months
    if ($balance >= 0 || empty($unpaid_months)) {
        echo "SKIPPED (paid/credit): {$c['name']}\n";
        $skipped++;
        continue;
    }

    $total_due = abs($balance);
    $pay_by_day = intval($c['pay_by_day'] ?? 10) ?: 10;
    $join_dt = new DateTime($c['billing_start_date']);
    $daily_rate = round($fee / 30, 4);
    $days_covered = $daily_rate > 0 ? (int)floor($total_paid / $daily_rate) : 0;

    // Walk day by day from join date, skipping vacation days, to find "active until" date
    $active_until = null;
    if ($days_covered > 0) {
        $cnt = 0; $cur = clone $join_dt;
        for ($i = 0; $i < 5000; $i++) {
            $oh = false;
            foreach ($vholds as $vh) { if ($cur >= $vh['s'] && $cur <= $vh['e']) { $oh = true; break; } }
            if (!$oh) {
                $cnt++;
                if ($cnt >= $days_covered) { $active_until = clone $cur; break; }
            }
            $cur->modify('+1 day');
        }
        if (!$active_until) $active_until = clone $cur;
    } else {
        $active_until = (clone $join_dt)->modify('-1 day');
    }

    $expired = $active_until < $today;
    $days_diff = abs($today->diff($active_until)->days);

    $movie_server = getSetting($db, 'movie_server');
    $s1n = getSetting($db, 'support_1_name'); $s1p = getSetting($db, 'support_1_phone');
    $s2n = getSetting($db, 'support_2_name'); $s2p = getSetting($db, 'support_2_phone');
    
    $message = "📶 CYBERNET ACCOUNT STATUS\n";
    $message .= "Assalamu Alaikum {$c['name']}!\n\n";
    $message .= "📅 Connected since: " . $join_dt->format('d M Y') . "\n";
    if ($expired) {
        $message .= "⚠️ Your service expired on: " . $active_until->format('d M Y') . " ($days_diff days ago)\n\n";
        $message .= "💰 Please recharge $fee SAR to continue service\n";
    } else {
        $message .= "✅ Your service is active until: " . $active_until->format('d M Y') . "\n\n";
        $message .= "💰 Please recharge $fee SAR before it ends\n";
    }
    $message .= "📆 Pay by: Day $pay_by_day of this month\n\n";
    $message .= "🎬 Movies: $movie_server\n";
    $message .= "⚽ Live Football: http://10.12.14.16:8086\n\n";
    $message .= "📞 Support (24/7):\n";
    $message .= "$s1n: $s1p\n";
    if ($s2n && $s2p) $message .= "$s2n: $s2p\n";
        $message .= "\nPlease recharge on time to avoid service interruption. 🙏\n";

    $phone = ltrim(trim($c['mobile']), '0');
    if (!preg_match('/^966/', $phone)) $phone = '966' . $phone;
    $chatId = $phone . "@c.us";

    // DISABLED FOR TESTING - uncomment to enable
    
    $ch = curl_init($openwa_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "X-API-Key: $api_key"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chatId' => $chatId, 'text' => $message]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    

    $status_str = $expired ? "-{$days_diff}d (expired)" : "+{$days_diff}d left";
    echo "SENT to {$c['name']} ({$c['mobile']}) - due day {$c['due_day']}, status: $status_str, total due: $total_due SAR\n";
    $sent++;
}

echo "\nDone. Would send: $sent | Skipped (paid): $skipped\n";
?>
